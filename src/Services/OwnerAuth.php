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
                INSERT INTO business_owners (email, full_name, phone, google_sub, created_at, last_login_at)
                VALUES (:em, :nm, :ph, :sub, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([
                ':em' => $normEmail,
                ':nm' => trim((string)($fullName ?? '')) ?: null,
                ':ph' => trim((string)($phone ?? '')) ?: null,
                ':sub' => trim((string)($googleSub ?? '')) ?: null,
            ]);
            $ownerId = (int)$pdo->lastInsertId();
        }

        // Automatically link any pre-populated or submitted businesses matching this owner's email or corporate domain
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
     * Post-Registration Auto-Linker:
     * Links any business where:
     * 1. LOWER(businesses.owner_email) === LOWER($email) (Admin pre-assigned or submitter email)
     * 2. LOWER(businesses.email_public) === LOWER($email) (e.g. bustanibistro@gmail.com)
     * 3. The owner's email uses a custom corporate domain (not gmail/yahoo/etc.) that matches the
     *    website_url or email_public domain of the business (e.g. @branfoot.com, @prescott.co.ke, @sasasignal.com).
     */
    public static function autoLinkMatchingBusinesses(PDO $pdo, int $ownerId, string $email): array
    {
        $normEmail = strtolower(trim($email));
        $emailDomain = self::extractEmailDomain($normEmail);
        $isCorporateDomain = ($emailDomain !== null && !self::isFreemailDomain($emailDomain));

        $rows = $pdo->query("
            SELECT id, name, email_public, owner_email, website_url
            FROM businesses
            WHERE status IN ('approved', 'pending')
        ")->fetchAll();

        $linkedIds = [];
        foreach ($rows as $row) {
            $bid = (int)$row['id'];
            $pubEmail = strtolower(trim((string)($row['email_public'] ?? '')));
            $ownEmail = strtolower(trim((string)($row['owner_email'] ?? '')));
            $webDomain = self::extractWebsiteDomain($row['website_url'] ?? null);
            $pubEmailDomain = $pubEmail !== '' ? self::extractEmailDomain($pubEmail) : null;

            $via = null;
            if ($ownEmail !== '' && $ownEmail === $normEmail) {
                $via = 'admin_or_submitter_email';
            } elseif ($pubEmail !== '' && $pubEmail === $normEmail) {
                $via = 'public_email_match';
            } elseif ($isCorporateDomain && (
                ($webDomain !== null && $webDomain === $emailDomain) ||
                ($pubEmailDomain !== null && !self::isFreemailDomain($pubEmailDomain) && $pubEmailDomain === $emailDomain)
            )) {
                $via = 'corporate_domain_match';
            }

            if ($via !== null) {
                self::linkOwnerToBusiness($pdo, $ownerId, $bid, $via);
                $linkedIds[] = $bid;
            }
        }

        return $linkedIds;
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
     * The Admin can copy this link and send it in a WhatsApp/DM to the business owner;
     * when the owner clicks it and signs in via Google SSO or Email Magic Link, the business is linked to their account.
     */
    public static function generateClaimInviteUrl(int $businessId, ?int $expiresAt = null): string
    {
        $exp = $expiresAt ?? (time() + self::CLAIM_LINK_TTL_SECONDS);
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "claim_invite|{$businessId}|{$exp}";
        $sig = hash_hmac('sha256', $payload, $secret);

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        return "{$baseUrl}/owner/claim.php?" . http_build_query([
            'biz_id' => $businessId,
            'exp' => $exp,
            'sig' => $sig,
        ]);
    }

    public static function verifyClaimInviteSignature(int $businessId, int $expiresAt, string $signature): bool
    {
        if ($businessId <= 0 || $expiresAt < time() || $signature === '') {
            return false;
        }
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "claim_invite|{$businessId}|{$expiresAt}";
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
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

        $owner = self::upsertOwner($pdo, (string)$row['email']);
        $claimBizId = !empty($row['claim_business_id']) ? (int)$row['claim_business_id'] : 0;
        if ($claimBizId > 0) {
            self::linkOwnerToBusiness($pdo, (int)$owner['id'], $claimBizId, 'claim_invite_link');
        }

        return [
            'owner' => $owner,
            'claimed_business_id' => $claimBizId > 0 ? $claimBizId : null,
        ];
    }

    /**
     * Start a persistent 30-day signed session cookie + PHP session for the authenticated owner.
     */
    public static function establishOwnerSession(array $owner): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $ownerId = (int)$owner['id'];
        $email = strtolower(trim((string)$owner['email']));
        $exp = time() + self::SESSION_TTL_SECONDS;

        $_SESSION['bbiz_owner_id'] = $ownerId;
        $_SESSION['bbiz_owner_email'] = $email;

        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "{$ownerId}|{$email}|{$exp}";
        $sig = hash_hmac('sha256', $payload, $secret);
        $cookieVal = base64_encode("{$payload}|{$sig}");

        $cName = self::cookieName();
        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie($cName, $cookieVal, [
                'expires' => $exp,
                'path' => self::cookiePath(),
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[$cName] = $cookieVal;
    }

    /**
     * Retrieve the currently authenticated owner from session or 30-day persistent signed cookie.
     */
    public static function getCurrentOwner(PDO $pdo): ?array
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $cName = self::cookieName();
        $ownerId = (int)($_SESSION['bbiz_owner_id'] ?? 0);
        $shouldExtendRollingCookie = false;
        if ($ownerId <= 0 && !empty($_COOKIE[$cName])) {
            $decoded = base64_decode((string)$_COOKIE[$cName], true);
            if (is_string($decoded) && substr_count($decoded, '|') === 3) {
                [$cid, $cem, $cexp, $csig] = explode('|', $decoded, 4);
                $expInt = (int)$cexp;
                if ($expInt >= time()) {
                    $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
                    $expected = hash_hmac('sha256', "{$cid}|{$cem}|{$expInt}", $secret);
                    if (hash_equals($expected, $csig)) {
                        $ownerId = (int)$cid;
                        $_SESSION['bbiz_owner_id'] = $ownerId;
                        $_SESSION['bbiz_owner_email'] = $cem;
                        $shouldExtendRollingCookie = true;
                    }
                }
            }
        }

        if ($ownerId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM business_owners WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $ownerId]);
        $owner = $stmt->fetch();
        if (!$owner) {
            return null;
        }

        // Rolling 30-day session renewal so active owners stay signed in continuously (#119)
        if ($shouldExtendRollingCookie) {
            self::establishOwnerSession($owner);
        }

        // Ensure any newly matching businesses are auto-linked
        self::autoLinkMatchingBusinesses($pdo, (int)$owner['id'], (string)$owner['email']);
        return $owner;
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

    public static function logoutOwner(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        unset($_SESSION['bbiz_owner_id'], $_SESSION['bbiz_owner_email'], $_SESSION['bbiz_pending_claim_biz_id']);
        $cName = self::cookieName();
        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie($cName, '', [
                'expires' => time() - 3600,
                'path' => self::cookiePath(),
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE[$cName]);
    }

    /**
     * Set or update an owner's password for instant multi-device sign-in (#119).
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
        $pdo->prepare("UPDATE business_owners SET password_hash = :ph WHERE id = :id")
            ->execute([':ph' => $hash, ':id' => $ownerId]);
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

    /**
     * Check whether Google OAuth 2.0 SSO credentials are configured in .env or app_settings.
     */
    public static function isGoogleSsoConfigured(?PDO $pdo = null): bool
    {
        $clientId = self::getGoogleClientId($pdo);
        $clientSecret = self::getGoogleClientSecret($pdo);
        return $clientId !== '' && $clientSecret !== '';
    }

    /**
     * Build the Google OAuth 2.0 authorization URL.
     */
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

    /**
     * Exchange Google OAuth 2.0 authorization code for verified user profile ({email, name, sub}).
     */
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
