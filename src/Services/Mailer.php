<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class Mailer
{
    private const ACTION_LINK_TTL_SECONDS = 259200; // 72 hours

    /** @var list<int> Outbox IDs queued inside an active DB transaction, flushed only after commit (#188). */
    private static array $pendingPostCommitIds = [];

    /**
     * Generate a cryptographically signed one-click Admin Triage URL (HMAC-SHA256).
     */
    public static function generateSignedAdminActionUrl(
        int $businessId,
        string $action,
        ?int $expiresAt = null,
        ?int $mergeCategoryId = null,
        ?string $jtiOverride = null
    ): string {
        $exp = $expiresAt ?? (time() + self::ACTION_LINK_TTL_SECONDS);
        $merge = $mergeCategoryId ?? 0;
        $jti = ($jtiOverride !== null && trim($jtiOverride) !== '') ? trim($jtiOverride) : bin2hex(random_bytes(16));
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "{$businessId}|{$action}|{$exp}|{$merge}|{$jti}";
        $sig = hash_hmac('sha256', $payload, $secret);

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $query = http_build_query([
            'id' => $businessId,
            'action' => $action,
            'exp' => $exp,
            'merge' => $merge,
            'jti' => $jti,
            'sig' => $sig,
        ]);

        return "{$baseUrl}/admin/review-action.php?{$query}";
    }

    public static function verifySignedAdminAction(
        int $businessId,
        string $action,
        int $expiresAt,
        int $mergeCategoryId,
        string $signature,
        string $jti = ''
    ): bool {
        $cleanJti = trim($jti);
        if ($businessId <= 0 || $expiresAt < time() || $signature === '' || strlen($cleanJti) < 16 || !preg_match('/^[A-Za-z0-9_-]+$/', $cleanJti)) {
            return false;
        }
        if (!in_array($action, ['approve', 'reject'], true)) {
            return false;
        }
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "{$businessId}|{$action}|{$expiresAt}|{$mergeCategoryId}|{$cleanJti}";
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    public static function adminActionNonceHash(
        int $businessId,
        string $action,
        int $expiresAt,
        int $mergeCategoryId,
        string $jti
    ): string {
        return hash('sha256', "admin_review_jti|{$businessId}|{$action}|{$expiresAt}|{$mergeCategoryId}|" . trim($jti));
    }

    /**
     * Enqueue and dispatch notification to admin@busherianbiasharas.com when a new business is submitted.
     * STRICT DPA PRIVACY RULE (#167): NEVER include House, Year Left, or School Number in email bodies or email_outbox.
     * Alumni vetting credentials remain exclusively in `business_vetting_queue` for authenticated Admin Console review.
     */
    public static function notifyAdminNewSubmission(PDO $pdo, array $business, array $vetting, ?array $customCategory = null): int
    {
        $adminEmail = Database::env('ADMIN_EMAIL', 'admin@busherianbiasharas.com');
        $bizId = (int)$business['id'];
        $bizName = htmlspecialchars((string)$business['name'], ENT_QUOTES, 'UTF-8');
        $school = htmlspecialchars((string)$vetting['school_code'], ENT_QUOTES, 'UTF-8');
        $submitterName = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)$vetting['submitter_name']), ENT_QUOTES, 'UTF-8');
        $submitterEmail = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)$vetting['submitter_email']), ENT_QUOTES, 'UTF-8');

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $adminConsoleUrl = htmlspecialchars("{$baseUrl}/admin/index.php", ENT_QUOTES, 'UTF-8');
        $adminEditUrl = htmlspecialchars("{$baseUrl}/admin/edit-business.php?id={$bizId}", ENT_QUOTES, 'UTF-8');
        $approveUrl = htmlspecialchars(self::generateSignedAdminActionUrl($bizId, 'approve'), ENT_QUOTES, 'UTF-8');
        $rejectUrl  = htmlspecialchars(self::generateSignedAdminActionUrl($bizId, 'reject'), ENT_QUOTES, 'UTF-8');

        $catNotice = '';
        if ($customCategory !== null) {
            $customCatName = htmlspecialchars((string)$customCategory['name'], ENT_QUOTES, 'UTF-8');
            $catNotice = "<p style='background:#FFFBEB;border-left:4px solid #D4AF37;padding:10px;'><strong>Custom Specialty Requested:</strong> {$customCatName} (Pending Admin Approval)</p>";
        }

        $subject = "[Busherian Biasharas] Review Required: {$business['name']} ({$vetting['school_code']})";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:620px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:4px;'>New Busherian Biashara Submission</h2>
                <p style='color:#4B5563;margin-top:0;'>Submitted by <strong>{$submitterName}</strong> ({$submitterEmail})</p>
                <div style='background:#F0FDF4;border:1px solid #114B3E;border-radius:8px;padding:14px;margin:16px 0;'>
                    <h3 style='margin:0 0 8px 0;color:#114B3E;'>Submission Summary (Alumni Vetting Protected)</h3>
                    <p style='margin:4px 0;'><strong>Business Name:</strong> {$bizName} (#{$bizId})</p>
                    <p style='margin:4px 0;'><strong>School Affiliation:</strong> {$school}</p>
                    <p style='margin:8px 0 0 0;font-size:13px;color:#065F46;'>
                        🔒 To protect alumni privacy under the Kenya Data Protection Act, one-time verification details are never transmitted by email and can only be viewed inside the authenticated Admin Console before approval or rejection.
                    </p>
                </div>
                {$catNotice}
                <p style='margin-top:20px;'>
                    <a href='{$adminConsoleUrl}' style='background:#1F2937;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;margin-right:8px;'>Open Admin Review Queue</a>
                    <a href='{$adminEditUrl}' style='background:#D97706;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;'>Inspect Submission #{$bizId}</a>
                </p>
                <p style='margin-top:14px;'>
                    <a href='{$approveUrl}' style='background:#114B3E;color:#ffffff;padding:9px 15px;border-radius:6px;text-decoration:none;font-weight:bold;margin-right:8px;'>Quick Approve &amp; Purge Vetting</a>
                    <a href='{$rejectUrl}' style='background:#991B1B;color:#ffffff;padding:9px 15px;border-radius:6px;text-decoration:none;font-weight:bold;'>Quick Reject &amp; Purge Vetting</a>
                </p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $adminEmail, $subject, $bodyHtml, $submitterEmail, $bizId);
    }

    /**
     * Enqueue and dispatch an Appointment / Consultation Booking Request to a verified approved business (#284).
     * Wraps visitor contact & topic fields in `data-visitor-pii="1"` so third-party PII is automatically redacted
     * if a different person exports correspondence under Kenya DPA s. 38.
     */
    public static function sendBookingInquiry(PDO $pdo, array $business, string $recipientEmail, array $inquiry): int
    {
        $bizId = (int)($business['id'] ?? 0);
        $bizNameRaw = AntiSpamGate::sanitizeHeaderValue((string)($business['name'] ?? 'Busherian Business'));
        $bizName = htmlspecialchars($bizNameRaw, ENT_QUOTES, 'UTF-8');

        $reqNameClean = AntiSpamGate::sanitizeHeaderValue((string)($inquiry['requester_name'] ?? ''));
        $reqEmailClean = strtolower(trim(AntiSpamGate::sanitizeHeaderValue((string)($inquiry['requester_email'] ?? ''))));
        $reqPhoneClean = AntiSpamGate::sanitizeHeaderValue((string)($inquiry['requester_phone'] ?? ''));
        $prefDateClean = AntiSpamGate::sanitizeHeaderValue((string)($inquiry['preferred_date'] ?? ''));
        $prefTimeClean = AntiSpamGate::sanitizeHeaderValue((string)($inquiry['preferred_time'] ?? $inquiry['preferred_slot'] ?? ''));
        $topicClean = trim((string)($inquiry['topic'] ?? ''));

        $reqNameHtml = htmlspecialchars($reqNameClean, ENT_QUOTES, 'UTF-8');
        $reqEmailHtml = htmlspecialchars($reqEmailClean, ENT_QUOTES, 'UTF-8');
        $reqPhoneHtml = htmlspecialchars($reqPhoneClean !== '' ? $reqPhoneClean : 'Not provided', ENT_QUOTES, 'UTF-8');
        $prefDateHtml = htmlspecialchars($prefDateClean, ENT_QUOTES, 'UTF-8');
        $prefTimeHtml = htmlspecialchars($prefTimeClean, ENT_QUOTES, 'UTF-8');
        $topicHtml = nl2br(htmlspecialchars($topicClean, ENT_QUOTES, 'UTF-8'));

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $ownerPortalUrl = htmlspecialchars("{$baseUrl}/owner/index.php", ENT_QUOTES, 'UTF-8');

        $subject = "[Busherian Biasharas] Appointment Request for {$bizNameRaw} ({$prefDateClean})";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:620px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:4px;'>📅 New Appointment / Consultation Request</h2>
                <p style='color:#4B5563;margin-top:0;'>A visitor on <strong>Busherian Biasharas</strong> requested an appointment with <strong>{$bizName}</strong>.</p>
                <div style='background:#FAF6F0;border:1px solid #D4AF37;border-radius:10px;padding:14px;margin:16px 0;'>
                    <p style='margin:5px 0;'><strong>Requester Name:</strong> <span data-visitor-pii='1'>{$reqNameHtml}</span></p>
                    <p style='margin:5px 0;'><strong>Requester Email:</strong> <span data-visitor-pii='1'>{$reqEmailHtml}</span></p>
                    <p style='margin:5px 0;'><strong>Phone / WhatsApp:</strong> <span data-visitor-pii='1'>{$reqPhoneHtml}</span></p>
                    <p style='margin:5px 0;'><strong>Preferred Date:</strong> {$prefDateHtml}</p>
                    <p style='margin:5px 0;'><strong>Preferred Time (EAT):</strong> {$prefTimeHtml}</p>
                    <p style='margin:8px 0 0 0;'><strong>Consultation Topic / Note:</strong><br><span data-visitor-pii='1'>{$topicHtml}</span></p>
                </div>
                <p style='font-size:13px;color:#374151;'>
                    Reply directly to this email to confirm or reschedule with the requester. You can update or pause appointment booking anytime in your <a href='{$ownerPortalUrl}' style='color:#114B3E;font-weight:bold;'>Owner Portal</a>.
                </p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $recipientEmail, $subject, $bodyHtml, $reqEmailClean, $bizId);
    }

    public static function enqueueAndSend(
        PDO $pdo,
        string $recipient,
        string $subject,
        string $bodyHtml,
        ?string $replyTo = null,
        ?int $businessId = null,
        ?int $expiresAt = null
    ): int {
        $cleanReply = ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL))
            ? AntiSpamGate::sanitizeHeaderValue($replyTo)
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO email_outbox (business_id, recipient_email, reply_to, subject, body_html, status, attempts, lease_until, next_retry_at, expires_at, sent_at)
            VALUES (:bid, :to, :rto, :subj, :body, 'queued', 0, NULL, NULL, :exp, NULL)
        ");
        $stmt->execute([
            ':bid' => ($businessId !== null && $businessId > 0) ? $businessId : null,
            ':to' => $recipient,
            ':rto' => $cleanReply,
            ':subj' => AntiSpamGate::sanitizeHeaderValue($subject),
            ':body' => $bodyHtml,
            ':exp' => ($expiresAt !== null && $expiresAt > 0) ? $expiresAt : null,
        ]);
        $outboxId = (int)$pdo->lastInsertId();

        // Decouple network SMTP handshakes from open DB transactions (#188):
        // Never hold DB locks across network I/O or send phantom emails before commit.
        if ($pdo->inTransaction()) {
            self::$pendingPostCommitIds[] = $outboxId;
            return $outboxId;
        }

        self::dispatchOutboxRow($pdo, $outboxId);
        return $outboxId;
    }

    /**
     * Dispatch a single `email_outbox` row outside any DB transaction (#188).
     */
    /**
     * Atomically lease, verify auth-challenge freshness & central mail quota, and dispatch an outbox row (#211, #212).
     * Note: If the PHP process crashes after the remote SMTP server accepts `DATA` (250) and before the local DB
     * updates `status = 'sent'`, at-least-once delivery may re-send after `lease_until` (60s) expires.
     */
    public const OUTBOX_LEASE_SECONDS = 120;
    public const SMTP_TOTAL_DEADLINE_SECONDS = 25;

    /**
     * Check whether the configured SMTP security mode is permitted for the given environment (#250 M-10).
     * Production requires encrypted 'ssl' (SMTPS) or 'tls' (STARTTLS) and rejects plaintext modes.
     */
    public static function isSmtpSecurityAllowed(string $smtpSecure, string $appEnv, bool $simulate = false): bool
    {
        if ($simulate) {
            return true;
        }
        $normSec = strtolower(trim($smtpSecure));
        $normEnv = strtolower(trim($appEnv));
        if ($normEnv === 'production') {
            return in_array($normSec, ['ssl', 'tls'], true);
        }
        return in_array($normSec, ['ssl', 'tls', 'none', ''], true);
    }

    /**
     * Check whether local unauthenticated PHP `mail()` fallback is enabled (#250 M-10: disabled by default).
     */
    public static function isPhpMailFallbackEnabled(): bool
    {
        $disableFallback = strtolower(trim(Database::env('SMTP_DISABLE_MAIL_FALLBACK', 'yes')));
        $allowLegacy = strtolower(trim(Database::env('ALLOW_PHP_MAIL_FALLBACK', 'no')));
        if ($allowLegacy === 'yes' && $disableFallback === 'no') {
            return true;
        }
        return $disableFallback === 'no' && strtolower(trim(Database::env('APP_ENV', 'production'))) !== 'production';
    }

    /**
     * Atomically finalize a leased `email_outbox` row ONLY when the caller still holds `lease_token` (#248 M-08).
     */
    public static function finalizeLeasedOutboxRow(
        PDO $pdo,
        int $outboxId,
        string $leaseToken,
        string $newStatus,
        int $attempts,
        ?int $nextRetryAt = null
    ): bool {
        if ($leaseToken === '') {
            return false;
        }
        if (in_array($newStatus, ['sent', 'simulated'], true)) {
            $stmt = $pdo->prepare("
                UPDATE email_outbox
                SET status = :st,
                    attempts = :att,
                    lease_until = NULL,
                    lease_token = NULL,
                    next_retry_at = NULL,
                    sent_at = CURRENT_TIMESTAMP
                WHERE id = :id AND status = 'sending' AND lease_token = :ltok
            ");
            $stmt->execute([
                ':st' => $newStatus,
                ':att' => $attempts,
                ':id' => $outboxId,
                ':ltok' => $leaseToken,
            ]);
            return $stmt->rowCount() === 1;
        }
        $stmt = $pdo->prepare("
            UPDATE email_outbox
            SET status = :st,
                attempts = :att,
                lease_until = NULL,
                lease_token = NULL,
                next_retry_at = :nra
            WHERE id = :id AND status = 'sending' AND lease_token = :ltok
        ");
        $stmt->execute([
            ':st' => $newStatus,
            ':att' => $attempts,
            ':nra' => $nextRetryAt,
            ':id' => $outboxId,
            ':ltok' => $leaseToken,
        ]);
        return $stmt->rowCount() === 1;
    }

    public static function dispatchOutboxRow(PDO $pdo, int $outboxId): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }
        $now = time();
        $leaseToken = bin2hex(random_bytes(16));

        // Step 1 (#248 M-08): Atomic worker lease acquisition fenced by unique `lease_token` (120s lease >= ~5x 25s SMTP deadline)
        $leaseStmt = $pdo->prepare("
            UPDATE email_outbox
            SET status = 'sending',
                lease_until = :lu,
                lease_token = :ltok
            WHERE id = :id
              AND (
                  status IN ('queued', 'failed')
                  OR (status = 'sending' AND (lease_until IS NULL OR lease_until < :now1))
              )
              AND (next_retry_at IS NULL OR next_retry_at <= :now2)
              AND body_html NOT LIKE '[REDACTED%'
              AND body_html NOT LIKE '[EXPIRED%'
        ");
        $leaseStmt->execute([
            ':lu' => $now + self::OUTBOX_LEASE_SECONDS,
            ':ltok' => $leaseToken,
            ':id' => $outboxId,
            ':now1' => $now,
            ':now2' => $now,
        ]);
        if ($leaseStmt->rowCount() !== 1) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT * FROM email_outbox WHERE id = :id AND lease_token = :ltok LIMIT 1");
        $stmt->execute([':id' => $outboxId, ':ltok' => $leaseToken]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        $bodyHtml = (string)($row['body_html'] ?? '');
        $rawSubject = (string)($row['subject'] ?? '');
        $recipient = trim((string)($row['recipient_email'] ?? ''));
        $expiresAt = isset($row['expires_at']) && $row['expires_at'] !== null ? (int)$row['expires_at'] : 0;

        // Step 2 (#211 M-03): Never dispatch expired or superseded authentication challenges (OTPs / magic links)
        $isExpiredChallenge = ($expiresAt > 0 && $expiresAt < $now);
        if (!$isExpiredChallenge && str_contains($rawSubject, '6-Digit Verification Code') && $recipient !== '') {
            $activeTokStmt = $pdo->prepare("
                SELECT id FROM owner_auth_tokens
                WHERE email = :em AND used_at IS NULL AND expires_at >= :now
                LIMIT 1
            ");
            $activeTokStmt->execute([':em' => strtolower($recipient), ':now' => $now]);
            if (!$activeTokStmt->fetchColumn()) {
                $isExpiredChallenge = true;
            } elseif (preg_match('/(\d{6})/', $rawSubject, $mCode)) {
                $codeCandidate = $mCode[1];
                $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
                $expectedOtpHash = hash_hmac('sha256', 'otp6|' . strtolower($recipient) . '|' . $codeCandidate, $secret);
                $exactTokStmt = $pdo->prepare("
                    SELECT id FROM owner_auth_tokens
                    WHERE token_hash = :th AND used_at IS NULL AND expires_at >= :now
                    LIMIT 1
                ");
                $exactTokStmt->execute([':th' => $expectedOtpHash, ':now' => $now]);
                if (!$exactTokStmt->fetchColumn()) {
                    $isExpiredChallenge = true;
                }
            }
        }

        if ($isExpiredChallenge) {
            $pdo->prepare("
                UPDATE email_outbox
                SET status = 'expired',
                    lease_until = NULL,
                    lease_token = NULL,
                    next_retry_at = NULL,
                    subject = '[EXPIRED AUTH EMAIL]',
                    body_html = '[EXPIRED OR SUPERSEDED AUTH CHALLENGE NOT DISPATCHED]'
                WHERE id = :id AND status = 'sending' AND lease_token = :ltok
            ")->execute([':id' => $outboxId, ':ltok' => $leaseToken]);
            return false;
        }

        // Step 3 (#212 M-04, #249 M-09): Enforce central delivery-wide 15/h quota across all callers & retry workers
        $centralQuota = AntiSpamGate::consumeCentralDispatchQuota($pdo);
        if (!$centralQuota['allowed']) {
            self::finalizeLeasedOutboxRow($pdo, $outboxId, $leaseToken, 'queued', (int)($row['attempts'] ?? 0), $now + 300);
            return false;
        }

        $simulate = strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes'
                 || strtolower(Database::env('APP_ENV', 'development')) !== 'production';

        $attempts = ((int)($row['attempts'] ?? 0)) + 1;
        if ($simulate) {
            return self::finalizeLeasedOutboxRow($pdo, $outboxId, $leaseToken, 'simulated', $attempts);
        }

        $cleanSubject = AntiSpamGate::sanitizeHeaderValue($rawSubject);
        $fromEmail = Database::env('SMTP_FROM_EMAIL', 'noreply@busherianbiasharas.com');
        $fromName  = Database::env('SMTP_FROM_NAME', 'Busherian Biasharas');
        $replyRaw  = trim((string)($row['reply_to'] ?? ''));
        $cleanReply = ($replyRaw !== '' && filter_var($replyRaw, FILTER_VALIDATE_EMAIL))
            ? AntiSpamGate::sanitizeHeaderValue($replyRaw)
            : null;

        $smtpHost = trim(Database::env('SMTP_HOST', ''));
        $ok = false;
        if ($smtpHost !== '') {
            $ok = self::sendViaAuthenticatedSmtp($recipient, $cleanSubject, $bodyHtml, $fromEmail, $fromName, $cleanReply);
        }
        if (!$ok && self::isPhpMailFallbackEnabled() && function_exists('mail')) {
            $msgId = '<' . bin2hex(random_bytes(12)) . '@busherianbiasharas.com>';
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                "From: {$fromName} <{$fromEmail}>",
                'Date: ' . date(DATE_RFC2822),
                "Message-ID: {$msgId}",
            ];
            if ($cleanReply !== null) {
                $headers[] = "Reply-To: {$cleanReply}";
            }
            $envelopeParam = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? "-f {$fromEmail}" : '';
            $ok = $envelopeParam !== ''
                ? @mail($recipient, $cleanSubject, $bodyHtml, implode("\r\n", $headers), $envelopeParam)
                : @mail($recipient, $cleanSubject, $bodyHtml, implode("\r\n", $headers));
        }

        if ($ok) {
            return self::finalizeLeasedOutboxRow($pdo, $outboxId, $leaseToken, 'sent', $attempts);
        }

        $backoffSeconds = min(3600, 60 * (1 << min(6, $attempts)));
        self::finalizeLeasedOutboxRow($pdo, $outboxId, $leaseToken, 'failed', $attempts, time() + $backoffSeconds);
        return false;
    }

    /**
     * Flush deferred outbox messages queued during a now-committed DB transaction (#188).
     *
     * @return array{dispatched: int, failed: int}
     */
    public static function flushPendingOutbox(PDO $pdo): array
    {
        if ($pdo->inTransaction() || self::$pendingPostCommitIds === []) {
            return ['dispatched' => 0, 'failed' => 0];
        }
        $ids = array_values(array_unique(self::$pendingPostCommitIds));
        self::$pendingPostCommitIds = [];
        $dispatched = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $existsStmt = $pdo->prepare("SELECT id FROM email_outbox WHERE id = :id LIMIT 1");
            $existsStmt->execute([':id' => $id]);
            if (!$existsStmt->fetchColumn()) {
                continue; // Rolled back before flush
            }
            if (self::dispatchOutboxRow($pdo, $id)) {
                $dispatched++;
            } else {
                $failed++;
            }
        }
        return ['dispatched' => $dispatched, 'failed' => $failed];
    }

    public static function clearPendingOutbox(): void
    {
        self::$pendingPostCommitIds = [];
    }

    public static function wasDeliveredOrSimulated(PDO $pdo, int $outboxId): bool
    {
        if ($outboxId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT status FROM email_outbox WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $outboxId]);
        $status = (string)($stmt->fetchColumn() ?: '');
        return in_array($status, ['sent', 'simulated'], true);
    }

    /**
     * Bounded retry worker for `queued` and `failed` outbox items (#188).
     *
     * @return array{processed: int, delivered: int, failed: int, last_run_utc: string}
     */
    public static function processRetryQueue(PDO $pdo, int $maxBatch = 25, int $maxAttempts = 5): array
    {
        $limit = max(1, min(100, $maxBatch));
        $cap = max(1, min(10, $maxAttempts));
        $now = time();
        $stmt = $pdo->prepare("
            SELECT id
            FROM email_outbox
            WHERE (
                  status IN ('queued', 'failed')
                  OR (status = 'sending' AND (lease_until IS NULL OR lease_until < :now1))
              )
              AND (next_retry_at IS NULL OR next_retry_at <= :now2)
              AND attempts < :cap
              AND body_html NOT LIKE '[REDACTED%'
              AND body_html NOT LIKE '[EXPIRED%'
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute([':now1' => $now, ':now2' => $now, ':cap' => $cap]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $delivered = 0;
        $failed = 0;
        foreach ($ids as $id) {
            if (self::dispatchOutboxRow($pdo, $id)) {
                $delivered++;
            } else {
                $failed++;
            }
        }
        $nowUtc = gmdate('Y-m-d H:i:s');
        Database::setSetting($pdo, 'OUTBOX_WORKER_LAST_RUN_AT', $nowUtc);
        return [
            'processed' => count($ids),
            'delivered' => $delivered,
            'failed' => $failed,
            'last_run_utc' => $nowUtc,
        ];
    }

    /**
     * Optional authenticated SMTP transport (cPanel / transactional SMTP) for high-deliverability Yahoo/Gmail delivery (#152).
     */
    private static function sendViaAuthenticatedSmtp(
        string $to,
        string $subject,
        string $bodyHtml,
        string $fromEmail,
        string $fromName,
        ?string $replyTo
    ): bool {
        $host = trim(Database::env('SMTP_HOST', ''));
        $port = (int)Database::env('SMTP_PORT', '465');
        $user = trim(Database::env('SMTP_USER', ''));
        $pass = Database::env('SMTP_PASS', '');
        $rawSec = trim(Database::env('SMTP_ENCRYPTION', Database::env('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls')));
        $sec = strtolower($rawSec);
        $appEnv = strtolower(trim(Database::env('APP_ENV', 'production')));
        if ($host === '' || $port <= 0 || !self::isSmtpSecurityAllowed($sec, $appEnv, false)) {
            error_log('[Mailer::sendViaAuthenticatedSmtp] Refusing unencrypted or invalid SMTP transport configuration in ' . $appEnv);
            return false;
        }

        $deadline = microtime(true) + (float)self::SMTP_TOTAL_DEADLINE_SECONDS;
        $verifyPeer = strtolower(trim(Database::env('SMTP_VERIFY_TLS_PEER', 'yes'))) !== 'no';
        $sslCtx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $remote = ($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 8.0, STREAM_CLIENT_CONNECT, $sslCtx);
        if (!is_resource($fp)) {
            return false;
        }

        $applyRemainingTimeout = static function () use ($fp, $deadline): bool {
            $rem = $deadline - microtime(true);
            if ($rem <= 0.05) {
                return false;
            }
            $secPart = (int)floor($rem);
            $usecPart = (int)(($rem - $secPart) * 1000000);
            stream_set_timeout($fp, max(0, $secPart), max(1000, $usecPart));
            return true;
        };
        $applyRemainingTimeout();

        $readResp = static function () use ($fp, $applyRemainingTimeout): string {
            $out = '';
            while ($applyRemainingTimeout() && ($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $sendCmd = static function (string $cmd, string $expectPrefix) use ($fp, $readResp, $applyRemainingTimeout): bool {
            if (!$applyRemainingTimeout()) {
                return false;
            }
            if (@fwrite($fp, $cmd . "\r\n") === false) {
                return false;
            }
            $resp = $readResp();
            return str_starts_with($resp, $expectPrefix);
        };

        if (!str_starts_with($readResp(), '220')) {
            fclose($fp);
            return false;
        }
        if (!$sendCmd('EHLO busherianbiasharas.com', '250')) {
            fclose($fp);
            return false;
        }
        if ($sec === 'tls') {
            if (!$sendCmd('STARTTLS', '220') || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return false;
            }
            if (!$sendCmd('EHLO busherianbiasharas.com', '250')) {
                fclose($fp);
                return false;
            }
        }
        if ($user !== '' && $pass !== '') {
            if (!$sendCmd('AUTH LOGIN', '334') ||
                !$sendCmd(base64_encode($user), '334') ||
                !$sendCmd(base64_encode($pass), '235')) {
                fclose($fp);
                return false;
            }
        }
        if (!$sendCmd("MAIL FROM:<{$fromEmail}>", '250') ||
            !$sendCmd("RCPT TO:<{$to}>", '25')) {
            fclose($fp);
            return false;
        }
        if (!$sendCmd('DATA', '354')) {
            fclose($fp);
            return false;
        }

        $msgId = '<' . bin2hex(random_bytes(12)) . '@busherianbiasharas.com>';
        $mimeHeaders = [
            "Date: " . date(DATE_RFC2822),
            "From: {$fromName} <{$fromEmail}>",
            "To: <{$to}>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
        ];
        if ($replyTo !== null) {
            $mimeHeaders[] = "Reply-To: {$replyTo}";
        }
        $payload = implode("\r\n", $mimeHeaders) . "\r\n\r\n" . chunk_split(base64_encode($bodyHtml)) . "\r\n.";
        $sentOk = $sendCmd($payload, '250');
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return $sentOk;
    }

    /**
     * Send a 6-digit verification code to set or reset an Owner Password right inside the active browser tab (#152).
     */
    public static function sendOwnerPasswordOtp(PDO $pdo, string $ownerEmail, string $otpCode, ?string $targetBusinessName = null): int
    {
        $safeEmail = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue($ownerEmail), ENT_QUOTES, 'UTF-8');
        $safeCode  = htmlspecialchars(preg_replace('/\D+/', '', $otpCode) ?? '', ENT_QUOTES, 'UTF-8');
        $bizContext = '';
        if ($targetBusinessName !== null && trim($targetBusinessName) !== '') {
            $safeBiz = htmlspecialchars($targetBusinessName, ENT_QUOTES, 'UTF-8');
            $bizContext = "<p style='background:#F0FDF4;border-left:4px solid #114B3E;padding:10px;'>Enter this code on the sign-in screen to set your password and edit <strong>{$safeBiz}</strong>.</p>";
        }

        $subject = "[Busherian Biasharas] Your 6-Digit Verification Code: {$safeCode}";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:560px;color:#111827;'>
                <h2 style='color:#114B3E;margin-bottom:6px;'>Set or Reset Your Owner Password</h2>
                <p style='color:#4B5563;'>Enter this 6-digit verification code in your open browser window for <strong>{$safeEmail}</strong>:</p>
                <div style='background:#FFFBEB;border:2px solid #D97706;border-radius:12px;padding:16px;text-align:center;margin:18px 0;'>
                    <div style='font-size:30px;font-weight:800;letter-spacing:8px;color:#7B1113;font-family:monospace;'>{$safeCode}</div>
                    <div style='font-size:12px;color:#6B7280;margin-top:6px;'>Valid for 15 minutes</div>
                </div>
                {$bizContext}
                <p style='font-size:12px;color:#6B7280;'>Once you save your password, you can sign in anytime on any phone or computer using your email and password.</p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml, null, null, time() + 900);
    }

    /**
     * Notify submitter/owner when their listing has been approved and is live (#152).
     */
    public static function notifyOwnerListingApproved(PDO $pdo, array $business, string $ownerEmail): int
    {
        $bizName = htmlspecialchars((string)($business['name'] ?? 'Your Business'), ENT_QUOTES, 'UTF-8');
        $bizId = (int)($business['id'] ?? 0);
        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $loginUrl = "{$baseUrl}/owner/login.php" . ($bizId > 0 ? "?biz={$bizId}" : '');
        $safeLoginUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

        $subject = "[Busherian Biasharas] Approved & Live: {$business['name']}";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:580px;color:#111827;'>
                <h2 style='color:#114B3E;margin-bottom:6px;'>Your Busherian Biashara Listing is Live!</h2>
                <p style='color:#374151;'><strong>{$bizName}</strong> has been verified and published on the Busherian Biasharas directory. As promised, your one-time alumni vetting details (House, Year Left, and School Number) have been permanently deleted.</p>
                <p style='color:#374151;'>Whenever you want to update your hours, photos, contacts, or map pin, sign in with your email and password:</p>
                <p style='margin:20px 0;'>
                    <a href='{$safeLoginUrl}' style='background:#114B3E;color:#ffffff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold;'>Sign In &amp; Edit {$bizName}</a>
                </p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml);
    }

    /**
     * Send a passwordless sign-in / business-claim Magic Link to a business owner.
     */
    public static function sendOwnerMagicLink(PDO $pdo, string $ownerEmail, string $magicUrl, ?string $claimBusinessName = null): int
    {
        $safeEmail = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue($ownerEmail), ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($magicUrl, ENT_QUOTES, 'UTF-8');
        $claimLine = '';
        if ($claimBusinessName !== null && trim($claimBusinessName) !== '') {
            $safeBiz = htmlspecialchars($claimBusinessName, ENT_QUOTES, 'UTF-8');
            $claimLine = "<p style='background:#F0FDF4;border-left:4px solid #114B3E;padding:10px;'>Signing in with this link will also link <strong>{$safeBiz}</strong> to your persistent Owner Account so you can manage and update it anytime.</p>";
        }

        $subject = '[Busherian Biasharas] Your Sign-In Link to Manage Your Business Listing';
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:580px;color:#111827;'>
                <h2 style='color:#114B3E;margin-bottom:6px;'>Sign in to Busherian Biasharas</h2>
                <p style='color:#4B5563;'>Click the button below to sign in as <strong>{$safeEmail}</strong> and manage your business listing(s). Your session will stay signed in on this device for 30 days.</p>
                {$claimLine}
                <p style='margin:22px 0;'>
                    <a href='{$safeUrl}' style='background:#7B1113;color:#ffffff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold;'>Sign In &amp; Manage My Business</a>
                </p>
                <p style='font-size:12px;color:#6B7280;'>This single-use link expires in 30 minutes. If you did not request it, you can safely ignore this email.</p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml, null, null, time() + 1800);
    }

    /**
     * Notify Admin when a verified owner changes their Business Name or Sector/Specialty Category.
     */
    public static function notifyAdminOwnerCriticalEdit(PDO $pdo, array $owner, array $oldBiz, array $newBiz, array $changes): int
    {
        $adminEmail = Database::env('ADMIN_EMAIL', 'admin@busherianbiasharas.com');
        $ownerEmail = htmlspecialchars((string)($owner['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $bizId = (int)($newBiz['id'] ?? $oldBiz['id'] ?? 0);
        $bizUid = htmlspecialchars((string)($newBiz['business_id'] ?? $oldBiz['business_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $newName = htmlspecialchars((string)($newBiz['name'] ?? ''), ENT_QUOTES, 'UTF-8');

        $diffRows = '';
        foreach ($changes as $fieldLabel => $pair) {
            $before = htmlspecialchars((string)($pair['before'] ?? ''), ENT_QUOTES, 'UTF-8');
            $after = htmlspecialchars((string)($pair['after'] ?? ''), ENT_QUOTES, 'UTF-8');
            $diffRows .= "<li><strong>{$fieldLabel}:</strong> <span style='color:#991B1B;text-decoration:line-through;'>{$before}</span> &rarr; <strong style='color:#065F46;'>{$after}</strong></li>";
        }

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $editUrl = "{$baseUrl}/admin/edit-business.php?id={$bizId}";

        $subject = "[Busherian Biasharas] Owner Updated Name/Category: {$newBiz['name']} ({$bizUid})";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:620px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:4px;'>Owner Updated Business Name or Category</h2>
                <p style='color:#4B5563;margin-top:0;'>Verified owner <strong>{$ownerEmail}</strong> updated a core identity field on Business #{$bizId} (<code>{$bizUid}</code>):</p>
                <ul style='background:#FFFBEB;border:1px solid #F59E0B;border-radius:8px;padding:12px 28px;margin:14px 0;'>
                    {$diffRows}
                </ul>
                <p><a href='{$editUrl}' style='background:#114B3E;color:#ffffff;padding:9px 16px;border-radius:6px;text-decoration:none;font-weight:bold;'>Review in Admin Console</a></p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $adminEmail, $subject, $bodyHtml, (string)($owner['email'] ?? null), $bizId);
    }

    /**
     * Immediately redact any historical `email_outbox` rows that contained legacy alumni vetting fields (#167).
     */
    public static function scrubLegacyVettingPiiFromOutbox(PDO $pdo, ?int $businessId = null): int
    {
        $redactedMarker = '[REDACTED PER KENYA DPA 2019 VETTING DATA MINIMIZATION POLICY]';
        if ($businessId !== null && $businessId > 0) {
            $stmt = $pdo->prepare("
                UPDATE email_outbox
                SET body_html = :marker
                WHERE business_id = :bid
                  AND (
                      body_html LIKE '%House:%'
                      OR body_html LIKE '%Year Left:%'
                      OR body_html LIKE '%School Number:%'
                      OR body_html LIKE '%One-Time Alumni Vetting Credentials%'
                  )
            ");
            $stmt->execute([':marker' => $redactedMarker, ':bid' => $businessId]);
            return $stmt->rowCount();
        }

        $stmt = $pdo->prepare("
            UPDATE email_outbox
            SET body_html = :marker
            WHERE body_html LIKE '%House:%'
               OR body_html LIKE '%Year Left:%'
               OR body_html LIKE '%School Number:%'
               OR body_html LIKE '%One-Time Alumni Vetting Credentials%'
        ");
        $stmt->execute([':marker' => $redactedMarker]);
        return $stmt->rowCount();
    }

    /**
     * Send a single-use mailbox confirmation link when a designated owner requests immediate self-quarantine (#168).
     */
    public static function sendOwnerTakedownConfirmationLink(PDO $pdo, array $business, string $ownerEmail, string $confirmUrl): int
    {
        $bizId = (int)($business['id'] ?? 0);
        $bizName = htmlspecialchars((string)($business['name'] ?? 'Your Listing'), ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');
        $subject = "[Busherian Biasharas] Confirm Removal Request for {$business['name']}";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:580px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:6px;'>Confirm Listing Removal / Pause</h2>
                <p style='color:#374151;'>We received a request to hide <strong>{$bizName}</strong> from the Busherian Biasharas public directory.</p>
                <p style='color:#374151;'>Click the button below within 2 hours to confirm you control this email address and immediately pause the listing:</p>
                <p style='margin:20px 0;'>
                    <a href='{$safeUrl}' style='background:#991B1B;color:#ffffff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:bold;'>Confirm &amp; Hide {$bizName}</a>
                </p>
                <p style='font-size:12px;color:#6B7280;'>If you did not submit this request, ignore this email and your listing will remain unchanged.</p>
            </div>
        ";
        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml, null, $bizId, time() + 7200);
    }

    /**
     * Notify Admin of a takedown / DPA objection event (#168).
     */
    public static function notifyAdminTakedownRequest(PDO $pdo, array $business, array $requestInfo, string $mode): int
    {
        $adminEmail = Database::env('ADMIN_EMAIL', 'admin@busherianbiasharas.com');
        $bizId = (int)($business['id'] ?? 0);
        $bizName = htmlspecialchars((string)($business['name'] ?? "Business #{$bizId}"), ENT_QUOTES, 'UTF-8');
        $reqName = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)($requestInfo['requester_name'] ?? '')), ENT_QUOTES, 'UTF-8');
        $reqEmail = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)($requestInfo['requester_email'] ?? '')), ENT_QUOTES, 'UTF-8');
        $reason = nl2br(htmlspecialchars((string)($requestInfo['reason'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $adminUrl = htmlspecialchars("{$baseUrl}/admin/index.php#takedown-queue", ENT_QUOTES, 'UTF-8');

        $modeLabel = $mode === 'owner_quarantined'
            ? 'Verified Owner Self-Quarantined Immediately'
            : 'Queued for Rapid Admin Review (Live Listing Preserved Pending Verification)';

        $subject = "[Busherian Biasharas] Takedown / Privacy Request (#{$bizId} {$business['name']})";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:620px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:4px;'>Takedown / Privacy Request</h2>
                <p><strong>Action Status:</strong> {$modeLabel}</p>
                <p><strong>Listing:</strong> {$bizName} (#{$bizId})</p>
                <p><strong>Requester:</strong> {$reqName} ({$reqEmail})</p>
                <p><strong>Reason:</strong><br>{$reason}</p>
                <p style='margin-top:18px;'><a href='{$adminUrl}' style='background:#114B3E;color:#ffffff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold;'>Review in Admin Console</a></p>
            </div>
        ";
        return self::enqueueAndSend($pdo, $adminEmail, $subject, $bodyHtml, (string)($requestInfo['requester_email'] ?? null), $bizId);
    }

    /**
     * Courtesy notice to a verified listing owner when an unauthenticated third party files a takedown review request (#168).
     */
    public static function notifyOwnerThirdPartyTakedownNotice(PDO $pdo, array $business, string $ownerEmail): int
    {
        $bizId = (int)($business['id'] ?? 0);
        $bizName = htmlspecialchars((string)($business['name'] ?? 'Your Listing'), ENT_QUOTES, 'UTF-8');
        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $portalUrl = htmlspecialchars("{$baseUrl}/owner/index.php", ENT_QUOTES, 'UTF-8');
        $subject = "[Busherian Biasharas] Notice: Review Request Received for {$business['name']}";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:580px;color:#111827;'>
                <h2 style='color:#114B3E;margin-bottom:6px;'>Listing Review Notice</h2>
                <p style='color:#374151;'>A third-party report or correction request was submitted regarding <strong>{$bizName}</strong>. Your listing remains published while our directory administrators review the report.</p>
                <p style='margin:18px 0;'><a href='{$portalUrl}' style='background:#114B3E;color:#ffffff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold;'>Open Owner Portal</a></p>
            </div>
        ";
        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml, null, $bizId);
    }
}
