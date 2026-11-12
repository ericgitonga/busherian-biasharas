<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$token = trim((string)($_GET['token'] ?? ''));
$pdo = Database::getConnection();

$result = OwnerAuth::verifyAndConsumeMagicLink($pdo, $token);
if ($result === null) {
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$owner = $result['owner'];
OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$owner['id'], (string)($owner['email'] ?? ''));
$claimedBizId = (int)($result['claimed_business_id'] ?? 0);
if ($claimedBizId <= 0) {
    $sessionClaimId = OwnerAuth::redeemVerifiedClaimFromSession($pdo, (int)$owner['id'], (string)($owner['email'] ?? ''));
    if ($sessionClaimId !== null && $sessionClaimId > 0) {
        $claimedBizId = $sessionClaimId;
    }
}

OwnerAuth::establishOwnerSession($owner, true, $pdo);
$targetEditBizId = (int)($_SESSION['bbiz_target_edit_biz_id'] ?? 0);
unset($_SESSION['bbiz_target_edit_biz_id']);

if ($claimedBizId > 0) {
    header("Location: /owner/edit-business.php?id={$claimedBizId}&claimed=1");
} elseif ($targetEditBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$owner['id'], $targetEditBizId)) {
    header("Location: /owner/edit-business.php?id={$targetEditBizId}");
} else {
    header('Location: /owner/index.php');
}
exit;
