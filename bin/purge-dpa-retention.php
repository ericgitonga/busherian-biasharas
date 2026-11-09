#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\VettingPurger;

$pdo = Database::getConnection();
$summary = VettingPurger::enforceRetentionCeilings($pdo, 30);
echo json_encode(['status' => 'ok', 'retention_purge' => $summary], JSON_UNESCAPED_SLASHES) . PHP_EOL;
