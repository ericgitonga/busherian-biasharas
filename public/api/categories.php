<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\CategoryMatcher;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();

    // Optional live Jaro-Winkler fuzzy duplicate check for custom category input
    $checkCustom = trim((string)($_GET['check_custom'] ?? ''));
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
