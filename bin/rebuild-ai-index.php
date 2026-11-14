<?php
declare(strict_types=1);

/**
 * CLI Utility: Rebuild & Audit Biashara Concierge AI Capability Index (#330-#332)
 *
 * Usage:
 *   php bin/rebuild-ai-index.php [--audit-only]
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AiListingIndexer;

$auditOnly = in_array('--audit-only', $argv ?? [], true);

echo "======================================================\n";
echo "Biashara Concierge: AI Capability Index " . ($auditOnly ? "Audit" : "Rebuild") . "\n";
echo "======================================================\n";

try {
    $pdo = Database::getConnection();
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    echo "Database: {$driver}\n\n";

    if ($auditOnly) {
        $stats = AiListingIndexer::getIndexStats($pdo);
        echo "Approved Businesses : {$stats['approved_count']}\n";
        echo "Indexed Capabilities: {$stats['indexed_count']}\n";
        echo "Coverage            : {$stats['coverage_pct']}%\n";
        exit(0);
    }

    echo "Rebuilding capability index for all approved businesses...\n";
    $start = microtime(true);
    $res = AiListingIndexer::rebuildAllApproved($pdo);
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    $stats = AiListingIndexer::getIndexStats($pdo);

    echo "\nResults:\n";
    echo "  Total Approved : {$res['total_approved']}\n";
    echo "  Newly Indexed  : {$res['indexed']}\n";
    echo "  Updated        : {$res['updated']}\n";
    echo "  Unchanged      : {$res['unchanged']}\n";
    echo "  Orphans Purged : {$res['purged']}\n";
    echo "  Final Coverage : {$stats['coverage_pct']}% ({$stats['indexed_count']}/{$stats['approved_count']})\n";
    echo "  Execution Time : {$elapsed} ms\n\n";
    echo "Done.\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    exit(1);
}
