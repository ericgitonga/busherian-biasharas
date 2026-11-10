<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$bizId = (int)($_REQUEST['biz_id'] ?? 0);
$exp = (int)($_REQUEST['exp'] ?? 0);
$sig = trim((string)($_REQUEST['sig'] ?? ''));

if (!OwnerAuth::verifyClaimInviteSignature($bizId, $exp, $sig)) {
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$pdo = Database::getConnection();
$bStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id LIMIT 1");
$bStmt->execute([':id' => $bizId]);
$biz = $bStmt->fetch();
if (!$biz) {
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$currentOwner = OwnerAuth::getCurrentOwner($pdo);
if ($currentOwner !== null) {
    OwnerAuth::linkOwnerToBusiness($pdo, (int)$currentOwner['id'], $bizId, 'claim_invite_link');
    header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
    exit;
}

$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $email = strtolower(trim((string)($_POST['owner_email'] ?? '')));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $errorMsg = 'Invalid security token. Please try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address for your Owner Account.';
    } else {
        $owner = OwnerAuth::upsertOwner($pdo, $email, $fullName ?: null, $phone ?: null);
        OwnerAuth::linkOwnerToBusiness($pdo, (int)$owner['id'], $bizId, 'claim_invite_link');
        OwnerAuth::establishOwnerSession($owner);
        header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
        exit;
    }
}

$_SESSION['bbiz_pending_claim_biz_id'] = $bizId;
$tokens = AntiSpamGate::generateFormTokens();
$suggestedEmail = (string)($biz['owner_email'] ?: ($biz['email_public'] ?? ''));
$suggestedPhone = (string)($biz['phone_primary'] ?? '');
$googleReady = OwnerAuth::isGoogleSsoConfigured($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Claim &amp; Manage <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?> — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Claim Your Business — Busherian Biasharas', 'Activate your persistent Owner Account and manage your Busherian Biasharas listing.', '/owner/claim.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Claim Your Listing</h1>
          <p class="brand-subtitle">1-Click Verified Owner Onboarding &amp; 30-Day Persistent Session</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:560px;margin:2.25rem auto;padding:0 1.25rem;">
    <?php if ($errorMsg !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section style="background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.6rem;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
      <div style="font-size:0.76rem;font-weight:800;text-transform:uppercase;color:#114B3E;letter-spacing:0.04em;">✓ Verified Invitation Link</div>
      <h2 style="margin:0.2rem 0 0.4rem 0;color:#7B1113;font-size:1.25rem;">
        Manage <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>
      </h2>
      <p style="margin:0 0 1.15rem 0;font-size:0.86rem;color:#4B5563;">
        Confirm your owner email below to link <strong><?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></strong> to your persistent Owner Account. You will stay logged in on this browser for 30 days and can edit your business details anytime.
      </p>

      <?php if ($googleReady): ?>
        <a href="/owner/login.php?action=google&amp;claim=<?= $bizId ?>" class="btn btn-outline" style="width:100%;justify-content:center;padding:0.7rem 1rem;font-weight:700;font-size:0.9rem;border:2px solid #D1D5DB;display:flex;align-items:center;gap:0.55rem;box-sizing:border-box;margin-bottom:1rem;">
          <span>🌐</span> Claim &amp; Continue with Google (SSO)
        </a>
      <?php endif; ?>

      <form method="POST" action="/owner/claim.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="biz_id" value="<?= $bizId ?>">
        <input type="hidden" name="exp" value="<?= $exp ?>">
        <input type="hidden" name="sig" value="<?= htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') ?>">

        <div style="margin-bottom:0.85rem;">
          <label style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;">Your Owner Email Address *</label>
          <input type="email" name="owner_email" class="input-control" value="<?= htmlspecialchars($suggestedEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="you@yourbusiness.co.ke or yourname@gmail.com" required>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.1rem;">
          <div>
            <label style="display:block;font-weight:700;font-size:0.82rem;margin-bottom:0.25rem;">Your Name (Optional)</label>
            <input type="text" name="full_name" class="input-control" placeholder="Your Full Name">
          </div>
          <div>
            <label style="display:block;font-weight:700;font-size:0.82rem;margin-bottom:0.25rem;">Direct Phone (Optional)</label>
            <input type="text" name="phone" class="input-control" value="<?= htmlspecialchars($suggestedPhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+2547...">
          </div>
        </div>

        <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.94rem;">
          ✓ Activate My Owner Account &amp; Edit Listing
        </button>
      </form>
    </section>
  </main>
</body>
</html>
