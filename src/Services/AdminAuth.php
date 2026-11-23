<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class AdminAuth
{
    public const REMEMBER_ME_COOKIE = 'bbiz_admin_remember';
    public const REMEMBER_ME_TTL_SECONDS = 2592000; // 30 days (30 * 86400)

    private static function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function clearRememberMeCookie(): void
    {
        if (!headers_sent()) {
            setcookie(self::REMEMBER_ME_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        unset($_COOKIE[self::REMEMBER_ME_COOKIE]);
    }

    private static function setRememberMeCookie(PDO $pdo): void
    {
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $selector = bin2hex(random_bytes(12));
        $exp = time() + self::REMEMBER_ME_TTL_SECONDS;
        $ver = self::getServerAuthVersion($pdo);
        $fp = self::currentCredentialFingerprint();
        $sig = hash_hmac('sha256', "admin_rem_v1|{$selector}|{$exp}|{$ver}|{$fp}", $secret);
        $cookieVal = "{$selector}.{$exp}.{$sig}";

        if (!headers_sent()) {
            setcookie(self::REMEMBER_ME_COOKIE, $cookieVal, [
                'expires' => $exp,
                'path' => '/',
                'secure' => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        $_COOKIE[self::REMEMBER_ME_COOKIE] = $cookieVal;
    }

    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            $isHttps = self::isHttpsRequest();
            session_set_cookie_params([
                'lifetime' => self::REMEMBER_ME_TTL_SECONDS,
                'path' => '/',
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
        self::clearRememberMeCookie();
        return $next;
    }

    public static function establishAdminSession(?PDO $pdo = null, bool $rememberMe = false): void
    {
        self::startSecureSession();
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $conn = $pdo ?? Database::getConnection();
        $ttl = $rememberMe ? self::REMEMBER_ME_TTL_SECONDS : 7200;
        $_SESSION['bbiz_admin_auth'] = true;
        $_SESSION['bbiz_admin_exp'] = time() + $ttl;
        $_SESSION['bbiz_admin_ver'] = self::getServerAuthVersion($conn);
        $_SESSION['bbiz_admin_cred_fp'] = self::currentCredentialFingerprint();
        $_SESSION['bbiz_admin_remember'] = $rememberMe;

        if ($rememberMe) {
            self::setRememberMeCookie($conn);
        } else {
            self::clearRememberMeCookie();
        }
    }

    public static function isAuthenticated(?PDO $pdo = null): bool
    {
        self::startSecureSession();

        // 1. Check session authentication flag + expiry + credential fingerprint + server revocation version (#225)
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
                $_SESSION['bbiz_admin_cred_fp'],
                $_SESSION['bbiz_admin_remember']
            );
        }

        // 2. Validate persistent 30-day remember-me cookie (#424)
        $remCookie = trim((string)($_COOKIE[self::REMEMBER_ME_COOKIE] ?? ''));
        if ($remCookie !== '') {
            $parts = explode('.', $remCookie, 3);
            if (count($parts) === 3) {
                [$sel, $expStr, $sig] = $parts;
                $exp = ctype_digit($expStr) ? (int)$expStr : 0;
                if ($exp >= time() && preg_match('/^[a-f0-9]{24}$/', $sel)) {
                    try {
                        $conn = $pdo ?? Database::getConnection();
                        $ver = self::getServerAuthVersion($conn);
                        $fp = self::currentCredentialFingerprint();
                        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
                        $expectedSig = hash_hmac('sha256', "admin_rem_v1|{$sel}|{$exp}|{$ver}|{$fp}", $secret);
                        if (hash_equals($expectedSig, $sig)) {
                            self::establishAdminSession($conn, true);
                            return true;
                        }
                    } catch (\Throwable $e) {
                        // ignore and fall through
                    }
                }
            }
            self::clearRememberMeCookie();
        }

        // 3. Support stateless X-Admin-Token ONLY when explicitly enabled in production (or non-prod testing),
        // strictly separated from password_verify() and protected by the same per-IP auth rate limiter (#236 H-01).
        $headerToken = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
        if ($headerToken !== '' && self::isHeaderTokenEnabled()) {
            try {
                $conn = $pdo ?? Database::getConnection();
                $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
                $rate = AntiSpamGate::checkAuthAttemptAllowed(
                    $conn,
                    $clientIp,
                    'admin_login',
                    '',
                    8,
                    25,
                    8,
                    25,
                    static fn(): bool => self::verifyAutomationHeaderToken($headerToken)
                );
                if (!empty($rate['allowed']) && !empty($rate['verified'])) {
                    return true;
                }
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    public static function isHeaderTokenEnabled(): bool
    {
        $appEnv = strtolower(trim(Database::env('APP_ENV', 'production')));
        if ($appEnv === 'production' && !PreprodSandbox::isPreprod()) {
            return strtolower(trim(Database::env('ALLOW_ADMIN_HEADER_TOKEN', 'false'))) === 'true';
        }
        return true;
    }

    /**
     * Verify stateless automation token ONLY — never evaluates ADMIN_PASSWORD_HASH / password_verify() (#236 H-01).
     */
    public static function verifyAutomationHeaderToken(string $submittedToken): bool
    {
        if ($submittedToken === '' || !self::isHeaderTokenEnabled()) {
            return false;
        }

        $autoToken = Database::env('ADMIN_AUTOMATION_TOKEN', '');
        if ($autoToken !== '') {
            return hash_equals($autoToken, $submittedToken);
        }

        $accessToken = Database::env('ADMIN_ACCESS_TOKEN', Database::env('ADMIN_Access_Token', ''));
        if ($accessToken === '' && strtolower(Database::env('APP_ENV', 'production')) !== 'production') {
            $accessToken = Database::env('APP_HMAC_SECRET', '');
        }
        return ($accessToken !== '' && hash_equals($accessToken, $submittedToken));
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

    public static function login(PDO $pdo, string $submittedPasswordOrToken, string $clientIp, bool $rememberMe = false): array
    {
        $rate = AntiSpamGate::checkAuthAttemptAllowed(
            $pdo,
            $clientIp,
            'admin_login',
            '',
            8,
            25,
            8,
            25,
            static fn(): bool => self::verifyCredentials($submittedPasswordOrToken)
        );
        if (!$rate['allowed']) {
            return ['ok' => false, 'error' => 'Too many login attempts from this network. Please wait 15 minutes.'];
        }
        if (empty($rate['verified'])) {
            return ['ok' => false, 'error' => 'Invalid admin access credentials.'];
        }

        self::establishAdminSession($pdo, $rememberMe);
        return ['ok' => true, 'error' => ''];
    }

    public static function logout(): void
    {
        self::startSecureSession();
        self::clearRememberMeCookie();
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
