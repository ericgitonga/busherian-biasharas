<?php
declare(strict_types=1);

/**
 * Route Retired (#487).
 * Photo recovery logic has been permanently relocated to private CLI utility `bin/locate-lost-photos.php`.
 */
http_response_code(404);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'status' => 'not_found',
    'error' => 'Endpoint retired. Use bin/locate-lost-photos.php via CLI.'
], JSON_UNESCAPED_SLASHES);
exit;
