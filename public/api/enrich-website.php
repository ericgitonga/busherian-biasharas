<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\WebsiteEnricher;

bbiz_send_security_headers(true);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawUrl = trim((string)($_POST['url'] ?? ''));
if ($rawUrl === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide a valid website URL.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $pdo = Database::getConnection();
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
