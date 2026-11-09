<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class AdminAuth
{
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 7200,
                'path' => '/',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            @session_start();
        }
    }

    public static function isAuthenticated(): bool
    {
        self::startSecureSession();

        // Check session authentication flag + expiry (2 hours max)
        if (!empty($_SESSION['bbiz_admin_auth']) && !empty($_SESSION['bbiz_admin_exp'])) {
            if ((int)$_SESSION['bbiz_admin_exp'] >= time()) {
                return true;
            }
            unset($_SESSION['bbiz_admin_auth'], $_SESSION['bbiz_admin_exp']);
        }

        // Also support stateless Bearer / X-Admin-Token header for automated tests & CLI
        $headerToken = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
        if ($headerToken !== '' && self::verifyCredentials($headerToken)) {
            return true;
        }

        return false;
    }

    public static function verifyCredentials(string $submittedPasswordOrToken): bool
    {
        if ($submittedPasswordOrToken === '') {
            return false;
        }

        $passHash = Database::env('ADMIN_PASSWORD_HASH', '');
        if ($passHash !== '' && password_verify($submittedPasswordOrToken, $passHash)) {
            return true;
        }

        $accessToken = Database::env('ADMIN_ACCESS_TOKEN', Database::env('ADMIN_Access_Token', ''));
        if ($accessToken === '' && strtolower(Database::env('APP_ENV', 'production')) !== 'production') {
            $accessToken = Database::env('APP_HMAC_SECRET', '');
        }

        if ($accessToken !== '' && hash_equals($accessToken, $submittedPasswordOrToken)) {
            return true;
        }

        return false;
    }

    public static function login(PDO $pdo, string $submittedPasswordOrToken, string $clientIp): array
    {
        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'admin_login', 5, 15);
        if (!$rate['allowed']) {
            return ['ok' => false, 'error' => 'Too many login attempts. Please wait 15 minutes.'];
        }

        if (!self::verifyCredentials($submittedPasswordOrToken)) {
            return ['ok' => false, 'error' => 'Invalid admin access credentials.'];
        }

        self::startSecureSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION['bbiz_admin_auth'] = true;
        $_SESSION['bbiz_admin_exp'] = time() + 7200;

        return ['ok' => true, 'error' => ''];
    }

    public static function logout(): void
    {
        self::startSecureSession();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_destroy();
        }
    }

    public static function requireAdmin(): void
    {
        if (self::isAuthenticated()) {
            return;
        }
        if (!headers_sent()) {
            http_response_code(401);
        }
        $base = dirname(__DIR__, 2);
        $loginFile = is_file($base . '/public/admin/login.php')
            ? $base . '/public/admin/login.php'
            : $base . '/html/admin/login.php';
        require $loginFile;
        exit;
    }
}
