<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\LocationEngine;

bbiz_send_security_headers(true);

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Please enter a street, neighborhood, or landmark name (e.g. Marist Lane, Karen).']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'geocode_origin', 30, 150, 600);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many location lookups. Please wait a moment.']);
        exit;
    }

    $allowExternal = (getenv('BBIZ_OFFLINE_ROUTING') !== '1');
    $loc = LocationEngine::geocodeKenyanLocation($q, $allowExternal);
    if ($loc === null) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Could not find that Kenyan street or area. Try adding the neighborhood or town (e.g. "Marist Lane, Karen").']);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'location' => $loc,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not look up location right now.']);
}
