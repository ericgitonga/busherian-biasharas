<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

bbiz_send_security_headers(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: $_POST;

$businessId = (int)($data['business_id'] ?? 0);
$eventType  = strtolower(trim((string)($data['event_type'] ?? '')));
$allowedEvents = ['website', 'whatsapp', 'call', 'email', 'map_pin', 'shortlist'];

if ($businessId <= 0 || !in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("INSERT INTO click_events (business_id, event_type) VALUES (:bid, :ev)");
    $stmt->execute([':bid' => $businessId, ':ev' => $eventType]);
    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
