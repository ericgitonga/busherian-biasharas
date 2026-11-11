<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

final class Mailer
{
    private const ACTION_LINK_TTL_SECONDS = 259200; // 72 hours

    /**
     * Generate a cryptographically signed one-click Admin Triage URL (HMAC-SHA256).
     */
    public static function generateSignedAdminActionUrl(int $businessId, string $action, ?int $expiresAt = null, ?int $mergeCategoryId = null): string
    {
        $exp = $expiresAt ?? (time() + self::ACTION_LINK_TTL_SECONDS);
        $merge = $mergeCategoryId ?? 0;
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "{$businessId}|{$action}|{$exp}|{$merge}";
        $sig = hash_hmac('sha256', $payload, $secret);

        $baseUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/');
        $query = http_build_query([
            'id' => $businessId,
            'action' => $action,
            'exp' => $exp,
            'merge' => $merge,
            'sig' => $sig,
        ]);

        return "{$baseUrl}/admin/review-action.php?{$query}";
    }

    public static function verifySignedAdminAction(int $businessId, string $action, int $expiresAt, int $mergeCategoryId, string $signature): bool
    {
        if ($expiresAt < time() || $signature === '') {
            return false;
        }
        $secret = Database::env('APP_HMAC_SECRET', 'dev_fallback_secret_key_32_bytes_min_len');
        $payload = "{$businessId}|{$action}|{$expiresAt}|{$mergeCategoryId}";
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Enqueue and dispatch notification to admin@busherianbiasharas.com when a new business is submitted.
     */
    public static function notifyAdminNewSubmission(PDO $pdo, array $business, array $vetting, ?array $customCategory = null): int
    {
        $adminEmail = Database::env('ADMIN_EMAIL', 'admin@busherianbiasharas.com');
        $bizId = (int)$business['id'];
        $bizName = htmlspecialchars((string)$business['name'], ENT_QUOTES, 'UTF-8');
        $school = htmlspecialchars((string)$vetting['school_code'], ENT_QUOTES, 'UTF-8');
        $house = htmlspecialchars((string)$vetting['house_name'], ENT_QUOTES, 'UTF-8');
        $yearLeft = (int)$vetting['year_left'];
        $schoolNum = htmlspecialchars((string)($vetting['school_number'] ?: 'Not provided (Optional)'), ENT_QUOTES, 'UTF-8');
        $submitterName = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)$vetting['submitter_name']), ENT_QUOTES, 'UTF-8');
        $submitterEmail = htmlspecialchars(AntiSpamGate::sanitizeHeaderValue((string)$vetting['submitter_email']), ENT_QUOTES, 'UTF-8');

        $approveUrl = self::generateSignedAdminActionUrl($bizId, 'approve');
        $rejectUrl  = self::generateSignedAdminActionUrl($bizId, 'reject');

        $catNotice = '';
        if ($customCategory !== null) {
            $customCatName = htmlspecialchars((string)$customCategory['name'], ENT_QUOTES, 'UTF-8');
            $catNotice = "<p style='background:#FFFBEB;border-left:4px solid #D4AF37;padding:10px;'><strong>Custom Specialty Requested:</strong> {$customCatName} (Pending Admin Approval)</p>";
        }

        $subject = "[Busherian Biasharas] Vetting & Review Required: {$business['name']} ({$vetting['school_code']})";
        $bodyHtml = "
            <div style='font-family:sans-serif;max-width:620px;color:#111827;'>
                <h2 style='color:#7B1113;margin-bottom:4px;'>New Busherian Biashara Submission</h2>
                <p style='color:#4B5563;margin-top:0;'>Submitted by <strong>{$submitterName}</strong> ({$submitterEmail})</p>
                <div style='background:#F0FDF4;border:1px solid #114B3E;border-radius:8px;padding:14px;margin:16px 0;'>
                    <h3 style='margin:0 0 8px 0;color:#114B3E;'>One-Time Alumni Vetting Credentials (Auto-Purged on Review)</h3>
                    <p style='margin:4px 0;'><strong>School:</strong> {$school}</p>
                    <p style='margin:4px 0;'><strong>House:</strong> {$house}</p>
                    <p style='margin:4px 0;'><strong>Year Left:</strong> {$yearLeft}</p>
                    <p style='margin:4px 0;'><strong>School Number:</strong> {$schoolNum}</p>
                </div>
                {$catNotice}
                <p><strong>Business Name:</strong> {$bizName}</p>
                <p style='margin-top:20px;'>
                    <a href='{$approveUrl}' style='background:#114B3E;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;margin-right:10px;'>Approve &amp; Purge Vetting Data</a>
                    <a href='{$rejectUrl}' style='background:#991B1B;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:bold;'>Reject &amp; Purge Vetting Data</a>
                </p>
            </div>
        ";

        return self::enqueueAndSend($pdo, $adminEmail, $subject, $bodyHtml, $submitterEmail);
    }

    public static function enqueueAndSend(PDO $pdo, string $recipient, string $subject, string $bodyHtml, ?string $replyTo = null): int
    {
        $simulate = strtolower(Database::env('SIMULATE_EMAILS', 'yes')) === 'yes'
                 || strtolower(Database::env('APP_ENV', 'development')) !== 'production';

        $status = $simulate ? 'simulated' : 'queued';
        $stmt = $pdo->prepare("
            INSERT INTO email_outbox (recipient_email, subject, body_html, status, attempts, sent_at)
            VALUES (:to, :subj, :body, :status, 1, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':to' => $recipient,
            ':subj' => AntiSpamGate::sanitizeHeaderValue($subject),
            ':body' => $bodyHtml,
            ':status' => $status,
        ]);
        $outboxId = (int)$pdo->lastInsertId();

        if (!$simulate) {
            $cleanSubject = AntiSpamGate::sanitizeHeaderValue($subject);
            $fromEmail = Database::env('SMTP_FROM_EMAIL', 'noreply@busherianbiasharas.com');
            $fromName  = Database::env('SMTP_FROM_NAME', 'Busherian Biasharas');
            $cleanReply = ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL))
                ? AntiSpamGate::sanitizeHeaderValue($replyTo)
                : null;

            $smtpHost = trim(Database::env('SMTP_HOST', ''));
            $ok = false;
            if ($smtpHost !== '') {
                $ok = self::sendViaAuthenticatedSmtp($recipient, $cleanSubject, $bodyHtml, $fromEmail, $fromName, $cleanReply);
            }
            if (!$ok && function_exists('mail')) {
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
            $newStatus = $ok ? 'sent' : 'failed';
            $pdo->prepare("UPDATE email_outbox SET status = :st WHERE id = :id")
                ->execute([':st' => $newStatus, ':id' => $outboxId]);
        }

        return $outboxId;
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
        $sec  = strtolower(trim(Database::env('SMTP_SECURE', $port === 465 ? 'ssl' : 'tls')));
        if ($host === '' || $port <= 0) {
            return false;
        }

        $remote = ($sec === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 8);
        if (!is_resource($fp)) {
            return false;
        }
        stream_set_timeout($fp, 8);

        $readResp = static function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $sendCmd = static function (string $cmd, string $expectPrefix) use ($fp, $readResp): bool {
            fwrite($fp, $cmd . "\r\n");
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

        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml);
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

        return self::enqueueAndSend($pdo, $ownerEmail, $subject, $bodyHtml);
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

        return self::enqueueAndSend($pdo, $adminEmail, $subject, $bodyHtml, (string)($owner['email'] ?? null));
    }
}
