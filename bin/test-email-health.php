#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\Mailer;

/**
 * CLI Email Health & Outbox Failure Monitor (#460).
 * Run manually or via cPanel Cron to alert immediately on SMTP connection or outbox failures.
 */
try {
    $pdo = Database::getConnection();
    $health = Mailer::checkSmtpHealth($pdo);

    // Also check for any failed outbox rows awaiting delivery
    $failedCount = (int)$pdo->query("
        SELECT COUNT(*) FROM email_outbox
        WHERE status = 'failed'
          AND body_html NOT LIKE '[REDACTED%'
          AND body_html NOT LIKE '[EXPIRED%'
    ")->fetchColumn();

    $report = [
        'status' => ($health['healthy'] && $failedCount === 0) ? 'ok' : 'alert',
        'smtp_health' => $health,
        'failing_outbox_emails' => $failedCount,
        'timestamp_utc' => gmdate('Y-m-d H:i:s'),
    ];

    if (!$health['healthy'] || $failedCount > 0) {
        fwrite(STDERR, "CRITICAL EMAIL ALERT: " . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }

    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "CRITICAL EMAIL HEALTH CHECK EXCEPTION: " . $e->getMessage() . PHP_EOL);
    exit(2);
}
