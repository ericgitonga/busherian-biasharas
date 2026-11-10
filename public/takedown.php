<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$message = '';
$error = '';
$prefillId = isset($_GET['business_id']) ? (int)$_GET['business_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $bizId = (int)($_POST['business_id'] ?? 0);
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
            'submitter_name' => $_POST['requester_name'] ?? 'Data Subject',
            'name' => 'DPA Section 40 Request',
            'tagline' => 'Section 40 Takedown',
            'description' => $reason,
        ];
        $spamCheck = AntiSpamGate::verifySubmission($pdo, $spamPayload, $clientIp, 'dpa_takedown');

        if (!$spamCheck['passed']) {
            if ($spamCheck['silent_drop']) {
                // Silent 200 drop for bots/rate-limits without quarantining the business!
                $message = 'Your Section 40 request has been logged for review.';
            } else {
                $error = 'Security verification failed (' . $spamCheck['reason'] . '). Please reload and try again.';
            }
        } elseif ($bizId <= 0 || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || $reason === '') {
            $error = 'Please provide the Business ID, your email address, and the reason for your Section 40 request.';
        } else {
            VettingPurger::quarantineBusinessDpa($pdo, $bizId);
            Mailer::enqueueAndSend(
                $pdo,
                Database::env('ADMIN_EMAIL', 'admin@busherianbiasharas.com'),
                "[DPA Section 40 Quarantine] Business #{$bizId} Quarantined",
                "<p>Business #{$bizId} has been placed in instant <code>quarantined_dpa</code> state by "
                . htmlspecialchars(AntiSpamGate::sanitizeHeaderValue($requesterEmail), ENT_QUOTES, 'UTF-8') . ".</p><p>Reason: "
                . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</p>",
                $requesterEmail
            );
            $message = "Business #{$bizId} has been immediately placed into Section 40 DPA Quarantine and hidden from the public directory while admin@busherianbiasharas.com completes erasure/rectification.";
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
$businesses = $pdo->query("SELECT id, name, city_county FROM businesses WHERE status = 'approved' ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Section 40 Takedown &amp; Rectification — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Section 40 Takedown & Rectification — Busherian Biasharas', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/takedown.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Kenya DPA 2019 Section 40 — Right to Erasure / Instant Quarantine</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:680px;margin:2rem auto;padding:0 1.25rem;">
    <?php if ($message !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;color:#065F46;">
        <strong>✓ Instant DPA Quarantine Activated:</strong> <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1.25rem;margin-bottom:1.25rem;color:#991B1B;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/takedown.php" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;">
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

      <h2 style="margin-top:0;color:#7B1113;">Request Immediate Listing Quarantine or Erasure (DPA s.40)</h2>
      <p style="font-size:0.88rem;color:#4B5563;">
        Submitting this form immediately transitions the selected listing to <code>quarantined_dpa</code> (removing it from public display and search APIs) and notifies <code>admin@busherianbiasharas.com</code>.
      </p>

      <div style="margin-bottom:0.9rem;">
        <label for="business_id" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Select Listing *</label>
        <select id="business_id" name="business_id" class="select-control" required>
          <option value="">Choose business listing...</option>
          <?php foreach ($businesses as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $prefillId === (int)$b['id'] ? 'selected' : '' ?>>
              #<?= (int)$b['id'] ?> — <?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$b['city_county'], ENT_QUOTES, 'UTF-8') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:0.9rem;">
        <label for="requester_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Full Name *</label>
        <input type="text" id="requester_name" name="requester_name" class="input-control" required placeholder="Jane Wanjiku">
      </div>

      <div style="margin-bottom:0.9rem;">
        <label for="requester_email" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Email Address *</label>
        <input type="email" id="requester_email" name="requester_email" class="input-control" required placeholder="you@domain.co.ke">
      </div>

      <div style="margin-bottom:1.1rem;">
        <label for="reason" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Reason for Erasure / Rectification Request *</label>
        <textarea id="reason" name="reason" rows="3" class="input-control" required placeholder="Specify why this listing should be removed or updated..."></textarea>
      </div>

      <button type="submit" class="btn btn-crimson">Immediately Quarantine Listing</button>
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
