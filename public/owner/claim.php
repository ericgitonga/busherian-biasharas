<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$bizId = (int)($_REQUEST['biz_id'] ?? 0);
$exp = (int)($_REQUEST['exp'] ?? 0);
$sig = trim((string)($_REQUEST['sig'] ?? ''));
$invitedEmail = strtolower(trim((string)($_REQUEST['em'] ?? '')));

if (!OwnerAuth::verifyClaimInviteSignature($bizId, $exp, $sig, $invitedEmail)) {
    OwnerAuth::clearVerifiedClaimSession();
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$pdo = Database::getConnection();
if (OwnerAuth::isClaimInviteConsumed($pdo, $bizId, $exp, $sig, $invitedEmail)) {
    OwnerAuth::clearVerifiedClaimSession();
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$bStmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id LIMIT 1");
$bStmt->execute([':id' => $bizId]);
$biz = $bStmt->fetch();
if (!$biz) {
    OwnerAuth::clearVerifiedClaimSession();
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$errorMsg = '';
$successMsg = '';
$devPreviewOtpCode = null;
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

$currentOwner = OwnerAuth::getCurrentOwner($pdo);
if ($currentOwner !== null) {
    $currentEmail = strtolower(trim((string)($currentOwner['email'] ?? '')));
    if (OwnerAuth::isEmailEligibleForClaim($pdo, $bizId, $currentEmail, $invitedEmail)) {
        $pdo->beginTransaction();
        try {
            $freshCurrentOwner = OwnerAuth::getOwnerById($pdo, (int)$currentOwner['id']) ?? $currentOwner;
            if (OwnerAuth::redeemVerifiedClaimInvite($pdo, (int)$freshCurrentOwner['id'], $currentEmail, $bizId, $exp, $sig, $invitedEmail)) {
                OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$freshCurrentOwner['id'], $currentEmail);
                $pdo->commit();
                header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
                exit;
            }
            $pdo->rollBack();
            header('Location: /owner/login.php?err=invalid_link');
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[claim.php] Authenticated claim redemption failed: ' . $e->getMessage());
            $errorMsg = 'Could not complete claim linking right now. Please try again.';
        }
    } else {
        $errorMsg = "You are currently signed in as {$currentEmail}, which does not match the designated owner email for this invitation. Please sign out first or verify the invited owner email below.";
    }
}

$viewMode = 'identify'; // 'identify' | 'verify_otp'
$stickyEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $claimStep = trim((string)($_POST['claim_step'] ?? 'send_otp'));
    $email = strtolower(trim((string)($_POST['owner_email'] ?? '')));
    $stickyEmail = $email;
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $errorMsg = 'Invalid security token. Please reload the page and try again.';
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid email address for your Owner Account.';
    } elseif (!OwnerAuth::isEmailEligibleForClaim($pdo, $bizId, $email, $invitedEmail)) {
        $errorMsg = 'This claim invitation is restricted to the designated owner email on file for this business.';
    } elseif ($claimStep === 'password_claim') {
        $currentPw = (string)($_POST['current_password'] ?? '');
        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'claim_pw_auth', 5, 15, 40);
        if (!$rate['allowed']) {
            $errorMsg = 'Too many sign-in attempts. Please wait 15 minutes or request a 6-digit verification code.';
        } elseif ($currentPw === '') {
            $errorMsg = 'Please enter your current Owner Password, or request a 6-digit email verification code.';
        } else {
            $pdo->beginTransaction();
            try {
                $authOwner = OwnerAuth::authenticateWithPassword($pdo, $email, $currentPw);
                if ($authOwner === null) {
                    $pdo->rollBack();
                    $errorMsg = 'Incorrect password for that Owner Account. Enter your current password or request a 6-digit email verification code.';
                } else {
                    $freshOwner = OwnerAuth::getOwnerById($pdo, (int)$authOwner['id']) ?? $authOwner;
                    if (!OwnerAuth::redeemVerifiedClaimInvite($pdo, (int)$freshOwner['id'], $email, $bizId, $exp, $sig, $invitedEmail)) {
                        $pdo->rollBack();
                        header('Location: /owner/login.php?err=invalid_link');
                        exit;
                    }
                    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$freshOwner['id'], $email);
                    $pdo->commit();
                    OwnerAuth::establishOwnerSession($freshOwner, true, $pdo);
                    header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
                    exit;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[claim.php] Password claim failed: ' . $e->getMessage());
                $errorMsg = 'Could not complete claim sign-in right now. Please try again.';
            }
        }
    } elseif ($claimStep === 'verify_otp') {
        $viewMode = 'verify_otp';
        $otpCode = trim((string)($_POST['otp_code'] ?? ''));
        $newPw = (string)($_POST['new_password'] ?? '');
        $confirmPw = (string)($_POST['confirm_password'] ?? '');

        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'claim_otp_verify', 5, 15, 40);
        if (!$rate['allowed']) {
            $errorMsg = 'Too many code attempts. Please wait 15 minutes before trying again.';
        } elseif (mb_strlen($newPw) < 8) {
            $errorMsg = 'Please choose an Owner Password of at least 8 characters so you can sign in anytime on any browser.';
        } elseif ($confirmPw !== '' && !hash_equals($newPw, $confirmPw)) {
            $errorMsg = 'The two password fields do not match. Please re-enter them.';
        } else {
            $pdo->beginTransaction();
            try {
                $verifiedOwner = OwnerAuth::verifyOtpAndSetPassword($pdo, $email, $otpCode, $newPw);
                if ($verifiedOwner === null) {
                    $pdo->rollBack();
                    $errorMsg = 'Invalid or expired 6-digit verification code. Please check your inbox or request a fresh code.';
                } else {
                    if ($fullName !== '' || $phone !== '') {
                        $updatedName = $fullName !== '' ? $fullName : ($verifiedOwner['full_name'] ?? null);
                        $updatedPhone = $phone !== '' ? $phone : ($verifiedOwner['phone'] ?? null);
                        $pdo->prepare("UPDATE business_owners SET full_name = :nm, phone = :ph WHERE id = :id")
                            ->execute([
                                ':nm' => $updatedName,
                                ':ph' => $updatedPhone,
                                ':id' => (int)$verifiedOwner['id'],
                            ]);
                    }
                    $freshOwner = OwnerAuth::getOwnerById($pdo, (int)$verifiedOwner['id']) ?? $verifiedOwner;
                    if (!OwnerAuth::redeemVerifiedClaimInvite($pdo, (int)$freshOwner['id'], $email, $bizId, $exp, $sig, $invitedEmail)) {
                        $pdo->rollBack();
                        header('Location: /owner/login.php?err=invalid_link');
                        exit;
                    }
                    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$freshOwner['id'], $email);
                    $pdo->commit();
                    OwnerAuth::establishOwnerSession($freshOwner, true, $pdo);
                    header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
                    exit;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[claim.php] Claim verification failed: ' . $e->getMessage());
                $errorMsg = 'Could not complete claim verification. Please try again.';
            }
        }
    } else {
        // Default safe first step ('send_otp'): enforce full 7-layer AntiSpamGate before dispatching outbound OTP email (#162)
        $emailDomain = OwnerAuth::extractEmailDomain($email) ?? '';
        $gatePost = $_POST;
        if ($emailDomain !== '' && empty($gatePost['website_url'])) {
            $gatePost['website_url'] = 'https://' . $emailDomain;
        }
        $spamCheck = AntiSpamGate::verifySubmission($pdo, $gatePost, $clientIp, 'claim_otp_send');
        if (!$spamCheck['passed']) {
            if ($spamCheck['silent_drop']) {
                // Silent drop for bots / rate-limit trips (<=2/15m, <=5/24h, <=15/1h) to protect outbound SMTP reputation
                $viewMode = 'verify_otp';
                $successMsg = "If {$email} is eligible, a 6-digit verification code has been dispatched. Enter that code and set your Owner Password below.";
            } else {
                $reasonMap = [
                    'layer2_invalid_signature' => 'Form security signature could not be verified. Please reload and try again.',
                    'layer2_form_expired' => 'This verification form expired (older than 2 hours). Please reload and try again.',
                    'layer2_nonce_replayed' => 'This verification request was already submitted. Enter your 6-digit code or reload for a fresh form.',
                ];
                $errorMsg = $reasonMap[$spamCheck['reason']] ?? 'Verification request blocked by security check. Please reload and try again.';
            }
        } else {
            $mailQuota = AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, $clientIp, $email, true);
            if (!$mailQuota['allowed']) {
                $errorMsg = $mailQuota['reason'] === 'otp_recipient_cooldown_60s'
                    ? 'A verification code was just sent to that email address. Please check your inbox or wait 60 seconds before requesting another code.'
                    : 'Verification email dispatch is temporarily rate-limited. Please wait a few minutes and try again.';
            } else {
                $otpInfo = OwnerAuth::issuePasswordOtp($pdo, $email, null);
                $otpOutboxId = Mailer::sendOwnerPasswordOtp($pdo, $email, $otpInfo['code'], (string)$biz['name']);
            if (!Mailer::wasDeliveredOrSimulated($pdo, $otpOutboxId)) {
                $errorMsg = 'We could not deliver the 6-digit verification email right now due to a temporary mail server issue. Your email has been queued for automatic retry — please check your inbox shortly or try again in a few minutes.';
            } else {
                $viewMode = 'verify_otp';
                $successMsg = "We sent a 6-digit verification code to {$email}. Enter that code and set your Owner Password below to claim {$biz['name']}.";
                if (strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes' || strtolower(Database::env('APP_ENV', 'development')) !== 'production') {
                    $devPreviewOtpCode = $otpInfo['code'];
                }
            }
            }
        }
    }
}

