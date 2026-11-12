<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $catBudget = AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'api_categories_read', '', 150, 2000, 150, 2000);
    if (!$catBudget['allowed']) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['status' => 'error', 'message' => 'Too many category requests. Please wait a moment and try again.']);
        exit;
    }

    // Optional live fuzzy duplicate check for custom category input (capped to 80 chars, #218 M-10)
    $checkCustom = mb_substr(trim((string)($_GET['check_custom'] ?? '')), 0, 80);
    if ($checkCustom !== '') {
        $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;
        $matches = CategoryMatcher::findFuzzyMatches($pdo, $checkCustom, $parentId, 0.62);
        echo json_encode([
            'status' => 'ok',
            'custom_input' => $checkCustom,
            'fuzzy_matches' => $matches,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $tree = CategoryMatcher::getTaxonomyTree($pdo, false);
    echo json_encode([
        'status' => 'ok',
        'sectors' => $tree,
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to load categories.']);
}
