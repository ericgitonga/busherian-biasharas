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
$targetBizId = (int)($_REQUEST['biz'] ?? ($_SESSION['bbiz_target_edit_biz_id'] ?? 0));
if ($targetBizId > 0) {
    $_SESSION['bbiz_target_edit_biz_id'] = $targetBizId;
}
$targetBizName = null;
$targetBizHints = [];
if ($targetBizId > 0) {
    $tbStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id LIMIT 1");
    $tbStmt->execute([':id' => $targetBizId]);
    $targetBizName = $tbStmt->fetchColumn() ?: null;
    if ($targetBizName !== null) {
        $targetBizHints = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $targetBizId);
    }
}

$currentOwner = OwnerAuth::getCurrentOwner($pdo);
if ($currentOwner !== null) {
    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$currentOwner['id'], (string)($currentOwner['email'] ?? ''));
    $redeemedClaimId = OwnerAuth::redeemVerifiedClaimFromSession(
        $pdo,
        (int)$currentOwner['id'],
        (string)($currentOwner['email'] ?? '')
    );
    if ($redeemedClaimId !== null && $redeemedClaimId > 0) {
        header("Location: /owner/edit-business.php?id={$redeemedClaimId}&claimed=1");
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

// Security Boundary (#163): Never accept unsigned claim query/body parameters on login.
// Only honor a cryptographically verified, unconsumed claim invitation placed in session by /owner/claim.php.
$verifiedClaim = OwnerAuth::getVerifiedClaimFromSession($pdo);
$claimBizId = $verifiedClaim !== null ? (int)$verifiedClaim['biz_id'] : 0;
$claimInvitedEmail = $verifiedClaim !== null ? (string)($verifiedClaim['em'] ?? '') : '';
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

$viewStep = trim((string)($_GET['mode'] ?? 'login')); // 'login' | 'reset' | 'verify_otp'
if (!in_array($viewStep, ['login', 'reset', 'verify_otp'], true)) {
    $viewStep = 'login';
}

$stickyEmail = strtolower(trim((string)($_REQUEST['email'] ?? '')));
$flashSuccess = '';
$flashError = '';
$devPreviewOtpCode = null;
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (isset($_GET['logged_out'])) {
    $flashSuccess = 'You have been signed out of your Owner session.';
}
if (isset($_GET['err']) && $_GET['err'] === 'invalid_link') {
    $flashError = 'That link has expired or was already used. Sign in with your email and password below, or request a 6-digit password reset code.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $formStep = trim((string)($_POST['form_step'] ?? 'password_login'));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $stickyEmail = $email;

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $flashError = 'Your page session expired. Please reload and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flashError = 'Please enter a valid email address.';
    } elseif ($formStep === 'send_otp') {
        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'owner_otp_send', 6, 15);
        $pendingSubBizId = OwnerAuth::findPendingSubmissionBusinessIdForEmail($pdo, $email);
        $hasEligibleVerifiedClaim = (
            $claimBizId > 0 &&
            OwnerAuth::isEmailEligibleForClaim($pdo, $claimBizId, $email, $claimInvitedEmail)
        );
        if (!$rate['allowed']) {
            $flashError = 'Too many verification code requests. Please wait a few minutes and try again.';
            $viewStep = 'reset';
        } elseif (!OwnerAuth::isRecognizedOwnerEmail($pdo, $email) && $pendingSubBizId === null && !$hasEligibleVerifiedClaim) {
            $flashError = 'We could not find a business listing matching that email address. Please check the masked email hint on your listing card, or ask the directory admin for your Owner Access Link.';
            $viewStep = 'reset';
        } else {
            $otpInfo = OwnerAuth::issuePasswordOtp($pdo, $email, $pendingSubBizId);
            $ctxName = $claimBizName ?: $targetBizName;
            Mailer::sendOwnerPasswordOtp($pdo, $email, $otpInfo['code'], $ctxName ? (string)$ctxName : null);
            $viewStep = 'verify_otp';
            $flashSuccess = "We sent a 6-digit verification code to {$email}. Enter the 6-digit code below and choose your password right here in this browser window.";
            if (strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes' || strtolower(Database::env('APP_ENV', 'development')) !== 'production') {
                $devPreviewOtpCode = $otpInfo['code'];
            }
        }
    } elseif ($formStep === 'verify_otp') {
        $viewStep = 'verify_otp';
        $otpCode = trim((string)($_POST['otp_code'] ?? ''));
        $newPw = (string)($_POST['new_password'] ?? '');
        $confirmPw = (string)($_POST['confirm_password'] ?? '');

        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'owner_otp_verify', 8, 15);
        if (!$rate['allowed']) {
            $flashError = 'Too many code attempts. Please wait 15 minutes before trying again.';
        } elseif (mb_strlen($newPw) < 8) {
            $flashError = 'Please choose a password that is at least 8 characters long.';
        } elseif ($confirmPw !== '' && $newPw !== $confirmPw) {
            $flashError = 'Your new password and confirmation password do not match.';
        } else {
            try {
                $verifiedOwner = OwnerAuth::verifyOtpAndSetPassword($pdo, $email, $otpCode, $newPw);
                if ($verifiedOwner === null) {
                    $flashError = 'Invalid or expired 6-digit code. Please double-check the code in your email or request a new one.';
                } else {
                    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$verifiedOwner['id'], $email);
                    $redeemedClaimId = OwnerAuth::redeemVerifiedClaimFromSession($pdo, (int)$verifiedOwner['id'], $email);
                    OwnerAuth::establishOwnerSession($verifiedOwner);
                    if ($redeemedClaimId !== null && $redeemedClaimId > 0) {
                        header("Location: /owner/edit-business.php?id={$redeemedClaimId}&claimed=1");
                        exit;
                    }
                    if ($targetBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$verifiedOwner['id'], $targetBizId)) {
                        unset($_SESSION['bbiz_target_edit_biz_id']);
                        header("Location: /owner/edit-business.php?id={$targetBizId}");
                        exit;
                    }
                    header('Location: /owner/index.php');
                    exit;
                }
            } catch (\Throwable $e) {
                $flashError = $e->getMessage();
            }
        }
    } else {
        // Primary Sign-In: Email + Password
        $password = (string)($_POST['password'] ?? '');
        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'owner_pw_login', 10, 15);
        if (!$rate['allowed']) {
            $flashError = 'Too many sign-in attempts. Please wait 15 minutes or reset your password.';
        } else {
            $existingOwner = OwnerAuth::findOwnerByEmail($pdo, $email);
            if ($existingOwner !== null && empty($existingOwner['password_hash'])) {
                // Existing pre-password owner (e.g. justinake@yahoo.com): seamlessly send 6-digit setup code!
                $otpInfo = OwnerAuth::issuePasswordOtp($pdo, $email, null);
                $ctxName = $claimBizName ?: $targetBizName;
                Mailer::sendOwnerPasswordOtp($pdo, $email, $otpInfo['code'], $ctxName ? (string)$ctxName : null);
                $viewStep = 'verify_otp';
                $flashSuccess = "Welcome back! Because your listing was added before passwords were enabled, we just sent a 6-digit verification code to {$email}. Enter that code below and choose your password to unlock editing.";
                if (strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes' || strtolower(Database::env('APP_ENV', 'development')) !== 'production') {
                    $devPreviewOtpCode = $otpInfo['code'];
                }
            } elseif ($existingOwner === null && OwnerAuth::isRecognizedOwnerEmail($pdo, $email)) {
                // Email matches public/owner email on a listing that hasn't created an owner row yet
                $otpInfo = OwnerAuth::issuePasswordOtp($pdo, $email, null);
                $ctxName = $claimBizName ?: $targetBizName;
                Mailer::sendOwnerPasswordOtp($pdo, $email, $otpInfo['code'], $ctxName ? (string)$ctxName : null);
                $viewStep = 'verify_otp';
                $flashSuccess = "We found a business listing matching {$email} and sent a 6-digit verification code to your inbox. Enter the code below and choose your password to start editing!";
                if (strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes' || strtolower(Database::env('APP_ENV', 'development')) !== 'production') {
                    $devPreviewOtpCode = $otpInfo['code'];
                }
            } else {
                $authOwner = OwnerAuth::authenticateWithPassword($pdo, $email, $password);
                if ($authOwner !== null) {
                    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$authOwner['id'], $email);
                    $redeemedClaimId = OwnerAuth::redeemVerifiedClaimFromSession($pdo, (int)$authOwner['id'], $email);
                    OwnerAuth::establishOwnerSession($authOwner);
                    if ($redeemedClaimId !== null && $redeemedClaimId > 0) {
                        header("Location: /owner/edit-business.php?id={$redeemedClaimId}&claimed=1");
                        exit;
                    }
                    if ($targetBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$authOwner['id'], $targetBizId)) {
                        unset($_SESSION['bbiz_target_edit_biz_id']);
                        header("Location: /owner/edit-business.php?id={$targetBizId}");
                        exit;
                    }
                    header('Location: /owner/index.php');
                    exit;
                }
                $flashError = 'Incorrect email or password. If you forgot your password or have not set one yet, click "Set or Reset Password" below.';
            }
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
$isAdminVisitor = AdminAuth::isAuthenticated();
$bizParamSuffix = $targetBizId > 0 ? ('&biz=' . $targetBizId) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Business Owner Sign In — Busherian Biasharas</title>
<?= bbiz_head_meta_tags('Business Owner Sign In — Busherian Biasharas', 'Sign in with your email and password to manage your Busherian Biashara listing.', '/owner/login.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Owner Sign In</h1>
          <p class="brand-subtitle">Sign In with Email &amp; Password to Edit Your Business</p>
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
          Sign in below to link <strong><?= htmlspecialchars((string)$claimBizName, ENT_QUOTES, 'UTF-8') ?></strong> to your Owner Account so you can edit its details anytime.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($claimBizName === null && $targetBizName !== null): ?>
      <div style="background:#EFF6FF;border:2px solid #1D4ED8;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <div style="font-size:0.78rem;font-weight:800;text-transform:uppercase;color:#1E40AF;letter-spacing:0.04em;">✏️ Sign In to Edit Business Listing</div>
        <div style="font-size:1.05rem;font-weight:800;color:#111827;margin-top:0.2rem;"><?= htmlspecialchars((string)$targetBizName, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if (!empty($targetBizHints)): ?>
          <div style="margin-top:0.45rem;padding:0.45rem 0.7rem;background:#DBEAFE;border-radius:8px;font-size:0.82rem;color:#1E3A8A;">
            📧 <strong>Linked owner email on file:</strong> <?= htmlspecialchars(implode(' or ', $targetBizHints), ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>
        <p style="margin:0.45rem 0 0 0;font-size:0.83rem;color:#1E3A8A;">
          Sign in with your email and password below to go straight to editing <strong><?= htmlspecialchars((string)$targetBizName, ENT_QUOTES, 'UTF-8') ?></strong>. Once signed in, the <strong>✏️ Edit My Business</strong> button will also appear directly on your card in the directory.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($flashSuccess !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($devPreviewOtpCode !== null): ?>
          <div style="margin-top:0.65rem;padding-top:0.65rem;border-top:1px dashed #059669;font-size:0.86rem;">
            🧪 Preview Verification Code: <strong style="font-size:1.15rem;letter-spacing:0.15em;"><?= htmlspecialchars($devPreviewOtpCode, ENT_QUOTES, 'UTF-8') ?></strong>
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
      <?php if ($viewStep === 'verify_otp'): ?>
        <h2 style="margin:0 0 0.35rem 0;color:#114B3E;font-size:1.2rem;">Enter 6-Digit Code &amp; Set Your Password</h2>
        <p style="margin:0 0 1.15rem 0;font-size:0.86rem;color:#4B5563;">
          Check your inbox (and Spam/Junk folder) for the 6-digit code sent to <strong><?= htmlspecialchars($stickyEmail, ENT_QUOTES, 'UTF-8') ?></strong>. Entering it right here keeps you signed in inside your current browser.
        </p>
        <form method="POST" action="/owner/login.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_step" value="verify_otp">
          <input type="hidden" name="biz" value="<?= $targetBizId ?>">
          <input type="hidden" name="email" value="<?= htmlspecialchars($stickyEmail, ENT_QUOTES, 'UTF-8') ?>">

          <div style="margin-bottom:0.85rem;">
            <label for="otp-code" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;color:#111827;">6-Digit Verification Code *</label>
            <input type="text" id="otp-code" name="otp_code" class="input-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" style="font-size:1.2rem;letter-spacing:0.25em;font-weight:800;text-align:center;" required autofocus>
          </div>

          <div style="margin-bottom:0.85rem;">
            <label for="new-owner-password" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;color:#111827;">Choose Your New Password (At least 8 characters) *</label>
            <input type="password" id="new-owner-password" name="new_password" class="input-control" minlength="8" autocomplete="new-password" placeholder="At least 8 characters..." required>
          </div>

          <div style="margin-bottom:1.1rem;">
            <label for="confirm-owner-password" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;color:#111827;">Confirm New Password *</label>
            <input type="password" id="confirm-owner-password" name="confirm_password" class="input-control" minlength="8" autocomplete="new-password" placeholder="Re-enter your password..." required>
          </div>

          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.94rem;">
            ✓ Save Password &amp; Sign In
          </button>
        </form>
        <div style="margin-top:0.95rem;text-align:center;font-size:0.83rem;">
          <a href="/owner/login.php?mode=reset&amp;email=<?= urlencode($stickyEmail) ?><?= htmlspecialchars($bizParamSuffix, ENT_QUOTES, 'UTF-8') ?>" style="color:#7B1113;font-weight:700;">Resend 6-digit code</a>
          &nbsp;·&nbsp;
          <a href="/owner/login.php?mode=login<?= htmlspecialchars($bizParamSuffix, ENT_QUOTES, 'UTF-8') ?>" style="color:#4B5563;">Back to Email &amp; Password Sign In</a>
        </div>

      <?php elseif ($viewStep === 'reset'): ?>
        <h2 style="margin:0 0 0.35rem 0;color:#7B1113;font-size:1.2rem;">Set or Reset Your Owner Password</h2>
        <p style="margin:0 0 1.15rem 0;font-size:0.86rem;color:#4B5563;">
          Enter the email address linked to your business listing. We will send a 6-digit verification code so you can set or reset your password right in this browser window.
        </p>
        <form method="POST" action="/owner/login.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_step" value="send_otp">
          <input type="hidden" name="biz" value="<?= $targetBizId ?>">

          <label for="reset-email" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.35rem;color:#111827;">
            Your Owner Email Address *
          </label>
          <input type="email" id="reset-email" name="email" class="input-control"
            value="<?= htmlspecialchars($stickyEmail, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="e.g. you@yourbusiness.co.ke or yourname@gmail.com" required autofocus>

          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.93rem;margin-top:1rem;">
            ✉️ Send 6-Digit Verification Code
          </button>
        </form>
        <div style="margin-top:0.95rem;text-align:center;font-size:0.84rem;">
          <a href="/owner/login.php?mode=login<?= htmlspecialchars($bizParamSuffix, ENT_QUOTES, 'UTF-8') ?>" style="color:#114B3E;font-weight:700;">&larr; Back to Email &amp; Password Sign In</a>
        </div>

      <?php else: ?>
        <h2 style="margin:0 0 0.35rem 0;color:#7B1113;font-size:1.2rem;">Business Owner Sign In</h2>
        <p style="margin:0 0 1.15rem 0;font-size:0.86rem;color:#4B5563;">
          Sign in with your email and password to stay logged in for 30 days and see the <strong>✏️ Edit My Business</strong> button directly on your listing card.
        </p>

        <?php if ($googleReady): ?>
          <a href="/owner/login.php?action=google" class="btn btn-outline" style="width:100%;justify-content:center;padding:0.7rem 1rem;font-weight:700;font-size:0.9rem;border:2px solid #D1D5DB;display:flex;align-items:center;gap:0.55rem;box-sizing:border-box;">
            <span>🌐</span> Continue with Google
          </a>
          <div style="display:flex;align-items:center;gap:0.75rem;margin:1.1rem 0;color:#9CA3AF;font-size:0.78rem;font-weight:700;text-transform:uppercase;">
            <div style="flex:1;height:1px;background:#E5E7EB;"></div>
            <span>Or Sign In with Email &amp; Password</span>
            <div style="flex:1;height:1px;background:#E5E7EB;"></div>
          </div>
        <?php elseif ($isAdminVisitor): ?>
          <div style="background:#FFFBEB;border:1px solid #F59E0B;border-radius:10px;padding:0.65rem 0.85rem;margin-bottom:1rem;font-size:0.78rem;color:#92400E;">
            ⚙️ <strong>Admin Note:</strong> Optional Google Sign-In can also be enabled in <a href="/admin/index.php#google-sso-config" style="color:#7B1113;font-weight:700;">Admin Console &rarr; Google Sign-In Settings</a>.
          </div>
        <?php endif; ?>

        <form method="POST" action="/owner/login.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_step" value="password_login">
          <input type="hidden" name="biz" value="<?= $targetBizId ?>">

          <label for="owner-email" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.35rem;color:#111827;">
            Your Owner Email Address *
          </label>
          <input type="email" id="owner-email" name="email" class="input-control"
            value="<?= htmlspecialchars($stickyEmail, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="e.g. you@yourbusiness.co.ke or yourname@gmail.com" required>

          <div style="margin-top:0.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:0.35rem;">
              <label for="owner-password" style="font-weight:700;font-size:0.84rem;color:#111827;">Password *</label>
              <a href="/owner/login.php?mode=reset<?= htmlspecialchars($bizParamSuffix, ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.79rem;color:#7B1113;font-weight:700;">Forgot or haven't set a password?</a>
            </div>
            <input type="password" id="owner-password" name="password" class="input-control"
              autocomplete="current-password"
              placeholder="Enter your password" required>
          </div>

          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.94rem;margin-top:1.1rem;">
            🔑 Sign In with Email &amp; Password
          </button>
        </form>

        <div style="margin-top:0.9rem;padding:0.75rem 0.9rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;text-align:center;font-size:0.82rem;color:#374151;">
          Registered your business earlier without setting a password?
          <a href="/owner/login.php?mode=reset<?= htmlspecialchars($bizParamSuffix, ENT_QUOTES, 'UTF-8') ?>" style="color:#114B3E;font-weight:800;display:inline-block;margin-top:0.15rem;">Set or Reset Password with a 6-Digit Email Code &rarr;</a>
        </div>
      <?php endif; ?>

      <div id="owner-signin-guide" style="margin-top:1.35rem;padding-top:1.15rem;border-top:1px solid #E5E7EB;">
        <h3 style="margin:0 0 0.5rem 0;font-size:0.92rem;color:#114B3E;">🔑 How Owner Sign-In &amp; Business Linking Works</h3>
        <ol style="margin:0;padding-left:1.2rem;font-size:0.81rem;color:#374151;line-height:1.55;">
          <li style="margin-bottom:0.35rem;"><strong>Sign In with Email &amp; Password:</strong> Enter the email address linked to your listing and your password. Your session stays active for 30 days and renews automatically when you visit.</li>
          <li style="margin-bottom:0.35rem;"><strong>Automatic Business Linking:</strong> Your account automatically links to any listing matching your registration email, your public business email, or your business website domain.</li>
          <li style="margin-bottom:0.35rem;"><strong>First Time or Forgot Password?</strong> Use <em>Set or Reset Password with a 6-Digit Email Code</em> above — entering the 6-digit code right in this browser window keeps you signed in on Safari, Chrome, or desktop without getting stuck in an email app preview.</li>
          <li><strong>Direct "✏️ Edit My Business" Button:</strong> Once signed in, an <strong>✏️ Edit My Business</strong> button appears directly on your business card and details window in the public directory.</li>
        </ol>
      </div>
    </section>
  </main>
</body>
</html>
