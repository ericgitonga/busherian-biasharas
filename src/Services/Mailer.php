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

        if (!$simulate && function_exists('mail')) {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Busherian Biasharas <noreply@busherianbiasharas.com>',
            ];
            if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $cleanReply = AntiSpamGate::sanitizeHeaderValue($replyTo);
                $headers[] = "Reply-To: {$cleanReply}";
            }
            $ok = @mail($recipient, AntiSpamGate::sanitizeHeaderValue($subject), $bodyHtml, implode("
", $headers));
            $newStatus = $ok ? 'sent' : 'failed';
            $pdo->prepare("UPDATE email_outbox SET status = :st WHERE id = :id")
                ->execute([':st' => $newStatus, ':id' => $outboxId]);
        }

        return $outboxId;
    }
}
