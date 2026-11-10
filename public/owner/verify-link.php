<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$token = trim((string)($_GET['token'] ?? ''));
$pdo = Database::getConnection();

$result = OwnerAuth::verifyAndConsumeMagicLink($pdo, $token);
if ($result === null) {
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$owner = $result['owner'];
$claimedBizId = (int)($result['claimed_business_id'] ?? ($_SESSION['bbiz_pending_claim_biz_id'] ?? 0));
if ($claimedBizId > 0) {
    OwnerAuth::linkOwnerToBusiness($pdo, (int)$owner['id'], $claimedBizId, 'claim_invite_link');
    unset($_SESSION['bbiz_pending_claim_biz_id']);
}

OwnerAuth::establishOwnerSession($owner);
if ($claimedBizId > 0) {
    header("Location: /owner/edit-business.php?id={$claimedBizId}&claimed=1");
} else {
    header('Location: /owner/index.php');
}
exit;
