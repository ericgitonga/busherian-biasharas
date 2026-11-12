<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$loginError = '';
$loginNotice = !empty($_GET['logged_out']) ? 'You have been signed out of the Admin Console.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        http_response_code(403);
        $loginError = 'Invalid or expired security token (CSRF) for sign-out.';
    } else {
        AdminAuth::logout();
        header('Location: /admin/login.php?logged_out=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_token'])) {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $loginError = 'Invalid or expired security token (CSRF).';
    } else {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $res = AdminAuth::login($pdo, trim((string)$_POST['admin_token']), $ip);
        if ($res['ok']) {
            if (!headers_sent()) {
                header('Location: /admin/index.php');
            }
            exit;
        }
        $loginError = $res['error'];
    }
}

$isAdminLoggedIn = AdminAuth::isAuthenticated();
$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Authentication Required — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Admin Sign In — Busherian Biasharas', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/admin/login.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Admin Security Gate</h1>
          <p class="brand-subtitle">Protected Vetting &amp; Moderation Queue (Kenya DPA 2019 s.41)</p>
        </div>
      </a>
      <nav class="header-actions">
        <?php if ($isAdminLoggedIn): ?>
          <a href="/admin/index.php" class="btn btn-outline-light">&larr; Admin Console</a>
        <?php endif; ?>
        <a href="/" class="btn btn-outline-light">&larr; Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:460px;margin:3rem auto;padding:0 1.25rem;">
    <?php if ($loginNotice !== ''): ?>
      <div data-testid="admin-logout-notice" style="background:#ECFDF5;border:2px solid #059669;border-radius:10px;padding:0.9rem;margin-bottom:1rem;color:#065F46;font-size:0.88rem;font-weight:600;">
        ✓ <?= htmlspecialchars($loginNotice, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($loginError !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:10px;padding:0.9rem;margin-bottom:1rem;color:#991B1B;font-size:0.88rem;">
        <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($isAdminLoggedIn): ?>
      <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
        <h2 style="margin-top:0;color:#7B1113;font-size:1.15rem;">🔒 Admin Session Active</h2>
        <p style="font-size:0.85rem;color:#4B5563;">
          Your administrator session is currently active. Confirm below if you wish to sign out of the Admin Console.
        </p>
        <form method="POST" action="/admin/login.php" style="margin-bottom:0.75rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit" data-testid="admin-confirm-logout-btn" class="btn btn-crimson" style="width:100%;justify-content:center;">
            Confirm Admin Sign Out
          </button>
        </form>
        <a href="/admin/index.php" class="btn btn-outline" style="width:100%;justify-content:center;box-sizing:border-box;">Return to Admin Console</a>
      </section>
    <?php else: ?>
      <form method="POST" action="/admin/login.php" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <h2 style="margin-top:0;color:#7B1113;font-size:1.15rem;">🔒 Admin Sign In</h2>
        <p style="font-size:0.84rem;color:#4B5563;">
          Pending alumni vetting records are strictly protected prior to their automatic post-review deletion. Enter your Admin Access Token or Password to continue.
        </p>
        <div style="margin-bottom:1rem;">
          <label for="admin_token" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.35rem;">Admin Password / Access Token *</label>
          <input type="password" id="admin_token" name="admin_token" class="input-control" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-crimson" style="width:100%;">Unlock Admin Queue &rarr;</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
