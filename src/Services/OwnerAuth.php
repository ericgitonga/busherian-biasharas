<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class OwnerAuth
{
    private const COOKIE_NAME = 'bbiz_owner_session';
    private const PREPROD_COOKIE_NAME = 'bbiz_preprod_owner_session';

    private static function cookieName(): string
    {
        return PreprodSandbox::isPreprod() ? self::PREPROD_COOKIE_NAME : self::COOKIE_NAME;
    }

    private static function cookiePath(): string
    {
        $bp = PreprodSandbox::basePath();
        return $bp !== '' ? $bp : '/';
    }

    private static function isHttpsRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Centralized secure PHP session bootstrap (#165):
     * Enforces session.use_strict_mode=1, session.use_only_cookies=1, HttpOnly, SameSite=Lax,
     * Secure flag under HTTPS/reverse-proxy TLS, and isolated preprod session cookie name/path.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            $basePath = PreprodSandbox::basePath();
            if ($basePath !== '') {
                session_name('BBIZ_PREPROD_SESS');
            }
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $basePath !== '' ? $basePath : '/',
                'secure' => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            @session_start();
        }
    }

    private const SESSION_TTL_SECONDS = 2592000; // 30 days persistent login
    private const MAGIC_LINK_TTL_SECONDS = 1800; // 30 minutes
    private const CLAIM_LINK_TTL_SECONDS = 2592000; // 30 days for DM claim links

    private const FREEMAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'yahoo.co.uk',
        'ymail.com',
        'outlook.com',
        'hotmail.com',
        'hotmail.co.uk',
        'live.com',
        'msn.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'aol.com',
        'protonmail.com',
        'proton.me',
        'zoho.com',
        'mail.com',
        'gmx.com',
    ];

    /**
     * Normalize and extract the domain from an email address.
     */
    public static function extractEmailDomain(string $email): ?string
    {
        $clean = strtolower(trim($email));
        if (!str_contains($clean, '@')) {
            return null;
        }
        [, $domain] = explode('@', $clean, 2);
        $domain = trim($domain, '. ');
        return $domain !== '' ? $domain : null;
    }

    /**
     * Extract normalized host domain (stripping leading www.) from a website URL.
     */
    public static function extractWebsiteDomain(?string $url): ?string
    {
        $raw = trim((string)$url);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $host = strtolower((string)(parse_url($raw, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host !== '' ? $host : null;
    }

    public static function isFreemailDomain(string $domain): bool
    {
        return in_array(strtolower(trim($domain)), self::FREEMAIL_DOMAINS, true);
    }

    /**
     * Create or update a persistent business owner profile by verified email.
     * Strictly stores zero alumni vetting fields (no House, Year Left, or School Number).
     */
    public static function upsertOwner(
        PDO $pdo,
        string $email,
        ?string $fullName = null,
        ?string $phone = null,
        ?string $googleSub = null
    ): array {
        $normEmail = strtolower(trim($email));
        if ($normEmail === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid owner email address is required.');
        }

        $stmt = $pdo->prepare("SELECT * FROM business_owners WHERE LOWER(email) = :em LIMIT 1");
        $stmt->execute([':em' => $normEmail]);
        $existing = $stmt->fetch();

        if ($existing) {
            $ownerId = (int)$existing['id'];
            $newName = trim((string)($fullName ?? '')) ?: ($existing['full_name'] ?? null);
            $newPhone = trim((string)($phone ?? '')) ?: ($existing['phone'] ?? null);
            $newSub = trim((string)($googleSub ?? '')) ?: ($existing['google_sub'] ?? null);

            $pdo->prepare("
                UPDATE business_owners
                SET full_name = :nm,
                    phone = :ph,
                    google_sub = :sub,
                    last_login_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([
                ':nm' => $newName,
                ':ph' => $newPhone,
                ':sub' => $newSub,
                ':id' => $ownerId,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO business_owners (email, full_name, phone, google_sub, auth_version, created_at, last_login_at)
                VALUES (:em, :nm, :ph, :sub, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([
                ':em' => $normEmail,
                ':nm' => trim((string)($fullName ?? '')) ?: null,
                ':ph' => trim((string)($phone ?? '')) ?: null,
                ':sub' => trim((string)($googleSub ?? '')) ?: null,
            ]);
            $ownerId = (int)$pdo->lastInsertId();
        }

        // Automatically link only exact-email-matched businesses (#164: never corporate domain alone)
        self::autoLinkMatchingBusinesses($pdo, $ownerId, $normEmail);

        $out = $pdo->prepare("SELECT * FROM business_owners WHERE id = :id");
        $out->execute([':id' => $ownerId]);
        return (array)$out->fetch();
    }

    /**
     * Link an owner to a specific business (idempotent across MySQL & SQLite).
     */
    public static function linkOwnerToBusiness(PDO $pdo, int $ownerId, int $businessId, string $linkedVia = 'auto_match'): void
    {
        if ($ownerId <= 0 || $businessId <= 0) {
            return;
        }
        $chk = $pdo->prepare("SELECT id FROM business_owner_links WHERE owner_id = :oid AND business_id = :bid LIMIT 1");
        $chk->execute([':oid' => $ownerId, ':bid' => $businessId]);
        if ($chk->fetchColumn()) {
            return;
        }
        $ins = $pdo->prepare("
            INSERT INTO business_owner_links (owner_id, business_id, linked_via, created_at)
            VALUES (:oid, :bid, :via, CURRENT_TIMESTAMP)
        ");
        $ins->execute([
            ':oid' => $ownerId,
            ':bid' => $businessId,
            ':via' => $linkedVia,
        ]);

        // Also populate businesses.owner_email if empty
        $ownStmt = $pdo->prepare("SELECT email FROM business_owners WHERE id = :oid");
        $ownStmt->execute([':oid' => $ownerId]);
        $ownerEmail = (string)($ownStmt->fetchColumn() ?: '');
        if ($ownerEmail !== '') {
            $pdo->prepare("
                UPDATE businesses
                SET owner_email = CASE WHEN owner_email IS NULL OR owner_email = '' THEN :em ELSE owner_email END
                WHERE id = :bid
            ")->execute([':em' => $ownerEmail, ':bid' => $businessId]);
        }
    }

    /**
     * Post-Registration Auto-Linker (#164):
     * Links a business ONLY when verified mailbox ownership matches an explicit full address on the listing:
     * 1. LOWER(businesses.owner_email) === LOWER($email) (Admin pre-assigned or verified submitter email)
     * 2. LOWER(businesses.email_public) === LOWER($email) (exact public contact email on the listing)
     *
     * Corporate website/email domain similarity alone NEVER grants write/delete privileges (#164).
     */
    public static function autoLinkMatchingBusinesses(PDO $pdo, int $ownerId, string $email): array
    {
        $normEmail = strtolower(trim($email));
        if ($ownerId <= 0 || $normEmail === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $rows = $pdo->query("
            SELECT id, email_public, owner_email
            FROM businesses
            WHERE status IN ('approved', 'pending')
        ")->fetchAll();

        $linkedIds = [];
        foreach ($rows as $row) {
            $bid = (int)$row['id'];
            $pubEmail = strtolower(trim((string)($row['email_public'] ?? '')));
            $ownEmail = strtolower(trim((string)($row['owner_email'] ?? '')));

            $via = null;
            if ($ownEmail !== '' && $ownEmail === $normEmail) {
                $via = 'admin_or_submitter_email';
            } elseif ($pubEmail !== '' && $pubEmail === $normEmail) {
                $via = 'public_email_match';
            }

            if ($via !== null) {
                self::linkOwnerToBusiness($pdo, $ownerId, $bid, $via);
                $linkedIds[] = $bid;
            }
        }

        return $linkedIds;
    }

    /**
     * Return non-authoritative claim suggestions where a corporate email domain matches a listing's
     * website_url or non-freemail email_public domain, WITHOUT creating business_owner_links (#164).
     *
     * @return list<array<string,mixed>>
     */
    public static function getCorporateDomainClaimSuggestions(PDO $pdo, string $email, int $excludeOwnerId = 0): array
    {
        $normEmail = strtolower(trim($email));
        $emailDomain = self::extractEmailDomain($normEmail);
        if ($emailDomain === null || self::isFreemailDomain($emailDomain)) {
            return [];
        }

        $alreadyLinked = [];
        if ($excludeOwnerId > 0) {
            $lStmt = $pdo->prepare("SELECT business_id FROM business_owner_links WHERE owner_id = :oid");
            $lStmt->execute([':oid' => $excludeOwnerId]);
            foreach ($lStmt->fetchAll() as $lr) {
                $alreadyLinked[(int)$lr['business_id']] = true;
            }
        }

        $rows = $pdo->query("
            SELECT id, business_number, name, slug, school_badge, email_public, owner_email, website_url
            FROM businesses
            WHERE status IN ('approved', 'pending')
            ORDER BY business_number ASC, id ASC
        ")->fetchAll();

        $suggestions = [];
        foreach ($rows as $row) {
            $bid = (int)$row['id'];
            if (isset($alreadyLinked[$bid])) {
                continue;
            }
            $pubEmail = strtolower(trim((string)($row['email_public'] ?? '')));
            $ownEmail = strtolower(trim((string)($row['owner_email'] ?? '')));
            if (($ownEmail !== '' && $ownEmail === $normEmail) || ($pubEmail !== '' && $pubEmail === $normEmail)) {
                continue;
            }

            $webDomain = self::extractWebsiteDomain($row['website_url'] ?? null);
            $pubEmailDomain = $pubEmail !== '' ? self::extractEmailDomain($pubEmail) : null;
            if (
                ($webDomain !== null && $webDomain === $emailDomain) ||
                ($pubEmailDomain !== null && !self::isFreemailDomain($pubEmailDomain) && $pubEmailDomain === $emailDomain)
            ) {
                $suggestions[] = [
                    'id' => $bid,
                    'business_number' => (int)($row['business_number'] ?? 0),
                    'name' => (string)$row['name'],
                    'slug' => (string)$row['slug'],
                    'school_badge' => (string)($row['school_badge'] ?? 'AHS'),
                    'matched_domain' => $emailDomain,
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Audit and revoke any pre-existing business_owner_links granted solely via 'corporate_domain_match' (#164).
     * Preserves links where the owner email exactly matches businesses.email_public (upgrading linked_via).
     */
    public static function revokeUnverifiedCorporateDomainLinks(PDO $pdo): int
    {
        $rows = $pdo->query("
            SELECT
                l.id AS link_id,
                l.owner_id,
                l.business_id,
                o.email AS owner_account_email,
                b.email_public,
                b.owner_email
            FROM business_owner_links l
            LEFT JOIN business_owners o ON o.id = l.owner_id
            LEFT JOIN businesses b ON b.id = l.business_id
            WHERE l.linked_via = 'corporate_domain_match'
        ")->fetchAll();

        $revoked = 0;
        $upgStmt = $pdo->prepare("UPDATE business_owner_links SET linked_via = :via WHERE id = :id");
        $delStmt = $pdo->prepare("DELETE FROM business_owner_links WHERE id = :id");
        $clrOwnEmail = $pdo->prepare("UPDATE businesses SET owner_email = NULL WHERE id = :bid AND LOWER(owner_email) = :em");

        foreach ($rows as $r) {
            $linkId = (int)$r['link_id'];
            $bid = (int)$r['business_id'];
            $acctEmail = strtolower(trim((string)($r['owner_account_email'] ?? '')));
            $pubEmail = strtolower(trim((string)($r['email_public'] ?? '')));

            if ($acctEmail !== '' && $pubEmail !== '' && $acctEmail === $pubEmail) {
                $upgStmt->execute([':via' => 'public_email_match', ':id' => $linkId]);
                continue;
            }

            $delStmt->execute([':id' => $linkId]);
            if ($acctEmail !== '' && $bid > 0) {
                // Clear owner_email if it had been auto-populated by the legacy corporate_domain_match link
                $clrOwnEmail->execute([':bid' => $bid, ':em' => $acctEmail]);
            }
            $revoked++;
        }

        return $revoked;
    }

    /**
     * Check whether an owner is authorized to edit a given business.
     */
    public static function canOwnerEditBusiness(PDO $pdo, int $ownerId, int $businessId): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM business_owner_links WHERE owner_id = :oid AND business_id = :bid");
        $stmt->execute([':oid' => $ownerId, ':bid' => $businessId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Return all businesses linked to a given owner.
     */
    public static function getBusinessesForOwner(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                b.*,
                sec.name AS sector_name,
                spec.name AS specialty_name,
                l.linked_via,
                l.created_at AS linked_at
            FROM business_owner_links l
            INNER JOIN businesses b ON b.id = l.business_id
            INNER JOIN categories sec ON sec.id = b.sector_id
            INNER JOIN categories spec ON spec.id = b.specialty_id
            WHERE l.owner_id = :oid
            ORDER BY b.business_number ASC, b.id ASC
        ");
        $stmt->execute([':oid' => $ownerId]);
        return $stmt->fetchAll();
    }

    /**
     * Return all linked owners for a given business (for Admin console display).
     */
    public static function getOwnersForBusiness(PDO $pdo, int $businessId): array
    {
        $stmt = $pdo->prepare("
            SELECT o.id, o.email, o.full_name, o.phone, l.linked_via, l.created_at AS linked_at
            FROM business_owner_links l
            INNER JOIN business_owners o ON o.id = l.owner_id
            WHERE l.business_id = :bid
            ORDER BY l.id ASC
        ");
        $stmt->execute([':bid' => $businessId]);
        return $stmt->fetchAll();
    }

    /**
     * Generate a signed 1-click DM Claim Link for a pre-populated business.
     */
    public static function generateClaimInviteUrl(
        int $businessId,
        ?int $expiresAt = null,
        ?string $recipientEmail = null
    ): string {
        $exp = $expiresAt ?? (time() + self::CLAIM_LINK_TTL_SECONDS);
        $normRecipient = strtolower(trim((string)($recipientEmail ?? '')));
        if ($normRecipient !== '' && !filter_var($normRecipient, FILTER_VALIDATE_EMAIL)) {
            $normRecipient = '';
        }

        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = $normRecipient !== ''
            ? "claim_invite|{$businessId}|{$exp}|{$normRecipient}"
            : "claim_invite|{$businessId}|{$exp}";
        $sig = hash_hmac('sha256', $payload, $secret);

        $params = [
            'biz_id' => $businessId,
            'exp' => $exp,
        ];
        if ($normRecipient !== '') {
            $params['em'] = $normRecipient;
        }
        $params['sig'] = $sig;

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        return "{$baseUrl}/owner/claim.php?" . http_build_query($params);
    }

    public static function verifyClaimInviteSignature(
        int $businessId,
        int $expiresAt,
        string $signature,
        ?string $recipientEmail = null
    ): bool {
        if ($businessId <= 0 || $expiresAt < time() || $signature === '') {
            return false;
        }
        $normRecipient = strtolower(trim((string)($recipientEmail ?? '')));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = $normRecipient !== ''
            ? "claim_invite|{$businessId}|{$expiresAt}|{$normRecipient}"
            : "claim_invite|{$businessId}|{$expiresAt}";
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    private static function claimInviteNonceHash(
        int $businessId,
        int $expiresAt,
        string $signature,
        string $recipientEmail = ''
    ): string {
        $normRecipient = strtolower(trim($recipientEmail));
        return hash('sha256', "claim_redeemed|{$businessId}|{$expiresAt}|{$normRecipient}|{$signature}");
    }

    public static function isClaimInviteConsumed(
        PDO $pdo,
        int $businessId,
        int $expiresAt,
        string $signature,
        string $recipientEmail = ''
    ): bool {
        $nh = self::claimInviteNonceHash($businessId, $expiresAt, $signature, $recipientEmail);
        $stmt = $pdo->prepare("SELECT 1 FROM used_nonces WHERE nonce_hash = :nh LIMIT 1");
        $stmt->execute([':nh' => $nh]);
        return (bool)$stmt->fetchColumn();
    }

    public static function consumeClaimInviteOnce(
        PDO $pdo,
        int $businessId,
        int $expiresAt,
        string $signature,
        string $recipientEmail = ''
    ): bool {
        if (!self::verifyClaimInviteSignature($businessId, $expiresAt, $signature, $recipientEmail)) {
            return false;
        }
        $nh = self::claimInviteNonceHash($businessId, $expiresAt, $signature, $recipientEmail);
        $chk = $pdo->prepare("SELECT 1 FROM used_nonces WHERE nonce_hash = :nh LIMIT 1");
        $chk->execute([':nh' => $nh]);
        if ($chk->fetchColumn()) {
            return false;
        }
        try {
            $ins = $pdo->prepare("INSERT INTO used_nonces (nonce_hash, expires_at) VALUES (:nh, :exp)");
            $ins->execute([':nh' => $nh, ':exp' => max($expiresAt, time() + 60)]);
            return true;
        } catch (\Throwable $e) {
            error_log('[OwnerAuth::consumeClaimInviteOnce] Nonce lock skipped/failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine whether $email is authorized to redeem a verified claim invitation for $businessId (#162, #163).
     */
    public static function isEmailEligibleForClaim(
        PDO $pdo,
        int $businessId,
        string $email,
        string $invitedEmail = ''
    ): bool {
        $normEmail = strtolower(trim($email));
        if ($businessId <= 0 || $normEmail === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $normInvited = strtolower(trim($invitedEmail));
        if ($normInvited !== '' && !hash_equals($normInvited, $normEmail)) {
            return false;
        }

        $bStmt = $pdo->prepare("SELECT id, owner_email, email_public FROM businesses WHERE id = :id LIMIT 1");
        $bStmt->execute([':id' => $businessId]);
        $biz = $bStmt->fetch();
        if (!$biz) {
            return false;
        }

        $designated = [];
        foreach (self::getOwnersForBusiness($pdo, $businessId) as $linked) {
            $lem = strtolower(trim((string)($linked['email'] ?? '')));
            if ($lem !== '' && filter_var($lem, FILTER_VALIDATE_EMAIL)) {
                $designated[$lem] = true;
            }
        }
        $ownEm = strtolower(trim((string)($biz['owner_email'] ?? '')));
        if ($ownEm !== '' && filter_var($ownEm, FILTER_VALIDATE_EMAIL)) {
            $designated[$ownEm] = true;
        }

        if (!empty($designated)) {
            $pubEm = strtolower(trim((string)($biz['email_public'] ?? '')));
            if ($pubEm !== '' && filter_var($pubEm, FILTER_VALIDATE_EMAIL)) {
                $designated[$pubEm] = true;
            }
            return isset($designated[$normEmail]);
        }

        return true;
    }

    public static function storeVerifiedClaimInSession(
        int $businessId,
        int $expiresAt,
        string $signature,
        string $invitedEmail = ''
    ): void {
        if (!self::verifyClaimInviteSignature($businessId, $expiresAt, $signature, $invitedEmail)) {
            return;
        }
        self::startSecureSession();
        unset($_SESSION['bbiz_pending_claim_biz_id']);
        $_SESSION['bbiz_verified_claim'] = [
            'biz_id' => $businessId,
            'exp' => $expiresAt,
            'sig' => $signature,
            'em' => strtolower(trim($invitedEmail)),
        ];
    }

    public static function clearVerifiedClaimSession(): void
    {
        self::startSecureSession();
        unset($_SESSION['bbiz_verified_claim'], $_SESSION['bbiz_pending_claim_biz_id']);
    }

    /**
     * Retrieve and re-verify a signed, unconsumed claim invitation stored in the PHP session (#163).
     *
     * @return array{biz_id:int,exp:int,sig:string,em:string}|null
     */
    public static function getVerifiedClaimFromSession(PDO $pdo): ?array
    {
        self::startSecureSession();
        unset($_SESSION['bbiz_pending_claim_biz_id']);
        $raw = $_SESSION['bbiz_verified_claim'] ?? null;
        if (!is_array($raw)) {
            return null;
        }
        $bizId = (int)($raw['biz_id'] ?? 0);
        $exp = (int)($raw['exp'] ?? 0);
        $sig = trim((string)($raw['sig'] ?? ''));
        $em = strtolower(trim((string)($raw['em'] ?? '')));

        if (!self::verifyClaimInviteSignature($bizId, $exp, $sig, $em) ||
            self::isClaimInviteConsumed($pdo, $bizId, $exp, $sig, $em)) {
            self::clearVerifiedClaimSession();
            return null;
        }

        $bStmt = $pdo->prepare("SELECT id FROM businesses WHERE id = :id LIMIT 1");
        $bStmt->execute([':id' => $bizId]);
        if (!$bStmt->fetchColumn()) {
            self::clearVerifiedClaimSession();
            return null;
        }

        return [
            'biz_id' => $bizId,
            'exp' => $exp,
            'sig' => $sig,
            'em' => $em,
        ];
    }

    /**
     * Atomically verify, consume single-use nonce, and link a claimed business to a verified owner (#162, #163).
     */
    public static function redeemVerifiedClaimInvite(
        PDO $pdo,
        int $ownerId,
        string $ownerEmail,
        int $businessId,
        int $expiresAt,
        string $signature,
        string $invitedEmail = ''
    ): bool {
        if ($ownerId <= 0 ||
            !self::verifyClaimInviteSignature($businessId, $expiresAt, $signature, $invitedEmail) ||
            !self::isEmailEligibleForClaim($pdo, $businessId, $ownerEmail, $invitedEmail)) {
            return false;
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if (!self::consumeClaimInviteOnce($pdo, $businessId, $expiresAt, $signature, $invitedEmail)) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return false;
            }
            self::linkOwnerToBusiness($pdo, $ownerId, $businessId, 'claim_invite_link');
            if ($ownTx) {
                $pdo->commit();
            }
            self::clearVerifiedClaimSession();
            return true;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function redeemVerifiedClaimFromSession(PDO $pdo, int $ownerId, string $ownerEmail): ?int
    {
        $claim = self::getVerifiedClaimFromSession($pdo);
        if ($claim === null) {
            return null;
        }
        $ok = self::redeemVerifiedClaimInvite(
            $pdo,
            $ownerId,
            $ownerEmail,
            $claim['biz_id'],
            $claim['exp'],
            $claim['sig'],
            $claim['em']
        );
        if (!$ok) {
            self::clearVerifiedClaimSession();
            return null;
        }
        return $claim['biz_id'];
    }

    /**
     * Issue a single-use passwordless Magic Link token for an owner email.
     */
    public static function createMagicLink(PDO $pdo, string $email, ?int $claimBusinessId = null): array
    {
        $normEmail = strtolower(trim($email));
        if ($normEmail === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Please enter a valid email address.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = time() + self::MAGIC_LINK_TTL_SECONDS;

        $stmt = $pdo->prepare("
            INSERT INTO owner_auth_tokens (email, token_hash, claim_business_id, expires_at, created_at)
            VALUES (:em, :th, :cbid, :exp, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':em' => $normEmail,
            ':th' => $tokenHash,
            ':cbid' => ($claimBusinessId !== null && $claimBusinessId > 0) ? $claimBusinessId : null,
            ':exp' => $expiresAt,
        ]);

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $url = "{$baseUrl}/owner/verify-link.php?token=" . urlencode($rawToken);

        return [
            'raw_token' => $rawToken,
            'url' => $url,
            'expires_at' => $expiresAt,
            'email' => $normEmail,
        ];
    }

    /**
     * Verify and consume a single-use Magic Link token, returning the authenticated owner record.
     */
    public static function verifyAndConsumeMagicLink(PDO $pdo, string $rawToken): ?array
    {
        $clean = trim($rawToken);
        if ($clean === '' || strlen($clean) < 32) {
            return null;
        }
        $tokenHash = hash('sha256', $clean);
        $stmt = $pdo->prepare("
            SELECT * FROM owner_auth_tokens
            WHERE token_hash = :th
              AND used_at IS NULL
              AND expires_at >= :now
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash, ':now' => time()]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $pdo->prepare("UPDATE owner_auth_tokens SET used_at = :now WHERE id = :id")
            ->execute([':now' => time(), ':id' => (int)$row['id']]);

        $normTokenEmail = strtolower(trim((string)$row['email']));
        $owner = self::upsertOwner($pdo, $normTokenEmail);
        $claimBizId = !empty($row['claim_business_id']) ? (int)$row['claim_business_id'] : 0;
        if ($claimBizId > 0 && self::isEmailEligibleForClaim($pdo, $claimBizId, $normTokenEmail)) {
            self::linkOwnerToBusiness($pdo, (int)$owner['id'], $claimBizId, 'claim_invite_link');
        } else {
            $claimBizId = 0;
        }

        return [
            'owner' => $owner,
            'claimed_business_id' => $claimBizId > 0 ? $claimBizId : null,
        ];
    }

    private static function setOwnerCookieValue(string $cookieVal, int $expTs): void
    {
        $cName = self::cookieName();
        if (!headers_sent()) {
            setcookie($cName, $cookieVal, [
                'expires' => $expTs,
                'path' => self::cookiePath(),
                'secure' => self::isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if ($cookieVal === '' || $expTs < time()) {
            unset($_COOKIE[$cName]);
        } else {
            $_COOKIE[$cName] = $cookieVal;
        }
    }

    /**
     * Start a server-side revocable 30-day persistent session (`owner_sessions`) + regenerated PHP session (#165).
     */
    public static function establishOwnerSession(array $owner, bool $regenerateSessionId = true, ?PDO $pdo = null): void
    {
        self::startSecureSession();
        if ($regenerateSessionId && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }

        $conn = $pdo ?? Database::getConnection();
        $ownerId = (int)($owner['id'] ?? 0);
        if ($ownerId <= 0) {
            return;
        }

        $avStmt = $conn->prepare("SELECT email, auth_version FROM business_owners WHERE id = :id LIMIT 1");
        $avStmt->execute([':id' => $ownerId]);
        $dbOwner = $avStmt->fetch();
        $email = strtolower(trim((string)($dbOwner['email'] ?? ($owner['email'] ?? ''))));
        $authVersion = max(1, (int)($dbOwner['auth_version'] ?? ($owner['auth_version'] ?? 1)));

        $nowSql = gmdate('Y-m-d H:i:s');
        $prevSelector = trim((string)($_SESSION['bbiz_owner_session_selector'] ?? ''));
        if ($prevSelector !== '') {
            $conn->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE selector = :sel AND revoked_at IS NULL")
                ->execute([':now' => $nowSql, ':sel' => $prevSelector]);
        }

        $selector = bin2hex(random_bytes(12)); // 24 hex chars
        $validator = bin2hex(random_bytes(32)); // 64 hex chars
        $validatorHash = hash('sha256', $validator);
        $expTs = time() + self::SESSION_TTL_SECONDS;
        $expSql = gmdate('Y-m-d H:i:s', $expTs);

        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $ipHmac = $remoteIp !== '' ? hash_hmac('sha256', 'owner_sess_ip|' . $remoteIp, $secret) : null;
        $uaSummary = mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 250) ?: null;

        $conn->prepare("
            INSERT INTO owner_sessions (
                owner_id, selector, validator_hash, auth_version,
                ip_hmac, user_agent_summary, expires_at, revoked_at, created_at, last_seen_at
            ) VALUES (
                :oid, :sel, :vh, :av,
                :iph, :ua, :exp, NULL, :now, :now2
            )
        ")->execute([
            ':oid' => $ownerId,
            ':sel' => $selector,
            ':vh' => $validatorHash,
            ':av' => $authVersion,
            ':iph' => $ipHmac,
            ':ua' => $uaSummary,
            ':exp' => $expSql,
            ':now' => $nowSql,
            ':now2' => $nowSql,
        ]);

        $_SESSION['bbiz_owner_id'] = $ownerId;
        $_SESSION['bbiz_owner_email'] = $email;
        $_SESSION['bbiz_owner_auth_version'] = $authVersion;
        $_SESSION['bbiz_owner_session_selector'] = $selector;

        $cookieVal = "v2.{$selector}.{$validator}";
        self::setOwnerCookieValue($cookieVal, $expTs);
    }

    private static function clearLocalOwnerSessionState(): void
    {
        unset(
            $_SESSION['bbiz_owner_id'],
            $_SESSION['bbiz_owner_email'],
            $_SESSION['bbiz_owner_auth_version'],
            $_SESSION['bbiz_owner_session_selector']
        );
    }

    /**
     * Retrieve the currently authenticated owner, enforcing server-side `owner_sessions` + `auth_version` checks (#165).
     */
    public static function getCurrentOwner(PDO $pdo): ?array
    {
        self::startSecureSession();

        $cName = self::cookieName();
        $nowSql = gmdate('Y-m-d H:i:s');

        // 1. Validate active PHP session against server-side owner_sessions & auth_version
        $sessOwnerId = (int)($_SESSION['bbiz_owner_id'] ?? 0);
        $sessSelector = trim((string)($_SESSION['bbiz_owner_session_selector'] ?? ''));
        $sessAuthVer = (int)($_SESSION['bbiz_owner_auth_version'] ?? 0);

        if ($sessOwnerId > 0) {
            if ($sessSelector === '' || $sessAuthVer <= 0) {
                self::clearLocalOwnerSessionState();
            } else {
                $sStmt = $pdo->prepare("
                    SELECT s.id AS sess_row_id, s.auth_version AS sess_av, o.*
                    FROM owner_sessions s
                    INNER JOIN business_owners o ON o.id = s.owner_id
                    WHERE s.selector = :sel
                      AND s.owner_id = :oid
                      AND s.revoked_at IS NULL
                      AND s.expires_at >= :now
                    LIMIT 1
                ");
                $sStmt->execute([
                    ':sel' => $sessSelector,
                    ':oid' => $sessOwnerId,
                    ':now' => $nowSql,
                ]);
                $joined = $sStmt->fetch();
                $dbAuthVer = $joined ? max(1, (int)($joined['auth_version'] ?? 1)) : 0;

                if ($joined && (int)$joined['sess_av'] === $dbAuthVer && $sessAuthVer === $dbAuthVer) {
                    $pdo->prepare("UPDATE owner_sessions SET last_seen_at = :now WHERE id = :id")
                        ->execute([':now' => $nowSql, ':id' => (int)$joined['sess_row_id']]);
                    unset($joined['sess_row_id'], $joined['sess_av']);
                    self::autoLinkMatchingBusinesses($pdo, (int)$joined['id'], (string)$joined['email']);
                    return (array)$joined;
                }

                // Active PHP session was revoked or password/auth_version changed — purge local state & stale cookie
                self::clearLocalOwnerSessionState();
                if (!empty($_COOKIE[$cName]) && str_starts_with((string)$_COOKIE[$cName], 'v2.' . $sessSelector . '.')) {
                    self::setOwnerCookieValue('', time() - 3600);
                }
            }
        }

        // 2. Validate persistent v2.<selector>.<validator> cookie against server-side owner_sessions
        $rawCookie = trim((string)($_COOKIE[$cName] ?? ''));
        if ($rawCookie === '') {
            return null;
        }

        if (!preg_match('/^v2\.([a-f0-9]{24})\.([a-f0-9]{64})$/', $rawCookie, $m)) {
            // Reject legacy stateless cookies immediately (#165)
            self::setOwnerCookieValue('', time() - 3600);
            return null;
        }

        $selector = $m[1];
        $validator = $m[2];

        $cStmt = $pdo->prepare("
            SELECT * FROM owner_sessions
            WHERE selector = :sel
              AND revoked_at IS NULL
              AND expires_at >= :now
            LIMIT 1
        ");
        $cStmt->execute([':sel' => $selector, ':now' => $nowSql]);
        $sessRow = $cStmt->fetch();
        if (!$sessRow) {
            self::setOwnerCookieValue('', time() - 3600);
            return null;
        }

        $calcHash = hash('sha256', $validator);
        if (!hash_equals((string)$sessRow['validator_hash'], $calcHash)) {
            // Possible validator tampering on a known selector — revoke server-side row immediately
            $pdo->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE id = :id")
                ->execute([':now' => $nowSql, ':id' => (int)$sessRow['id']]);
            self::setOwnerCookieValue('', time() - 3600);
            return null;
        }

        $oStmt = $pdo->prepare("SELECT * FROM business_owners WHERE id = :id LIMIT 1");
        $oStmt->execute([':id' => (int)$sessRow['owner_id']]);
        $owner = $oStmt->fetch();
        if (!$owner) {
            self::setOwnerCookieValue('', time() - 3600);
            return null;
        }

        $dbAuthVer = max(1, (int)($owner['auth_version'] ?? 1));
        if ((int)($sessRow['auth_version'] ?? 0) !== $dbAuthVer) {
            $pdo->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL")
                ->execute([':now' => $nowSql, ':id' => (int)$sessRow['id']]);
            self::setOwnerCookieValue('', time() - 3600);
            return null;
        }

        // Valid persistent session — regenerate PHP session ID on cookie restore and extend rolling 30-day TTL
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $newExpTs = time() + self::SESSION_TTL_SECONDS;
        $newExpSql = gmdate('Y-m-d H:i:s', $newExpTs);
        $pdo->prepare("UPDATE owner_sessions SET expires_at = :exp, last_seen_at = :now WHERE id = :id")
            ->execute([
                ':exp' => $newExpSql,
                ':now' => $nowSql,
                ':id' => (int)$sessRow['id'],
            ]);

        $_SESSION['bbiz_owner_id'] = (int)$owner['id'];
        $_SESSION['bbiz_owner_email'] = strtolower(trim((string)$owner['email']));
        $_SESSION['bbiz_owner_auth_version'] = $dbAuthVer;
        $_SESSION['bbiz_owner_session_selector'] = $selector;
        self::setOwnerCookieValue($rawCookie, $newExpTs);

        self::autoLinkMatchingBusinesses($pdo, (int)$owner['id'], (string)$owner['email']);
        return (array)$owner;
    }

    /**
     * Require an authenticated owner or redirect to /owner/login.php.
     */
    public static function requireOwner(PDO $pdo): array
    {
        $owner = self::getCurrentOwner($pdo);
        if ($owner !== null) {
            return $owner;
        }
        if (!headers_sent()) {
            header('Location: /owner/login.php');
        }
        exit;
    }

    /**
     * Revoke all active server-side sessions for an owner (and optionally bump auth_version) (#165).
     */
    public static function revokeAllOwnerSessions(PDO $pdo, int $ownerId, bool $bumpAuthVersion = true): int
    {
        if ($ownerId <= 0) {
            return 0;
        }
        if ($bumpAuthVersion) {
            $pdo->prepare("UPDATE business_owners SET auth_version = COALESCE(auth_version, 1) + 1 WHERE id = :id")
                ->execute([':id' => $ownerId]);
        }
        $stmt = $pdo->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE owner_id = :oid AND revoked_at IS NULL");
        $stmt->execute([
            ':now' => gmdate('Y-m-d H:i:s'),
            ':oid' => $ownerId,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Sign out the current device session, revoking its server-side `owner_sessions` token and rotating PHP session ID (#165).
     */
    public static function logoutOwner(?PDO $pdo = null): void
    {
        self::startSecureSession();

        $cName = self::cookieName();
        $selector = trim((string)($_SESSION['bbiz_owner_session_selector'] ?? ''));
        if ($selector === '' && !empty($_COOKIE[$cName])) {
            if (preg_match('/^v2\.([a-f0-9]{24})\.[a-f0-9]{64}$/', (string)$_COOKIE[$cName], $m)) {
                $selector = $m[1];
            }
        }

        if ($selector !== '') {
            try {
                $conn = $pdo ?? Database::getConnection();
                $conn->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE selector = :sel AND revoked_at IS NULL")
                    ->execute([':now' => gmdate('Y-m-d H:i:s'), ':sel' => $selector]);
            } catch (\Throwable $e) {
                error_log('[OwnerAuth::logoutOwner] Session revoke skipped: ' . $e->getMessage());
            }
        }

        unset(
            $_SESSION['bbiz_owner_id'],
            $_SESSION['bbiz_owner_email'],
            $_SESSION['bbiz_owner_auth_version'],
            $_SESSION['bbiz_owner_session_selector'],
            $_SESSION['bbiz_pending_claim_biz_id'],
            $_SESSION['bbiz_verified_claim'],
            $_SESSION['bbiz_self_submitted_pending']
        );

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        self::setOwnerCookieValue('', time() - 3600);
    }

    /**
     * Set or update an owner's password, bumping `auth_version` and revoking all prior persistent sessions (#119, #165).
     */
    public static function setOwnerPassword(PDO $pdo, int $ownerId, string $plainPassword): void
    {
        if ($ownerId <= 0) {
            throw new \InvalidArgumentException('Invalid owner account.');
        }
        if (mb_strlen($plainPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long.');
        }
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $pdo->prepare("
            UPDATE business_owners
            SET password_hash = :ph,
                auth_version = COALESCE(auth_version, 1) + 1
            WHERE id = :id
        ")->execute([':ph' => $hash, ':id' => $ownerId]);

        $pdo->prepare("UPDATE owner_sessions SET revoked_at = :now WHERE owner_id = :oid AND revoked_at IS NULL")
            ->execute([':now' => gmdate('Y-m-d H:i:s'), ':oid' => $ownerId]);
    }

    /**
     * Privacy-safe email masker (e.g. justinake@yahoo.com -> j•••••••e@yahoo.com) (#152, #154).
     */
    public static function maskEmailAddress(string $email): string
    {
        $clean = strtolower(trim($email));
        if ($clean === '' || !str_contains($clean, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $clean, 2);
        $len = mb_strlen($local);
        if ($len <= 1) {
            $maskedLocal = $local . '••';
        } elseif ($len === 2) {
            $maskedLocal = mb_substr($local, 0, 1) . '••';
        } else {
            $dots = str_repeat('•', min(8, max(3, $len - 2)));
            $maskedLocal = mb_substr($local, 0, 1) . $dots . mb_substr($local, -1);
        }
        return $maskedLocal . '@' . $domain;
    }

    /**
     * Return deduplicated, privacy-masked email hints that can manage a business (#152, #154, #164).
     * Never advertises wildcard corporate domain (*@domain) hints.
     *
     * @return list<string>
     */
    public static function getMaskedOwnerEmailHintsForBusiness(PDO $pdo, int $businessId): array
    {
        if ($businessId <= 0) {
            return [];
        }
        $rawEmails = [];
        foreach (self::getOwnersForBusiness($pdo, $businessId) as $o) {
            if (!empty($o['email'])) {
                $rawEmails[] = strtolower(trim((string)$o['email']));
            }
        }

        $bStmt = $pdo->prepare("SELECT owner_email, email_public FROM businesses WHERE id = :id LIMIT 1");
        $bStmt->execute([':id' => $businessId]);
        $bRow = $bStmt->fetch();
        if ($bRow) {
            foreach (['owner_email', 'email_public'] as $col) {
                $val = strtolower(trim((string)($bRow[$col] ?? '')));
                if ($val !== '' && filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $rawEmails[] = $val;
                }
            }
        }

        $seen = [];
        $hints = [];
        foreach ($rawEmails as $em) {
            if (isset($seen[$em])) {
                continue;
            }
            $seen[$em] = true;
            $masked = self::maskEmailAddress($em);
            if ($masked !== '') {
                $hints[] = $masked;
            }
        }

        return $hints;
    }

    /**
     * Look up an existing business_owners record by ID (#152).
     */
    public static function getOwnerById(PDO $pdo, int $ownerId): ?array
    {
        if ($ownerId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM business_owners WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $ownerId]);
        $row = $stmt->fetch();
        return $row ? (array)$row : null;
    }

    public static function findOwnerByEmail(PDO $pdo, string $email): ?array
    {
        $norm = strtolower(trim($email));
        if ($norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM business_owners WHERE LOWER(email) = :em LIMIT 1");
        $stmt->execute([':em' => $norm]);
        $row = $stmt->fetch();
        return $row ? (array)$row : null;
    }

    /**
     * Record a pending listing submitted in the current browser session (#162).
     */
    public static function recordSelfSubmittedPendingInSession(int $businessId, string $submitterEmail): void
    {
        $norm = strtolower(trim($submitterEmail));
        if ($businessId <= 0 || $norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        self::startSecureSession();
        $items = is_array($_SESSION['bbiz_self_submitted_pending'] ?? null)
            ? $_SESSION['bbiz_self_submitted_pending']
            : [];
        $items[(string)$businessId] = [
            'biz_id' => $businessId,
            'email' => $norm,
            'recorded_at' => time(),
        ];
        $_SESSION['bbiz_self_submitted_pending'] = $items;
    }

    /**
     * Link any pending submissions initiated in this exact browser session once $ownerEmail is authenticated (#162).
     *
     * @return list<int>
     */
    public static function linkSessionSubmittedPendingBusinesses(PDO $pdo, int $ownerId, string $ownerEmail): array
    {
        $normOwner = strtolower(trim($ownerEmail));
        if ($ownerId <= 0 || $normOwner === '' || !filter_var($normOwner, FILTER_VALIDATE_EMAIL)) {
            return [];
        }
        self::startSecureSession();
        $items = $_SESSION['bbiz_self_submitted_pending'] ?? null;
        if (!is_array($items) || empty($items)) {
            return [];
        }

        $linked = [];
        $remaining = [];
        $chkStmt = $pdo->prepare("
            SELECT b.id
            FROM business_vetting_queue v
            INNER JOIN businesses b ON b.id = v.business_id
            WHERE v.business_id = :bid
              AND LOWER(v.submitter_email) = :em
              AND b.status = 'pending'
            LIMIT 1
        ");
        $updEmail = $pdo->prepare("
            UPDATE businesses
            SET owner_email = CASE WHEN owner_email IS NULL OR owner_email = '' THEN :em ELSE owner_email END
            WHERE id = :bid
        ");

        foreach ($items as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bid = (int)($entry['biz_id'] ?? 0);
            $recEm = strtolower(trim((string)($entry['email'] ?? '')));
            $recAt = (int)($entry['recorded_at'] ?? 0);
            if ($bid <= 0 || $recEm === '' || ($recAt > 0 && (time() - $recAt) > 86400)) {
                continue;
            }
            if (!hash_equals($normOwner, $recEm)) {
                $remaining[(string)$key] = $entry;
                continue;
            }
            $chkStmt->execute([':bid' => $bid, ':em' => $normOwner]);
            if ($chkStmt->fetchColumn()) {
                $updEmail->execute([':em' => $normOwner, ':bid' => $bid]);
                self::linkOwnerToBusiness($pdo, $ownerId, $bid, 'verified_submitter_signin');
                $linked[] = $bid;
            }
        }

        if (empty($remaining)) {
            unset($_SESSION['bbiz_self_submitted_pending']);
        } else {
            $_SESSION['bbiz_self_submitted_pending'] = $remaining;
        }

        return $linked;
    }

    public static function findPendingSubmissionBusinessIdForEmail(PDO $pdo, string $email): ?int
    {
        $norm = strtolower(trim($email));
        if ($norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT v.business_id
            FROM business_vetting_queue v
            INNER JOIN businesses b ON b.id = v.business_id
            WHERE LOWER(v.submitter_email) = :em
              AND b.status = 'pending'
            ORDER BY v.business_id DESC
            LIMIT 1
        ");
        $stmt->execute([':em' => $norm]);
        $bid = (int)($stmt->fetchColumn() ?: 0);
        return $bid > 0 ? $bid : null;
    }

    /**
     * Check if an email belongs to an existing owner OR exactly matches a business's owner_email or email_public (#152, #164).
     * Corporate domain similarity alone does NOT qualify as a recognized owner email.
     */
    public static function isRecognizedOwnerEmail(PDO $pdo, string $email): bool
    {
        $norm = strtolower(trim($email));
        if ($norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (self::findOwnerByEmail($pdo, $norm) !== null) {
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM businesses
            WHERE status IN ('approved', 'pending')
              AND (LOWER(owner_email) = :em1 OR LOWER(email_public) = :em2)
            LIMIT 1
        ");
        $stmt->execute([':em1' => $norm, ':em2' => $norm]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Issue a single-use 6-digit numeric verification code (15-minute TTL) for setting or resetting an owner password (#152).
     *
     * @return array{code:string,expires_at:int,email:string}
     */
    public static function issuePasswordOtp(PDO $pdo, string $email, ?int $claimBusinessId = null): array
    {
        $normEmail = strtolower(trim($email));
        if ($normEmail === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Please enter a valid email address.');
        }

        $code = sprintf('%06d', random_int(100000, 999999));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $tokenHash = hash_hmac('sha256', "otp6|{$normEmail}|{$code}", $secret);
        $expiresAt = time() + 900; // 15 minutes

        $pdo->prepare("UPDATE owner_auth_tokens SET used_at = :now WHERE LOWER(email) = :em AND used_at IS NULL")
            ->execute([':now' => time(), ':em' => $normEmail]);

        $pdo->prepare("
            INSERT INTO owner_auth_tokens (email, token_hash, claim_business_id, expires_at, created_at)
            VALUES (:em, :th, :cbid, :exp, CURRENT_TIMESTAMP)
        ")->execute([
            ':em' => $normEmail,
            ':th' => $tokenHash,
            ':cbid' => ($claimBusinessId !== null && $claimBusinessId > 0) ? $claimBusinessId : null,
            ':exp' => $expiresAt,
        ]);

        return [
            'code' => $code,
            'expires_at' => $expiresAt,
            'email' => $normEmail,
        ];
    }

    /**
     * Verify a 6-digit verification code and set the owner's password in one atomic flow (#152, #165).
     */
    public static function verifyOtpAndSetPassword(PDO $pdo, string $email, string $submittedCode, string $newPassword): ?array
    {
        $normEmail = strtolower(trim($email));
        $digits = preg_replace('/\D+/', '', $submittedCode) ?? '';
        if ($normEmail === '' || strlen($digits) !== 6) {
            return null;
        }
        if (mb_strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters long.');
        }

        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $expectedHash = hash_hmac('sha256', "otp6|{$normEmail}|{$digits}", $secret);

        $stmt = $pdo->prepare("
            SELECT * FROM owner_auth_tokens
            WHERE LOWER(email) = :em
              AND token_hash = :th
              AND used_at IS NULL
              AND expires_at >= :now
            LIMIT 1
        ");
        $stmt->execute([
            ':em' => $normEmail,
            ':th' => $expectedHash,
            ':now' => time(),
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $consume = $pdo->prepare("UPDATE owner_auth_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL");
            $consume->execute([':now' => time(), ':id' => (int)$row['id']]);
            if ($consume->rowCount() < 1) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return null;
            }

            $owner = self::upsertOwner($pdo, $normEmail);
            self::setOwnerPassword($pdo, (int)$owner['id'], $newPassword);

            $claimBizId = !empty($row['claim_business_id']) ? (int)$row['claim_business_id'] : 0;
            if ($claimBizId > 0) {
                $vStmt = $pdo->prepare("SELECT 1 FROM business_vetting_queue WHERE business_id = :bid AND LOWER(submitter_email) = :em LIMIT 1");
                $vStmt->execute([':bid' => $claimBizId, ':em' => $normEmail]);
                $matchesPendingSubmission = (bool)$vStmt->fetchColumn();

                if ($matchesPendingSubmission || self::isEmailEligibleForClaim($pdo, $claimBizId, $normEmail)) {
                    self::linkOwnerToBusiness($pdo, (int)$owner['id'], $claimBizId, 'otp_password_setup');
                }
            }

            if ($ownTx) {
                $pdo->commit();
            }
            return self::getOwnerById($pdo, (int)$owner['id']);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Authenticate an owner using email and password from any device (#119).
     */
    public static function authenticateWithPassword(PDO $pdo, string $email, string $plainPassword): ?array
    {
        $normEmail = strtolower(trim($email));
        if ($normEmail === '' || $plainPassword === '' || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT * FROM business_owners WHERE LOWER(email) = :em LIMIT 1");
        $stmt->execute([':em' => $normEmail]);
        $owner = $stmt->fetch();
        if (!$owner || empty($owner['password_hash'])) {
            return null;
        }
        if (!password_verify($plainPassword, (string)$owner['password_hash'])) {
            return null;
        }
        $pdo->prepare("UPDATE business_owners SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => (int)$owner['id']]);
        self::autoLinkMatchingBusinesses($pdo, (int)$owner['id'], $normEmail);

        $out = $pdo->prepare("SELECT * FROM business_owners WHERE id = :id");
        $out->execute([':id' => (int)$owner['id']]);
        return (array)$out->fetch();
    }

    public static function getGoogleClientId(?PDO $pdo = null): string
    {
        $conn = $pdo ?? Database::getConnection();
        return trim(Database::getSetting($conn, 'GOOGLE_CLIENT_ID', ''));
    }

    public static function getGoogleClientSecret(?PDO $pdo = null): string
    {
        $conn = $pdo ?? Database::getConnection();
        return trim(Database::getSetting($conn, 'GOOGLE_CLIENT_SECRET', ''));
    }

    public static function isGoogleSsoConfigured(?PDO $pdo = null): bool
    {
        $clientId = self::getGoogleClientId($pdo);
        $clientSecret = self::getGoogleClientSecret($pdo);
        return $clientId !== '' && $clientSecret !== '';
    }

    public static function getGoogleAuthorizationUrl(string $stateToken, ?PDO $pdo = null): string
    {
        $clientId = self::getGoogleClientId($pdo);
        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $redirectUri = "{$baseUrl}/owner/google-callback.php";

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $stateToken,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    public static function exchangeGoogleCodeForProfile(string $code, ?PDO $pdo = null): ?array
    {
        if (!self::isGoogleSsoConfigured($pdo) || !function_exists('curl_init')) {
            return null;
        }
        $clientId = self::getGoogleClientId($pdo);
        $clientSecret = self::getGoogleClientSecret($pdo);
        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $redirectUri = "{$baseUrl}/owner/google-callback.php";

        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $rawTokenResp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($rawTokenResp) || $httpCode !== 200) {
            return null;
        }
        $tokenData = json_decode($rawTokenResp, true);
        $accessToken = (string)($tokenData['access_token'] ?? '');
        if ($accessToken === '') {
            return null;
        }

        $uch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        if ($uch === false) {
            return null;
        }
        curl_setopt_array($uch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $rawUser = curl_exec($uch);
        $uCode = (int)curl_getinfo($uch, CURLINFO_HTTP_CODE);
        curl_close($uch);

        if (!is_string($rawUser) || $uCode !== 200) {
            return null;
        }
        $userData = json_decode($rawUser, true);
        if (!is_array($userData) || empty($userData['email']) || empty($userData['email_verified'])) {
            return null;
        }

        return [
            'email' => strtolower(trim((string)$userData['email'])),
            'name' => trim((string)($userData['name'] ?? '')),
            'sub' => trim((string)($userData['sub'] ?? '')),
        ];
    }
}
