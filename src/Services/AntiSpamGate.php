<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class AntiSpamGate
{
    private const MIN_FILL_SECONDS = 4;
    private const MAX_FORM_AGE_SECONDS = 7200; // 2 hours

    private const BLOCKED_DOMAINS = [
        'telegra.ph',
        't.me',
        'bit.ly',
        'tinyurl.com',
        'rb.gy',
        'cutt.ly',
        'is.gd',
    ];

    private const BLOCKED_TLDS = [
        '.ru',
        '.xyz',
        '.top',
        '.click',
        '.work',
        '.gq',
    ];

    private const SCAM_PATTERNS = [
        '/\b(?:crypto\s+airdrop|bitcoin\s+investment|forex\s+signals\s+guaranteed|casino\s+bonus|buy\s+backlinks|seo\s+ranking\s+service|passive\s+income\s+daily)\b/i',
    ];

    /**
     * Generate signed timestamp + nonce tokens for rendering in a public form.
     */
    public static function generateFormTokens(?int $timestampOverride = null): array
    {
        $ts = $timestampOverride ?? time();
        $nonce = bin2hex(random_bytes(16));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $sig = hash_hmac('sha256', "{$ts}|{$nonce}", $secret);

        $csrfSecret = Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len');
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $sessionId = session_id() ?: 'stateless';
        $csrfToken = hash_hmac('sha256', $sessionId, $csrfSecret);

        return [
            'form_ts' => $ts,
            'form_nonce' => $nonce,
            'form_sig' => $sig,
            'csrf_token' => $csrfToken,
        ];
    }

    public static function verifyCsrfToken(string $submittedToken): bool
    {
        if ($submittedToken === '') {
            return false;
        }
        $csrfSecret = Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len');
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        $sessionId = session_id() ?: 'stateless';
        $expected = hash_hmac('sha256', $sessionId, $csrfSecret);
        $expectedStateless = hash_hmac('sha256', 'stateless', $csrfSecret);
        return hash_equals($expected, $submittedToken) || hash_equals($expectedStateless, $submittedToken);
    }

    /**
     * Evaluate all 7 Anti-Spam Layers on an unauthenticated public form submission.
     *
     * @return array{passed: bool, silent_drop: bool, reason: string}
     */
    public static function verifySubmission(PDO $pdo, array $post, string $clientIp, string $actionType = 'business_submit'): array
    {
        // Layer 1: Disguised Text Honeypot (Never Checkbox-Only)
        $honeypot = trim((string)($post['website'] ?? ''));
        if ($honeypot !== '') {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer1_honeypot_filled'];
        }

        // Layer 2: Signed Timestamp Time-Trap & Single-Use Nonce Replay Lock
        $ts = (int)($post['form_ts'] ?? 0);
        $nonce = trim((string)($post['form_nonce'] ?? ''));
        $sig = trim((string)($post['form_sig'] ?? ''));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $expectedSig = hash_hmac('sha256', "{$ts}|{$nonce}", $secret);

        if ($ts <= 0 || $nonce === '' || $sig === '' || !hash_equals($expectedSig, $sig)) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer2_invalid_signature'];
        }

        $elapsed = time() - $ts;
        if ($elapsed < self::MIN_FILL_SECONDS) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer2_submitted_too_fast'];
        }
        if ($elapsed > self::MAX_FORM_AGE_SECONDS) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer2_form_expired'];
        }

        // Single-use nonce replay check
        $nonceHash = hash('sha256', $nonce);
        $pdo->prepare("DELETE FROM used_nonces WHERE expires_at < :now")->execute([':now' => time()]);
        $checkNonce = $pdo->prepare("SELECT nonce_hash FROM used_nonces WHERE nonce_hash = :nh");
        $checkNonce->execute([':nh' => $nonceHash]);
        if ($checkNonce->fetch()) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer2_nonce_replayed'];
        }
        $pdo->prepare("INSERT INTO used_nonces (nonce_hash, expires_at) VALUES (:nh, :exp)")
            ->execute([':nh' => $nonceHash, ':exp' => time() + self::MAX_FORM_AGE_SECONDS]);

        // Layer 3: Client-Side Human Interaction Token
        $jsToken = trim((string)($post['js_interaction_token'] ?? ''));
        if ($jsToken === '' || !str_starts_with($jsToken, 'human_verified_')) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer3_missing_human_interaction_token'];
        }

        // Layer 4: Heuristic Content & URL Filter
        $submitterName = trim((string)($post['submitter_name'] ?? ''));
        $businessName = trim((string)($post['name'] ?? ''));
        $tagline = trim((string)($post['tagline'] ?? ''));
        $description = trim((string)($post['description'] ?? ''));

        // 4a. Reject single-word CamelCase bot names (e.g., "EdwardWaw", "oliviaVaugt")
        foreach ([$submitterName, $businessName] as $nameField) {
            if ($nameField !== '' && !str_contains($nameField, ' ') && preg_match('/^[a-zA-Z]+[a-z][A-Z][a-zA-Z]*$/', $nameField)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_camelcase_bot_name'];
            }
            // Reject URLs in Name fields
            if (preg_match('#(?:https?://|www\.)#i', $nameField)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_url_in_name'];
            }
        }

        // 4b. Cap URLs in Description/Message (<= 1)
        $combinedMessage = $tagline . ' ' . $description;
        $urlCount = preg_match_all('#https?://[^\s"\x27<>]+#i', $combinedMessage, $urlMatches);
        if ($urlCount > 1) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_excessive_urls_in_description'];
        }

        // 4c. Block spam/shortener/paste domains & high-abuse TLDs across all fields
        $allUrlsText = strtolower($combinedMessage . ' ' . ($post['website_url'] ?? ''));
        foreach (self::BLOCKED_DOMAINS as $domain) {
            if (str_contains($allUrlsText, $domain)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_blocked_spam_domain'];
            }
        }
        if (preg_match_all('#https?://([a-z0-9.\-]+)#i', $allUrlsText, $hostMatches)) {
            foreach ($hostMatches[1] as $h) {
                foreach (self::BLOCKED_TLDS as $tld) {
                    if (str_ends_with($h, $tld)) {
                        return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_blocked_tld'];
                    }
                }
            }
        }

        // 4d. Reject ALL-CAPS shouting (> 60% uppercase across >= 15 alphabetic characters)
        foreach ([$businessName, $tagline, $description] as $textField) {
            if (self::isAllCapsShouting($textField)) {
                return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_all_caps_shouting'];
            }
        }

        // 4e. Block financial/crypto/SEO scam lures
        foreach (self::SCAM_PATTERNS as $pattern) {
            if (preg_match($pattern, $combinedMessage)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_scam_lure_detected'];
            }
        }

        // Layer 5: Per-IP & Global Circuit-Breaker Rate Limiting (Keyed HMAC-SHA256 IP Hash)
        $rateCheck = self::checkAndRecordRateLimit($pdo, $clientIp, $actionType);
        if (!$rateCheck['allowed']) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => $rateCheck['reason']];
        }

        return ['passed' => true, 'silent_drop' => false, 'reason' => 'ok'];
    }

    public static function isAllCapsShouting(string $text): bool
    {
        $lettersOnly = preg_replace('/[^a-zA-Z]/', '', $text) ?? '';
        $totalLetters = strlen($lettersOnly);
        if ($totalLetters < 15) {
            return false;
        }
        $upperLetters = strlen(preg_replace('/[^A-Z]/', '', $lettersOnly) ?? '');
        return ($upperLetters / $totalLetters) > 0.60;
    }

    /**
     * Keyed HMAC-SHA256 pseudonymization of IP address (Kenya DPA 2019 s.31/41).
     * Never stores raw IP address in database.
     */
    public static function pseudonymizeIp(string $rawIp): string
    {
        $salt = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        return hash_hmac('sha256', trim($rawIp), $salt);
    }

    public static function checkAndRecordRateLimit(PDO $pdo, string $rawIp, string $actionType): array
    {
        $now = time();
        $ipHmac = self::pseudonymizeIp($rawIp);

        // Clean up records older than 24h
        $pdo->prepare("DELETE FROM rate_limits WHERE created_at < :cutoff")
            ->execute([':cutoff' => $now - 86400]);

        // Per-IP 15-minute window (<= 2 submissions / 15m)
        $stmt15m = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
        $stmt15m->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 900]);
        if ((int)$stmt15m->fetchColumn() >= 2) {
            return ['allowed' => false, 'reason' => 'layer5_ip_rate_limit_15m'];
        }

        // Per-IP 24-hour window (<= 5 submissions / 24h)
        $stmt24h = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
        $stmt24h->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 86400]);
        if ((int)$stmt24h->fetchColumn() >= 5) {
            return ['allowed' => false, 'reason' => 'layer5_ip_rate_limit_24h'];
        }

        // Global circuit-breaker (<= 15 submissions / 1h across all IPs)
        $stmtGlobal = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_type = :act AND created_at >= :since");
        $stmtGlobal->execute([':act' => $actionType, ':since' => $now - 3600]);
        if ((int)$stmtGlobal->fetchColumn() >= 15) {
            return ['allowed' => false, 'reason' => 'layer5_global_circuit_breaker_1h'];
        }

        // Record this submission
        $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:ip, :act, :now)")
            ->execute([':ip' => $ipHmac, ':act' => $actionType, ':now' => $now]);

        return ['allowed' => true, 'reason' => 'ok'];
    }

    /**
     * Layer 6: Strip header injection characters from user-supplied names/emails used in Reply-To.
     */
    public static function sanitizeHeaderValue(string $input): string
    {
        return trim(preg_replace('/[\r\n<>",;]+/', ' ', $input) ?? '');
    }

    /**
     * L1 CSV/Spreadsheet Formula Injection Sanitizer:
     * Prefixes leading '=', '+', '-', '@', '|', '	', '
' with a single quote.
     */
    public static function sanitizeForCsv(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', '|', "	", "
"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