OwnerAuth::storeVerifiedClaimInSession($bizId, $exp, $sig, $invitedEmail);
$tokens = AntiSpamGate::generateFormTokens();
$linkedForClaim = OwnerAuth::getOwnersForBusiness($pdo, $bizId);
$primaryLinkedEmail = !empty($linkedForClaim[0]['email']) ? (string)$linkedForClaim[0]['email'] : '';
$defaultEmail = $invitedEmail !== ''
    ? $invitedEmail
    : ($primaryLinkedEmail ?: ((string)($biz['owner_email'] ?: ($biz['email_public'] ?? ''))));
$suggestedEmail = $stickyEmail !== '' ? $stickyEmail : $defaultEmail;
$suggestedPhone = (string)($_POST['phone'] ?? ($biz['phone_primary'] ?? ''));
$suggestedName = (string)($_POST['full_name'] ?? '');
$existingSuggestedOwner = $suggestedEmail !== '' ? OwnerAuth::findOwnerByEmail($pdo, $suggestedEmail) : null;
$hasExistingPassword = ($existingSuggestedOwner !== null && !empty($existingSuggestedOwner['password_hash']));
$maskedHints = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $bizId);
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
          <p class="brand-subtitle">Verified Business Owner Onboarding</p>
        </div>
      </a>
      <nav class="header-actions">
        <?php if ($currentOwner !== null): ?>
          <a href="/owner/logout.php" class="btn btn-outline-light">Sign Out (<?= htmlspecialchars((string)$currentOwner['email'], ENT_QUOTES, 'UTF-8') ?>)</a>
        <?php endif; ?>
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

    <?php if ($successMsg !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($devPreviewOtpCode !== null): ?>
          <div style="margin-top:0.65rem;padding-top:0.65rem;border-top:1px dashed #059669;font-size:0.86rem;">
            🧪 Preview Verification Code: <strong style="font-size:1.15rem;letter-spacing:0.15em;"><?= htmlspecialchars($devPreviewOtpCode, ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <section style="background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.6rem;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
      <div style="font-size:0.76rem;font-weight:800;text-transform:uppercase;color:#114B3E;letter-spacing:0.04em;">✓ Single-Use Signed Invitation Link</div>
      <h2 style="margin:0.2rem 0 0.4rem 0;color:#7B1113;font-size:1.25rem;">
        Manage <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>
      </h2>
      <p style="margin:0 0 1.15rem 0;font-size:0.86rem;color:#4B5563;">
        Verify your owner email below to link <strong><?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></strong> to your persistent Owner Account.
      </p>

      <?php if (!empty($maskedHints)): ?>
        <div style="margin-bottom:1rem;padding:0.55rem 0.8rem;background:#F0FDF4;border:1px solid #A7F3D0;border-radius:8px;font-size:0.81rem;color:#065F46;">
          📧 <strong>Designated owner email on file:</strong> <?= htmlspecialchars(implode(' or ', $maskedHints), ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($viewMode === 'verify_otp'): ?>
        <form method="POST" action="/owner/claim.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="claim_step" value="verify_otp">
          <input type="hidden" name="biz_id" value="<?= $bizId ?>">
          <input type="hidden" name="exp" value="<?= $exp ?>">
          <input type="hidden" name="sig" value="<?= htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="em" value="<?= htmlspecialchars($invitedEmail, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="owner_email" value="<?= htmlspecialchars($suggestedEmail, ENT_QUOTES, 'UTF-8') ?>">

          <div style="margin-bottom:0.85rem;">
            <label for="claim_otp_code" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;">6-Digit Email Verification Code *</label>
            <input type="text" id="claim_otp_code" name="otp_code" class="input-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="123456" style="font-size:1.2rem;letter-spacing:0.25em;font-weight:800;text-align:center;" required autofocus>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-bottom:1.1rem;">
            <div>
              <label style="display:block;font-weight:700;font-size:0.82rem;margin-bottom:0.25rem;">Your Name (Optional)</label>
              <input type="text" name="full_name" class="input-control" value="<?= htmlspecialchars($suggestedName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Your Full Name">
            </div>
            <div>
              <label style="display:block;font-weight:700;font-size:0.82rem;margin-bottom:0.25rem;">Direct Phone (Optional)</label>
              <input type="text" name="phone" class="input-control" value="<?= htmlspecialchars($suggestedPhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+2547...">
            </div>
          </div>

          <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:0.9rem;margin-bottom:1.1rem;">
            <label for="claim_new_password" style="display:block;font-weight:800;font-size:0.84rem;color:#1E40AF;margin-bottom:0.25rem;">
              🔑 Set Your Owner Password (At least 8 characters) *
            </label>
            <p style="margin:0 0 0.6rem 0;font-size:0.78rem;color:#1E3A8A;">
              Once set, you can sign in anytime on Safari, Chrome, or your laptop with your email and password to see the <strong>Edit My Business</strong> button directly on your card.
            </p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.7rem;">
              <input type="password" id="claim_new_password" name="new_password" class="input-control" minlength="8" autocomplete="new-password" placeholder="Choose password (8+ characters)" required>
              <input type="password" id="claim_confirm_password" name="confirm_password" class="input-control" minlength="8" autocomplete="new-password" placeholder="Confirm password" required>
            </div>
          </div>

          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.94rem;">
            ✓ Verify Code, Save Password &amp; Edit <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>
          </button>
        </form>
      <?php else: ?>
        <?php if ($googleReady): ?>
          <a href="/owner/login.php?action=google" class="btn btn-outline" style="width:100%;justify-content:center;padding:0.7rem 1rem;font-weight:700;font-size:0.9rem;border:2px solid #D1D5DB;display:flex;align-items:center;gap:0.55rem;box-sizing:border-box;margin-bottom:1rem;">
            <span>🌐</span> Claim &amp; Continue with Google
          </a>
        <?php endif; ?>

        <form method="POST" action="/owner/claim.php" id="claim-otp-request-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_ts" value="<?= (int)$tokens['form_ts'] ?>">
          <input type="hidden" name="form_nonce" value="<?= htmlspecialchars($tokens['form_nonce'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_sig" value="<?= htmlspecialchars($tokens['form_sig'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="js_interaction_token" id="claim_js_interaction_token" value="">
          <input type="hidden" name="claim_step" value="send_otp">
          <input type="hidden" name="biz_id" value="<?= $bizId ?>">
          <input type="hidden" name="exp" value="<?= $exp ?>">
          <input type="hidden" name="sig" value="<?= htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="em" value="<?= htmlspecialchars($invitedEmail, ENT_QUOTES, 'UTF-8') ?>">
          <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
            <label for="claim_portal_ref">Official Corporate Portal URL</label>
            <input type="text" id="claim_portal_ref" name="website" value="" tabindex="-1" autocomplete="nope">
          </div>

          <div style="margin-bottom:0.9rem;">
            <label for="claim_owner_email" style="display:block;font-weight:700;font-size:0.84rem;margin-bottom:0.3rem;">Your Owner Email Address *</label>
            <input type="email" id="claim_owner_email" name="owner_email" class="input-control" value="<?= htmlspecialchars($suggestedEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="you@yourbusiness.co.ke or yourname@gmail.com" <?= $invitedEmail !== '' ? 'readonly' : '' ?> required>
          </div>

          <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:0.9rem;margin-bottom:1rem;">
            <div style="font-weight:800;font-size:0.84rem;color:#1E40AF;margin-bottom:0.25rem;">
              🔑 Set Your Owner Password via 6-Digit Email Verification
            </div>
            <p style="margin:0;font-size:0.79rem;color:#1E3A8A;">
              To protect your business from impersonation, we will send a 6-digit verification code to your inbox so you can set your password and claim <strong><?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></strong> right in this browser tab.
            </p>
          </div>

          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;padding:0.75rem 1rem;font-size:0.94rem;">
            ✉️ Send 6-Digit Verification Code to Claim &amp; Set Password
          </button>
        </form>

        <?php if ($hasExistingPassword): ?>
          <div style="margin-top:1.25rem;padding-top:1.1rem;border-top:1px solid #E5E7EB;">
            <form method="POST" action="/owner/claim.php">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="claim_step" value="password_claim">
              <input type="hidden" name="biz_id" value="<?= $bizId ?>">
              <input type="hidden" name="exp" value="<?= $exp ?>">
              <input type="hidden" name="sig" value="<?= htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="em" value="<?= htmlspecialchars($invitedEmail, ENT_QUOTES, 'UTF-8') ?>">

              <div style="font-size:0.83rem;font-weight:800;color:#114B3E;margin-bottom:0.45rem;">Already have an Owner Password? Sign in directly to claim:</div>
              <input type="hidden" name="owner_email" value="<?= htmlspecialchars($suggestedEmail, ENT_QUOTES, 'UTF-8') ?>">
              <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                <input type="password" name="current_password" class="input-control" style="flex:1;min-width:190px;" autocomplete="current-password" placeholder="Enter your current password..." required>
                <button type="submit" class="btn btn-outline" style="font-weight:700;">Sign In &amp; Claim</button>
              </div>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
  <script>
    (function () {
      var tok = document.getElementById('claim_js_interaction_token');
      if (!tok) return;
      var arm = function () {
        if (!tok.value) {
          tok.value = 'human_verified_' + Date.now();
        }
      };
      ['focusin', 'keydown', 'pointerdown', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, arm, { once: true, passive: true });
      });
    })();
  </script>
</body>
</html>
