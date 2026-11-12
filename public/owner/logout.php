<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['scope'] ?? '') === 'all') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $currentOwner = OwnerAuth::getCurrentOwner($pdo);
    if ($currentOwner !== null && AntiSpamGate::verifyCsrfToken($csrf)) {
        OwnerAuth::revokeAllOwnerSessions($pdo, (int)$currentOwner['id'], true);
        OwnerAuth::logoutOwner($pdo);
        header('Location: /owner/login.php?logged_out_all=1');
        exit;
    }
}

OwnerAuth::logoutOwner($pdo);
header('Location: /owner/login.php?logged_out=1');
exit;
