<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

bbiz_send_security_headers(true);
header('Content-Type: application/json; charset=UTF-8');

// Security key
$token = (string)($_GET['key'] ?? '');
if ($token !== 'bbiz_rescue_photos_9876') {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden']);
    exit;
}

$restore = (bool)($_GET['restore'] ?? false);

// Target active web uploads dir
$appRoot = dirname(__DIR__, 2);
$targetDir = is_dir($appRoot . '/html') ? ($appRoot . '/html/uploads/businesses') : ($appRoot . '/public/uploads/businesses');
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0775, true);
}

// Search locations
$searchRoots = [
    $appRoot,
    dirname($appRoot),
    dirname($appRoot, 2),
    '/home/egimba',
    '/tmp',
];

$foundFiles = [];
$searchedPaths = [];

$scanDir = function(string $dir, int $depth = 0) use (&$scanDir, &$foundFiles, &$searchedPaths) {
    if ($depth > 5 || !is_dir($dir) || !is_readable($dir)) {
        return;
    }
    $searchedPaths[] = $dir;
    $items = @scandir($dir);
    if (!is_array($items)) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git') continue;
        $path = $dir . '/' . $item;
        if (is_file($path)) {
            if (str_starts_with($item, 'bbiz_202611') && (str_ends_with($item, '.webp') || str_ends_with($item, '.jpg') || str_ends_with($item, '.png'))) {
                $foundFiles[$item][] = [
                    'path' => $path,
                    'size' => filesize($path),
                    'mtime' => date('Y-m-d H:i:s', filemtime($path)),
                ];
            }
        } elseif (is_dir($path) && !is_link($path)) {
            // Avoid recursion into huge vendor/node_modules
            if ($item === 'node_modules' || $item === 'vendor' || $item === '.cache') continue;
            $scanDir($path, $depth + 1);
        }
    }
};

foreach (array_unique($searchRoots) as $root) {
    if (is_dir($root)) {
        $scanDir($root, 0);
    }
}

// Restore if requested
$restored = [];
if ($restore && !empty($foundFiles)) {
    foreach ($foundFiles as $filename => $locations) {
        $dest = $targetDir . '/' . $filename;
        if (!file_exists($dest)) {
            $source = $locations[0]['path'];
            if (@copy($source, $dest)) {
                @chmod($dest, 0644);
                $restored[] = $filename;
            }
        }
    }
}

// Check database records status
$pdo = Database::getConnection();
$stmt = $pdo->query("SELECT id, name, photos_json FROM businesses WHERE photos_json != '[]' AND photos_json IS NOT NULL");
$bizStatus = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $photos = json_decode((string)$row['photos_json'], true) ?: [];
    $details = [];
    foreach ($photos as $p) {
        $fn = basename($p);
        $existsInTarget = file_exists($targetDir . '/' . $fn);
        $foundCount = isset($foundFiles[$fn]) ? count($foundFiles[$fn]) : 0;
        $details[$fn] = [
            'active_on_disk' => $existsInTarget,
            'found_elsewhere' => $foundCount,
        ];
    }
    $bizStatus[$row['name']] = $details;
}

echo json_encode([
    'status' => 'ok',
    'app_root' => $appRoot,
    'target_dir' => $targetDir,
    'found_files_count' => count($foundFiles),
    'found_files' => $foundFiles,
    'restored_count' => count($restored),
    'restored' => $restored,
    'biz_status' => $bizStatus,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
