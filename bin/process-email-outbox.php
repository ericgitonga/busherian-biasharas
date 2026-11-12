#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\Mailer;

$maxBatch = isset($argv[1]) ? max(1, (int)$argv[1]) : 25;
$maxAttempts = isset($argv[2]) ? max(1, (int)$argv[2]) : 5;

$pdo = Database::getConnection();
$result = Mailer::processRetryQueue($pdo, $maxBatch, $maxAttempts);
echo json_encode(['status' => 'ok', 'outbox_retry' => $result], JSON_UNESCAPED_SLASHES) . PHP_EOL;
