<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$pdo = Database::getConnection();
$targetBizId = (int)($_GET['biz'] ?? ($_SESSION['bbiz_target_edit_biz_id'] ?? 0));
if (isset($_GET['biz']) && (int)$_GET['biz'] > 0) {
    $_SESSION['bbiz_target_edit_biz_id'] = (int)$_GET['biz'];
    $targetBizId = (int)$_GET['biz'];
}
$targetBizName = null;
if ($targetBizId > 0) {
    $tbStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id LIMIT 1");
    $tbStmt->execute([':id' => $targetBizId]);
    $targetBizName = $tbStmt->fetchColumn() ?: null;
}

$currentOwner = OwnerAuth::getCurrentOwner($pdo);
if ($currentOwner !== null) {
    $pendingClaimId = (int)($_SESSION['bbiz_pending_claim_biz_id'] ?? 0);
    if ($pendingClaimId > 0) {
        OwnerAuth::linkOwnerToBusiness($pdo, (int)$currentOwner['id'], $pendingClaimId, 'claim_invite_link');
        unset($_SESSION['bbiz_pending_claim_biz_id']);
        header("Location: /owner/edit-business.php?id={$pendingClaimId}&claimed=1");
        exit;
    }
    if ($targetBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$currentOwner['id'], $targetBizId)) {
        unset($_SESSION['bbiz_target_edit_biz_id']);
        header("Location: /owner/edit-business.php?id={$targetBizId}");
        exit;
    }
    header('Location: /owner/index.php');
    exit;
}

$claimBizId = (int)($_GET['claim'] ?? ($_SESSION['bbiz_pending_claim_biz_id'] ?? 0));
$claimBizName = null;
if ($claimBizId > 0) {
    $bStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id LIMIT 1");
    $bStmt->execute([':id' => $claimBizId]);
    $claimBizName = $bStmt->fetchColumn() ?: null;
}

$googleReady = OwnerAuth::isGoogleSsoConfigured($pdo);

if (($_GET['action'] ?? '') === 'google' && $googleReady) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['bbiz_google_oauth_state'] = $state;
    header('Location: ' . OwnerAuth::getGoogleAuthorizationUrl($state, $pdo));
    exit;
}

$flashSuccess = '';
$flashError = '';
$devPreviewMagicUrl = null;

