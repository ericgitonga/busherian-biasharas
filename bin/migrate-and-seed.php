#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Busherian Biasharas — Explicit CLI Schema Migration, Taxonomy Sync & Coordinate Backfill (#183)
 *
 * Usage:
 *   php bin/migrate-and-seed.php [--backfill-external-pins]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI execution only.');
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

$withExternalPins = in_array('--backfill-external-pins', $argv ?? [], true);

try {
    $pdo = Database::getConnection();
    $summary = Database::migrateAndSeed($pdo, $withExternalPins);
    echo json_encode([
        'status' => 'ok',
        'ran_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'external_pin_backfill' => $withExternalPins,
        'summary' => $summary,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[migrate-and-seed] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
