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

        $dedupStmt = $pdo->prepare($dedupSql);
        $dedupStmt->execute([
            ':h' => $dedupHash,
            ':exp' => $expiresAt,
        ]);

        if ($dedupStmt->rowCount() !== 1) {
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

        return [
            'status' => 'ok',
            'recorded' => true,
            'deduplicated' => false,
            'signal_group' => $signalGroup,
            'signal_weight' => $signalWeight,
            'engagement_score' => $recomp['engagement_score'],
            'reference_count_90d' => $recomp['reference_count_90d'],
        ];
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
}
