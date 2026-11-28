<?php
declare(strict_types=1);

/**
 * CLI Utility: Locate & Restore Lost Business Photos (#487).
 *
 * Usage:
 *   php bin/locate-lost-photos.php [--restore]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: This utility can only be executed via the command-line interface.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;

$restore = in_array('--restore', $argv ?? [], true);

$appRoot = dirname(__DIR__);
$targetDir = is_dir($appRoot . '/html') ? ($appRoot . '/html/uploads/businesses') : ($appRoot . '/public/uploads/businesses');
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0775, true);
}

$searchRoots = [
    $appRoot,
    dirname($appRoot),
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

echo "Found files count: " . count($foundFiles) . "\n";
echo "Restored count: " . count($restored) . "\n";
if (!empty($restored)) {
    echo "Restored files:\n - " . implode("\n - ", $restored) . "\n";
}
