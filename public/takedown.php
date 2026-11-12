<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);
$messageHeading = '';
$message = '';
$error = '';
$prefillId = isset($_GET['business_id']) ? (int)$_GET['business_id'] : 0;

$confirmToken = trim((string)($_GET['confirm_token'] ?? ($_POST['confirm_token'] ?? '')));
$pendingTokenRecord = null;

if ($confirmToken !== '' && strlen($confirmToken) >= 32 && ctype_xdigit($confirmToken)) {
    $th = hash('sha256', $confirmToken);
    $tkStmt = $pdo->prepare("
        SELECT tr.*, b.name AS business_name
        FROM takedown_requests tr
        INNER JOIN businesses b ON b.id = tr.business_id
        WHERE tr.verification_token_hash = :th
          AND tr.status = 'awaiting_owner_email_confirm'
          AND tr.verified_at IS NULL
          AND tr.token_expires_at >= :now
        LIMIT 1
    ");
    $tkStmt->execute([':th' => $th, ':now' => gmdate('Y-m-d H:i:s')]);
    $pendingTokenRecord = $tkStmt->fetch() ?: null;
    if ($pendingTokenRecord === null && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $error = 'That confirmation link has expired or has already been used. You may submit a new request below.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_owner_takedown') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $error = 'Invalid security token. Please reload the page and try again.';
    } else {
        $confirmed = VettingPurger::confirmOwnerTakedownByToken($pdo, $confirmToken);
        if ($confirmed === null) {
            $error = 'That confirmation link has expired or was already completed.';
            $pendingTokenRecord = null;
        } else {
            $pendingTokenRecord = null;
            $cBizId = (int)$confirmed['business_id'];
            $bStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id LIMIT 1");
            $bStmt->execute([':id' => $cBizId]);
            $bRow = $bStmt->fetch() ?: ['id' => $cBizId, 'name' => "Listing #{$cBizId}"];
            Mailer::notifyAdminTakedownRequest($pdo, (array)$bRow, (array)$confirmed, 'owner_quarantined');
            $messageHeading = '✓ Email Confirmed — Listing Immediately Paused:';
            $message = "Your ownership email has been confirmed and {$bRow['name']} is now hidden from the public directory while our team completes your request.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $bizId = (int)($_POST['business_id'] ?? 0);
    $requesterName = trim((string)($_POST['requester_name'] ?? ''));
    $requesterEmail = strtolower(trim((string)($_POST['requester_email'] ?? '')));
    $reason = trim((string)($_POST['reason'] ?? ''));

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $error = 'Invalid security token. Please reload the page.';
    } else {
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $spamPayload = [
            'website' => $_POST['website'] ?? '',
            'form_ts' => $_POST['form_ts'] ?? 0,
            'form_nonce' => $_POST['form_nonce'] ?? '',
            'form_sig' => $_POST['form_sig'] ?? '',
            'js_interaction_token' => $_POST['js_interaction_token'] ?? '',
            'submitter_name' => $requesterName !== '' ? $requesterName : 'Data Subject',
            'name' => 'Privacy Review Request',
            'tagline' => 'Directory Listing Review',
            'description' => $reason,
        ];
        $spamCheck = AntiSpamGate::verifySubmission($pdo, $spamPayload, $clientIp, 'dpa_takedown');

        if (!$spamCheck['passed']) {
            if ($spamCheck['silent_drop']) {
                // Silent 200 drop for bots/rate-limits without altering any business record
                $messageHeading = '✓ Request Received for Review:';
                $message = 'Your listing removal or correction request has been received for review.';
            } else {
                $error = 'Security verification could not be completed. Please reload the page and try again.';
            }
        } elseif ($bizId <= 0 || $requesterName === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || $reason === '') {
            $error = 'Please select the business listing, enter your full name and email address, and describe your request.';
        } else {
            $bStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id LIMIT 1");
            $bStmt->execute([':id' => $bizId]);
            $bizRow = $bStmt->fetch();

            if (!$bizRow) {
                $error = 'We could not find the selected business listing. Please choose an active listing from the menu.';
            } else {
                $isAuthOwner = (
                    $currentOwner !== null &&
                    OwnerAuth::canOwnerEditBusiness($pdo, (int)$currentOwner['id'], $bizId)
                );

                $designatedEmails = [];
                foreach (OwnerAuth::getOwnersForBusiness($pdo, $bizId) as $lo) {
                    $lem = strtolower(trim((string)($lo['email'] ?? '')));
                    if ($lem !== '' && filter_var($lem, FILTER_VALIDATE_EMAIL)) {
                        $designatedEmails[$lem] = true;
                    }
                }
                foreach (['owner_email', 'email_public'] as $col) {
                    $cem = strtolower(trim((string)($bizRow[$col] ?? '')));
                    if ($cem !== '' && filter_var($cem, FILTER_VALIDATE_EMAIL)) {
                        $designatedEmails[$cem] = true;
                    }
                }
                $vEmStmt = $pdo->prepare("SELECT submitter_email FROM business_vetting_queue WHERE business_id = :bid LIMIT 1");
                $vEmStmt->execute([':bid' => $bizId]);
                $vEm = strtolower(trim((string)($vEmStmt->fetchColumn() ?: '')));
                if ($vEm !== '' && filter_var($vEm, FILTER_VALIDATE_EMAIL)) {
                    $designatedEmails[$vEm] = true;
                }

                $matchesDesignatedOwnerEmail = isset($designatedEmails[$requesterEmail]);

                $reqRes = VettingPurger::submitTakedownRequest(
                    $pdo,
                    $bizId,
                    $requesterName,
                    $requesterEmail,
                    $reason,
                    $clientIp,
                    $isAuthOwner,
                    $matchesDesignatedOwnerEmail
                );

                $reqSummary = [
                    'requester_name' => $requesterName,
                    'requester_email' => $requesterEmail,
                    'reason' => $reason,
                ];

                if ($reqRes['status'] === 'owner_verified_quarantined') {
                    Mailer::notifyAdminTakedownRequest($pdo, (array)$bizRow, $reqSummary, 'owner_quarantined');
                    $messageHeading = '✓ Verified Owner Request — Listing Immediately Paused:';
                    $message = "{$bizRow['name']} has been immediately hidden from the public directory while our team at admin@busherianbiasharas.com completes your removal or correction request.";
                } elseif ($reqRes['status'] === 'awaiting_owner_email_confirm' && !empty($reqRes['raw_token'])) {
                    $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
                    $confirmUrl = "{$baseUrl}/takedown.php?confirm_token=" . urlencode((string)$reqRes['raw_token']);
                    Mailer::sendOwnerTakedownConfirmationLink($pdo, (array)$bizRow, $requesterEmail, $confirmUrl);
                    Mailer::notifyAdminTakedownRequest($pdo, (array)$bizRow, $reqSummary, 'queued_review');
                    $messageHeading = '✓ Check Your Email to Pause Immediately (Also Queued for Admin Review):';
                    $message = "We sent a single-use confirmation link to {$requesterEmail}. Click that link to pause {$bizRow['name']} immediately, or allow our team at admin@busherianbiasharas.com to complete priority review.";
                } else {
                    Mailer::notifyAdminTakedownRequest($pdo, (array)$bizRow, $reqSummary, 'queued_review');
                    $primaryOwnerNotify = array_key_first($designatedEmails);
                    if (is_string($primaryOwnerNotify) && $primaryOwnerNotify !== '' && $primaryOwnerNotify !== $requesterEmail) {
                        Mailer::notifyOwnerThirdPartyTakedownNotice($pdo, (array)$bizRow, $primaryOwnerNotify);
                    }
                    $messageHeading = '✓ Request Queued for Priority Admin Review:';
                    $message = "Thank you. Your report regarding {$bizRow['name']} has been logged for priority review by our team at admin@busherianbiasharas.com. To protect alumni listings against unverified disruption, published listings stay live until owner confirmation or administrator review is completed.";
                }
            }
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
$businesses = $pdo->query("SELECT id, business_number, name, city_county FROM businesses WHERE status = 'approved' ORDER BY business_number ASC, name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Report or Remove a Listing — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Report or Remove a Listing — Busherian Biasharas', 'Request a correction, pause, or removal for a Busherian Biasharas directory listing.', '/takedown.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Report, Correct, or Remove a Directory Listing</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:680px;margin:2rem auto;padding:0 1.25rem;">
    <?php if ($message !== ''): ?>
      <div data-testid="takedown-success" style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;color:#065F46;">
        <strong><?= htmlspecialchars($messageHeading, ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div data-testid="takedown-error" style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;color:#991B1B;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($pendingTokenRecord !== null): ?>
      <section style="background:#FFFBEB;border:2px solid #D97706;border-radius:14px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin-top:0;color:#7B1113;">Confirm Pause for <?= htmlspecialchars((string)$pendingTokenRecord['business_name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p style="font-size:0.9rem;color:#374151;">
          Click the button below to confirm your email request and immediately hide <strong><?= htmlspecialchars((string)$pendingTokenRecord['business_name'], ENT_QUOTES, 'UTF-8') ?></strong> from the public directory while our team completes your request.
        </p>
        <form method="POST" action="/takedown.php" data-testid="takedown-confirm-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="confirm_owner_takedown">
          <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($confirmToken, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" data-testid="takedown-confirm-submit" class="btn btn-crimson">Confirm &amp; Immediately Pause My Listing</button>
        </form>
      </section>
    <?php endif; ?>

    <form method="POST" action="/takedown.php" data-testid="takedown-form" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="form_ts" value="<?= (int)$tokens['form_ts'] ?>">
      <input type="hidden" name="form_nonce" value="<?= htmlspecialchars($tokens['form_nonce'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="form_sig" value="<?= htmlspecialchars($tokens['form_sig'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="js_interaction_token" id="js_interaction_token" value="">

      <!-- Layer 1 Anti-Spam: Disguised Text Honeypot -->
      <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
        <label for="td_website_ref">Website Reference</label>
        <input type="text" id="td_website_ref" name="website" value="" tabindex="-1" autocomplete="nope">
      </div>

      <h2 style="margin-top:0;color:#7B1113;">Request a Listing Removal or Correction</h2>
      <p style="font-size:0.88rem;color:#4B5563;line-height:1.55;">
        Verified business owners who are signed in (or who confirm via the single-use link sent to their registered business email) can pause their own listing immediately. Third-party accuracy or privacy reports are sent straight to our administrators at <strong>admin@busherianbiasharas.com</strong> for priority review while keeping verified alumni listings protected against unverified disruption.
      </p>

      <div style="margin-bottom:0.9rem;">
        <label for="business_id" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Select Listing *</label>
        <select id="business_id" name="business_id" data-testid="takedown-business-select" class="select-control" required>
          <option value="">Choose business listing...</option>
          <?php foreach ($businesses as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $prefillId === (int)$b['id'] ? 'selected' : '' ?>>
              #<?= (int)($b['business_number'] ?: $b['id']) ?> — <?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$b['city_county'], ENT_QUOTES, 'UTF-8') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:0.9rem;">
        <label for="requester_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Full Name *</label>
        <input type="text" id="requester_name" name="requester_name" data-testid="takedown-name" class="input-control"
          value="<?= htmlspecialchars((string)($currentOwner['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          required placeholder="Jane Wanjiku">
      </div>

      <div style="margin-bottom:0.9rem;">
        <label for="requester_email" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Email Address *</label>
        <input type="email" id="requester_email" name="requester_email" data-testid="takedown-email" class="input-control"
          value="<?= htmlspecialchars((string)($currentOwner['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          required placeholder="you@domain.co.ke">
      </div>

      <div style="margin-bottom:1.1rem;">
        <label for="reason" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Reason for Removal or Correction Request *</label>
        <textarea id="reason" name="reason" data-testid="takedown-reason" rows="3" class="input-control" required placeholder="Specify why this listing should be removed or updated..."></textarea>
      </div>

      <button type="submit" data-testid="takedown-submit" class="btn btn-crimson">Submit Removal or Correction Request</button>
    </form>
  </main>

  <script>
    (function () {
      const tokenEl = document.getElementById('js_interaction_token');
      const mark = () => {
        if (tokenEl && !tokenEl.value) tokenEl.value = 'human_verified_' + Date.now();
      };
      ['focusin', 'keydown', 'pointerdown', 'touchstart'].forEach(ev => {
        document.addEventListener(ev, mark, { once: true, passive: true });
      });
    })();
  </script>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
