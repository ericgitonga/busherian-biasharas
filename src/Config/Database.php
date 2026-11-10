<?php
declare(strict_types=1);

namespace BusherianBiasharas\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $envLoaded = false;
    private static string $activeDriver = 'sqlite';

    public static function loadEnv(?string $envPath = null): void
    {
        if (self::$envLoaded) {
            return;
        }
        $path = $envPath ?? dirname(__DIR__, 2) . '/.env';
        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
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
                    if (getenv($key) === false) {
                        putenv("{$key}={$val}");
                        $_ENV[$key] = $val;
                    }
                }
            }
        }
        self::$envLoaded = true;
    }

    public static function env(string $key, string $default = ''): string
    {
        self::loadEnv();
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }
        return $_ENV[$key] ?? $default;
    }

    /**
     * L1 Security Gate: Fail closed in production if cryptographic secrets are missing or left at placeholder defaults.
     */
    public static function validateProductionSecrets(): void
    {
        $appEnv = strtolower(self::env('APP_ENV', 'production'));
        $hmac = self::env('APP_HMAC_SECRET', '');
        $ipSalt = self::env('IP_HASH_SECRET', '');
        $csrf = self::env('CSRF_SECRET', '');

        $placeholders = [
            '',
            'replace_with_64_char_random_hex_secret_for_signed_tokens',
            'replace_with_64_char_random_hex_secret_for_dpa_ip_hashing',
            'replace_with_64_char_random_hex_secret_for_csrf_protection',
            'changeme',
            'default',
        ];

        if ($appEnv === 'production') {
            if (in_array($hmac, $placeholders, true) || strlen($hmac) < 32 ||
                in_array($ipSalt, $placeholders, true) || strlen($ipSalt) < 32 ||
                in_array($csrf, $placeholders, true) || strlen($csrf) < 32) {
                throw new RuntimeException('FATAL: Production cryptographic secrets (APP_HMAC_SECRET, IP_HASH_SECRET, CSRF_SECRET) must be configured with high-entropy values.');
            }
        }
    }

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::loadEnv();
        self::validateProductionSecrets();

        $driver = strtolower(self::env('DB_DRIVER', 'mysql'));
        $fallback = strtolower(self::env('DB_DRIVER_FALLBACK', 'sqlite'));
        $appEnv = strtolower(self::env('APP_ENV', 'development'));

        if ($driver === 'mysql') {
            $host = self::env('DB_HOST', '127.0.0.1');
            $port = self::env('DB_PORT', '3306');
            $db   = self::env('DB_NAME', 'busherian_biasharas');
            $user = self::env('DB_USER', 'db_user');
            $pass = self::env('DB_PASS', '');

            try {
                if (!extension_loaded('pdo_mysql')) {
                    throw new PDOException('pdo_mysql extension not loaded');
                }
                $hostsToTry = array_values(array_unique([$host, 'localhost', '127.0.0.1']));
                $lastErr = null;
                foreach ($hostsToTry as $candidateHost) {
                    try {
                        $dsn = ($candidateHost === 'localhost')
                            ? "mysql:host=localhost;dbname={$db};charset=utf8mb4"
                            : "mysql:host={$candidateHost};port={$port};dbname={$db};charset=utf8mb4";
                        self::$pdo = new PDO($dsn, $user, $pass, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]);
                        $lastErr = null;
                        break;
                    } catch (PDOException $connEx) {
                        $lastErr = $connEx;
                    }
                }
                if ($lastErr !== null || self::$pdo === null) {
                    throw $lastErr ?? new PDOException('MySQL connection failed');
                }
                self::$activeDriver = 'mysql';
                self::ensureInitialized(self::$pdo, 'mysql');
                return self::$pdo;
            } catch (PDOException $e) {
                if ($appEnv === 'production' && $fallback !== 'sqlite') {
                    throw new RuntimeException('Database connection failed.');
                }
                // Fall back to SQLite for local dev / CI testing when remote cPanel MySQL is unreachable
            }
        }

        $sqliteRel = self::env('DB_SQLITE_PATH', 'storage/busherian_biasharas.sqlite');
        $sqlitePath = str_starts_with($sqliteRel, '/')
            ? $sqliteRel
            : dirname(__DIR__, 2) . '/' . $sqliteRel;

        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        self::$pdo = new PDO("sqlite:{$sqlitePath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON;');
        self::$pdo->exec('PRAGMA journal_mode = WAL;');
        self::registerSqliteMathFunctions(self::$pdo);
        self::$activeDriver = 'sqlite';
        self::ensureInitialized(self::$pdo, 'sqlite');

        return self::$pdo;
    }

    public static function resetConnection(): void
    {
        self::$pdo = null;
        self::$envLoaded = false;
    }

    public static function getActiveDriver(): string
    {
        return self::$activeDriver;
    }

    private static function registerSqliteMathFunctions(PDO $pdo): void
    {
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('SIN', static fn($x) => sin((float)$x), 1);
            $pdo->sqliteCreateFunction('COS', static fn($x) => cos((float)$x), 1);
            $pdo->sqliteCreateFunction('ACOS', static fn($x) => acos(max(-1.0, min(1.0, (float)$x))), 1);
            $pdo->sqliteCreateFunction('RADIANS', static fn($x) => deg2rad((float)$x), 1);
        }
    }

    private static function ensureInitialized(PDO $pdo, string $driver): void
    {
        if ($driver === 'mysql') {
            $hasCat = $pdo->query("SHOW TABLES LIKE 'categories'")->fetchColumn();
            if (!$hasCat) {
                $schemaFile = dirname(__DIR__) . '/schema.sql';
                if (is_file($schemaFile)) {
                    $rawSql = (string)file_get_contents($schemaFile);
                    // Strip full-line SQL comments before splitting by ';'
                    $cleanLines = array_filter(
                        explode("\n", $rawSql),
                        static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
                    );
                    foreach (explode(';', implode("\n", $cleanLines)) as $stmtSql) {
                        $trimmed = trim($stmtSql);
                        if ($trimmed !== '') {
                            $pdo->exec($trimmed);
                        }
                    }
                }
            }
        } elseif ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    parent_id INTEGER NULL REFERENCES categories(id) ON DELETE CASCADE,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE,
                    icon TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'approved',
                    submitted_by_email TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS businesses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id TEXT NULL UNIQUE,
                    business_number INTEGER NOT NULL DEFAULT 1,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE,
                    tagline TEXT NOT NULL,
                    description TEXT NOT NULL,
                    school_badge TEXT NOT NULL DEFAULT 'AHS',
                    sector_id INTEGER NOT NULL REFERENCES categories(id),
                    specialty_id INTEGER NOT NULL REFERENCES categories(id),
                    service_mode TEXT NOT NULL DEFAULT 'physical',
                    alumni_perk TEXT NULL,
                    b2b_ready INTEGER NOT NULL DEFAULT 0,
                    accepts_lpo INTEGER NOT NULL DEFAULT 0,
                    regulator_name TEXT NULL,
                    regulator_license TEXT NULL,
                    branch_name TEXT NULL,
                    building_name TEXT NULL,
                    floor_suite TEXT NULL,
                    street_road TEXT NULL,
                    area_neighborhood TEXT NULL,
                    city_county TEXT NOT NULL DEFAULT 'Nairobi',
                    landmark_directions TEXT NULL,
                    plus_code TEXT NULL,
                    latitude REAL NULL,
                    longitude REAL NULL,
                    google_maps_url TEXT NULL,
                    operating_hours_json TEXT NOT NULL,
                    phone_primary TEXT NOT NULL,
                    whatsapp_number TEXT NULL,
                    email_public TEXT NULL,
                    website_url TEXT NULL,
                    social_instagram TEXT NULL,
                    social_linkedin TEXT NULL,
                    social_x TEXT NULL,
                    social_facebook TEXT NULL,
                    social_tiktok TEXT NULL,
                    social_youtube TEXT NULL,
                    logo_path TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    verified_at TEXT NULL,
                    last_confirmed_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS business_vetting_queue (
                    business_id INTEGER PRIMARY KEY REFERENCES businesses(id) ON DELETE CASCADE,
                    school_code TEXT NOT NULL,
                    house_name TEXT NOT NULL,
                    year_left INTEGER NOT NULL,
                    school_number TEXT NULL,
                    submitter_name TEXT NOT NULL,
                    submitter_email TEXT NOT NULL,
                    submitter_phone TEXT NOT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS used_nonces (
                    nonce_hash TEXT PRIMARY KEY,
                    expires_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_hmac TEXT NOT NULL,
                    action_type TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS email_outbox (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    recipient_email TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body_html TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'queued',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    sent_at TEXT NULL
                );
                CREATE TABLE IF NOT EXISTS click_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL,
                    event_type TEXT NOT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
            ");
        }

        // Ensure business_id and business_number columns exist on pre-existing databases
        self::ensureBusinessIdentityAndNumberColumns($pdo, $driver);

        // Seed categories and initial verified listings if empty
        $count = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        if ($count === 0) {
            \BusherianBiasharas\Services\CategoryMatcher::seedDefaultTaxonomy($pdo);
            \BusherianBiasharas\Services\CategoryMatcher::seedSampleBusinesses($pdo);
        }
    }

    private static function ensureBusinessIdentityAndNumberColumns(PDO $pdo, string $driver): void
    {
        $hasBizId = false;
        $hasBizNum = false;
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'business_id') $hasBizId = true;
                if (($col['name'] ?? '') === 'business_number') $hasBizNum = true;
            }
            if (!$hasBizId) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_id TEXT NULL");
            }
            if (!$hasBizNum) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_number INTEGER NOT NULL DEFAULT 1");
            }
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll();
            foreach ($cols as $col) {
                if (($col['Field'] ?? '') === 'business_id') $hasBizId = true;
                if (($col['Field'] ?? '') === 'business_number') $hasBizNum = true;
            }
            if (!$hasBizId) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_id VARCHAR(32) NULL UNIQUE AFTER id");
            }
            if (!$hasBizNum) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER business_id");
            }
        }

        if (!$hasBizId || !$hasBizNum) {
            \BusherianBiasharas\Services\VettingPurger::renumberBusinesses($pdo);
        }
    }
}
