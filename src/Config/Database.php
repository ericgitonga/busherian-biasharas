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
        $fallback = strtolower(self::env('DB_DRIVER_FALLBACK', 'none'));
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
                error_log(sprintf(
                    '[Database] MySQL connection failure (env=%s, host=%s, db=%s): %s',
                    $appEnv,
                    $host,
                    $db,
                    $e->getMessage()
                ));
                // Fail closed in production (never fall back to local SQLite regardless of DB_DRIVER_FALLBACK) (#169)
                if ($appEnv === 'production' || $fallback !== 'sqlite') {
                    throw new DatabaseUnavailableException('Service temporarily unavailable due to database maintenance.');
                }
                // Explicit non-production fallback (only when APP_ENV != production and DB_DRIVER_FALLBACK=sqlite)
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
                    country TEXT NOT NULL DEFAULT 'Kenya',
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
                    owner_email TEXT NULL,
                    website_url TEXT NULL,
                    social_instagram TEXT NULL,
                    social_linkedin TEXT NULL,
                    social_x TEXT NULL,
                    social_facebook TEXT NULL,
                    social_tiktok TEXT NULL,
                    social_youtube TEXT NULL,
                    logo_path TEXT NULL,
                    photos_json TEXT NULL,
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
                CREATE TABLE IF NOT EXISTS usage_metrics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_type TEXT NOT NULL,
                    visitor_hash TEXT NOT NULL,
                    page_path TEXT NULL,
                    business_id INTEGER NULL,
                    meta_label TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
            ");
        }

        // Ensure business_id and business_number columns exist on pre-existing databases
        self::ensureBusinessIdentityAndNumberColumns($pdo, $driver);
        self::ensureUsageMetricsTable($pdo, $driver);
        self::ensureOwnerTables($pdo, $driver);
        self::ensureOutboxAndTakedownTables($pdo, $driver);

        // Seed default taxonomy if empty; NEVER seed sample businesses in production (#169)
        $appEnv = strtolower(self::env('APP_ENV', 'development'));
        $count = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
        if ($count === 0) {
            \BusherianBiasharas\Services\CategoryMatcher::seedDefaultTaxonomy($pdo);
            if ($appEnv !== 'production' && self::env('BBIZ_SKIP_SAMPLE_SEED', '0') !== '1') {
                \BusherianBiasharas\Services\CategoryMatcher::seedSampleBusinesses($pdo);
            }
        } else {
            \BusherianBiasharas\Services\CategoryMatcher::ensureTaxonomyUpToDate($pdo);
        }

        // One-time idempotent security & privacy migrations (#164 corporate domain link audit, #167 outbox PII scrub)
        if (self::getSetting($pdo, 'purged_corp_domain_links_v1') !== 'done') {
            try {
                \BusherianBiasharas\Services\OwnerAuth::revokeUnverifiedCorporateDomainLinks($pdo);
                self::setSetting($pdo, 'purged_corp_domain_links_v1', 'done');
            } catch (\Throwable $migErr) {
                error_log('[Database::ensureInitialized] purged_corp_domain_links_v1 failed: ' . $migErr->getMessage());
            }
        }
        if (self::getSetting($pdo, 'scrubbed_outbox_vetting_pii_v1') !== 'done') {
            try {
                \BusherianBiasharas\Services\Mailer::scrubLegacyVettingPiiFromOutbox($pdo);
                self::setSetting($pdo, 'scrubbed_outbox_vetting_pii_v1', 'done');
            } catch (\Throwable $migErr) {
                error_log('[Database::ensureInitialized] scrubbed_outbox_vetting_pii_v1 failed: ' . $migErr->getMessage());
            }
        }

        // One-time idempotent backfill of missing latitude/longitude from listed map pins/links (#138, #141)
        $backfillState = self::getSetting($pdo, 'coords_pin_backfill_v1');
        if ($backfillState !== 'done') {
            $attempts = ctype_digit($backfillState) ? (int)$backfillState : 0;
            if ($attempts < 3) {
                try {
                    $allowExt = (getenv('BBIZ_OFFLINE_ROUTING') !== '1');
                    $bf = \BusherianBiasharas\Services\VettingPurger::backfillMissingCoordinatesFromPins($pdo, $allowExt);
                    if (($bf['network_errors'] ?? 0) === 0 && empty($bf['item_errors'])) {
                        self::setSetting($pdo, 'coords_pin_backfill_v1', 'done');
                    } else {
                        self::setSetting($pdo, 'coords_pin_backfill_v1', (string)($attempts + 1));
                    }
                } catch (\Throwable $bfErr) {
                    error_log('[Database::ensureInitialized] coords_pin_backfill_v1 failed: ' . $bfErr->getMessage());
                }
            }
        }
    }

    private static function ensureBusinessIdentityAndNumberColumns(PDO $pdo, string $driver): void
    {
        $hasBizId = false;
        $hasBizNum = false;
        $hasCountry = false;
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'business_id') $hasBizId = true;
                if (($col['name'] ?? '') === 'business_number') $hasBizNum = true;
                if (($col['name'] ?? '') === 'country') $hasCountry = true;
            }
            if (!$hasBizId) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_id TEXT NULL");
            }
            if (!$hasBizNum) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_number INTEGER NOT NULL DEFAULT 1");
            }
            if (!$hasCountry) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN country TEXT NOT NULL DEFAULT 'Kenya'");
            }
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll();
            foreach ($cols as $col) {
                if (($col['Field'] ?? '') === 'business_id') $hasBizId = true;
                if (($col['Field'] ?? '') === 'business_number') $hasBizNum = true;
                if (($col['Field'] ?? '') === 'country') $hasCountry = true;
            }
            if (!$hasBizId) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_id VARCHAR(32) NULL UNIQUE AFTER id");
            }
            if (!$hasBizNum) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN business_number INT UNSIGNED NOT NULL DEFAULT 1 AFTER business_id");
            }
            if (!$hasCountry) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN country VARCHAR(80) NOT NULL DEFAULT 'Kenya' AFTER area_neighborhood");
            }
        }

        if (!$hasBizId || !$hasBizNum) {
            \BusherianBiasharas\Services\VettingPurger::renumberBusinesses($pdo);
        }
    }

    private static function ensureUsageMetricsTable(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usage_metrics (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    event_type TEXT NOT NULL,
                    visitor_hash TEXT NOT NULL,
                    page_path TEXT NULL,
                    business_id INTEGER NULL,
                    meta_label TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS usage_metrics (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    event_type VARCHAR(40) NOT NULL,
                    visitor_hash CHAR(64) NOT NULL,
                    page_path VARCHAR(160) NULL,
                    business_id INT UNSIGNED NULL,
                    meta_label VARCHAR(120) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_metric_type_time (event_type, created_at),
                    INDEX idx_metric_visitor (visitor_hash, created_at),
                    INDEX idx_metric_biz (business_id, event_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public static function getSetting(PDO $pdo, string $key, string $default = ''): string
    {
        $envVal = self::env($key, '');
        if ($envVal !== '') {
            return $envVal;
        }
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
            $stmt->execute([':k' => $key]);
            $val = $stmt->fetchColumn();
            if ($val !== false && trim((string)$val) !== '') {
                return trim((string)$val);
            }
        } catch (\Throwable $e) {
        }
        return $default;
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void
    {
        $del = $pdo->prepare("DELETE FROM app_settings WHERE setting_key = :k");
        $del->execute([':k' => $key]);
        if (trim($value) !== '') {
            $ins = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)");
            $ins->execute([':k' => $key, ':v' => trim($value)]);
        }
    }

    private static function ensureOwnerTables(PDO $pdo, string $driver): void
    {
        $hasOwnerEmail = false;
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'owner_email') {
                    $hasOwnerEmail = true;
                }
            }
            $hasPhotosJson = false;
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'photos_json') {
                    $hasPhotosJson = true;
                }
            }
            if (!$hasOwnerEmail) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN owner_email TEXT NULL");
            }
            if (!$hasPhotosJson) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN photos_json TEXT NULL");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS business_owners (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL UNIQUE,
                    full_name TEXT NULL,
                    phone TEXT NULL,
                    google_sub TEXT NULL,
                    password_hash TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    last_login_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS business_owner_links (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    owner_id INTEGER NOT NULL REFERENCES business_owners(id) ON DELETE CASCADE,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    linked_via TEXT NOT NULL DEFAULT 'auto_match',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(owner_id, business_id)
                );
                CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key TEXT PRIMARY KEY,
                    setting_value TEXT NOT NULL,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS owner_auth_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL,
                    token_hash TEXT NOT NULL UNIQUE,
                    claim_business_id INTEGER NULL,
                    expires_at INTEGER NOT NULL,
                    used_at INTEGER NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
            ");
            $ownerCols = $pdo->query("PRAGMA table_info(business_owners)")->fetchAll();
            $hasPwHash = false;
            $hasAuthVer = false;
            foreach ($ownerCols as $oc) {
                if (($oc['name'] ?? '') === 'password_hash') {
                    $hasPwHash = true;
                }
                if (($oc['name'] ?? '') === 'auth_version') {
                    $hasAuthVer = true;
                }
            }
            if (!$hasPwHash) {
                $pdo->exec("ALTER TABLE business_owners ADD COLUMN password_hash TEXT NULL");
            }
            if (!$hasAuthVer) {
                $pdo->exec("ALTER TABLE business_owners ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS owner_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    owner_id INTEGER NOT NULL REFERENCES business_owners(id) ON DELETE CASCADE,
                    selector TEXT NOT NULL UNIQUE,
                    validator_hash TEXT NOT NULL,
                    auth_version INTEGER NOT NULL DEFAULT 1,
                    ip_hmac TEXT NULL,
                    user_agent_summary TEXT NULL,
                    expires_at TEXT NOT NULL,
                    revoked_at TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_owner_sess_owner ON owner_sessions (owner_id, revoked_at);
                CREATE INDEX IF NOT EXISTS idx_owner_sess_exp ON owner_sessions (expires_at);
            ");
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll();
            foreach ($cols as $col) {
                if (($col['Field'] ?? '') === 'owner_email') {
                    $hasOwnerEmail = true;
                }
            }
            $hasPhotosJson = false;
            foreach ($cols as $col) {
                if (($col['Field'] ?? '') === 'photos_json') {
                    $hasPhotosJson = true;
                }
            }
            if (!$hasOwnerEmail) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN owner_email VARCHAR(190) NULL AFTER email_public");
            }
            if (!$hasPhotosJson) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN photos_json TEXT NULL AFTER logo_path");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS business_owners (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    full_name VARCHAR(150) NULL,
                    phone VARCHAR(40) NULL,
                    google_sub VARCHAR(120) NULL,
                    password_hash VARCHAR(255) NULL,
                    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_owner_google (google_sub)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $ownerColsMysql = $pdo->query("SHOW COLUMNS FROM business_owners")->fetchAll();
            $hasPwHashMysql = false;
            $hasAuthVerMysql = false;
            foreach ($ownerColsMysql as $ocm) {
                if (($ocm['Field'] ?? '') === 'password_hash') {
                    $hasPwHashMysql = true;
                }
                if (($ocm['Field'] ?? '') === 'auth_version') {
                    $hasAuthVerMysql = true;
                }
            }
            if (!$hasPwHashMysql) {
                $pdo->exec("ALTER TABLE business_owners ADD COLUMN password_hash VARCHAR(255) NULL AFTER google_sub");
            }
            if (!$hasAuthVerMysql) {
                $pdo->exec("ALTER TABLE business_owners ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash");
            }
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS owner_sessions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT UNSIGNED NOT NULL,
                    selector VARCHAR(32) NOT NULL UNIQUE,
                    validator_hash CHAR(64) NOT NULL,
                    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
                    ip_hmac CHAR(64) NULL,
                    user_agent_summary VARCHAR(255) NULL,
                    expires_at DATETIME NOT NULL,
                    revoked_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_owner_sess_owner (owner_id, revoked_at),
                    INDEX idx_owner_sess_exp (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS business_owner_links (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT UNSIGNED NOT NULL,
                    business_id INT UNSIGNED NOT NULL,
                    linked_via VARCHAR(40) NOT NULL DEFAULT 'auto_match',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_owner_business (owner_id, business_id),
                    INDEX idx_link_business (business_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS app_settings (
                    setting_key VARCHAR(80) PRIMARY KEY,
                    setting_value TEXT NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS owner_auth_tokens (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(190) NOT NULL,
                    token_hash CHAR(64) NOT NULL UNIQUE,
                    claim_business_id INT UNSIGNED NULL,
                    expires_at INT UNSIGNED NOT NULL,
                    used_at INT UNSIGNED NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    private static function ensureOutboxAndTakedownTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $bizCols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
            $hasPrevStatus = false;
            foreach ($bizCols as $c) {
                if (($c['name'] ?? '') === 'quarantine_prev_status') {
                    $hasPrevStatus = true;
                }
            }
            if (!$hasPrevStatus) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN quarantine_prev_status TEXT NULL");
            }

            $outboxCols = $pdo->query("PRAGMA table_info(email_outbox)")->fetchAll();
            $hasOutboxBizId = false;
            foreach ($outboxCols as $oc) {
                if (($oc['name'] ?? '') === 'business_id') {
                    $hasOutboxBizId = true;
                }
            }
            if (!$hasOutboxBizId) {
                $pdo->exec("ALTER TABLE email_outbox ADD COLUMN business_id INTEGER NULL");
            }
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_outbox_business ON email_outbox (business_id)");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS takedown_requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    business_id INTEGER NOT NULL REFERENCES businesses(id) ON DELETE CASCADE,
                    requester_name TEXT NOT NULL,
                    requester_email TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    verification_token_hash TEXT NULL,
                    token_expires_at TEXT NULL,
                    verified_at TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'pending_review',
                    ip_hmac TEXT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    resolved_at TEXT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_takedown_biz_status ON takedown_requests (business_id, status);
                CREATE INDEX IF NOT EXISTS idx_takedown_token ON takedown_requests (verification_token_hash);
            ");
        } else {
            $bizCols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll();
            $hasPrevStatus = false;
            foreach ($bizCols as $c) {
                if (($c['Field'] ?? '') === 'quarantine_prev_status') {
                    $hasPrevStatus = true;
                }
            }
            if (!$hasPrevStatus) {
                $pdo->exec("ALTER TABLE businesses ADD COLUMN quarantine_prev_status VARCHAR(24) NULL AFTER status");
            }

            $outboxCols = $pdo->query("SHOW COLUMNS FROM email_outbox")->fetchAll();
            $hasOutboxBizId = false;
            foreach ($outboxCols as $oc) {
                if (($oc['Field'] ?? '') === 'business_id') {
                    $hasOutboxBizId = true;
                }
            }
            if (!$hasOutboxBizId) {
                $pdo->exec("ALTER TABLE email_outbox ADD COLUMN business_id INT UNSIGNED NULL AFTER id, ADD INDEX idx_outbox_business (business_id)");
            }

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS takedown_requests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    business_id INT UNSIGNED NOT NULL,
                    requester_name VARCHAR(140) NOT NULL,
                    requester_email VARCHAR(190) NOT NULL,
                    reason TEXT NOT NULL,
                    verification_token_hash CHAR(64) NULL,
                    token_expires_at DATETIME NULL,
                    verified_at DATETIME NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending_review',
                    ip_hmac CHAR(64) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL,
                    INDEX idx_takedown_biz_status (business_id, status),
                    INDEX idx_takedown_token (verification_token_hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
}
