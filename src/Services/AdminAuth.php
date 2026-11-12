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
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            $basePath = PreprodSandbox::basePath();
            if ($basePath !== '') {
                session_name('BBIZ_PREPROD_SESS');
            }
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 7200,
                'path' => $basePath !== '' ? $basePath : '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            @session_start();
        }
    }

    public static function currentCredentialFingerprint(): string
    {
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $passHash = Database::env('ADMIN_PASSWORD_HASH', '');
        $accessToken = Database::env('ADMIN_ACCESS_TOKEN', Database::env('ADMIN_Access_Token', ''));
        return hash_hmac('sha256', "admin_cred_v1|{$passHash}|{$accessToken}", $secret);
    }

    public static function getServerAuthVersion(PDO $pdo): int
    {
        $raw = trim(Database::getSetting($pdo, 'admin_auth_version', '1'));
        $ver = ctype_digit($raw) ? (int)$raw : 1;
        return max(1, $ver);
    }

    public static function revokeAllAdminSessions(PDO $pdo): int
    {
        $next = self::getServerAuthVersion($pdo) + 1;
        Database::setSetting($pdo, 'admin_auth_version', (string)$next);
        return $next;
    }

    public static function establishAdminSession(?PDO $pdo = null): void
    {
        self::startSecureSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $conn = $pdo ?? Database::getConnection();
        $_SESSION['bbiz_admin_auth'] = true;
        $_SESSION['bbiz_admin_exp'] = time() + 7200;
        $_SESSION['bbiz_admin_ver'] = self::getServerAuthVersion($conn);
        $_SESSION['bbiz_admin_cred_fp'] = self::currentCredentialFingerprint();
    }

    public static function isAuthenticated(?PDO $pdo = null): bool
    {
        self::startSecureSession();

        // Check session authentication flag + expiry (2 hours max) + credential fingerprint + server revocation version (#225)
        if (!empty($_SESSION['bbiz_admin_auth']) && !empty($_SESSION['bbiz_admin_exp'])) {
            $expValid = ((int)$_SESSION['bbiz_admin_exp'] >= time());
            $sessFp = (string)($_SESSION['bbiz_admin_cred_fp'] ?? '');
            $fpValid = ($sessFp !== '' && hash_equals(self::currentCredentialFingerprint(), $sessFp));
            if ($expValid && $fpValid) {
                try {
                    $conn = $pdo ?? Database::getConnection();
                    $sessVer = (int)($_SESSION['bbiz_admin_ver'] ?? 0);
                    if ($sessVer > 0 && $sessVer === self::getServerAuthVersion($conn)) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    return false;
                }
            }
            unset(
                $_SESSION['bbiz_admin_auth'],
                $_SESSION['bbiz_admin_exp'],
                $_SESSION['bbiz_admin_ver'],
                $_SESSION['bbiz_admin_cred_fp']
            );
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
        $rate = AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'admin_login', '', 8, 25, 8, 25);
        if (!$rate['allowed']) {
            return ['ok' => false, 'error' => 'Too many login attempts from this network. Please wait 15 minutes.'];
        }

        if (!self::verifyCredentials($submittedPasswordOrToken)) {
            return ['ok' => false, 'error' => 'Invalid admin access credentials.'];
        }

        self::establishAdminSession($pdo);
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
        $pubRoot = Database::env('BBIZ_PUBLIC_WEB_ROOT', '');
        if ($pubRoot !== '' && is_file($pubRoot . '/admin/login.php')) {
            $loginFile = $pubRoot . '/admin/login.php';
        } else {
            $base = dirname(__DIR__, 2);
            $loginFile = is_file($base . '/public/admin/login.php')
                ? $base . '/public/admin/login.php'
                : $base . '/html/admin/login.php';
        }
        require $loginFile;
        exit;
    }
}
