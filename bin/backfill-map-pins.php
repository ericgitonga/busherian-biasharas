#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\VettingPurger;

try {
    $pdo = Database::getConnection();
    $allowExternal = (getenv('BBIZ_OFFLINE_ROUTING') !== '1') && !in_array('--offline', $argv ?? [], true);
    $summary = VettingPurger::backfillMissingCoordinatesFromPins($pdo, $allowExternal);
    if (($summary['network_errors'] ?? 0) === 0 && empty($summary['item_errors'])) {
        Database::setSetting($pdo, 'coords_pin_backfill_v1', 'done');
    }
    echo json_encode(['status' => 'ok', 'backfill' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'message' => 'Failed to run map-pin coordinate backfill: ' . $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
