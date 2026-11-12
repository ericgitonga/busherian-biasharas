<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();
    $readiness = Database::getSchemaReadiness($pdo);
    $catCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $bizCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();
    $version = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: '0.1.0';

    if (!$readiness['schema_ready']) {
        http_response_code(503);
    }

    echo json_encode([
        'status' => $readiness['schema_ready'] ? 'ok' : 'degraded_schema',
        'version' => $version,
        'driver' => Database::getActiveDriver(),
        'schema_version' => $readiness['schema_version'],
        'schema_ready' => $readiness['schema_ready'],
        'categories_count' => $catCount,
        'approved_businesses_count' => $bizCount,
        'timezone' => date_default_timezone_get(),
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Health check failed.']);
}
