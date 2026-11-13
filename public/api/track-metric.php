<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Strict Kenya DPA 2019 s.32 Opt-In Gate: require 'bbiz_consent=accepted' AND active server-side provenance (#245 M-05)
$consentCookie = trim((string)($_COOKIE['bbiz_consent'] ?? ''));
$visitorId     = trim((string)($_COOKIE['bbiz_vid'] ?? ''));
if ($consentCookie !== 'accepted' || $visitorId === '' || !preg_match('/^[a-zA-Z0-9_\-]{12,64}$/', $visitorId)) {
    echo json_encode(['status' => 'ignored_no_consent']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: $_POST;

$eventType = strtolower(trim((string)($data['event_type'] ?? '')));
$allowedTypes = ['page_view', 'search', 'filter', 'modal_open', 'route_request', 'booking'];
if (!in_array($eventType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid metric event_type']);
    exit;
}

// Server-side page_path sanitization & route allowlisting (#244 M-04): strip query/fragment & PII
$rawPageInput = trim((string)($data['page_path'] ?? ''));
$pagePath = null;
if ($rawPageInput !== '') {
    $parsedPath = (string)(parse_url($rawPageInput, PHP_URL_PATH) ?? '');
    $parsedPath = rawurldecode($parsedPath);
    $parsedPath = (string)preg_replace('#/+#', '/', '/' . ltrim($parsedPath, '/'));
    if ($parsedPath !== '/' && str_ends_with($parsedPath, '/')) {
        $parsedPath = rtrim($parsedPath, '/');
    }
    if (str_starts_with($parsedPath, '/admin/preprod/')) {
        $parsedPath = substr($parsedPath, strlen('/admin/preprod')) ?: '/';
    } elseif ($parsedPath === '/admin/preprod') {
        $parsedPath = '/';
    }
    $allowedRoutes = [
        '/',
        '/index.php',
        '/submit.php',
        '/privacy.php',
        '/takedown.php',
        '/owner/login.php',
        '/owner/index.php',
        '/owner/edit-business.php',
        '/owner/claim.php',
        '/owner/google-callback.php',
        '/admin/index.php',
        '/admin/login.php',
        '/admin/add-business.php',
        '/admin/edit-business.php',
        '/admin/metrics.php',
        '/admin/sandbox.php',
    ];
    $pagePath = in_array($parsedPath, $allowedRoutes, true) ? $parsedPath : '/[other]';
}

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

    // Verify durable server-side consent provenance under current notice version & unexpired window (#245 M-05)
    $nowUtc = gmdate('Y-m-d H:i:s');
    $consentStmt = $pdo->prepare("
        SELECT 1
        FROM analytics_consents
        WHERE visitor_hash = :vh
          AND consent_status = 'accepted'
          AND policy_version = :pv
          AND expires_at >= :now_utc
        LIMIT 1
    ");
    $consentStmt->execute([
        ':vh' => $visitorHash,
        ':pv' => VettingPurger::PRIVACY_NOTICE_VERSION,
        ':now_utc' => $nowUtc,
    ]);
    if (!$consentStmt->fetchColumn()) {
        echo json_encode(['status' => 'ignored_no_consent']);
        exit;
    }

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
