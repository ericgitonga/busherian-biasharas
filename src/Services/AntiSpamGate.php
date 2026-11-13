<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class AntiSpamGate
{
    private const MIN_FILL_SECONDS = 4;
    private const MAX_FORM_AGE_SECONDS = 7200; // 2 hours
    public const GLOBAL_OUTBOUND_MAIL_LIMIT_1H = 15;
    public const CENTRAL_DISPATCH_MAIL_LIMIT_1H = 15;
    public const OTP_RECIPIENT_COOLDOWN_SECONDS = 60;
    public const OTP_RECIPIENT_LIMIT_1H = 4;

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

    public static function generateFormTokens(?int $timestampOverride = null): array
    {
        $ts = $timestampOverride ?? time();
        $nonce = bin2hex(random_bytes(16));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $sig = hash_hmac('sha256', "{$ts}|{$nonce}", $secret);

        $csrfToken = self::generateCsrfToken();

        return [
            'form_ts' => $ts,
            'form_nonce' => $nonce,
            'form_sig' => $sig,
            'csrf_token' => $csrfToken,
        ];
    }

    public static function generateCsrfToken(): string
    {
        OwnerAuth::startSecureSession();
        $sessionId = session_id();
        if (!is_string($sessionId) || $sessionId === '') {
            throw new \RuntimeException('Cannot generate CSRF token without an active PHP session.');
        }
        $csrfSecret = Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len');
        return hash_hmac('sha256', $sessionId, $csrfSecret);
    }

    public static function verifyCsrfToken(string $submittedToken): bool
    {
        if ($submittedToken === '') {
            return false;
        }
        OwnerAuth::startSecureSession();
        $sessionId = session_id();
        if (!is_string($sessionId) || $sessionId === '') {
            return false;
        }
        $csrfSecret = Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len');
        $expected = hash_hmac('sha256', $sessionId, $csrfSecret);
        return hash_equals($expected, $submittedToken);
    }

    public static function claimSingleUseNonce(PDO $pdo, string $nonceHash, int $expiresAt): bool
    {
        $cleanHash = trim($nonceHash);
        if ($cleanHash === '') {
            return false;
        }
        $now = time();
        $pdo->prepare("DELETE FROM used_nonces WHERE expires_at < :now")->execute([':now' => $now]);

        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $sql = ($driver === 'sqlite')
            ? "INSERT OR IGNORE INTO used_nonces (nonce_hash, expires_at) VALUES (:nh, :exp)"
            : "INSERT IGNORE INTO used_nonces (nonce_hash, expires_at) VALUES (:nh, :exp)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nh' => $cleanHash,
            ':exp' => max($expiresAt, $now + 60),
        ]);
        return $stmt->rowCount() === 1;
    }

    public static function isNonceUsed(PDO $pdo, string $nonceHash): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM used_nonces WHERE nonce_hash = :nh AND expires_at >= :now LIMIT 1");
        $stmt->execute([':nh' => trim($nonceHash), ':now' => time()]);
        return (bool)$stmt->fetchColumn();
    }

    public static function verifySubmission(PDO $pdo, array $post, string $clientIp, string $actionType = 'business_submit'): array
    {
        $honeypot = trim((string)($post['website'] ?? ''));
        if ($honeypot !== '') {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer1_honeypot_filled'];
        }

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

        $jsToken = trim((string)($post['js_interaction_token'] ?? ''));
        if ($jsToken === '' || !str_starts_with($jsToken, 'human_verified_')) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer3_missing_human_interaction_token'];
        }

        $nonceHash = hash('sha256', $nonce);
        if (!self::claimSingleUseNonce($pdo, $nonceHash, time() + self::MAX_FORM_AGE_SECONDS)) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer2_nonce_replayed'];
        }

        $submitterName = trim((string)($post['submitter_name'] ?? ''));
        $businessName = trim((string)($post['name'] ?? ''));
        $tagline = trim((string)($post['tagline'] ?? ''));
        $description = trim((string)($post['description'] ?? ''));

        foreach ([$submitterName, $businessName] as $nameField) {
            if ($nameField !== '' && !str_contains($nameField, ' ') && preg_match('/^[a-zA-Z]+[a-z][A-Z][a-zA-Z]*$/', $nameField)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_camelcase_bot_name'];
            }
            if (preg_match('#(?:https?://|www\.)#i', $nameField)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_url_in_name'];
            }
        }

        $combinedMessage = $tagline . ' ' . $description;
        $urlCount = preg_match_all('#https?://[^\s"\x27<>]+#i', $combinedMessage, $urlMatches);
        if ($urlCount > 1) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_excessive_urls_in_description'];
        }

        $allUrlsText = strtolower($combinedMessage . ' ' . ($post['website_url'] ?? '') . ' ' . ($post['booking_url'] ?? ''));
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

        foreach ([$businessName, $tagline, $description] as $textField) {
            if (self::isAllCapsShouting($textField)) {
                return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_all_caps_shouting'];
            }
        }

        foreach (self::SCAM_PATTERNS as $pattern) {
            if (preg_match($pattern, $combinedMessage)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_scam_lure_detected'];
            }
        }

        $rateCheck = self::checkAndRecordRateLimit($pdo, $clientIp, $actionType);
        if (!$rateCheck['allowed']) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => $rateCheck['reason']];
        }

        return ['passed' => true, 'silent_drop' => false, 'reason' => 'ok'];
    }

    public static function isBlockedSpamOrShortenerUrl(?string $rawUrl): bool
    {
        $u = strtolower(trim((string)($rawUrl ?? '')));
        if ($u === '') {
            return false;
        }
        $host = strtolower((string)(parse_url($u, PHP_URL_HOST) ?? ''));
        foreach (self::BLOCKED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain) || str_contains($u, $domain)) {
                return true;
            }
        }
        if ($host !== '') {
            foreach (self::BLOCKED_TLDS as $tld) {
                if (str_ends_with($host, $tld)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Full 7-Layer Anti-Spam Gate for unauthenticated public Appointment & Consultation Booking inquiries (#284).
     *
     * @return array{passed:bool, silent_drop:bool, reason:string}
     */
    public static function verifyBookingInquiry(PDO $pdo, array $post, string $clientIp): array
    {
        // Layer 1: Disguised off-screen text honeypot
        $honeypot = trim((string)($post['website'] ?? ''));
        if ($honeypot !== '') {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer1_honeypot_filled'];
        }

        // Layer 2: Signed timestamp time-trap (>= 4s, <= 2h) & single-use replay lock
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

        // Layer 3: Client-side human interaction proof token
        $jsToken = trim((string)($post['js_interaction_token'] ?? ''));
        if ($jsToken === '' || !str_starts_with($jsToken, 'human_verified_')) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer3_missing_human_interaction_token'];
        }

        $nonceHash = hash('sha256', 'booking_form|' . $nonce);
        if (!self::claimSingleUseNonce($pdo, $nonceHash, time() + self::MAX_FORM_AGE_SECONDS)) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer2_nonce_replayed'];
        }

        // Layer 4: Heuristic content, CamelCase bot-name, URL count, spam domain/TLD, ALL-CAPS & scam lure filter
        $requesterName = trim((string)($post['requester_name'] ?? ''));
        $topic = trim((string)($post['topic'] ?? ''));

        if ($requesterName !== '' && !str_contains($requesterName, ' ') && preg_match('/^[a-zA-Z]+[a-z][A-Z][a-zA-Z]*$/', $requesterName)) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_camelcase_bot_name'];
        }
        if (preg_match('#(?:https?://|www\.)#i', $requesterName)) {
            return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_url_in_name'];
        }

        $urlCount = preg_match_all('#https?://[^\s"\x27<>]+#i', $topic, $urlMatches);
        if ($urlCount > 1) {
            return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_excessive_urls_in_message'];
        }

        $lowerTopic = strtolower($topic);
        foreach (self::BLOCKED_DOMAINS as $domain) {
            if (str_contains($lowerTopic, $domain)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_blocked_spam_domain'];
            }
        }
        if (preg_match_all('#https?://([a-z0-9.\-]+)#i', $lowerTopic, $hostMatches)) {
            foreach ($hostMatches[1] as $h) {
                foreach (self::BLOCKED_TLDS as $tld) {
                    if (str_ends_with($h, $tld)) {
                        return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_blocked_tld'];
                    }
                }
            }
        }

        foreach ([$requesterName, $topic] as $textField) {
            if (self::isAllCapsShouting($textField)) {
                return ['passed' => false, 'silent_drop' => false, 'reason' => 'layer4_all_caps_shouting'];
            }
        }

        foreach (self::SCAM_PATTERNS as $pattern) {
            if (preg_match($pattern, $topic)) {
                return ['passed' => false, 'silent_drop' => true, 'reason' => 'layer4_scam_lure_detected'];
            }
        }

        // Layer 5: Strict per-IP (<= 2 / 15m, <= 5 / 24h) & global (<= 15 / 1h) rate limiting with silent drop
        $rateCheck = self::checkAndRecordRateLimit($pdo, $clientIp, 'booking_inquiry', 2, 5, 15);
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

    public static function pseudonymizeIp(string $rawIp): string
    {
        $salt = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        return hash_hmac('sha256', trim($rawIp), $salt);
    }

    public static function acquireRateLimitSerializationLock(PDO $pdo): void
    {
        try {
            $upd = $pdo->prepare("UPDATE business_sequences SET current_val = current_val + 1 WHERE seq_key = 'rate_limit_mutex'");
            $upd->execute();
            if ($upd->rowCount() === 0) {
                $drv = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
                if ($drv === 'sqlite') {
                    $pdo->exec("INSERT OR IGNORE INTO business_sequences (seq_key, current_val) VALUES ('rate_limit_mutex', 0)");
                } else {
                    $pdo->exec("INSERT IGNORE INTO business_sequences (seq_key, current_val) VALUES ('rate_limit_mutex', 0)");
                }
                $upd->execute();
                if ($upd->rowCount() === 0) {
                    throw new \RuntimeException('rate_limit_mutex row missing after initialization.');
                }
            }
        } catch (\Throwable $e) {
            error_log('[AntiSpamGate] Critical rate-limit serialization mutex failure: ' . $e->getMessage());
            throw new \RuntimeException('Rate-limit serialization mutex could not be acquired.', 0, $e);
        }
    }

    public static function checkAndRecordRateLimit(
        PDO $pdo,
        string $rawIp,
        string $actionType,
        int $limit15m = 2,
        int $limit24h = 5,
        int $limitGlobal1h = 15
    ): array {
        $now = time();
        $ipHmac = self::pseudonymizeIp($rawIp);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            self::acquireRateLimitSerializationLock($pdo);

            $pdo->prepare("DELETE FROM rate_limits WHERE created_at < :cutoff")
                ->execute([':cutoff' => $now - 86400]);

            $stmt15m = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
            $stmt15m->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 900]);
            if ((int)$stmt15m->fetchColumn() >= $limit15m) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'layer5_ip_rate_limit_15m'];
            }

            $stmt24h = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
            $stmt24h->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 86400]);
            if ((int)$stmt24h->fetchColumn() >= $limit24h) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'layer5_ip_rate_limit_24h'];
            }

            $stmtGlobal = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_type = :act AND created_at >= :since");
            $stmtGlobal->execute([':act' => $actionType, ':since' => $now - 3600]);
            if ((int)$stmtGlobal->fetchColumn() >= $limitGlobal1h) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'layer5_global_circuit_breaker_1h'];
            }

            $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:ip, :act, :now)")
                ->execute([':ip' => $ipHmac, ':act' => $actionType, ':now' => $now]);

            if ($ownTx) {
                $pdo->commit();
            }
            return ['allowed' => true, 'reason' => 'ok'];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function checkAndRecordOutboundMailQuota(
        PDO $pdo,
        string $rawIp,
        ?string $recipientEmail = null,
        bool $isOtpOrAuth = false,
        int $globalLimit1h = self::GLOBAL_OUTBOUND_MAIL_LIMIT_1H
    ): array {
        $now = time();
        $ipHmac = self::pseudonymizeIp($rawIp);
        $salt = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        $normEmail = $recipientEmail !== null ? strtolower(trim($recipientEmail)) : '';
        $rcptHmac = $normEmail !== '' ? hash_hmac('sha256', 'mail_rcpt|' . $normEmail, $salt) : '';

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            self::acquireRateLimitSerializationLock($pdo);

            $stmtGlobal = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_type = 'global_outbound_mail' AND created_at >= :since");
            $stmtGlobal->execute([':since' => $now - 3600]);
            if ((int)$stmtGlobal->fetchColumn() >= $globalLimit1h) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'global_outbound_mail_circuit_breaker_1h'];
            }

            if ($isOtpOrAuth && $rcptHmac !== '') {
                $stmt60s = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :rh AND action_type = 'otp_recipient_cooldown' AND created_at >= :since");
                $stmt60s->execute([':rh' => $rcptHmac, ':since' => $now - self::OTP_RECIPIENT_COOLDOWN_SECONDS]);
                if ((int)$stmt60s->fetchColumn() >= 1) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return ['allowed' => false, 'reason' => 'otp_recipient_cooldown_60s'];
                }

                $stmtRcpt1h = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :rh AND action_type = 'otp_recipient_cooldown' AND created_at >= :since");
                $stmtRcpt1h->execute([':rh' => $rcptHmac, ':since' => $now - 3600]);
                if ((int)$stmtRcpt1h->fetchColumn() >= self::OTP_RECIPIENT_LIMIT_1H) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return ['allowed' => false, 'reason' => 'otp_recipient_limit_1h'];
                }
            }

            $ins = $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:h, :act, :now)");
            $ins->execute([':h' => $ipHmac, ':act' => 'global_outbound_mail', ':now' => $now]);
            if ($isOtpOrAuth && $rcptHmac !== '') {
                $ins->execute([':h' => $rcptHmac, ':act' => 'otp_recipient_cooldown', ':now' => $now]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
            return ['allowed' => true, 'reason' => 'ok'];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Per-IP + Per-Account authentication throttle WITHOUT a shared global DoS ceiling (#213 M-05).
     */
    public static function checkAuthAttemptAllowed(
        PDO $pdo,
        string $rawIp,
        string $actionType,
        string $accountIdentifier = '',
        int $ipLimit15m = 10,
        int $ipLimit24h = 30,
        int $acctLimit15m = 5,
        int $acctLimit24h = 15,
        ?callable $verifyCallback = null
    ): array {
        $now = time();
        $ipHmac = self::pseudonymizeIp($rawIp);
        $salt = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        $normAcct = strtolower(trim($accountIdentifier));
        $acctHmac = $normAcct !== '' ? hash_hmac('sha256', 'auth_acct|' . $actionType . '|' . $normAcct, $salt) : '';
        $acctAction = substr('acct_' . $actionType, 0, 40);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            self::acquireRateLimitSerializationLock($pdo);
            $pdo->prepare("DELETE FROM rate_limits WHERE created_at < :cutoff")
                ->execute([':cutoff' => $now - 86400]);

            $stmtIp15 = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
            $stmtIp15->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 900]);
            if ((int)$stmtIp15->fetchColumn() >= $ipLimit15m) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'auth_ip_rate_limit_15m'];
            }

            $stmtIp24 = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ip AND action_type = :act AND created_at >= :since");
            $stmtIp24->execute([':ip' => $ipHmac, ':act' => $actionType, ':since' => $now - 86400]);
            if ((int)$stmtIp24->fetchColumn() >= $ipLimit24h) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'auth_ip_rate_limit_24h'];
            }

            if ($acctHmac !== '') {
                $stmtAc15 = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ah AND action_type = :act AND created_at >= :since");
                $stmtAc15->execute([':ah' => $acctHmac, ':act' => $acctAction, ':since' => $now - 900]);
                if ((int)$stmtAc15->fetchColumn() >= $acctLimit15m) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return ['allowed' => false, 'reason' => 'auth_account_rate_limit_15m'];
                }

                $stmtAc24 = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip_hmac = :ah AND action_type = :act AND created_at >= :since");
                $stmtAc24->execute([':ah' => $acctHmac, ':act' => $acctAction, ':since' => $now - 86400]);
                if ((int)$stmtAc24->fetchColumn() >= $acctLimit24h) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return ['allowed' => false, 'reason' => 'auth_account_rate_limit_24h'];
                }
            }

            if ($verifyCallback !== null) {
                $verified = (bool)$verifyCallback();
                if ($verified) {
                    if ($ownTx) {
                        $pdo->commit();
                    }
                    return ['allowed' => true, 'verified' => true, 'reason' => 'ok'];
                }
            }

            $ins = $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:h, :act, :now)");
            $ins->execute([':h' => $ipHmac, ':act' => $actionType, ':now' => $now]);
            if ($acctHmac !== '') {
                $ins->execute([':h' => $acctHmac, ':act' => $acctAction, ':now' => $now]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
            if ($verifyCallback !== null) {
                return ['allowed' => true, 'verified' => false, 'reason' => 'invalid_credentials'];
            }
            return ['allowed' => true, 'reason' => 'ok'];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Central dispatch-time outbound mail ceiling enforced directly in Mailer::dispatchOutboxRow() (#212 M-04).
     */
    public static function consumeCentralDispatchQuota(PDO $pdo, ?int $hourlyCeiling = null): array
    {
        $now = time();
        $envOverride = trim(Database::env('BBIZ_MAX_DISPATCH_PER_HOUR', ''));
        if ($hourlyCeiling !== null) {
            $ceiling = max(1, $hourlyCeiling);
        } elseif ($envOverride !== '' && ctype_digit($envOverride)) {
            $ceiling = max(1, (int)$envOverride);
        } elseif (strtolower(Database::env('APP_ENV', 'development')) === 'testing') {
            $ceiling = 250;
        } else {
            $ceiling = self::CENTRAL_DISPATCH_MAIL_LIMIT_1H;
        }

        $salt = Database::env('IP_HASH_SECRET', 'dev_dpa_ip_salt_secret_key_32_bytes_min');
        $dispHmac = hash_hmac('sha256', 'central_mail_dispatcher', $salt);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            self::acquireRateLimitSerializationLock($pdo);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action_type = 'central_mail_dispatch' AND created_at >= :since");
            $stmt->execute([':since' => $now - 3600]);
            if ((int)$stmt->fetchColumn() >= $ceiling) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return ['allowed' => false, 'reason' => 'central_mail_dispatch_ceiling_1h', 'ceiling' => $ceiling];
            }
            $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:h, 'central_mail_dispatch', :now)")
                ->execute([':h' => $dispHmac, ':now' => $now]);
            if ($ownTx) {
                $pdo->commit();
            }
            return ['allowed' => true, 'reason' => 'ok', 'ceiling' => $ceiling];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function sanitizeHeaderValue(string $input): string
    {
        return trim(preg_replace('/[\r\n<>",;]+/', ' ', $input) ?? '');
    }

    public static function sanitizeForCsv(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', '|', "\t", "\r", "\n"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
