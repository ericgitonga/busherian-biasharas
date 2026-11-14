<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

/**
 * Chapter 3 (Chunk 10, #304..#307): Cookie-Free, Sybil-Resistant Engagement Recorder & Time-Decayed Score Engine.
 *
 * Score Formula:
 *   S(b, t) = 0.60 * DecayedPoints_14d(b)
 *           + 0.30 * DecayedPoints_90d(b)
 *           + 0.10 * ln(1 + LifetimeRefs(b))
 *           + ColdStartBoost_14d(b)
 *
 * Privacy & Kenya DPA 2019 Guarantees:
 * - Zero visitor cookies, zero cross-day visitor profiles, and zero third-party network calls.
 * - 24-hour anti-spam deduplication uses a daily-rotating keyed HMAC:
 *   HMAC-SHA256(UTC_DATE | IP_HMAC | UserAgent_Class | business_id | signal_group, IP_HASH_SECRET)
 *   stored solely in `engagement_dedup_24h` with a strict 24-hour TTL (`expires_at`).
 */
final class EngagementRanker
{
    public const HALF_LIFE_SHORT_DAYS = 14.0;
    public const HALF_LIFE_MEDIUM_DAYS = 90.0;
    public const COLD_START_WINDOW_DAYS = 14.0;
    public const COLD_START_MAX_BOOST = 3.5;

    public const TIE_ROTATION_BUCKET_SECONDS = 7200;

    // Chunk 12 (#315): Dignified momentum badges (boolean cue only, zero public counters)
    public const POPULAR_TOP_FRACTION = 0.10;
    public const POPULAR_MIN_REFS_90D = 3;
    public const NEW_BADGE_WINDOW_DAYS = 14;
    public const NEW_BADGE_MAX_SHARE = 0.30;

    /**
     * Owner-facing labels for private engagement insights (#316).
     *
     * @var array<string, string>
     */
    public const OWNER_INSIGHT_LABELS = [
        'profile_checkout' => 'Profile Checkouts',
        'direct_contact' => 'Direct Contacts (Call, WhatsApp, Email, Booking)',
        'directions_ref' => 'Direction Requests',
        'external_link' => 'Website & Social Visits',
        'concierge_ref' => 'Concierge Recommendations',
    ];
    public const TIE_ROTATION_PRIME = 65521;

    /**
     * Canonical directory sort modes (#309, #310).
     *
     * @var array<int, string>
     */
    public const ALLOWED_SORT_MODES = [
        'most_referenced',
        'trending',
        'newest',
        'nearest',
        'az',
        'directory_number',
    ];

    public const WEIGHT_PROFILE_CHECKOUT = 1.0;
    public const WEIGHT_EXTERNAL_LINK = 2.0;
    public const WEIGHT_DIRECTIONS_REF = 2.5;
    public const WEIGHT_CONCIERGE_REF = 3.0;
    public const WEIGHT_DIRECT_CONTACT = 4.0;

    /**
     * Canonical signal-group weights.
     *
     * @var array<string, float>
     */
    public const SIGNAL_GROUP_WEIGHTS = [
        'profile_checkout' => self::WEIGHT_PROFILE_CHECKOUT,
        'external_link' => self::WEIGHT_EXTERNAL_LINK,
        'directions_ref' => self::WEIGHT_DIRECTIONS_REF,
        'concierge_ref' => self::WEIGHT_CONCIERGE_REF,
        'direct_contact' => self::WEIGHT_DIRECT_CONTACT,
    ];

