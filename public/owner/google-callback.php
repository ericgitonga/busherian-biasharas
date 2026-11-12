<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();
$onboardingError = '';

$completeOwnerSignIn = static function (PDO $pdo, array $profile): never {
    $owner = OwnerAuth::upsertOwner($pdo, (string)$profile['email'], (string)($profile['name'] ?? ''), null, (string)($profile['sub'] ?? ''));
    OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$owner['id'], (string)$profile['email']);
    $pendingSessionClaim = OwnerAuth::getVerifiedClaimFromSession($pdo);
    OwnerAuth::establishOwnerSession($owner, true, $pdo);
    if ($pendingSessionClaim !== null) {
        $claimQs = http_build_query([
            'biz_id' => $pendingSessionClaim['biz_id'],
            'exp' => $pendingSessionClaim['exp'],
            'sig' => $pendingSessionClaim['sig'],
            'em' => $pendingSessionClaim['em'],
        ]);
        header("Location: /owner/claim.php?{$claimQs}");
    } else {
        header('Location: /owner/index.php');
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        header('Location: /owner/login.php?err=invalid_link');
        exit;
    }
    if (!empty($_POST['cancel'])) {
        unset($_SESSION['bbiz_pending_google_onboarding']);
        header('Location: /owner/login.php');
        exit;
    }
    $pending = $_SESSION['bbiz_pending_google_onboarding'] ?? null;
    if (!is_array($pending) || empty($pending['email']) || (int)($pending['expires_at'] ?? 0) < time()) {
        unset($_SESSION['bbiz_pending_google_onboarding']);
        header('Location: /owner/login.php?err=invalid_link');
        exit;
    }
    if (empty($_POST['age_attestation_18'])) {
        $onboardingError = 'You must confirm that you are 18 years or older and acknowledge the Privacy Notice before creating an Owner Account.';
    } else {
        unset($_SESSION['bbiz_pending_google_onboarding']);
        $completeOwnerSignIn($pdo, $pending);
    }
} else {
    $state = trim((string)($_GET['state'] ?? ''));
    $expectedState = trim((string)($_SESSION['bbiz_google_oauth_state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? ''));

    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || $code === '') {
        header('Location: /owner/login.php?err=invalid_link');
        exit;
    }
    unset($_SESSION['bbiz_google_oauth_state']);

    $profile = OwnerAuth::exchangeGoogleCodeForProfile($code);
    if ($profile === null) {
        header('Location: /owner/login.php?err=invalid_link');
        exit;
    }

    // Check whether an Owner Account already exists before persisting any personal data (#246 M-06)
    $existingOwner = OwnerAuth::findOwnerByEmail($pdo, (string)$profile['email']);
    if ($existingOwner === null && !empty($profile['sub'])) {
        $subStmt = $pdo->prepare("SELECT * FROM business_owners WHERE google_sub = :sub LIMIT 1");
        $subStmt->execute([':sub' => (string)$profile['sub']]);
        $existingOwner = $subStmt->fetch() ?: null;
    }

    if ($existingOwner !== null) {
        $completeOwnerSignIn($pdo, $profile);
    }

    // First-time Google account onboarding: hold in ephemeral session until 18+ & Privacy Notice attestation is confirmed
    $_SESSION['bbiz_pending_google_onboarding'] = [
        'email' => (string)$profile['email'],
        'name' => (string)($profile['name'] ?? ''),
        'sub' => (string)($profile['sub'] ?? ''),
        'expires_at' => time() + 600,
    ];
}

$pendingOnboarding = $_SESSION['bbiz_pending_google_onboarding'] ?? [];
$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirm Adult Account Creation — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Owner Account Adult-Only (18+) Confirmation</p>
        </div>
      </a>
    </div>
  </header>

  <main style="max-width:560px;margin:2.5rem auto;padding:0 1.25rem;">
    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.75rem;box-shadow:0 2px 10px rgba(0,0,0,0.04);">
      <h2 style="margin-top:0;color:#7B1113;">Complete Your Owner Account Setup</h2>
      <p style="font-size:0.9rem;color:#374151;line-height:1.55;">
        Google verified your email address as
        <strong><?= htmlspecialchars((string)($pendingOnboarding['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>.
        Before we create your Owner Account record, please confirm our adult-only scope under the Kenya Data Protection Act, 2019.
      </p>

      <?php if ($onboardingError !== ''): ?>
        <div style="background:#FEF2F2;border:1px solid #FCA5A5;color:#991B1B;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.86rem;">
          <?= htmlspecialchars($onboardingError, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="/owner/google-callback.php" data-testid="google-onboarding-18-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <label style="display:flex;align-items:flex-start;gap:0.6rem;background:#F9FAFB;border:1px solid #D1D5DB;border-radius:10px;padding:0.9rem;font-size:0.86rem;line-height:1.5;cursor:pointer;">
          <input type="checkbox" name="age_attestation_18" value="1" data-testid="google-onboarding-18-checkbox" required style="margin-top:0.2rem;">
          <span>
            I confirm that I am an adult aged <strong>18 years or older</strong>, that any business contact details or photographs I manage involve only adults (18+), and that I have read the
            <a href="/privacy.php" target="_blank" rel="noopener" style="color:#7B1113;font-weight:700;">Privacy Notice (<?= htmlspecialchars(VettingPurger::PRIVACY_NOTICE_VERSION, ENT_QUOTES, 'UTF-8') ?>)</a>.
          </span>
        </label>

        <div style="margin-top:1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
          <button type="submit" class="btn btn-green" data-testid="google-onboarding-confirm-btn" style="padding:0.65rem 1.25rem;">Confirm 18+ &amp; Create Account</button>
          <button type="submit" name="cancel" value="1" formnovalidate class="btn btn-outline" data-testid="google-onboarding-cancel-btn">Cancel &amp; Discard</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
