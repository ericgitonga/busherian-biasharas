<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(true);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode([
        'status' => 'error',
        'message' => 'Method Not Allowed. Use GET or POST.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Extract bearer token or query/header token
$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$providedToken = '';
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $providedToken = $matches[1];
} elseif (isset($_SERVER['HTTP_X_CRON_KEY'])) {
    $providedToken = trim((string)$_SERVER['HTTP_X_CRON_KEY']);
} elseif (isset($_GET['token'])) {
    $providedToken = trim((string)$_GET['token']);
} elseif (isset($_GET['key'])) {
    $providedToken = trim((string)$_GET['key']);
}

$expectedSecret = trim((string)Database::env('CRON_SECRET', Database::env('APP_HMAC_SECRET', '')));

if ($providedToken === '' || $expectedSecret === '' || !hash_equals($expectedSecret, $providedToken)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized: Invalid or missing cron authentication token.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. Non-blocking concurrency lock
$workerLock = bbiz_acquire_cli_worker_lock('scheduled_cron_worker');
if ($workerLock === null) {
    http_response_code(200);
    echo json_encode([
        'status' => 'skipped_locked',
        'message' => 'Another cron worker instance currently holds the execution lock.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Task 1: DPA 30-day retention storage-limitation sweep & heartbeat
    $retentionSummary = VettingPurger::enforceRetentionCeilings($pdo, 30);
    $heartbeatUtc = VettingPurger::recordCronHeartbeat($pdo, $retentionSummary);

    // Task 2: Process bounded email outbox retry queue
    $maxBatch = isset($_GET['outbox_batch']) ? max(1, min(100, (int)$_GET['outbox_batch'])) : 25;
    $maxAttempts = isset($_GET['outbox_attempts']) ? max(1, min(10, (int)$_GET['outbox_attempts'])) : 5;
    $outboxSummary = Mailer::processRetryQueue($pdo, $maxBatch, $maxAttempts);

    // Task 3: Drain durable DPA media erasure obligations
    $mediaSummary = VettingPurger::drainDurableMediaDeletions($pdo, null);

    // Task 4: Proactive SMTP Relay Health & Connectivity Check (#460)
    $smtpHealth = Mailer::checkSmtpHealth($pdo);

    $isDegraded = ($outboxSummary['failed'] > 0) || ($smtpHealth['healthy'] === false);

    http_response_code(200);
    echo json_encode([
        'status' => $isDegraded ? 'warning_degraded' : 'ok',
        'timestamp_utc' => gmdate('Y-m-d H:i:s'),
        'heartbeat_utc' => $heartbeatUtc,
        'retention_purge' => $retentionSummary,
        'outbox_retry' => $outboxSummary,
        'media_deletions' => $mediaSummary,
        'smtp_health' => $smtpHealth,
    ], JSON_UNESCAPED_SLASHES);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    $isProd = strtolower(Database::env('APP_ENV', 'production')) === 'production';
    echo json_encode([
        'status' => 'error',
        'message' => $isProd ? 'Internal Cron Error.' : ('Cron Error: ' . $e->getMessage()),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
