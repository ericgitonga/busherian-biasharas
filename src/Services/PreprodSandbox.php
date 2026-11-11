<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PreprodSandbox — Isolated Pre-Production Preview & Testing Environment (/admin/preprod).
 *
 * Guarantees strict separation from live production:
 * 1. Forces SQLite persistence in an isolated pre-prod storage file outside webroot.
 * 2. Forces SIMULATE_EMAILS="yes" (Rule #7 Staging SMTP Isolation — zero real outbound emails).
 * 3. Isolates PHP session & persistent owner cookies under /admin/preprod.
 * 4. Gates public access behind Admin credentials (browser gate cookie, 1-click signed prod handoff,
 *    or X-Preprod-Access header for automated CLI/E2E test suites) + X-Robots-Tag: noindex.
 * 5. Transparently rewrites root-relative HTML links, Location redirects, and JS fetch() calls
 *    under BBIZ_BASE_PATH (/admin/preprod) with zero overhead on live production (/).
 */
final class PreprodSandbox
{
    private const GATE_COOKIE_NAME = 'bbiz_preprod_gate';
    private const GATE_TTL_SECONDS = 43200; // 12 hours browser preview access
    private const HANDOFF_TTL_SECONDS = 300; // 5 minutes 1-click handoff from live /admin/index.php

    private static bool $booted = false;
    /** @var array<string, string> */
    private static array $prodEnv = [];
    private static string $prodHmacSecret = '';

    public static function isPreprod(): bool
    {
        if (defined('BBIZ_PREPROD_MODE') && BBIZ_PREPROD_MODE === true) {
            return true;
        }
        if (strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''))) === 'preprod') {
            return true;
        }
        return self::basePath() !== '';
    }

    public static function basePath(): string
    {
        $raw = '';
        if (defined('BBIZ_PREPROD_BASE_PATH') && is_string(BBIZ_PREPROD_BASE_PATH)) {
            $raw = BBIZ_PREPROD_BASE_PATH;
        } elseif (getenv('BBIZ_BASE_PATH') !== false) {
            $raw = (string)getenv('BBIZ_BASE_PATH');
        } elseif (!empty($_ENV['BBIZ_BASE_PATH'])) {
            $raw = (string)$_ENV['BBIZ_BASE_PATH'];
        }
        $clean = rtrim(trim($raw), '/');
        if ($clean === '') {
            return '';
        }
        return '/' . ltrim($clean, '/');
    }

    public static function prefixPath(string $path): string
    {
        $base = self::basePath();
        if ($base === '' || $path === '') {
            return $path;
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }
        if ($path === $base || str_starts_with($path, $base . '/') || str_starts_with($path, $base . '?') || str_starts_with($path, $base . '#')) {
            return $path;
        }
        return $base . ($path === '/' ? '/' : $path);
    }

    /**
     * Parse a .env file into a key => value map without mutating process environment variables.
     *
     * @return array<string, string>
     */
    public static function parseEnvFile(string $filePath): array
    {
        $parsed = [];
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $parsed;
        }
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $parsed;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $trimmed, 2);
            $key = trim($key);
            $val = trim($val);
            if ((str_starts_with($val, "'") && str_ends_with($val, "'")) ||
                (str_starts_with($val, '"') && str_ends_with($val, '"'))) {
                $val = substr($val, 1, -1);
            }
            if ($key !== '') {
                $parsed[$key] = $val;
            }
        }
        return $parsed;
    }

    /**
     * Boot isolated Pre-Prod environment, headers, access gate, and subpath URL rewriters.
     */
    public static function boot(): void
    {
        if (self::$booted || !self::isPreprod()) {
            return;
        }
        self::$booted = true;

        $appRoot = dirname(__DIR__, 2);
        $prodEnvCandidates = [];
        if (defined('BBIZ_PROD_ENV_PATH') && is_string(BBIZ_PROD_ENV_PATH)) {
            $prodEnvCandidates[] = BBIZ_PROD_ENV_PATH;
        }
        $prodEnvCandidates[] = dirname($appRoot) . '/.env';
        $prodEnvCandidates[] = $appRoot . '/.env';

        foreach ($prodEnvCandidates as $cand) {
            if (is_file($cand) && is_readable($cand)) {
                self::$prodEnv = self::parseEnvFile($cand);
                break;
            }
        }

        $basePath = self::basePath();
        if ($basePath === '') {
            $basePath = '/admin/preprod';
            self::setEnvVar('BBIZ_BASE_PATH', $basePath);
        }

        // Inherit Admin credentials from production .env when not explicitly overridden in process env
        foreach (['ADMIN_PASSWORD_HASH', 'ADMIN_ACCESS_TOKEN', 'ADMIN_Access_Token', 'ADMIN_EMAIL'] as $inheritKey) {
            if (getenv($inheritKey) === false && !empty(self::$prodEnv[$inheritKey])) {
                self::setEnvVar($inheritKey, self::$prodEnv[$inheritKey]);
            }
        }

        $rawProdHmac = (string)(getenv('BBIZ_PROD_HMAC_SECRET') ?: (self::$prodEnv['APP_HMAC_SECRET'] ?? getenv('APP_HMAC_SECRET') ?: 'bbiz_preprod_default_hmac_seed_32b_min'));
        self::$prodHmacSecret = $rawProdHmac;

        // Derive isolated cryptographic keys for pre-prod unless explicitly supplied by test runner
        if (getenv('APP_HMAC_SECRET') === false || defined('BBIZ_PREPROD_MODE')) {
            self::setEnvVar('APP_HMAC_SECRET', hash_hmac('sha256', 'bbiz_preprod_app_hmac_v1', $rawProdHmac));
        }
        if (getenv('CSRF_SECRET') === false || defined('BBIZ_PREPROD_MODE')) {
            self::setEnvVar('CSRF_SECRET', hash_hmac('sha256', 'bbiz_preprod_csrf_v1', $rawProdHmac));
        }
        if (getenv('IP_HASH_SECRET') === false || defined('BBIZ_PREPROD_MODE')) {
            self::setEnvVar('IP_HASH_SECRET', hash_hmac('sha256', 'bbiz_preprod_ip_salt_v1', $rawProdHmac));
        }

        // Strictly force isolated SQLite DB & simulated outbound emails (Rule #7)
        self::setEnvVar('APP_ENV', 'preprod');
        self::setEnvVar('DB_DRIVER', 'sqlite');
        self::setEnvVar('DB_DRIVER_FALLBACK', 'sqlite');
        self::setEnvVar('SIMULATE_EMAILS', 'yes');

        $existingSqlite = getenv('DB_SQLITE_PATH');
        if ($existingSqlite === false || trim((string)$existingSqlite) === '' || defined('BBIZ_PREPROD_MODE')) {
            $defaultSqlite = $appRoot . '/storage/preprod.sqlite';
            if (!defined('BBIZ_PREPROD_MODE') && $existingSqlite !== false && trim((string)$existingSqlite) !== '') {
                $defaultSqlite = (string)$existingSqlite;
            }
            self::setEnvVar('DB_SQLITE_PATH', $defaultSqlite);
        }

        if (defined('BBIZ_PREPROD_PUBLIC_ROOT') && is_string(BBIZ_PREPROD_PUBLIC_ROOT)) {
            self::setEnvVar('BBIZ_PUBLIC_WEB_ROOT', BBIZ_PREPROD_PUBLIC_ROOT);
        }

        if (getenv('APP_URL') === false || defined('BBIZ_PREPROD_MODE')) {
            $prodOrigin = rtrim((string)(self::$prodEnv['APP_URL'] ?? 'https://www.busherianbiasharas.com'), '/');
            self::setEnvVar('APP_URL', $prodOrigin . $basePath);
        }

        Database::resetConnection();

        if (PHP_SAPI !== 'cli') {
            if (!headers_sent()) {
                header('X-Robots-Tag: noindex, nofollow, noarchive');
                header('X-BBiz-Environment: preprod');
            }

            if (function_exists('header_register_callback')) {
                header_register_callback([self::class, 'rewriteLocationHeaders']);
            }

            self::enforceAccessGate();

            ob_start([self::class, 'rewriteHtmlOutput']);
        }
    }

    private static function setEnvVar(string $key, string $val): void
    {
        putenv("{$key}={$val}");
        $_ENV[$key] = $val;
    }

    /**
     * Generate a short-lived (5-minute) HMAC-signed 1-click handoff URL from Live Prod Admin -> Pre-Prod Preview.
     */
    public static function generateProdToPreprodHandoffUrl(string $subPath = '/'): string
    {
        $exp = time() + self::HANDOFF_TTL_SECONDS;
        $secret = self::isPreprod() && self::$prodHmacSecret !== ''
            ? self::$prodHmacSecret
            : Database::env('APP_HMAC_SECRET', 'bbiz_preprod_default_hmac_seed_32b_min');
        $sig = hash_hmac('sha256', "preprod_handoff|{$exp}", $secret);

        $cleanSub = '/' . ltrim($subPath, '/');
        if ($cleanSub === '/index.php') {
            $cleanSub = '/';
        }
        $sep = str_contains($cleanSub, '?') ? '&' : '?';
        return '/admin/preprod' . $cleanSub . $sep . 'preprod_handoff=' . urlencode("{$exp}.{$sig}");
    }

    public static function verifyProdHandoffToken(string $rawToken): bool
    {
        if (!str_contains($rawToken, '.')) {
            return false;
        }
        [$expStr, $sig] = explode('.', $rawToken, 2);
        $exp = (int)$expStr;
        if ($exp < time() || $exp > (time() + self::HANDOFF_TTL_SECONDS + 60) || $sig === '') {
            return false;
        }

        $secretsToTry = array_filter(array_unique([
            self::$prodHmacSecret,
            Database::env('APP_HMAC_SECRET', ''),
        ]));

        foreach ($secretsToTry as $secret) {
            $expected = hash_hmac('sha256', "preprod_handoff|{$exp}", $secret);
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    private static function issueGateCookie(): void
    {
        $exp = time() + self::GATE_TTL_SECONDS;
        $secret = Database::env('APP_HMAC_SECRET', 'bbiz_preprod_gate_fallback');
        $sig = hash_hmac('sha256', "gate_ok|{$exp}", $secret);
        $val = "{$exp}.{$sig}";
        $cookiePath = self::basePath() ?: '/';
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if (!headers_sent()) {
            setcookie(self::GATE_COOKIE_NAME, $val, [
                'expires' => $exp,
                'path' => $cookiePath,
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::GATE_COOKIE_NAME] = $val;
    }

    private static function hasValidGateCookie(): bool
    {
        $raw = trim((string)($_COOKIE[self::GATE_COOKIE_NAME] ?? ''));
        if ($raw === '' || !str_contains($raw, '.')) {
            return false;
        }
        [$expStr, $sig] = explode('.', $raw, 2);
        $exp = (int)$expStr;
        if ($exp < time() || $sig === '') {
            return false;
        }
        $secret = Database::env('APP_HMAC_SECRET', 'bbiz_preprod_gate_fallback');
        $expected = hash_hmac('sha256', "gate_ok|{$exp}", $secret);
        return hash_equals($expected, $sig);
    }

    /**
     * Protect /admin/preprod/* from public visitors & search bots while allowing:
     * - 1-click signed handoff from Live Prod Admin (?preprod_handoff=...)
     * - Signed browser gate cookie (bbiz_preprod_gate)
     * - Automated CLI/E2E header (X-Preprod-Access or X-Admin-Token)
     * - Interactive Admin password unlock form
     */
    private static function enforceAccessGate(): void
    {
        if (getenv('BBIZ_SKIP_PREPROD_GATE') === '1') {
            return;
        }

        // 1. Lock / sign-out of Pre-Prod Gate if requested
        if (!empty($_GET['preprod_lock'])) {
            $cookiePath = self::basePath() ?: '/';
            if (!headers_sent()) {
                setcookie(self::GATE_COOKIE_NAME, '', [
                    'expires' => time() - 3600,
                    'path' => $cookiePath,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            unset($_COOKIE[self::GATE_COOKIE_NAME]);
            AdminAuth::logout();
            if (!headers_sent()) {
                header('Location: https://www.busherianbiasharas.com/admin/index.php');
            }
            exit;
        }

        // 2. 1-click signed handoff from Live Prod /admin/index.php
        if (!empty($_GET['preprod_handoff']) && self::verifyProdHandoffToken((string)$_GET['preprod_handoff'])) {
            self::issueGateCookie();
            AdminAuth::startSecureSession();
            $_SESSION['bbiz_admin_auth'] = true;
            $_SESSION['bbiz_admin_exp'] = time() + 7200;

            $reqUri = (string)($_SERVER['REQUEST_URI'] ?? self::basePath() . '/');
            $cleanUri = preg_replace('/([?&])preprod_handoff=[^&]*(&|$)/', '$1', $reqUri) ?? $reqUri;
            $cleanUri = rtrim($cleanUri, '?&');
            if ($cleanUri === '') {
                $cleanUri = self::basePath() . '/';
            }
            if (!headers_sent()) {
                header('Location: ' . $cleanUri);
            }
            exit;
        }

        // 3. Valid browser gate cookie
        if (self::hasValidGateCookie()) {
            return;
        }

        // 4. Stateless header for automated CLI / E2E test suites (does NOT mutate Admin session so 401 admin tests remain valid!)
        $preprodHdr = trim((string)($_SERVER['HTTP_X_PREPROD_ACCESS'] ?? ''));
        if ($preprodHdr !== '' && AdminAuth::verifyCredentials($preprodHdr)) {
            return;
        }
        $adminHdr = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
        if ($adminHdr !== '' && AdminAuth::verifyCredentials($adminHdr)) {
            return;
        }

        // 5. Interactive Pre-Prod Gate form submission
        $pdo = Database::getConnection();
        $gateError = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['preprod_gate_token'])) {
            $csrf = trim((string)($_POST['csrf_token'] ?? ''));
            if (!AntiSpamGate::verifyCsrfToken($csrf)) {
                $gateError = 'Invalid or expired security token. Please try again.';
            } else {
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
                $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $ip, 'preprod_gate_login', 8, 15);
                if (!$rate['allowed']) {
                    $gateError = 'Too many attempts. Please wait 15 minutes.';
                } elseif (AdminAuth::verifyCredentials(trim((string)$_POST['preprod_gate_token']))) {
                    self::issueGateCookie();
                    AdminAuth::startSecureSession();
                    $_SESSION['bbiz_admin_auth'] = true;
                    $_SESSION['bbiz_admin_exp'] = time() + 7200;
                    $target = (string)($_SERVER['REQUEST_URI'] ?? (self::basePath() . '/'));
                    if (!str_starts_with($target, '/')) {
                        $target = self::basePath() . '/';
                    }
                    if (!headers_sent()) {
                        header('Location: ' . $target);
                    }
                    exit;
                } else {
                    $gateError = 'Invalid admin credentials.';
                }
            }
        }

        if (!headers_sent()) {
            http_response_code(401);
        }

        $reqPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        if (str_contains($reqPath, '/api/')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'status' => 'error',
                'error' => 'Pre-Prod Sandbox authentication required. Unlock in browser or pass X-Preprod-Access header.',
            ]);
            exit;
        }

        $tokens = AntiSpamGate::generateFormTokens();
        $csrfEsc = htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8');
        $actionEsc = htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? (self::basePath() . '/')), ENT_QUOTES, 'UTF-8');
        $errBlock = $gateError !== ''
            ? '<div style="background:#FEF2F2;border:1px solid #DC2626;color:#991B1B;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.88rem;font-weight:600;">' . htmlspecialchars($gateError, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Pre-Prod Preview Sandbox Gate — Busherian Biasharas</title>
  <style>
    body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:#F9FAFB; color:#111827; }
    .hdr { background:linear-gradient(135deg,#114B3E,#7B1113); color:#fff; padding:1rem 1.5rem; display:flex; justify-content:space-between; align-items:center; }
    .card { max-width:460px; margin:3.5rem auto; background:#fff; border:2px solid #F59E0B; border-radius:14px; padding:1.75rem; box-shadow:0 8px 24px rgba(0,0,0,0.08); }
    .badge { display:inline-block; background:#FEF3C7; color:#92400E; border:1px solid #F59E0B; border-radius:999px; padding:0.2rem 0.65rem; font-size:0.75rem; font-weight:800; margin-bottom:0.65rem; }
    input[type="password"] { width:100%; box-sizing:border-box; padding:0.65rem 0.85rem; border:1px solid #D1D5DB; border-radius:8px; font-size:0.95rem; margin-top:0.35rem; }
    button { width:100%; margin-top:1rem; background:#114B3E; color:#fff; border:none; border-radius:8px; padding:0.72rem 1rem; font-size:0.95rem; font-weight:700; cursor:pointer; }
    button:hover { background:#0D3B31; }
  </style>
</head>
<body>
  <header class="hdr">
    <div>
      <strong>Busherian Biasharas — Pre-Prod Preview Sandbox</strong>
      <div style="font-size:0.78rem;opacity:0.9;">Isolated SQLite DB · Simulated Outbound Emails · Pre-Production Code Verification</div>
    </div>
    <a href="https://www.busherianbiasharas.com/" style="color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,0.5);padding:0.35rem 0.75rem;border-radius:8px;font-size:0.82rem;">&larr; Live Site</a>
  </header>
  <main class="card">
    <span class="badge">🧪 ADMIN PREVIEW GATE</span>
    <h1 style="margin:0 0 0.4rem 0;font-size:1.3rem;color:#114B3E;">Unlock Pre-Prod Preview Site</h1>
    <p style="margin:0 0 1.15rem 0;font-size:0.88rem;color:#4B5563;line-height:1.5;">
      Enter your Busherian Biasharas Admin password (or open 1-click from the Live Admin Console) to test pre-production code, dummy listings, owner workflows, and automated checks safely.
    </p>
    {$errBlock}
    <form method="POST" action="{$actionEsc}">
      <input type="hidden" name="csrf_token" value="{$csrfEsc}">
      <label for="preprod_gate_token" style="font-size:0.85rem;font-weight:700;color:#374151;">Admin Password or Access Token</label>
      <input type="password" id="preprod_gate_token" name="preprod_gate_token" required autofocus autocomplete="current-password" placeholder="Enter admin password...">
      <button type="submit">Enter Pre-Prod Sandbox &rarr;</button>
    </form>
  </main>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Automatically prefix root-relative PHP Location redirect headers with BBIZ_BASE_PATH (/admin/preprod).
     */
    public static function rewriteLocationHeaders(): void
    {
        $base = self::basePath();
        if ($base === '') {
            return;
        }
        foreach (headers_list() as $hdr) {
            if ( stripos($hdr, 'Location:') === 0 ) {
                $target = trim(substr($hdr, strlen('Location:')));
                if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
                    $rewritten = self::prefixPath($target);
                    if ($rewritten !== $target) {
                        header('Location: ' . $rewritten, true);
                    }
                }
                break;
            }
        }
    }

    /**
     * Rewrite root-relative HTML URLs, inject client-side fetch/DOM subpath adapter, and render Pre-Prod banner.
     */
    public static function rewriteHtmlOutput(string $buffer): string
    {
        $base = self::basePath();
        if ($base === '' || $buffer === '') {
            return $buffer;
        }

        // Never alter JSON or non-HTML responses
        foreach (headers_list() as $hdr) {
            if (stripos($hdr, 'Content-Type:') === 0 && stripos($hdr, 'text/html') === false) {
                return $buffer;
            }
        }
        if (stripos($buffer, '<html') === false && stripos($buffer, '<!DOCTYPE html') === false) {
            return $buffer;
        }

        $quotedBase = preg_quote(ltrim($base, '/'), '#');
        // Rewrite href="/...", src="/...", action="/...", data-delete-action-url="/..."
        $pattern = '#\b(href|src|action|data-delete-action-url)=(["\'])/(?!' . $quotedBase . '(?:/|\?|\#|$)|/)([^"\']*)\2#i';
        $buffer = preg_replace_callback(
            $pattern,
            static function (array $m) use ($base): string {
                $attr = $m[1];
                $q = $m[2];
                $rest = $m[3];
                return $attr . '=' . $q . $base . '/' . $rest . $q;
            },
            $buffer
        ) ?? $buffer;

        $baseJson = json_encode($base, JSON_UNESCAPED_SLASHES);
        $clientRuntimeScript = <<<SCRIPT
<script>
(function(){
  var BASE = {$baseJson};
  window.__BBIZ_BASE_PATH__ = BASE;
  function pfx(u) {
    if (typeof u !== 'string' || u.charAt(0) !== '/' || u.charAt(1) === '/') return u;
    if (u === BASE || u.indexOf(BASE + '/') === 0 || u.indexOf(BASE + '?') === 0 || u.indexOf(BASE + '#') === 0) return u;
    return BASE + u;
  }
  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function(input, init) {
      if (typeof input === 'string') {
        input = pfx(input);
      } else if (input && typeof Request !== 'undefined' && input instanceof Request) {
        try {
          var u = new URL(input.url);
          if (u.origin === window.location.origin) {
            var np = pfx(u.pathname);
            if (np !== u.pathname) {
              u.pathname = np;
              input = new Request(u.toString(), input);
            }
          }
        } catch (_) {}
      }
      return origFetch.call(this, input, init);
    };
  }
  function fixBizList(arr) {
    if (!Array.isArray(arr)) return;
    for (var i = 0; i < arr.length; i++) {
      var b = arr[i];
      if (!b || typeof b !== 'object') continue;
      if (typeof b.owner_edit_url === 'string') b.owner_edit_url = pfx(b.owner_edit_url);
      if (Array.isArray(b.photos)) {
        for (var j = 0; j < b.photos.length; j++) {
          if (typeof b.photos[j] === 'string') b.photos[j] = pfx(b.photos[j]);
        }
      }
    }
  }
  function fixTree(root) {
    if (!root || !root.querySelectorAll) return;
    var nodes = root.querySelectorAll('[href^="/"],[src^="/"],[action^="/"],[data-delete-action-url^="/"]');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      ['href','src','action','data-delete-action-url'].forEach(function(a){
        var v = el.getAttribute(a);
        if (v && v.charAt(0) === '/' && v.charAt(1) !== '/') {
          var nv = pfx(v);
          if (nv !== v) el.setAttribute(a, nv);
        }
      });
    }
  }
  document.addEventListener('DOMContentLoaded', function(){
    fixBizList(window.INITIAL_BUSINESSES);
    fixTree(document.documentElement);
    if (typeof MutationObserver !== 'undefined' && document.body) {
      new MutationObserver(function(muts){
        for (var k = 0; k < muts.length; k++) {
          var added = muts[k].addedNodes;
          for (var m = 0; m < added.length; m++) {
            if (added[m] && added[m].nodeType === 1) {
              fixTree(added[m]);
              ['href','src','action','data-delete-action-url'].forEach(function(a){
                var v = added[m].getAttribute && added[m].getAttribute(a);
                if (v && v.charAt(0) === '/' && v.charAt(1) !== '/') added[m].setAttribute(a, pfx(v));
              });
            }
          }
        }
      }).observe(document.body, { childList: true, subtree: true });
    }
  });
})();
</script>
SCRIPT;

        $buffer = preg_replace('#<head\b[^>]*>#i', '$0' . "\n" . $clientRuntimeScript, $buffer, 1) ?? $buffer;

        if (empty($GLOBALS['BBIZ_SUPPRESS_PREPROD_BANNER'])) {
            $verFile = dirname(__DIR__, 2) . '/VERSION';
            $ver = is_file($verFile) ? trim((string)file_get_contents($verFile)) : 'dev';
            $verEsc = htmlspecialchars($ver, ENT_QUOTES, 'UTF-8');
            $bEsc = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');

            $banner = <<<BANNER
<div id="bbiz-preprod-banner" role="region" aria-label="Pre-Production Preview Notice" style="background:#FFFBEB;border-bottom:2px solid #F59E0B;color:#78350F;padding:0.45rem 1rem;font-size:0.8rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.6rem;position:sticky;top:0;z-index:9999;">
  <div>
    <strong>🧪 PRE-PROD PREVIEW SANDBOX (v{$verEsc})</strong>
    <span style="margin-left:0.4rem;color:#92400E;">Isolated SQLite DB · Outbound Emails Simulated · Safe for Dummy Listings &amp; Pre-Prod Code</span>
  </div>
  <div style="display:flex;gap:0.45rem;flex-wrap:wrap;align-items:center;">
    <a href="{$bEsc}/" style="color:#78350F;font-weight:700;text-decoration:underline;">Directory</a>
    <span>·</span>
    <a href="{$bEsc}/submit.php" style="color:#78350F;font-weight:700;text-decoration:underline;">+ Submit Test</a>
    <span>·</span>
    <a href="{$bEsc}/admin/index.php" style="color:#78350F;font-weight:700;text-decoration:underline;">Admin Queue</a>
    <span>·</span>
    <a href="{$bEsc}/owner/login.php" style="color:#78350F;font-weight:700;text-decoration:underline;">Owner Portal</a>
    <span>·</span>
    <a href="{$bEsc}/admin/sandbox.php" style="background:#D97706;color:#fff;text-decoration:none;padding:0.2rem 0.6rem;border-radius:6px;font-weight:800;">🛠️ Sandbox &amp; Outbox</a>
    <a href="https://www.busherianbiasharas.com/admin/index.php" style="background:#114B3E;color:#fff;text-decoration:none;padding:0.2rem 0.6rem;border-radius:6px;font-weight:700;">↗ Live Prod</a>
  </div>
</div>
BANNER;
            $buffer = preg_replace('#<body\b[^>]*>#i', '$0' . "\n" . $banner, $buffer, 1) ?? $buffer;
        }

        return $buffer;
    }

    /**
     * Assert strictly that destructive/mutating sandbox helpers NEVER execute outside isolated Pre-Prod SQLite.
     */
    private static function assertSafePreprodSqlite(): void
    {
        if (!self::isPreprod() || Database::getActiveDriver() !== 'sqlite') {
            throw new RuntimeException('Refusing sandbox operation: Pre-Prod Sandbox requires APP_ENV=preprod with an isolated SQLite database.');
        }
    }

    /**
     * Seed 5 realistic dummy AHS & AGHS listings (3 approved + 2 pending in vetting queue) for QA & pagination tests.
     *
     * @return array{approved_added:int,pending_added:int,skipped:int}
     */
    public static function seedDummyListings(PDO $pdo): array
    {
        self::assertSafePreprodSqlite();

        $catBySlug = [];
        foreach ($pdo->query("SELECT id, slug, parent_id FROM categories")->fetchAll() as $row) {
            $catBySlug[(string)$row['slug']] = [
                'id' => (int)$row['id'],
                'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            ];
        }

        $findSpecialty = static function (string $slug, string $fallbackSectorSlug) use ($catBySlug): array {
            if (isset($catBySlug[$slug]) && $catBySlug[$slug]['parent_id'] !== null) {
                return [(int)$catBySlug[$slug]['parent_id'], (int)$catBySlug[$slug]['id']];
            }
            foreach ($catBySlug as $info) {
                if ($info['parent_id'] !== null) {
                    return [$info['parent_id'], $info['id']];
                }
            }
            return [1, 2];
        };

        [$foodSec, $cafeSpec] = $findSpecialty('cafes-coffee-houses', 'food-hospitality-events');
        [$legalSec, $advSpec] = $findSpecialty('advocates-law-firms', 'legal-governance-compliance');
        [$techSec, $cloudSpec] = $findSpecialty('cloud-data-ai', 'technology-software-digital');
        [$energySec, $solarSpec] = $findSpecialty('solar-clean-energy', 'energy-environment-agribusiness');
        [$artsSec, $visArtsSpec] = $findSpecialty('visual-arts-fine-art', 'arts-visual-performing-arts');

        $standardHours = (string)json_encode([
            'mon' => [['08:00', '18:00']],
            'tue' => [['08:00', '18:00']],
            'wed' => [['08:00', '18:00']],
            'thu' => [['08:00', '18:00']],
            'fri' => [['08:00', '18:00']],
            'sat' => [['09:00', '15:00']],
            'sun' => [],
        ]);

        $approvedSamples = [
            [
                'name' => '[DUMMY] Kikuyu Ridge Specialty Roasters',
                'tagline' => 'Single-origin Kenyan AA coffee bar & coworking patio (Pre-Prod Test)',
                'description' => 'Dummy pre-prod test listing verifying Google Maps !3d!4d pin coordinate inference, EAT operating hours, and AHS green marker styling.',
                'school_badge' => 'AHS',
                'sector_id' => $foodSec,
                'specialty_id' => $cafeSpec,
                'service_mode' => 'physical',
                'country' => 'Kenya',
                'city_county' => 'Nairobi',
                'area_neighborhood' => 'Karen',
                'street_road' => 'Karen Shopping Centre, Ngong Road',
                'google_maps_url' => 'https://www.google.com/maps/place/Karen/@-1.3201,36.7042,15z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-1.319167!4d36.706944',
                'phone_primary' => '+254700111001',
                'whatsapp_number' => '+254700111001',
                'email_public' => 'roasters@dummy-preprod.example',
                'website_url' => 'https://roasters.dummy-preprod.example',
                'operating_hours_json' => $standardHours,
            ],
            [
                'name' => '[DUMMY] Naserian & Co. Advocates',
                'tagline' => 'Commercial, IP & Technology Law Practice (Pre-Prod Test)',
                'description' => 'Dummy pre-prod AGHS regulated legal practice verifying LSK statutory regulator badge and AGHS green-maroon marker styling.',
                'school_badge' => 'AGHS',
                'sector_id' => $legalSec,
                'specialty_id' => $advSpec,
                'regulator_name' => 'Law Society of Kenya (LSK)',
                'service_mode' => 'hybrid',
                'country' => 'Kenya',
                'city_county' => 'Nairobi',
                'area_neighborhood' => 'Westlands',
                'street_road' => 'Waiyaki Way, Westlands',
                'plus_code' => '6GCRPPP3+8V',
                'phone_primary' => '+254700111002',
                'whatsapp_number' => '+254700111002',
                'email_public' => 'partners@naserian-dummy.example',
                'website_url' => 'https://naserian-dummy.example',
                'operating_hours_json' => $standardHours,
            ],
            [
                'name' => '[DUMMY] BushCloud Analytics LLC',
                'tagline' => 'Diaspora Cloud & AI Engineering Studio (Pre-Prod Test)',
                'description' => 'Dummy diaspora online listing verifying United States -> State (Texas) administrative unit selection.',
                'school_badge' => 'AHS',
                'sector_id' => $techSec,
                'specialty_id' => $cloudSpec,
                'service_mode' => 'online',
                'country' => 'United States',
                'city_county' => 'Texas',
                'area_neighborhood' => 'Austin (Remote)',
                'phone_primary' => '+15125550199',
                'email_public' => 'hello@bushcloud-dummy.example',
                'website_url' => 'https://bushcloud-dummy.example',
                'operating_hours_json' => $standardHours,
            ],
        ];

        $pendingSamples = [
            [
                'biz' => [
                    'name' => '[DUMMY PENDING] Ridgeways Solar & Power',
                    'tagline' => 'Commercial C&I solar microgrids & battery backup (Pre-Prod Queue Test)',
                    'description' => 'Pending dummy submission for testing 1-click Admin Approve/Reject and immediate hard-deletion of alumni vetting data.',
                    'school_badge' => 'AHS',
                    'sector_id' => $energySec,
                    'specialty_id' => $solarSpec,
                    'service_mode' => 'physical',
                    'country' => 'Kenya',
                    'city_county' => 'Kiambu',
                    'area_neighborhood' => 'Ridgeways',
                    'street_road' => 'Kiambu Road, Ridgeways',
                    'latitude' => -1.2248,
                    'longitude' => 36.8425,
                    'phone_primary' => '+254700222001',
                    'whatsapp_number' => '+254700222001',
                    'email_public' => 'info@ridgewayssolar-dummy.example',
                    'website_url' => 'https://ridgewayssolar-dummy.example',
                    'operating_hours_json' => $standardHours,
                    'status' => 'pending',
                ],
                'vetting' => [
                    'school_code' => 'AHS',
                    'house_name' => 'Livingstone',
                    'year_left' => 2012,
                    'school_number' => '9412',
                    'submitter_name' => 'Kamau Test (Dummy)',
                    'submitter_email' => 'kamau.dummy@preprod.example',
                    'submitter_phone' => '+254700222001',
                ],
            ],
            [
                'biz' => [
                    'name' => '[DUMMY PENDING] Studio Mwangaza Ceramics',
                    'tagline' => 'Handcrafted Kenyan stoneware & gallery workshops (Pre-Prod Queue Test)',
                    'description' => 'Pending dummy AGHS Arts submission for testing submitter mailto/tel/WhatsApp contact links in the Admin Vetting Queue.',
                    'school_badge' => 'AGHS',
                    'sector_id' => $artsSec,
                    'specialty_id' => $visArtsSpec,
                    'service_mode' => 'physical',
                    'country' => 'Kenya',
                    'city_county' => 'Nairobi',
                    'area_neighborhood' => 'Lavington',
                    'street_road' => 'James Gichuru Road, Lavington',
                    'latitude' => -1.2804,
                    'longitude' => 36.7742,
                    'phone_primary' => '+254700222002',
                    'whatsapp_number' => '+254700222002',
                    'email_public' => 'hello@mwangaza-dummy.example',
                    'operating_hours_json' => $standardHours,
                    'status' => 'pending',
                ],
                'vetting' => [
                    'school_code' => 'AGHS',
                    'house_name' => 'Joan Mjomba',
                    'year_left' => 2016,
                    'school_number' => null,
                    'submitter_name' => 'Wanjiru Test (Dummy)',
                    'submitter_email' => 'wanjiru.dummy@preprod.example',
                    'submitter_phone' => '+254700222002',
                ],
            ],
        ];

        $approvedAdded = 0;
        $pendingAdded = 0;
        $skipped = 0;

        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE name = :nm");
        $insVet = $pdo->prepare("
            INSERT INTO business_vetting_queue (
                business_id, school_code, house_name, year_left,
                school_number, submitter_name, submitter_email, submitter_phone
            ) VALUES (
                :bid, :school, :house, :year,
                :snum, :sname, :semail, :sphone
            )
        ");

        foreach ($approvedSamples as $row) {
            $existsStmt->execute([':nm' => $row['name']]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                $skipped++;
                continue;
            }
            VettingPurger::createBusinessDirectly($pdo, $row);
            $approvedAdded++;
        }

        foreach ($pendingSamples as $item) {
            $existsStmt->execute([':nm' => $item['biz']['name']]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                $skipped++;
                continue;
            }
            $bizId = VettingPurger::createBusinessDirectly($pdo, $item['biz']);
            $v = $item['vetting'];
            $insVet->execute([
                ':bid' => $bizId,
                ':school' => $v['school_code'],
                ':house' => $v['house_name'],
                ':year' => $v['year_left'],
                ':snum' => $v['school_number'],
                ':sname' => $v['submitter_name'],
                ':semail' => $v['submitter_email'],
                ':sphone' => $v['submitter_phone'],
            ]);
            $bizRow = array_merge($item['biz'], ['id' => $bizId]);
            Mailer::notifyAdminNewSubmission($pdo, $bizRow, $v);
            $pendingAdded++;
        }

        return [
            'approved_added' => $approvedAdded,
            'pending_added' => $pendingAdded,
            'skipped' => $skipped,
        ];
    }

    /**
     * Wipe Pre-Prod SQLite database & dummy uploads and re-initialize clean 12-business baseline seed.
     */
    public static function resetDatabaseToCleanSeed(): array
    {
        self::assertSafePreprodSqlite();

        $sqliteRel = Database::env('DB_SQLITE_PATH', 'storage/preprod.sqlite');
        $sqlitePath = str_starts_with($sqliteRel, '/')
            ? $sqliteRel
            : dirname(__DIR__, 2) . '/' . $sqliteRel;

        Database::resetConnection();

        foreach ([$sqlitePath, $sqlitePath . '-wal', $sqlitePath . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // Clean any dummy uploaded images inside preprod's isolated uploads directory only
        $preprodWebRoot = Database::env('BBIZ_PUBLIC_WEB_ROOT', '');
        $removedUploads = 0;
        if ($preprodWebRoot !== '' && str_contains($preprodWebRoot, 'preprod') && is_dir($preprodWebRoot . '/uploads/businesses')) {
            foreach (glob($preprodWebRoot . '/uploads/businesses/bbiz_*') ?: [] as $imgFile) {
                if (is_file($imgFile) && @unlink($imgFile)) {
                    $removedUploads++;
                }
            }
        }

        $freshPdo = Database::getConnection();
        $approvedCount = (int)$freshPdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();

        return [
            'approved_count' => $approvedCount,
            'removed_uploads' => $removedUploads,
            'sqlite_path' => $sqlitePath,
        ];
    }

    /**
     * Read-only snapshot clone: SELECT approved listings from live Production MySQL into Pre-Prod SQLite.
     * Strictly never writes to Production MySQL and never touches business_vetting_queue.
     */
    public static function cloneApprovedFromProductionMysql(PDO $preprodPdo): array
    {
        self::assertSafePreprodSqlite();

        if ((self::$prodEnv['DB_DRIVER'] ?? '') !== 'mysql' || empty(self::$prodEnv['DB_NAME']) || empty(self::$prodEnv['DB_USER'])) {
            throw new RuntimeException('Production MySQL credentials not present in parent .env (available on deployed server).');
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql extension is not loaded.');
        }

        $host = self::$prodEnv['DB_HOST'] ?? 'localhost';
        $port = self::$prodEnv['DB_PORT'] ?? '3306';
        $db   = self::$prodEnv['DB_NAME'];
        $user = self::$prodEnv['DB_USER'];
        $pass = self::$prodEnv['DB_PASS'] ?? '';

        $prodPdo = null;
        $lastErr = null;
        foreach (array_values(array_unique([$host, 'localhost', '127.0.0.1'])) as $candHost) {
            try {
                $dsn = ($candHost === 'localhost')
                    ? "mysql:host=localhost;dbname={$db};charset=utf8mb4"
                    : "mysql:host={$candHost};port={$port};dbname={$db};charset=utf8mb4";
                $prodPdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                break;
            } catch (Throwable $e) {
                $lastErr = $e;
            }
        }

        if ($prodPdo === null) {
            throw new RuntimeException('Could not open read-only connection to production MySQL: ' . ($lastErr ? $lastErr->getMessage() : 'unknown error'));
        }

        $prodCategories = $prodPdo->query("SELECT * FROM categories ORDER BY parent_id IS NOT NULL ASC, id ASC")->fetchAll();
        $prodApproved = $prodPdo->query("SELECT * FROM businesses WHERE status = 'approved' ORDER BY id ASC")->fetchAll();
        $prodPdo = null; // Close production MySQL handle immediately

        // Discover valid columns in Pre-Prod SQLite businesses table
        $sqliteCols = [];
        foreach ($preprodPdo->query("PRAGMA table_info(businesses)")->fetchAll() as $col) {
            $sqliteCols[] = (string)$col['name'];
        }

        $preprodPdo->exec('PRAGMA foreign_keys = OFF;');
        $preprodPdo->beginTransaction();
        try {
            if (!empty($prodCategories)) {
                $preprodPdo->exec('DELETE FROM categories');
                $catStmt = $preprodPdo->prepare("
                    INSERT INTO categories (id, parent_id, name, slug, icon, synonyms, is_regulated, default_regulator, status, sort_order)
                    VALUES (:id, :parent_id, :name, :slug, :icon, :synonyms, :is_regulated, :default_regulator, :status, :sort_order)
                ");
                foreach ($prodCategories as $c) {
                    $catStmt->execute([
                        ':id' => (int)$c['id'],
                        ':parent_id' => $c['parent_id'] !== null ? (int)$c['parent_id'] : null,
                        ':name' => (string)$c['name'],
                        ':slug' => (string)$c['slug'],
                        ':icon' => $c['icon'] ?? null,
                        ':synonyms' => $c['synonyms'] ?? null,
                        ':is_regulated' => (int)($c['is_regulated'] ?? 0),
                        ':default_regulator' => $c['default_regulator'] ?? null,
                        ':status' => (string)($c['status'] ?? 'approved'),
                        ':sort_order' => (int)($c['sort_order'] ?? 0),
                    ]);
                }
            }

            $preprodPdo->exec('DELETE FROM business_vetting_queue');
            $preprodPdo->exec('DELETE FROM businesses');

            $copied = 0;
            foreach ($prodApproved as $b) {
                $filtered = [];
                foreach ($sqliteCols as $colName) {
                    if (array_key_exists($colName, $b)) {
                        $filtered[$colName] = $b[$colName];
                    }
                }
                if (empty($filtered)) {
                    continue;
                }
                $colsSql = implode(', ', array_keys($filtered));
                $placeholders = implode(', ', array_map(static fn(string $k): string => ':' . $k, array_keys($filtered)));
                $ins = $preprodPdo->prepare("INSERT INTO businesses ({$colsSql}) VALUES ({$placeholders})");
                $params = [];
                foreach ($filtered as $k => $v) {
                    $params[':' . $k] = $v;
                }
                $ins->execute($params);
                $copied++;
            }

            $preprodPdo->commit();
        } catch (Throwable $txErr) {
            if ($preprodPdo->inTransaction()) {
                $preprodPdo->rollBack();
            }
            $preprodPdo->exec('PRAGMA foreign_keys = ON;');
            throw $txErr;
        }
        $preprodPdo->exec('PRAGMA foreign_keys = ON;');

        return [
            'categories_cloned' => count($prodCategories),
            'businesses_cloned' => count($prodApproved),
        ];
    }

    /**
     * Run built-in non-destructive Pre-Prod verification checks right inside the browser or CLI.
     *
     * @return list<array{name:string,passed:bool,detail:string}>
     */
    public static function runSelfTests(PDO $pdo): array
    {
        $results = [];

        // 1. Database Isolation Check
        $driver = Database::getActiveDriver();
        $sqlitePath = Database::env('DB_SQLITE_PATH', '');
        $results[] = [
            'name' => 'Database Isolation (SQLite Only)',
            'passed' => ($driver === 'sqlite' && $sqlitePath !== ''),
            'detail' => "Active driver: {$driver}; path: {$sqlitePath}",
        ];

        // 2. Outbound SMTP Sandbox Check (Rule #7)
        $simEmails = strtolower(Database::env('SIMULATE_EMAILS', 'no'));
        $results[] = [
            'name' => 'Outbound Email Simulation (Zero Live SMTP)',
            'passed' => ($simEmails === 'yes'),
            'detail' => "SIMULATE_EMAILS={$simEmails}",
        ];

        // 3. DPA Vetting Column Separation
        $bizCols = array_map(
            static fn(array $r): string => (string)$r['name'],
            $pdo->query("PRAGMA table_info(businesses)")->fetchAll()
        );
        $forbidden = array_intersect(['house_name', 'year_left', 'school_number', 'admission_number'], $bizCols);
        $results[] = [
            'name' => 'Kenya DPA 2019 Vetting Column Isolation',
            'passed' => empty($forbidden),
            'detail' => 'businesses table contains 0 alumni vetting columns',
        ];

        // 4. Taxonomy & Swahili Synonym Expansion
        $sectorsCount = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE parent_id IS NULL AND status = 'approved'")->fetchColumn();
        $wakiliTerms = CategoryMatcher::expandSearchTerms('wakili');
        $results[] = [
            'name' => '2-Level Taxonomy & Swahili Synonym Search',
            'passed' => ($sectorsCount >= 15 && count($wakiliTerms) > 1),
            'detail' => "{$sectorsCount} Industry Sectors; 'wakili' expanded to " . count($wakiliTerms) . ' search terms',
        ];

        // 5. Anti-Spam 7-Layer Gate
        $tokens = AntiSpamGate::generateFormTokens(time() - 1); // < 4s submission
        $fastCheck = AntiSpamGate::verifySubmission($pdo, [
            'website' => '',
            'form_ts' => $tokens['form_ts'],
            'form_nonce' => $tokens['form_nonce'],
            'form_sig' => $tokens['form_sig'],
            'js_interaction_token' => 'focusin:1',
            'name' => 'Valid Business',
            'description' => 'Normal description',
        ], '127.0.0.1', 'preprod_selftest');
        $results[] = [
            'name' => '7-Layer Anti-Spam Speed-Trap (< 4s Rejection)',
            'passed' => (($fastCheck['passed'] ?? true) === false),
            'detail' => 'Rejected sub-4-second automated form post (' . ($fastCheck['reason'] ?? 'blocked') . ')',
        ];

        // 6. SSRF Guard Check
        $ssrfBlocked = false;
        try {
            WebsiteEnricher::validateSafeUrlAndResolveIp('http://169.254.169.254/latest/meta-data/');
        } catch (Throwable $e) {
            $ssrfBlocked = true;
        }
        $results[] = [
            'name' => 'SSRF Metadata & Loopback Protection',
            'passed' => $ssrfBlocked,
            'detail' => 'Blocked private/link-local metadata endpoint 169.254.169.254',
        ];

        // 7. Maps Link -> Latitude/Longitude Inference (#138)
        $samplePin = 'https://www.google.com/maps/place/Test/@-2.70,36.98,8z/data=!4m6!3m5!1s0x0:0x0!8m2!3d-1.3766863!4d36.9280114';
        $coords = LocationEngine::parseCoordinatesFromInput($samplePin);
        $pinOk = is_array($coords) && abs($coords['latitude'] - (-1.376686)) < 0.0001 && abs($coords['longitude'] - 36.928011) < 0.0001;
        $results[] = [
            'name' => 'Maps Link !3d!4d Coordinate Inference (#138)',
            'passed' => $pinOk,
            'detail' => $pinOk ? "Extracted ({$coords['latitude']}, {$coords['longitude']}) prioritizing !3d!4d over @viewport" : 'Failed extraction',
        ];

        // 8. Africa/Nairobi HoursEngine Deterministic Evaluation
        $hoursJson = (string)json_encode(['wed' => [['08:00', '17:00']]]);
        $wedOpen = new \DateTimeImmutable('2026-11-11 10:30:00', new \DateTimeZone('Africa/Nairobi'));
        $statusEval = HoursEngine::evaluateStatus($hoursJson, $wedOpen);
        $results[] = [
            'name' => 'Africa/Nairobi HoursEngine Status Evaluation',
            'passed' => (($statusEval['state'] ?? '') === 'OPEN_NOW'),
            'detail' => '10:30 EAT evaluated as ' . ($statusEval['state'] ?? 'UNKNOWN'),
        ];

        return $results;
    }
}
