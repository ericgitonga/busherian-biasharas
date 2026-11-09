<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();
    $catCount = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $bizCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();
    $version = trim((string)@file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: '0.1.0';

    echo json_encode([
        'status' => 'ok',
        'version' => $version,
        'driver' => Database::getActiveDriver(),
        'categories_count' => $catCount,
        'approved_businesses_count' => $bizCount,
        'timezone' => date_default_timezone_get(),
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Health check failed.']);
}
