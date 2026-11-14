<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\EngagementRanker;

bbiz_send_security_headers(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Strict Kenya DPA 2019 s.32 Opt-In Gate: ignore analytics clicks unless user explicitly clicked 'Accept'
if (($_COOKIE['bbiz_consent'] ?? '') !== 'accepted') {
    echo json_encode(['status' => 'ignored_no_consent']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: $_POST;

$businessId = (int)($data['business_id'] ?? 0);
$eventType  = strtolower(trim((string)($data['event_type'] ?? '')));
$allowedEvents = ['website', 'whatsapp', 'call', 'email', 'map_pin', 'shortlist', 'booking'];

if ($businessId <= 0 || !in_array($eventType, $allowedEvents, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $interactionToken = trim((string)($data['interaction_token'] ?? $data['js_interaction_token'] ?? ''));

    // Check if business exists and is approved (Adversarial H-01)
    $bizStmt = $pdo->prepare("SELECT id, status FROM businesses WHERE id = :id LIMIT 1");
    $bizStmt->execute([':id' => $businessId]);
    $biz = $bizStmt->fetch();
    if (!$biz || (string)($biz['status'] ?? '') !== 'approved') {
        echo json_encode(['status' => 'ignored', 'reason' => 'business_not_approved']);
        exit;
    }

    $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'track_click', 30, 120, 500);
    if (!$rate['allowed']) {
        echo json_encode(['status' => 'ok', 'rate_limited' => true]);
        exit;
    }

    $resolvedSig = EngagementRanker::resolveSignal($eventType);
    if ($resolvedSig !== null) {
        // Route through EngagementRanker with 24h HMAC deduplication, bot filtering, and owner/admin exclusion (H-01)
        $viewer = EngagementRanker::resolveViewerWithoutStartingAnonymousSession($pdo);
        $result = EngagementRanker::recordCheckout(
            $pdo,
            $businessId,
            $eventType,
            $clientIp,
            $userAgent,
            $interactionToken,
            $viewer['owner_id'],
            $viewer['is_admin']
        );
        echo json_encode($result);
        exit;
    }

    // For unweighted UI events (e.g. 'shortlist'), record with zero weight
    $stmt = $pdo->prepare("INSERT INTO click_events (business_id, event_type, signal_group, signal_weight) VALUES (:bid, :ev, 'other', 0.00)");
    $stmt->execute([':bid' => $businessId, ':ev' => $eventType]);
    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
