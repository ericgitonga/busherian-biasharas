<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Nairobi');

spl_autoload_register(static function (string $class): void {
    $prefix = 'BusherianBiasharas\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (!function_exists('bbiz_send_security_headers')) {
    function bbiz_send_security_headers(bool $isJson = false): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        if ($isJson) {
            header('Content-Type: application/json; charset=UTF-8');
        }
    }
}
