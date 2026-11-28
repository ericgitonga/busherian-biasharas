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
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $validatorHash = hash('sha256', $validator);
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = $ua !== '' ? hash('sha256', $ua) : null;
        $exp = time() + self::REMEMBER_ME_TTL_SECONDS;
        $ver = self::getServerAuthVersion($pdo);

        try {
            $pdo->exec("DELETE FROM admin_remember_tokens WHERE expires_at < " . time());
            $stmt = $pdo->prepare("
                INSERT INTO admin_remember_tokens (selector, validator_hash, user_agent_hash, auth_version, expires_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$selector, $validatorHash, $uaHash, $ver, $exp]);
        } catch (\Throwable $e) {
            // fall through
        }

        $cookieVal = "{$selector}.{$exp}.{$validator}";

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
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['bbiz_admin_remember_sel'] = $selector;
        }
    }

    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            session_name('bbiz_admin_sess');
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
        try {
            $pdo->exec("DELETE FROM admin_remember_tokens");
        } catch (\Throwable $e) {
            // ignore
        }
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
            $sel = (string)($_SESSION['bbiz_admin_remember_sel'] ?? '');
            if ($sel === '' && !empty($_COOKIE[self::REMEMBER_ME_COOKIE])) {
                $parts = explode('.', (string)$_COOKIE[self::REMEMBER_ME_COOKIE], 3);
                if (!empty($parts[0])) {
                    $sel = $parts[0];
                }
            }
            if ($sel !== '') {
                try {
                    $del = $conn->prepare("DELETE FROM admin_remember_tokens WHERE selector = ?");
                    $del->execute([$sel]);
                } catch (\Throwable $e) {
                }
            }
            unset($_SESSION['bbiz_admin_remember_sel']);
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

        // 2. Validate persistent 30-day remember-me cookie (#424, #441)
        $remCookie = trim((string)($_COOKIE[self::REMEMBER_ME_COOKIE] ?? ''));
        if ($remCookie !== '') {
            $parts = explode('.', $remCookie, 3);
            if (count($parts) === 3) {
                [$sel, $expStr, $validator] = $parts;
                $exp = ctype_digit($expStr) ? (int)$expStr : 0;
                if ($exp >= time() && preg_match('/^[a-f0-9]{24,64}$/', $sel) && strlen($validator) >= 16) {
                    try {
                        $conn = $pdo ?? Database::getConnection();
                        $stmt = $conn->prepare("
                            SELECT id, selector, validator_hash, user_agent_hash, auth_version, expires_at
                            FROM admin_remember_tokens
                            WHERE selector = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$sel]);
                        $tok = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($tok && (int)$tok['expires_at'] >= time()) {
                            $serverVer = self::getServerAuthVersion($conn);
                            if ((int)$tok['auth_version'] === $serverVer) {
                                if (hash_equals((string)$tok['validator_hash'], hash('sha256', $validator))) {
                                    $uaValid = true;
                                    if (!empty($tok['user_agent_hash']) && !empty($_SERVER['HTTP_USER_AGENT'])) {
                                        $uaValid = hash_equals((string)$tok['user_agent_hash'], hash('sha256', (string)$_SERVER['HTTP_USER_AGENT']));
                                    }
                                    if ($uaValid) {
                                        // Rotate validator upon each successful session renewal (#441)
                                        $newValidator = bin2hex(random_bytes(32));
                                        $newValidatorHash = hash('sha256', $newValidator);
                                        $newExp = time() + self::REMEMBER_ME_TTL_SECONDS;

                                        $upStmt = $conn->prepare("
                                            UPDATE admin_remember_tokens
                                            SET validator_hash = ?, expires_at = ?, last_used_at = CURRENT_TIMESTAMP
                                            WHERE id = ?
                                        ");
                                        $upStmt->execute([$newValidatorHash, $newExp, $tok['id']]);

                                        $newCookieVal = "{$sel}.{$newExp}.{$newValidator}";
                                        if (!headers_sent()) {
                                            setcookie(self::REMEMBER_ME_COOKIE, $newCookieVal, [
                                                'expires' => $newExp,
                                                'path' => '/',
                                                'secure' => self::isHttpsRequest(),
                                                'httponly' => true,
                                                'samesite' => 'Strict',
                                            ]);
                                        }
                                        $_COOKIE[self::REMEMBER_ME_COOKIE] = $newCookieVal;

                                        self::startSecureSession();
                                        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                                            @session_regenerate_id(true);
                                        }
                                        $_SESSION['bbiz_admin_auth'] = true;
                                        $_SESSION['bbiz_admin_exp'] = time() + self::REMEMBER_ME_TTL_SECONDS;
                                        $_SESSION['bbiz_admin_ver'] = $serverVer;
                                        $_SESSION['bbiz_admin_cred_fp'] = self::currentCredentialFingerprint();
                                        $_SESSION['bbiz_admin_remember'] = true;
                                        $_SESSION['bbiz_admin_remember_sel'] = $sel;
                                        return true;
                                    }
                                }
                            } else {
                                $delStmt = $conn->prepare("DELETE FROM admin_remember_tokens WHERE id = ?");
                                $delStmt->execute([$tok['id']]);
                            }
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
        if ($appEnv === 'production') {
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

    public static function logout(?PDO $pdo = null): void
    {
        self::startSecureSession();

        $selector = (string)($_SESSION['bbiz_admin_remember_sel'] ?? '');
        if ($selector === '' && !empty($_COOKIE[self::REMEMBER_ME_COOKIE])) {
            $parts = explode('.', (string)$_COOKIE[self::REMEMBER_ME_COOKIE], 3);
            if (!empty($parts[0]) && preg_match('/^[a-f0-9]{24,64}$/', $parts[0])) {
                $selector = $parts[0];
            }
        }
        if ($selector !== '') {
            try {
                $conn = $pdo ?? Database::getConnection();
                $delStmt = $conn->prepare("DELETE FROM admin_remember_tokens WHERE selector = ?");
                $delStmt->execute([$selector]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        self::clearRememberMeCookie();
        unset(
            $_SESSION['bbiz_admin_auth'],
            $_SESSION['bbiz_admin_exp'],
            $_SESSION['bbiz_admin_ver'],
            $_SESSION['bbiz_admin_cred_fp'],
            $_SESSION['bbiz_admin_remember'],
            $_SESSION['bbiz_admin_remember_sel']
        );
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
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
