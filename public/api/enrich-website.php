<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\WebsiteEnricher;

bbiz_send_security_headers(true);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
if (strlen($rawBody) > 8192) {
    http_response_code(413);
    echo json_encode(['error' => 'Payload too large.'], JSON_THROW_ON_ERROR);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$csrfToken = trim((string)($payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
$isTest = Database::env('APP_ENV') === 'testing' || (PHP_SAPI === 'cli' && empty($csrfToken));
$isAdmin = AdminAuth::isAuthenticated();
if (!$isAdmin && !$isTest && !AntiSpamGate::verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or expired security token (CSRF). Please refresh the page.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawUrl = trim((string)($payload['url'] ?? ($_POST['url'] ?? '')));
if ($rawUrl === '' || strlen($rawUrl) > 2048) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide a valid website URL.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rate = $isAdmin ? ['allowed' => true] : AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'autofill_url', 10, 30, 60);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded for website enrichment. Please wait a few minutes.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $extracted = WebsiteEnricher::fetchAndExtract($rawUrl, $pdo);
    echo json_encode([
        'status' => 'ok',
        'data' => $extracted,
    ], JSON_THROW_ON_ERROR);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    error_log('[enrich-website] Error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'error' => 'Could not extract website details: ' . $e->getMessage()
    ], JSON_THROW_ON_ERROR);
}
