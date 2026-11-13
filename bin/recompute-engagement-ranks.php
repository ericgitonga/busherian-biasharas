#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\EngagementRanker;

try {
    $workerLock = bbiz_acquire_cli_worker_lock('recompute_engagement_ranks');
    if ($workerLock === null) {
        echo json_encode([
            'status' => 'skipped_locked',
            'script' => 'recompute-engagement-ranks.php',
            'reason' => 'Another engagement rank recomputation worker currently holds flock.',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $pdo = Database::getConnection();
    $nowTs = time();
    $dedupPurged = EngagementRanker::purgeExpiredDeduplicationHashes($pdo, $nowTs);
    $summary = EngagementRanker::recomputeAllApprovedScores($pdo, $nowTs);

    echo json_encode([
        'status' => 'ok',
        'dedup_expired_purged' => $dedupPurged,
        'recompute' => $summary,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'error',
        'script' => 'recompute-engagement-ranks.php',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
