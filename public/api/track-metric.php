<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;

bbiz_send_security_headers(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Strict Kenya DPA 2019 s.32 Opt-In Gate: NEVER record usage metrics unless 'bbiz_consent' cookie is 'accepted'
$consentCookie = trim((string)($_COOKIE['bbiz_consent'] ?? ''));
$visitorId     = trim((string)($_COOKIE['bbiz_vid'] ?? ''));
if ($consentCookie !== 'accepted' || $visitorId === '' || strlen($visitorId) < 8) {
    echo json_encode(['status' => 'ignored_no_consent']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: $_POST;

$eventType = strtolower(trim((string)($data['event_type'] ?? '')));
$allowedTypes = ['page_view', 'search', 'filter', 'modal_open', 'route_request'];
if (!in_array($eventType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid metric event_type']);
    exit;
}

$pagePath   = mb_substr(trim((string)($data['page_path'] ?? '')), 0, 160) ?: null;
$businessId = isset($data['business_id']) && (int)$data['business_id'] > 0 ? (int)$data['business_id'] : null;
$rawMeta    = trim((string)($data['meta_label'] ?? ''));
if ($rawMeta !== '') {
    // Minimize search/filter labels before storage (#217 M-09): strip emails, URLs, and phone/ID digit sequences
    $rawMeta = (string)preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $rawMeta);
    $rawMeta = (string)preg_replace('#https?://\S+#i', '[redacted-url]', $rawMeta);
    $rawMeta = (string)preg_replace('/\+?\d[\d\s\-()]{6,}\d/', '[redacted-number]', $rawMeta);
    $rawMeta = trim((string)preg_replace('/\s+/', ' ', $rawMeta));
}
$metaLabel  = $rawMeta !== '' ? mb_substr($rawMeta, 0, 80) : null;

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'track_metric', 90, 600, 3000);
    if (!$rate['allowed']) {
        echo json_encode(['status' => 'ok', 'rate_limited' => true]);
        exit;
    }

    $salt = Database::env('IP_HASH_SECRET', 'bbiz_default_metric_salt');
    $visitorHash = hash_hmac('sha256', $visitorId, $salt);

    $stmt = $pdo->prepare("
        INSERT INTO usage_metrics (event_type, visitor_hash, page_path, business_id, meta_label)
        VALUES (:etype, :vhash, :ppath, :bid, :meta)
    ");
    $stmt->execute([
        ':etype' => $eventType,
        ':vhash' => $visitorHash,
        ':ppath' => $pagePath,
        ':bid'   => $businessId,
        ':meta'  => $metaLabel,
    ]);

    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
