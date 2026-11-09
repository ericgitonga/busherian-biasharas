<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\LocationEngine;

bbiz_send_security_headers(true);

$fromLat = isset($_GET['from_lat']) ? (float)$_GET['from_lat'] : null;
$fromLng = isset($_GET['from_lng']) ? (float)$_GET['from_lng'] : null;
$toLat   = isset($_GET['to_lat']) ? (float)$_GET['to_lat'] : null;
$toLng   = isset($_GET['to_lng']) ? (float)$_GET['to_lng'] : null;

if (
    $fromLat === null || $fromLng === null || $toLat === null || $toLng === null ||
    $fromLat < -90.0 || $fromLat > 90.0 || $toLat < -90.0 || $toLat > 90.0 ||
    $fromLng < -180.0 || $fromLng > 180.0 || $toLng < -180.0 || $toLng > 180.0
) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid origin and destination coordinates are required.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'route_directions', 30, 150, 600);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many route requests. Please wait a moment.']);
        exit;
    }

    $allowExternal = (getenv('BBIZ_OFFLINE_ROUTING') !== '1');
    $route = LocationEngine::calculateRouteAndDirections($fromLat, $fromLng, $toLat, $toLng, $allowExternal);

    echo json_encode([
        'status' => 'ok',
        'route' => $route,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not compute route directions.']);
}
