#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\VettingPurger;

try {
    $pdo = Database::getConnection();
    $summary = VettingPurger::enforceRetentionCeilings($pdo, 30);
    $heartbeatUtc = VettingPurger::recordCronHeartbeat($pdo, $summary);
    echo json_encode([
        'status' => 'ok',
        'heartbeat_utc' => $heartbeatUtc,
        'retention_purge' => $summary,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'script' => 'purge-dpa-retention.php',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