if (isset($_GET['logged_out'])) {
    $flashSuccess = 'You have been signed out of your Owner session.';
}
if (isset($_GET['err']) && $_GET['err'] === 'invalid_link') {
    $flashError = 'That sign-in link has expired or was already used. Please request a fresh link below.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $flashError = 'Invalid security token (CSRF). Please reload and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Please enter a valid email address.';
    } else {
        try {
            $magic = OwnerAuth::createMagicLink($pdo, $email, $claimBizId > 0 ? $claimBizId : null);
            Mailer::sendOwnerMagicLink($pdo, $email, $magic['url'], $claimBizName ? (string)$claimBizName : null);
            $flashSuccess = "We have sent a one-click sign-in link to {$email}. Click the link in your email to sign in and stay logged in for 30 days.";
            if (strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes' || strtolower(Database::env('APP_ENV', 'development')) !== 'production') {
                $devPreviewMagicUrl = $magic['url'];
            }
        } catch (\Throwable $e) {
            $flashError = 'Could not generate sign-in link. Please verify your email and try again.';
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
$isAdminVisitor = AdminAuth::isAuthenticated();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Business Owner Sign In — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Business Owner Sign In — Busherian Biasharas', 'Sign in to manage your Busherian Biashara listing.', '/owner/login.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Owner Portal</h1>
          <p class="brand-subtitle">Persistent SSO &amp; Self-Service Listing Management</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/submit.php" class="btn btn-gold">+ List New Biashara</a>
        <a href="/" class="btn btn-outline-light">&larr; Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:540px;margin:2.25rem auto;padding:0 1.25rem;">
    <?php if ($claimBizName !== null): ?>
      <div style="background:#F0FDF4;border:2px solid #114B3E;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="font-size:0.78rem;font-weight:800;text-transform:uppercase;color:#114B3E;letter-spacing:0.04em;">🔗 Claim &amp; Link Pre-Populated Business</div>
        <div style="font-size:1.02rem;font-weight:800;color:#111827;margin-top:0.2rem;"><?= htmlspecialchars((string)$claimBizName, ENT_QUOTES, 'UTF-8') ?></div>
        <p style="margin:0.35rem 0 0 0;font-size:0.84rem;color:#374151;">
          Sign in below to link <strong><?= htmlspecialchars((string)$claimBizName, ENT_QUOTES, 'UTF-8') ?></strong> to your persistent Owner Account so you can edit its details anytime.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($claimBizName === null && $targetBizName !== null): ?>
      <div style="background:#EFF6FF;border:2px solid #1D4ED8;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="font-size:0.78rem;font-weight:800;text-transform:uppercase;color:#1E40AF;letter-spacing:0.04em;">✏️ Sign In to Edit Business Listing</div>
        <div style="font-size:1.02rem;font-weight:800;color:#111827;margin-top:0.2rem;"><?= htmlspecialchars((string)$targetBizName, ENT_QUOTES, 'UTF-8') ?></div>
        <p style="margin:0.35rem 0 0 0;font-size:0.84rem;color:#1E3A8A;">
          Enter the email address associated with <strong><?= htmlspecialchars((string)$targetBizName, ENT_QUOTES, 'UTF-8') ?></strong> below. Once you click your 1-click sign-in link, you will be taken directly to the edit form and an <strong>✏️ Edit My Business</strong> button will appear right on your listing card!
        </p>
      </div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($devPreviewMagicUrl !== null): ?>
          <div style="margin-top:0.65rem;padding-top:0.65rem;border-top:1px dashed #059669;font-size:0.82rem;">
            <a href="<?= htmlspecialchars($devPreviewMagicUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-green" style="display:inline-block;margin-top:0.25rem;">⚡ Click Here to Complete Sign-In</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.6rem;box-shadow:0 4px 14px rgba(0,0,0,0.05);">
      <h2 style="margin:0 0 0.35rem 0;color:#7B1113;font-size:1.2rem;">Business Owner Sign In</h2>
      <p style="margin:0 0 1.2rem 0;font-size:0.86rem;color:#4B5563;">
        Sign in once to stay logged in for 30 days and update your business hours, contacts, location pin, or description anytime without waiting on admin.
      </p>

      <?php if ($googleReady): ?>
        <a href="/owner/login.php?action=google" class="btn btn-outline" style="width:100%;justify-content:center;padding:0.7rem 1rem;font-weight:700;font-size:0.9rem;border:2px solid #D1D5DB;display:flex;align-items:center;gap:0.55rem;box-sizing:border-box;">
          <span>🌐</span> Continue with Google (SSO)
        </a>

        <div style="display:flex;align-items:center;gap:0.75rem;margin:1.15rem 0;color:#9CA3AF;font-size:0.78rem;font-weight:700;text-transform:uppercase;">
          <div style="flex:1;height:1px;background:#E5E7EB;"></div>
          <span>Or Sign In via Email Link</span>
          <div style="flex:1;height:1px;background:#E5E7EB;"></div>
        </div>
      <?php elseif ($isAdminVisitor): ?>
        <div style="background:#FFFBEB;border:1px solid #F59E0B;border-radius:10px;padding:0.65rem 0.85rem;margin-bottom:1rem;font-size:0.78rem;color:#92400E;">
          ⚙️ <strong>Admin Note:</strong> To enable the <em>Continue with Google (SSO)</em> button alongside Email Sign-In, paste your Google OAuth Client ID &amp; Secret in <a href="/admin/index.php#google-sso-config" style="color:#7B1113;font-weight:700;">Admin Console &rarr; Google SSO Settings</a>.
        </div>
      <?php endif; ?>

      <!-- Passwordless Email Magic Link -->
      <form method="POST" action="/owner/login.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="owner-email" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.35rem;color:#111827;">
          Your Business or Personal Email Address *
        </label>
        <input type="email" id="owner-email" name="email" class="input-control"
          placeholder="e.g. you@yourbusiness.co.ke or yourname@gmail.com" required>
        <p style="margin:0.4rem 0 0.9rem 0;font-size:0.77rem;color:#6B7280;">
          💡 <strong>Automatic Linking:</strong> If you sign in with an email matching your business's listed email or website domain (e.g. <code>@branfoot.com</code>, <code>@prescott.co.ke</code>, <code>@sasasignal.com</code>), your pre-populated listing is linked to your account automatically.
        </p>
        <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.72rem 1rem;font-size:0.92rem;">
          ✉️ Send 1-Click Sign-In Link
        </button>
      </form>

      <!-- Step-by-Step Guide: How Owner Sign-In & Business Linking Works (#107) -->
      <div id="owner-signin-guide" style="margin-top:1.35rem;padding-top:1.15rem;border-top:1px solid #E5E7EB;">
        <h3 style="margin:0 0 0.5rem 0;font-size:0.92rem;color:#114B3E;">🔑 How Owner Sign-In &amp; Business Linking Works</h3>
        <ol style="margin:0;padding-left:1.2rem;font-size:0.81rem;color:#374151;line-height:1.55;">
          <li style="margin-bottom:0.35rem;"><strong>1-Click Passwordless Sign-In:</strong> Enter your email above and click <em>Send 1-Click Sign-In Link</em>. Click the link in your email to sign in for 30 days (no password required).</li>
          <li style="margin-bottom:0.35rem;"><strong>Automatic Business Linking (4 Ways):</strong> Your account automatically links to any business matching:
            <ul style="margin:0.2rem 0 0.2rem 0;padding-left:1.1rem;color:#4B5563;">
              <li>The <strong>Submitter Email</strong> you used when registering on <code>/submit.php</code></li>
              <li>Your business's <strong>Public Contact Email</strong> shown on the listing</li>
              <li>Any email on your business's <strong>Official Website Domain</strong> (e.g. <code>you@yourcompany.co.ke</code>)</li>
              <li>An <strong>Admin-Assigned Owner Email</strong> or <strong>1-Click WhatsApp/DM Claim Link</strong></li>
            </ul>
          </li>
          <li><strong>Direct "✏️ Edit My Business" Button:</strong> Once signed in, an <strong>✏️ Edit My Business</strong> button appears directly on your business card and modal in the public directory, as well as in your <strong>My Biasharas</strong> dashboard.</li>
        </ol>
      </div>
    </section>
  </main>
</body>
</html>
