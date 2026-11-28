<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$loginError = '';
$loginNotice = !empty($_GET['revoked_all'])
    ? 'All administrator sessions across all devices have been revoked.'
    : (!empty($_GET['logged_out']) ? 'You have been signed out of the Admin Console.' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        http_response_code(403);
        $loginError = 'Invalid or expired security token (CSRF) for sign-out.';
    } else {
        AdminAuth::logout($pdo);
        header('Location: /admin/login.php?logged_out=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_token'])) {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $loginError = 'Invalid or expired security token (CSRF).';
    } else {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $rememberMe = !empty($_POST['remember_me']);
        $res = AdminAuth::login($pdo, trim((string)$_POST['admin_token']), $ip, $rememberMe);
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
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= urlencode(bbiz_app_version()) ?>">
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
          <div style="position:relative;">
            <input type="password" id="admin_token" name="admin_token" class="input-control" required autocomplete="current-password" style="padding-right:2.75rem;">
            <button type="button" class="btn-toggle-password" data-target="admin_token" data-testid="toggle-admin-token" aria-label="Show password" title="Show password" style="position:absolute;right:0.45rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0.4rem;color:#6B7280;display:flex;align-items:center;justify-content:center;border-radius:6px;">
              <svg class="eye-icon-show" style="width:20px;height:20px;display:block;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              <svg class="eye-icon-hide" style="width:20px;height:20px;display:none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
            </button>
          </div>
        </div>
        <div style="margin-bottom:1.25rem;display:flex;align-items:center;gap:0.5rem;">
          <input type="checkbox" id="admin_remember_me" name="remember_me" value="1" data-testid="admin-remember-me-checkbox" style="width:16px;height:16px;accent-color:#7B1113;" checked>
          <label for="admin_remember_me" style="font-size:0.85rem;color:#374151;cursor:pointer;user-select:none;">
            Remember me on this machine for 30 days
          </label>
        </div>
        <button type="submit" class="btn btn-crimson" style="width:100%;">Unlock Admin Queue &rarr;</button>
      </form>
    <?php endif; ?>
  </main>
  <script>
    document.querySelectorAll('.btn-toggle-password').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-target');
        var input = document.getElementById(targetId);
        if (!input) return;
        var isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        var iconShow = btn.querySelector('.eye-icon-show');
        var iconHide = btn.querySelector('.eye-icon-hide');
        if (iconShow) iconShow.style.display = isPassword ? 'none' : 'block';
        if (iconHide) iconHide.style.display = isPassword ? 'block' : 'none';
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
        input.focus();
      });
    });
  </script>
</body>
</html>