    /**
     * Map raw client/API signal_type or legacy event_type to [event_type, signal_group, weight].
     *
     * @return array{event_type:string, signal_group:string, weight:float}|null
     */
    public static function resolveSignal(string $rawSignal): ?array
    {
        $norm = strtolower(trim($rawSignal));
        return match ($norm) {
            'profile_checkout', 'modal_open', 'checkout' => [
                'event_type' => 'profile_checkout',
                'signal_group' => 'profile_checkout',
                'weight' => self::WEIGHT_PROFILE_CHECKOUT,
            ],
            'external_link', 'website' => [
                'event_type' => 'website',
                'signal_group' => 'external_link',
                'weight' => self::WEIGHT_EXTERNAL_LINK,
            ],
            'social' => [
                'event_type' => 'social',
                'signal_group' => 'external_link',
                'weight' => self::WEIGHT_EXTERNAL_LINK,
            ],
            'directions_ref', 'directions' => [
                'event_type' => 'directions',
                'signal_group' => 'directions_ref',
                'weight' => self::WEIGHT_DIRECTIONS_REF,
            ],
            'map_pin' => [
                'event_type' => 'map_pin',
                'signal_group' => 'directions_ref',
                'weight' => self::WEIGHT_DIRECTIONS_REF,
            ],
            'plus_code' => [
                'event_type' => 'plus_code',
                'signal_group' => 'directions_ref',
                'weight' => self::WEIGHT_DIRECTIONS_REF,
            ],
            'concierge_ref', 'concierge' => [
                'event_type' => 'concierge',
                'signal_group' => 'concierge_ref',
                'weight' => self::WEIGHT_CONCIERGE_REF,
            ],
            'direct_contact', 'call' => [
                'event_type' => 'call',
                'signal_group' => 'direct_contact',
                'weight' => self::WEIGHT_DIRECT_CONTACT,
            ],
            'whatsapp' => [
                'event_type' => 'whatsapp',
                'signal_group' => 'direct_contact',
                'weight' => self::WEIGHT_DIRECT_CONTACT,
            ],
            'email' => [
                'event_type' => 'email',
                'signal_group' => 'direct_contact',
                'weight' => self::WEIGHT_DIRECT_CONTACT,
            ],
            'booking' => [
                'event_type' => 'booking',
                'signal_group' => 'direct_contact',
                'weight' => self::WEIGHT_DIRECT_CONTACT,
            ],
            'share' => [
                'event_type' => 'share',
                'signal_group' => 'direct_contact',
                'weight' => self::WEIGHT_DIRECT_CONTACT,
            ],
            default => null,
        };
    }

