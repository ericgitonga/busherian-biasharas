<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

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
$redeemedClaimId = OwnerAuth::redeemVerifiedClaimFromSession($pdo, (int)$owner['id'], (string)$profile['email']);

OwnerAuth::establishOwnerSession($owner);
if ($redeemedClaimId !== null && $redeemedClaimId > 0) {
    header("Location: /owner/edit-business.php?id={$redeemedClaimId}&claimed=1");
} else {
    header('Location: /owner/index.php');
}
exit;
