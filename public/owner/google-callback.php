<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

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

$pdo = Database::getConnection();
$owner = OwnerAuth::upsertOwner($pdo, $profile['email'], $profile['name'], null, $profile['sub']);

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
