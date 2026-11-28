<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;

bbiz_send_security_headers(true);
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $tokRate = AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'csrf_token_refresh', '', 60, 400, 60, 400);
    if (!$tokRate['allowed']) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['status' => 'error', 'message' => 'Too many token refresh requests. Please wait a moment.']);
        exit;
    }

    OwnerAuth::startSecureSession();
    $tokens = AntiSpamGate::generateFormTokens();

    echo json_encode([
        'status' => 'ok',
        'csrf_token' => $tokens['csrf_token'],
        'form_ts' => $tokens['form_ts'],
        'form_nonce' => $tokens['form_nonce'],
        'form_sig' => $tokens['form_sig'],
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to refresh security tokens.']);
}
