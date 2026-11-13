<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\EngagementRanker;

// Anonymous JSON endpoint: emits zero Set-Cookie headers and stores zero visitor profiles (Kenya DPA 2019).
bbiz_send_security_headers(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
$payload = is_array($decoded) ? $decoded : $_POST;

$businessId = (int)($payload['business_id'] ?? 0);
$signalType = strtolower(trim((string)($payload['signal_type'] ?? $payload['event_type'] ?? '')));
$interactionToken = trim((string)($payload['interaction_token'] ?? $payload['js_interaction_token'] ?? ''));

if ($businessId <= 0 || EngagementRanker::resolveSignal($signalType) === null) {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid business_id or signal_type.',
    ]);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $viewer = EngagementRanker::resolveViewerWithoutStartingAnonymousSession($pdo);
    $result = EngagementRanker::recordCheckout(
        $pdo,
        $businessId,
        $signalType,
        $clientIp,
        $userAgent,
        $interactionToken,
        $viewer['owner_id'],
        $viewer['is_admin']
    );

    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('[api/record-checkout.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to record engagement signal right now.']);
}
