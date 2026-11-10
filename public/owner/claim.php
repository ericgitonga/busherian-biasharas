<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

$bizId = (int)($_GET['biz_id'] ?? 0);
$exp = (int)($_GET['exp'] ?? 0);
$sig = trim((string)($_GET['sig'] ?? ''));

if (!OwnerAuth::verifyClaimInviteSignature($bizId, $exp, $sig)) {
    header('Location: /owner/login.php?err=invalid_link');
    exit;
}

$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);

if ($currentOwner !== null) {
    OwnerAuth::linkOwnerToBusiness($pdo, (int)$currentOwner['id'], $bizId, 'claim_invite_link');
    header("Location: /owner/edit-business.php?id={$bizId}&claimed=1");
    exit;
}

// Store verified claim intent in session and prompt owner to sign in via Google SSO or Email Magic Link
$_SESSION['bbiz_pending_claim_biz_id'] = $bizId;
header("Location: /owner/login.php?claim={$bizId}");
exit;
