<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);
$logoutError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        http_response_code(403);
        $logoutError = 'Your security token expired or could not be verified. Please confirm sign-out below.';
    } else {
        if ($currentOwner !== null && ($_POST['scope'] ?? '') === 'all') {
            OwnerAuth::revokeAllOwnerSessions($pdo, (int)$currentOwner['id'], true);
            OwnerAuth::logoutOwner($pdo);
            header('Location: /owner/login.php?logged_out_all=1');
            exit;
        }
        OwnerAuth::logoutOwner($pdo);
        header('Location: /owner/login.php?logged_out=1');
        exit;
    }
}

if ($currentOwner === null) {
    header('Location: /owner/login.php');
    exit;
}

$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirm Sign Out — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Confirm Sign Out — Busherian Biasharas', 'Confirm signing out of your Busherian Biasharas Owner Account.', '/owner/logout.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Confirm Sign Out</h1>
          <p class="brand-subtitle">Signed in as <?= htmlspecialchars((string)$currentOwner['email'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/owner/index.php" class="btn btn-outline-light">&larr; Back to My Businesses</a>
      </nav>
    </div>
  </header>

  <main style="max-width:460px;margin:3rem auto;padding:0 1.25rem;">
    <?php if ($logoutError !== ''): ?>
      <div data-testid="owner-logout-error" style="background:#FEF2F2;border:2px solid #DC2626;border-radius:10px;padding:0.9rem;margin-bottom:1rem;color:#991B1B;font-size:0.88rem;font-weight:600;">
        ⚠️ <?= htmlspecialchars($logoutError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
      <h2 style="margin-top:0;color:#7B1113;font-size:1.15rem;">Sign Out of Owner Portal?</h2>
      <p style="font-size:0.86rem;color:#4B5563;">
        Confirm below to sign out <strong><?= htmlspecialchars((string)$currentOwner['email'], ENT_QUOTES, 'UTF-8') ?></strong> on this browser.
      </p>
      <form method="POST" action="/owner/logout.php" style="margin-bottom:0.75rem;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" data-testid="owner-confirm-logout-btn" class="btn btn-crimson" style="width:100%;justify-content:center;">
          Confirm Sign Out
        </button>
      </form>
      <a href="/owner/index.php" class="btn btn-outline" style="width:100%;justify-content:center;box-sizing:border-box;">Cancel &amp; Stay Signed In</a>
    </section>
  </main>
</body>
</html>