    /**
     * Bucket User-Agent into coarse, non-fingerprinting device classes.
     */
    public static function normalizeUserAgentClass(string $userAgent): string
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return 'other';
        }
        if (preg_match('/\b(?:ipad|tablet|playbook|silk)\b|(?:android(?!.*mobile))/i', $ua) === 1) {
            return 'tablet';
        }
        if (preg_match('/\b(?:mobi|iphone|ipod|android|windows phone|opera mini)\b/i', $ua) === 1) {
            return 'mobile';
        }
        if (preg_match('/\b(?:mozilla|chrome|safari|firefox|edg|x11|macintosh|windows nt|busheriane2e)\b/i', $ua) === 1) {
            return 'desktop';
        }
        return 'other';
    }

    public static function isAutomatedCliScraperUserAgent(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return false;
        }
        return preg_match('/\b(?:curl|wget|python-requests|python-urllib\/[0-9]|scrapy|sqlmap|nikto|masscan|go-http-client|apache-httpclient)\b/i', $ua) === 1;
    }

    public static function isValidInteractionToken(string $token, ?int $nowTs = null): bool
    {
        $clean = trim($token);
        if (!str_starts_with($clean, 'human_verified_')) {
            return false;
        }
        $suffix = substr($clean, strlen('human_verified_'));
        if ($suffix === '' || preg_match('/^[a-zA-Z0-9_\-]{3,64}$/', $suffix) !== 1) {
            return false;
        }
        if (ctype_digit($suffix) && strlen($suffix) >= 12) {
            $nowMs = ($nowTs ?? time()) * 1000;
            $tokMs = (int)$suffix;
            if ($tokMs > ($nowMs + 300_000) || $tokMs < ($nowMs - 86_400_000)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compute the daily-rotating 24h anti-spam deduplication HMAC without storing plaintext IP or visitor cookies.
     */
    public static function computeDedupHash(
        string $clientIp,
        string $userAgent,
        int $businessId,
        string $signalGroup,
        ?int $nowTs = null
    ): string {
        $ts = $nowTs ?? time();
        $utcDate = gmdate('Y-m-d', $ts);
        $ipHmac = AntiSpamGate::pseudonymizeIp($clientIp);
        $uaClass = self::normalizeUserAgentClass($userAgent);
        $secret = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        return hash_hmac('sha256', "{$utcDate}|{$ipHmac}|{$uaClass}|{$businessId}|{$signalGroup}", $secret);
    }

    /**
     * Resolve whether the current HTTP request belongs to an authenticated Owner or Admin
     * WITHOUT starting a PHP session or emitting any Set-Cookie header for anonymous visitors.
     *
     * @return array{owner_id:?int, is_admin:bool}
     */
    public static function resolveViewerWithoutStartingAnonymousSession(PDO $pdo): array
    {
        $hasPhpSessionCookie = !empty($_COOKIE['PHPSESSID'])
            || !empty($_COOKIE['BBIZ_PREPROD_SESS'])
            || (session_status() === PHP_SESSION_ACTIVE);
        $hasOwnerCookie = !empty($_COOKIE['bbiz_owner_session'])
            || !empty($_COOKIE['bbiz_preprod_owner_session']);
        $hasAdminHeader = trim((string)($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')) !== '';

        $ownerId = null;
        if ($hasPhpSessionCookie || $hasOwnerCookie) {
            $owner = OwnerAuth::getCurrentOwner($pdo);
            if (is_array($owner) && isset($owner['id'])) {
                $ownerId = (int)$owner['id'];
            }
        }

        $isAdmin = false;
        if ($hasPhpSessionCookie || $hasAdminHeader) {
            $isAdmin = AdminAuth::isAuthenticated($pdo);
        }

        return [
            'owner_id' => $ownerId,
            'is_admin' => $isAdmin,
        ];
    }

    public static function purgeExpiredDeduplicationHashes(PDO $pdo, ?int $nowTs = null): int
    {
        $ts = $nowTs ?? time();
        $stmt = $pdo->prepare("DELETE FROM engagement_dedup_24h WHERE expires_at <= :now");
        $stmt->execute([':now' => $ts]);
        return $stmt->rowCount();
    }

    /**
     * Record a sybil-resistant engagement checkout and immediately refresh the target listing's decayed score.
     *
     * @return array{
     *   status:string,
     *   recorded:bool,
     *   deduplicated:bool,
     *   reason?:string,
     *   signal_group?:string,
     *   signal_weight?:float,
     *   engagement_score?:float,
     *   reference_count_90d?:int
     * }
     */
    public static function recordCheckout(
        PDO $pdo,
        int $businessId,
        string $signalType,
        string $clientIp,
        string $userAgent,
        string $interactionToken,
        ?int $currentOwnerId = null,
        bool $isAdmin = false,
        ?int $nowTs = null
    ): array {
        $ts = $nowTs ?? time();
        if ($businessId <= 0) {
            return ['status' => 'error', 'recorded' => false, 'deduplicated' => false, 'reason' => 'invalid_business_id'];
        }

        $resolved = self::resolveSignal($signalType);
        if ($resolved === null) {
            return ['status' => 'error', 'recorded' => false, 'deduplicated' => false, 'reason' => 'invalid_signal_type'];
        }

        if (self::isAutomatedCliScraperUserAgent($userAgent)) {
            return ['status' => 'ignored', 'recorded' => false, 'deduplicated' => false, 'reason' => 'automated_scraper_ua'];
        }

        if (!self::isValidInteractionToken($interactionToken, $ts)) {
            return ['status' => 'ignored', 'recorded' => false, 'deduplicated' => false, 'reason' => 'missing_interaction_proof'];
        }

        if ($isAdmin) {
            return ['status' => 'ignored', 'recorded' => false, 'deduplicated' => false, 'reason' => 'excluded_admin_session'];
        }

        if ($currentOwnerId !== null && $currentOwnerId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, $currentOwnerId, $businessId)) {
            return ['status' => 'ignored', 'recorded' => false, 'deduplicated' => false, 'reason' => 'excluded_owner_self_reference'];
        }

        $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'record_checkout', 60, 250, 1200);
        if (!$rate['allowed']) {
            return ['status' => 'ok', 'recorded' => false, 'deduplicated' => true, 'reason' => 'rate_limited'];
        }

        $bizStmt = $pdo->prepare("SELECT id, status, engagement_score, reference_count_90d FROM businesses WHERE id = :id LIMIT 1");
        $bizStmt->execute([':id' => $businessId]);
        $biz = $bizStmt->fetch();
        if (!$biz || (string)($biz['status'] ?? '') !== 'approved') {
            return ['status' => 'ignored', 'recorded' => false, 'deduplicated' => false, 'reason' => 'business_not_approved'];
        }

        $signalGroup = $resolved['signal_group'];
        $signalWeight = $resolved['weight'];
        $eventType = $resolved['event_type'];

        self::purgeExpiredDeduplicationHashes($pdo, $ts);
        $dedupHash = self::computeDedupHash($clientIp, $userAgent, $businessId, $signalGroup, $ts);
        $expiresAt = $ts + 86400;

        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $dedupSql = ($driver === 'sqlite')
            ? "INSERT OR IGNORE INTO engagement_dedup_24h (dedup_hash, expires_at) VALUES (:h, :exp)"
            : "INSERT IGNORE INTO engagement_dedup_24h (dedup_hash, expires_at) VALUES (:h, :exp)";

        $alreadyInTx = $pdo->inTransaction();
        if (!$alreadyInTx) {
            $pdo->beginTransaction();
        }
        try {
            $dedupStmt = $pdo->prepare($dedupSql);
            $dedupStmt->execute([
                ':h' => $dedupHash,
                ':exp' => $expiresAt,
            ]);

            if ($dedupStmt->rowCount() !== 1) {
                if (!$alreadyInTx) {
                    $pdo->commit();
                }
                return [
                    'status' => 'ok',
                    'recorded' => false,
                    'deduplicated' => true,
                    'signal_group' => $signalGroup,
                    'signal_weight' => $signalWeight,
                    'engagement_score' => round((float)($biz['engagement_score'] ?? 0.0), 4),
                    'reference_count_90d' => (int)($biz['reference_count_90d'] ?? 0),
                ];
            }

            $nowUtc = gmdate('Y-m-d H:i:s', $ts);
            $insClick = $pdo->prepare("
                INSERT INTO click_events (business_id, event_type, signal_group, signal_weight, created_at)
                VALUES (:bid, :ev, :sg, :sw, :cat)
            ");
            $insClick->execute([
                ':bid' => $businessId,
                ':ev' => $eventType,
                ':sg' => $signalGroup,
                ':sw' => $signalWeight,
                ':cat' => $nowUtc,
            ]);

            $recomp = self::recomputeBusinessScore($pdo, $businessId, $ts);

            if (!$alreadyInTx) {
                $pdo->commit();
            }

            return [
                'status' => 'ok',
                'recorded' => true,
                'deduplicated' => false,
                'signal_group' => $signalGroup,
                'signal_weight' => $signalWeight,
                'engagement_score' => $recomp['engagement_score'],
                'reference_count_90d' => $recomp['reference_count_90d'],
            ];
        } catch (\Throwable $e) {
            if (!$alreadyInTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Parse a DB timestamp string into a UTC Unix timestamp safely (immune to PHP default EAT timezone).
     */
    public static function parseUtcTimestamp(?string $raw, int $fallbackTs): int
    {
        $s = trim((string)($raw ?? ''));
        if ($s === '') {
            return $fallbackTs;
        }
        if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $s) === 1) {
            $parsed = strtotime($s);
        } else {
            $parsed = strtotime($s . ' UTC');
        }
        return ($parsed !== false && $parsed > 0) ? $parsed : $fallbackTs;
    }

    /**
     * Compute the 14-day linear cold-start newcomer boost from `verified_at` (falling back to `created_at`).
     */
    public static function computeColdStartBoost(?string $verifiedAt, ?string $createdAt, int $nowTs): float
    {
        $anchorRaw = trim((string)($verifiedAt ?? '')) !== '' ? $verifiedAt : $createdAt;
        if ($anchorRaw === null || trim($anchorRaw) === '') {
            return 0.0;
        }
        $anchorTs = self::parseUtcTimestamp($anchorRaw, $nowTs);
        $ageDays = max(0.0, ($nowTs - $anchorTs) / 86400.0);
        if ($ageDays >= self::COLD_START_WINDOW_DAYS) {
            return 0.0;
        }
        return round(self::COLD_START_MAX_BOOST * (1.0 - ($ageDays / self::COLD_START_WINDOW_DAYS)), 6);
    }

    /**
     * Recompute and persist `engagement_score` and `reference_count_90d` for a single business.
     *
     * @return array{business_id:int, engagement_score:float, reference_count_90d:int, lifetime_refs:int, cold_start_boost:float}
     */
    public static function recomputeBusinessScore(PDO $pdo, int $businessId, ?int $nowTs = null): array
    {
        $ts = $nowTs ?? time();
        $bStmt = $pdo->prepare("SELECT id, status, verified_at, created_at FROM businesses WHERE id = :id LIMIT 1");
        $bStmt->execute([':id' => $businessId]);
        $biz = $bStmt->fetch();
        if (!$biz) {
            return [
                'business_id' => $businessId,
                'engagement_score' => 0.0,
                'reference_count_90d' => 0,
                'lifetime_refs' => 0,
                'cold_start_boost' => 0.0,
            ];
        }

        if ((string)($biz['status'] ?? '') !== 'approved') {
            $pdo->prepare("
                UPDATE businesses
                SET engagement_score = 0,
                    reference_count_90d = 0,
                    updated_at = updated_at
                WHERE id = :id
            ")->execute([':id' => $businessId]);
            return [
                'business_id' => $businessId,
                'engagement_score' => 0.0,
                'reference_count_90d' => 0,
                'lifetime_refs' => 0,
                'cold_start_boost' => 0.0,
            ];
        }

        $evStmt = $pdo->prepare("
            SELECT event_type, signal_weight, created_at
            FROM click_events
            WHERE business_id = :bid
              AND event_type != 'shortlist'
        ");
        $evStmt->execute([':bid' => $businessId]);
        $events = $evStmt->fetchAll();

        $decayed14 = 0.0;
        $decayed90 = 0.0;
        $refs90d = 0;
        $lifetimeRefs = 0;

        foreach ($events as $ev) {
            $lifetimeRefs++;
            $evTs = self::parseUtcTimestamp(isset($ev['created_at']) ? (string)$ev['created_at'] : null, $ts);
            $ageDays = max(0.0, ($ts - $evTs) / 86400.0);
            if ($ageDays <= 90.0) {
                $refs90d++;
            }
            $rawW = isset($ev['signal_weight']) ? (float)$ev['signal_weight'] : 0.0;
            if ($rawW <= 0.0) {
                $fallback = self::resolveSignal((string)($ev['event_type'] ?? ''));
                $rawW = $fallback !== null ? $fallback['weight'] : 1.0;
            }
            if ($ageDays <= 180.0) {
                $decayed14 += $rawW * exp(-M_LN2 * ($ageDays / self::HALF_LIFE_SHORT_DAYS));
                $decayed90 += $rawW * exp(-M_LN2 * ($ageDays / self::HALF_LIFE_MEDIUM_DAYS));
            }
        }

        $coldStartBoost = self::computeColdStartBoost(
            isset($biz['verified_at']) ? (string)$biz['verified_at'] : null,
            isset($biz['created_at']) ? (string)$biz['created_at'] : null,
            $ts
        );

        $rawScore = (0.60 * $decayed14)
            + (0.30 * $decayed90)
            + (0.10 * log(1.0 + $lifetimeRefs))
            + $coldStartBoost;

        $finalScore = round(max(0.0, $rawScore), 4);

        $pdo->prepare("
            UPDATE businesses
            SET engagement_score = :sc,
                reference_count_90d = :r90,
                updated_at = updated_at
            WHERE id = :id
        ")->execute([
            ':sc' => $finalScore,
            ':r90' => $refs90d,
            ':id' => $businessId,
        ]);

        return [
            'business_id' => $businessId,
            'engagement_score' => $finalScore,
            'reference_count_90d' => $refs90d,
            'lifetime_refs' => $lifetimeRefs,
            'cold_start_boost' => round($coldStartBoost, 4),
        ];
    }

    /**
     * Recompute time-decayed engagement scores for all businesses in the directory.
     *
     * @return array{updated_approved_count:int, zeroed_non_approved_count:int, computed_at_utc:string}
     */
    public static function recomputeAllApprovedScores(PDO $pdo, ?int $nowTs = null): array
    {
        $ts = $nowTs ?? time();

        $zeroStmt = $pdo->prepare("
            UPDATE businesses
            SET engagement_score = 0,
                reference_count_90d = 0,
                updated_at = updated_at
            WHERE status != 'approved'
              AND (engagement_score != 0 OR reference_count_90d != 0)
        ");
        $zeroStmt->execute();
        $zeroed = $zeroStmt->rowCount();

        $ids = $pdo->query("SELECT id FROM businesses WHERE status = 'approved' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $updated = 0;
        foreach ($ids as $rawId) {
            self::recomputeBusinessScore($pdo, (int)$rawId, $ts);
            $updated++;
        }

        $computedUtc = gmdate('Y-m-d H:i:s', $ts);
        Database::setSetting($pdo, 'last_engagement_recompute_utc', $computedUtc);

        return [
            'updated_approved_count' => $updated,
            'zeroed_non_approved_count' => $zeroed,
            'computed_at_utc' => $computedUtc,
        ];
    }

    /**
     * Record a Chapter 2 Biashara Concierge recommendation citation (`concierge_ref`, 3.0 pts) (#311).
     *
     * @return array<string, mixed>
     */
    public static function recordConciergeCitation(
        PDO $pdo,
        int $businessId,
        string $clientIp,
        string $userAgent,
        string $interactionToken,
        ?int $currentOwnerId = null,
        bool $isAdmin = false,
        ?int $nowTs = null
    ): array {
        return self::recordCheckout(
            $pdo,
            $businessId,
            'concierge_ref',
            $clientIp,
            $userAgent,
            $interactionToken,
            $currentOwnerId,
            $isAdmin,
            $nowTs
        );
    }

    /**
     * Normalize requested `sort=` mode with automatic proximity fallback (#310).
     */
    public static function normalizeSortMode(?string $rawSort, bool $hasCoordsQuery = false): string
    {
        $norm = strtolower(trim((string)($rawSort ?? '')));
        if ($norm === '') {
            return $hasCoordsQuery ? 'nearest' : 'most_referenced';
        }

        return match ($norm) {
            'most_referenced', 'default', 'popular', 'most_checked_out' => 'most_referenced',
            'trending', 'trending_week' => 'trending',
            'newest', 'recent' => 'newest',
            'nearest', 'distance', 'proximity' => $hasCoordsQuery ? 'nearest' : 'most_referenced',
            'az', 'name', 'alphabetical' => 'az',
            'directory_number', 'number', 'registration' => 'directory_number',
            default => $hasCoordsQuery ? 'nearest' : 'most_referenced',
        };
    }

    /**
     * Compute deterministic 2-hour rotation slot index.
     */
    public static function computeTieRotationSlot(?int $nowTs = null): int
    {
        return intdiv(max(0, $nowTs ?? time()), self::TIE_ROTATION_BUCKET_SECONDS);
    }

    /**
     * Derive bijective affine permutation constants mod 65521 for a 2-hour tie-rotation slot (#310).
     *
     * @return array{slot:int, mult:int, add:int}
     */
    public static function computeTieRotationParams(?int $nowTs = null): array
    {
        $slot = self::computeTieRotationSlot($nowTs);
        $crcA = (int)sprintf('%u', crc32("bbiz_tie_slot_{$slot}_a"));
        $crcB = (int)sprintf('%u', crc32("bbiz_tie_slot_{$slot}_b"));
        $mult = ($crcA % (self::TIE_ROTATION_PRIME - 1)) + 1;
        $add = ($crcB % self::TIE_ROTATION_PRIME);

        return [
            'slot' => $slot,
            'mult' => $mult,
            'add' => $add,
        ];
    }

    /**
     * Cross-dialect (SQLite + MySQL 8.0) monotonic ~5% relative engagement tie-band expression (#310).
     * Clearly higher engagement bands always outrank lower bands, while near-equal scores share a band.
     */
    public static function buildEngagementBandSqlExpr(string $tableAlias = 'b'): string
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $tableAlias) ?: 'b';
        return "CASE "
            . "WHEN {$a}.engagement_score <= 0.05 THEN 0 "
            . "WHEN {$a}.engagement_score < 2.0 THEN 1 + ROUND({$a}.engagement_score * 10.0, 0) "
            . "WHEN {$a}.engagement_score < 10.0 THEN 21 + ROUND(({$a}.engagement_score - 2.0) * 4.0, 0) "
            . "WHEN {$a}.engagement_score < 50.0 THEN 53 + ROUND(({$a}.engagement_score - 10.0) * 1.0, 0) "
            . "ELSE 93 + ROUND(({$a}.engagement_score - 50.0) * 0.25, 0) "
            . "END";
    }

    /**
     * Cross-dialect 2-hour bucketed tie-breaker expression within an equal engagement band (#310).
     */
    public static function buildTieJitterSqlExpr(string $tableAlias = 'b', ?int $nowTs = null): string
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $tableAlias) ?: 'b';
        $rot = self::computeTieRotationParams($nowTs);
        $prime = self::TIE_ROTATION_PRIME;
        return "((({$a}.id * {$rot['mult']}) + {$rot['add']}) % {$prime})";
    }

    /**
     * Build the SQL `ORDER BY` clause (without the leading `ORDER BY` keyword) for `/api/businesses.php` and `public/index.php` (#310).
     * Uses distinct named placeholders for MySQL 8.0 native prepared-statement compatibility (`ATTR_EMULATE_PREPARES => false`).
     *
     * @param array<string, mixed> $bindParams
     */
    public static function buildDirectoryOrderBySql(
        string $normalizedSortMode,
        array &$bindParams,
        string $paramPrefix = 'srt',
        bool $hasDistAlias = false,
        ?int $nowTs = null
    ): string {
        $ts = $nowTs ?? time();
        $cleanPrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $paramPrefix) ?: 'srt';
        $bandExpr = self::buildEngagementBandSqlExpr('b');
        $jitterExpr = self::buildTieJitterSqlExpr('b', $ts);

        return match ($normalizedSortMode) {
            'nearest' => $hasDistAlias
                ? "CASE WHEN b.latitude IS NULL OR b.longitude IS NULL THEN 1 ELSE 0 END ASC, _sql_dist ASC, ({$bandExpr}) DESC, ({$jitterExpr}) DESC, b.id DESC"
                : "({$bandExpr}) DESC, ({$jitterExpr}) DESC, b.id DESC",
            'trending' => (function () use (&$bindParams, $cleanPrefix, $ts, $bandExpr, $jitterExpr): string {
                $pKey = ":{$cleanPrefix}_trend_since";
                $bindParams[$pKey] = gmdate('Y-m-d H:i:s', $ts - (14 * 86400));
                return "(SELECT COALESCE(SUM(ce.signal_weight), 0) FROM click_events ce WHERE ce.business_id = b.id AND ce.created_at >= {$pKey}) DESC, ({$bandExpr}) DESC, ({$jitterExpr}) DESC, b.id DESC";
            })(),
            'newest' => "COALESCE(b.verified_at, b.created_at) DESC, b.id DESC",
            'az' => "LOWER(b.name) ASC, b.id ASC",
            'directory_number' => "b.business_number ASC, b.id ASC",
            default => "({$bandExpr}) DESC, ({$jitterExpr}) DESC, b.id DESC",
        };
    }
    /**
     * Compute the popularity context for momentum badges in one pass (#315).
     *
     * @return array{popular_cutoff:?float, new_badges_enabled:bool}
     */
    public static function computeMomentumContext(PDO $pdo, ?int $nowTs = null): array
    {
        $ts = $nowTs ?? time();
        $cntStmt = $pdo->query("SELECT COUNT(*) AS total_count FROM businesses WHERE status = 'approved'");
        $n = (int)($cntStmt->fetchColumn() ?: 0);
        if ($n === 0) {
            return ['popular_cutoff' => null, 'new_badges_enabled' => false];
        }

        $k = max(1, (int)ceil($n * self::POPULAR_TOP_FRACTION));
        $offset = (int)($k - 1);
        $cutoffStmt = $pdo->query("
            SELECT engagement_score
            FROM businesses
            WHERE status = 'approved'
            ORDER BY engagement_score DESC, id DESC
            LIMIT 1 OFFSET {$offset}
        ");
        $cutoff = (float)($cutoffStmt->fetchColumn() ?: 0.0);

        $since14dUtc = gmdate('Y-m-d H:i:s', $ts - (int)(self::NEW_BADGE_WINDOW_DAYS * 86400));
        $newStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM businesses
            WHERE status = 'approved'
              AND (
                  (verified_at IS NOT NULL AND TRIM(verified_at) != '' AND verified_at >= :since1)
                  OR ((verified_at IS NULL OR TRIM(verified_at) = '') AND created_at >= :since2)
              )
        ");
        $newStmt->execute([':since1' => $since14dUtc, ':since2' => $since14dUtc]);
        $newCount = (int)($newStmt->fetchColumn() ?: 0);

        return [
            'popular_cutoff' => $cutoff > 0.0 ? $cutoff : null,
            // A "New" cue only means something when newcomers are a distinctive minority (e.g. not at launch).
            'new_badges_enabled' => ($newCount / $n) <= self::NEW_BADGE_MAX_SHARE,
        ];
    }

    public static function isWithinNewWindow(?string $verifiedAt, ?string $createdAt, int $nowTs): bool
    {
        $anchorRaw = trim((string)($verifiedAt ?? '')) !== '' ? $verifiedAt : $createdAt;
        if ($anchorRaw === null || trim((string)$anchorRaw) === '') {
            return false;
        }
        $anchorTs = self::parseUtcTimestamp($anchorRaw, 0);
        if ($anchorTs <= 0) {
            return false;
        }
        return (($nowTs - $anchorTs) / 86400.0) < self::NEW_BADGE_WINDOW_DAYS;
    }

    /**
     * Resolve a single listing's public momentum cue: 'popular' | 'new' | null (#315).
     * Never returns a "low" or "unpopular" label, and never exposes the underlying numbers.
     *
     * @param array<string, mixed> $row
     * @param array{popular_cutoff:?float, new_badges_enabled:bool} $ctx
     */
    public static function resolveMomentumBadge(array $row, array $ctx, ?int $nowTs = null): ?string
    {
        $ts = $nowTs ?? time();
        if (!empty($ctx['new_badges_enabled'])
            && self::isWithinNewWindow($row['verified_at'] ?? null, $row['created_at'] ?? null, $ts)) {
            return 'new';
        }
        $cutoff = $ctx['popular_cutoff'] ?? null;
        if ($cutoff !== null
            && (float)($row['engagement_score'] ?? 0.0) >= (float)$cutoff
            && (int)($row['reference_count_90d'] ?? 0) >= self::POPULAR_MIN_REFS_90D) {
            return 'popular';
        }
        return null;
    }

    /**
     * Private 30d / 90d engagement breakdown for a verified owner's listing (#316).
     *
     * @return array{window_30d: array<string,int>, window_90d: array<string,int>, total_30d:int, total_90d:int}
     */
    public static function getOwnerEngagementSummary(PDO $pdo, int $businessId, ?int $nowTs = null): array
    {
        $ts = $nowTs ?? time();
        $w30 = array_fill_keys(array_keys(self::OWNER_INSIGHT_LABELS), 0);
        $w90 = $w30;

        $stmt = $pdo->prepare("
            SELECT signal_group,
                   SUM(CASE WHEN created_at >= :since30 THEN 1 ELSE 0 END) AS c30,
                   COUNT(*) AS c90
            FROM click_events
            WHERE business_id = :bid
              AND signal_group IS NOT NULL
              AND created_at >= :since90
            GROUP BY signal_group
        ");
        $stmt->execute([
            ':since30' => gmdate('Y-m-d H:i:s', $ts - (30 * 86400)),
            ':since90' => gmdate('Y-m-d H:i:s', $ts - (90 * 86400)),
            ':bid' => $businessId,
        ]);
        foreach ($stmt->fetchAll() as $r) {
            $g = (string)($r['signal_group'] ?? '');
            if (!array_key_exists($g, $w30)) {
                continue;
            }
            $w30[$g] = (int)$r['c30'];
            $w90[$g] = (int)$r['c90'];
        }

        return [
            'window_30d' => $w30,
            'window_90d' => $w90,
            'total_30d' => array_sum($w30),
            'total_90d' => array_sum($w90),
        ];
    }

    /**
     * Render the private Owner Portal insights panel (#316). Output is HTML-escaped and owner-only.
     *
     * @param array{window_30d: array<string,int>, window_90d: array<string,int>, total_30d:int, total_90d:int} $summary
     */
    public static function renderOwnerInsightsHtml(int $businessId, array $summary): string
    {
        $rowsHtml = '';
        foreach (self::OWNER_INSIGHT_LABELS as $group => $label) {
            $c30 = (int)($summary['window_30d'][$group] ?? 0);
            $c90 = (int)($summary['window_90d'][$group] ?? 0);
            $g = htmlspecialchars($group, ENT_QUOTES, 'UTF-8');
            $rowsHtml .= '<tr data-testid="owner-eng-row-' . $g . '">'
                . '<td style="padding:0.3rem 0.5rem;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td data-testid="owner-eng-30d-' . $g . '" style="padding:0.3rem 0.5rem;text-align:right;font-weight:700;">' . $c30 . '</td>'
                . '<td data-testid="owner-eng-90d-' . $g . '" style="padding:0.3rem 0.5rem;text-align:right;">' . $c90 . '</td>'
                . '</tr>';
        }
        $total30 = (int)($summary['total_30d'] ?? 0);
        $total90 = (int)($summary['total_90d'] ?? 0);
        $hint = $total90 === 0
            ? '<p style="margin:0.45rem 0 0 0;font-size:0.76rem;color:#6B7280;">No recorded visits yet. Sharing your listing link on WhatsApp or with fellow Busherians is the quickest way to get noticed.</p>'
            : '';

        return '<div data-testid="owner-engagement-insights" data-business-id="' . $businessId . '" '
            . 'style="margin-top:0.75rem;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:0.7rem 0.85rem;max-width:520px;">'
            . '<div style="font-size:0.82rem;font-weight:800;color:#114B3E;">📊 Private Engagement Insights '
            . '<span style="font-weight:600;color:#6B7280;font-size:0.74rem;">(only you can see this)</span></div>'
            . '<table style="width:100%;border-collapse:collapse;font-size:0.8rem;margin-top:0.35rem;color:#374151;">'
            . '<thead><tr style="color:#6B7280;font-size:0.74rem;"><th style="text-align:left;padding:0.2rem 0.5rem;">Activity</th>'
            . '<th style="text-align:right;padding:0.2rem 0.5rem;">Last 30 days</th><th style="text-align:right;padding:0.2rem 0.5rem;">Last 90 days</th></tr></thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '<tfoot><tr style="border-top:1px solid #E5E7EB;font-weight:800;"><td style="padding:0.3rem 0.5rem;">Total</td>'
            . '<td data-testid="owner-eng-total-30d" style="padding:0.3rem 0.5rem;text-align:right;">' . $total30 . '</td>'
            . '<td data-testid="owner-eng-total-90d" style="padding:0.3rem 0.5rem;text-align:right;">' . $total90 . '</td></tr></tfoot>'
            . '</table>'
            . '<p style="margin:0.4rem 0 0 0;font-size:0.72rem;color:#6B7280;">Counted once per visitor network per day. Your own visits while signed in are never counted.</p>'
            . $hint
            . '</div>';
    }
}
