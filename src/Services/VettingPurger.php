<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;
use RuntimeException;

final class VettingPurger
{
    public const PRIVACY_NOTICE_VERSION = '2026-11-v2';

    public const ALLOWED_ADMIN_PROVENANCE_BASES = [
        'alumni_direct_authorization',
        'public_alumni_business_registry',
        'verified_event_or_chapter_directory',
        'owner_written_request',
    ];

    public static function allocateNextBusinessNumber(PDO $pdo): int
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            \BusherianBiasharas\Config\Database::syncBusinessSequenceFloor($pdo);
            $pdo->prepare("UPDATE business_sequences SET last_value = last_value + 1 WHERE seq_key = 'business_number'")
                ->execute();
            $stmt = $pdo->prepare("SELECT last_value FROM business_sequences WHERE seq_key = 'business_number' LIMIT 1");
            $stmt->execute();
            $val = (int)($stmt->fetchColumn() ?: 1);
            if ($ownTx) {
                $pdo->commit();
            }
            return max(1, $val);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function recordModerationAudit(
        PDO $pdo,
        int $businessId,
        string $action,
        string $channel,
        string $actorIdentifier,
        ?string $previousStatus,
        string $newStatus,
        ?string $reasonNote = null,
        ?string $clientIp = null
    ): void {
        $ipHash = ($clientIp !== null && trim($clientIp) !== '')
            ? AntiSpamGate::pseudonymizeIp($clientIp)
            : null;
        $pdo->prepare("
            INSERT INTO moderation_audit_log (
                business_id, action, channel, actor_identifier,
                previous_status, new_status, reason_note, ip_hash, created_at
            ) VALUES (
                :bid, :act, :ch, :actor,
                :prev, :next, :note, :iph, CURRENT_TIMESTAMP
            )
        ")->execute([
            ':bid' => $businessId,
            ':act' => mb_substr(trim($action), 0, 32),
            ':ch' => mb_substr(trim($channel), 0, 40),
            ':actor' => mb_substr(trim($actorIdentifier) !== '' ? trim($actorIdentifier) : 'system', 0, 190),
            ':prev' => $previousStatus !== null ? mb_substr(trim($previousStatus), 0, 24) : null,
            ':next' => mb_substr(trim($newStatus), 0, 24),
            ':note' => $reasonNote !== null && trim($reasonNote) !== '' ? mb_substr(trim($reasonNote), 0, 255) : null,
            ':iph' => $ipHash,
        ]);
    }

    public static function validateHttpUrl(?string $rawUrl, string $fieldLabel): ?string
    {
        $u = trim((string)($rawUrl ?? ''));
        if ($u === '') {
            return null;
        }
        if (strlen($u) > 500) {
            throw new \InvalidArgumentException("{$fieldLabel} must be 500 characters or fewer.");
        }
        if (!preg_match('#^https?://[^\s<>"\x00-\x1f]+$#i', $u)) {
            throw new \InvalidArgumentException("{$fieldLabel} must start with http:// or https://.");
        }
        $parts = parse_url($u);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException("{$fieldLabel} must be a valid http:// or https:// URL.");
        }
        return $u;
    }

    public static function validateListingWritePayload(
        PDO $pdo,
        array $data,
        ?float $latitude,
        ?float $longitude,
        string $hoursJson
    ): void {
        $sectorId = (int)($data['sector_id'] ?? 0);
        $specialtyId = (int)($data['specialty_id'] ?? 0);
        if (!CategoryMatcher::validateSectorAndSpecialtyPair($pdo, $sectorId, $specialtyId)) {
            throw new \InvalidArgumentException('Selected Specialty does not belong to the chosen Industry Sector.');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 180) {
            throw new \InvalidArgumentException('Business Name is required and must be 180 characters or fewer.');
        }
        $tagline = trim((string)($data['tagline'] ?? ''));
        if (mb_strlen($tagline) > 220) {
            throw new \InvalidArgumentException('Short Tagline must be 220 characters or fewer.');
        }
        $desc = trim((string)($data['description'] ?? ''));
        if (mb_strlen($desc) > 5000) {
            throw new \InvalidArgumentException('Business Description must be 5,000 characters or fewer.');
        }

        $emailPub = trim((string)($data['email_public'] ?? ''));
        if ($emailPub !== '' && (!filter_var($emailPub, FILTER_VALIDATE_EMAIL) || strlen($emailPub) > 190)) {
            throw new \InvalidArgumentException('Public Contact Email must be a valid email address.');
        }

        $urlFields = [
            'website_url' => 'Website URL',
            'google_maps_url' => 'Google Maps URL',
            'social_instagram' => 'Instagram URL',
            'social_linkedin' => 'LinkedIn URL',
            'social_x' => 'X (Twitter) URL',
            'social_facebook' => 'Facebook URL',
            'social_tiktok' => 'TikTok URL',
            'social_youtube' => 'YouTube URL',
        ];
        foreach ($urlFields as $key => $label) {
            if (array_key_exists($key, $data)) {
                self::validateHttpUrl(is_scalar($data[$key]) ? (string)$data[$key] : null, $label);
            }
        }

        if (($latitude !== null && $longitude === null) || ($latitude === null && $longitude !== null)) {
            throw new \InvalidArgumentException('Both Latitude and Longitude must be provided together.');
        }
        if ($latitude !== null && $longitude !== null) {
            if (!is_finite($latitude) || !is_finite($longitude) || $latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
                throw new \InvalidArgumentException('Coordinates must be within valid bounds (-90..90 latitude, -180..180 longitude).');
            }
        }

        if (!HoursEngine::validateScheduleJson($hoursJson)) {
            throw new \InvalidArgumentException('Operating hours schedule contains invalid 24-hour HH:MM values.');
        }
    }

    public static function approveBusiness(
        PDO $pdo,
        int $businessId,
        ?int $mergeIntoSpecialtyId = null,
        string $channel = 'admin_dashboard',
        string $actorIdentifier = 'admin_console',
        ?string $clientIp = null
    ): array {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("SELECT id, name, specialty_id, status FROM businesses WHERE id = :id");
            $stmt->execute([':id' => $businessId]);
            $biz = $stmt->fetch();
            if (!$biz) {
                throw new RuntimeException("Business #{$businessId} not found.");
            }
            $prevStatus = (string)($biz['status'] ?? '');
            if ($prevStatus !== 'pending') {
                throw new RuntimeException("Business #{$businessId} cannot be approved because its status is '{$prevStatus}' (expected 'pending').");
            }

            if ($mergeIntoSpecialtyId !== null && $mergeIntoSpecialtyId > 0) {
                $specStmt = $pdo->prepare("SELECT id, parent_id FROM categories WHERE id = :sid AND parent_id IS NOT NULL");
                $specStmt->execute([':sid' => $mergeIntoSpecialtyId]);
                $targetSpec = $specStmt->fetch();
                if ($targetSpec) {
                    $oldSpecId = (int)$biz['specialty_id'];
                    $pdo->prepare("UPDATE businesses SET sector_id = :sec, specialty_id = :spec WHERE id = :id")
                        ->execute([
                            ':sec' => (int)$targetSpec['parent_id'],
                            ':spec' => (int)$targetSpec['id'],
                            ':id' => $businessId,
                        ]);
                    $pdo->prepare("UPDATE categories SET status = 'rejected' WHERE id = :old AND status = 'pending_approval'")
                        ->execute([':old' => $oldSpecId]);
                }
            } else {
                $pdo->prepare("UPDATE categories SET status = 'approved' WHERE id = :sid AND status = 'pending_approval'")
                    ->execute([':sid' => (int)$biz['specialty_id']]);
            }

            $upd = $pdo->prepare("
                UPDATE businesses
                SET status = 'approved',
                    quarantine_prev_status = NULL,
                    verified_at = CURRENT_TIMESTAMP,
                    last_confirmed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND status = 'pending'
            ");
            $upd->execute([':id' => $businessId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("Business #{$businessId} state changed concurrently; approval aborted.");
            }

            $vetRowStmt = $pdo->prepare("SELECT submitter_email, submitter_name, submitter_phone FROM business_vetting_queue WHERE business_id = :id LIMIT 1");
            $vetRowStmt->execute([':id' => $businessId]);
            $vetRow = $vetRowStmt->fetch();
            if ($vetRow && !empty($vetRow['submitter_email']) && filter_var($vetRow['submitter_email'], FILTER_VALIDATE_EMAIL)) {
                $subEmail = strtolower(trim((string)$vetRow['submitter_email']));
                $pdo->prepare("UPDATE businesses SET owner_email = :oem WHERE id = :id AND (owner_email IS NULL OR owner_email = '')")
                    ->execute([':oem' => $subEmail, ':id' => $businessId]);
                $ownerRec = OwnerAuth::upsertOwner(
                    $pdo,
                    $subEmail,
                    (string)($vetRow['submitter_name'] ?? ''),
                    (string)($vetRow['submitter_phone'] ?? '')
                );
                OwnerAuth::linkOwnerToBusiness($pdo, (int)$ownerRec['id'], $businessId, 'approved_submission');
                Mailer::notifyOwnerListingApproved($pdo, ['id' => $businessId, 'name' => (string)$biz['name']], $subEmail);
            }

            $del = $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id");
            $del->execute([':id' => $businessId]);
            $purgedRows = $del->rowCount();
            Mailer::scrubLegacyVettingPiiFromOutbox($pdo, $businessId);

            self::recordModerationAudit(
                $pdo,
                $businessId,
                'approve',
                $channel,
                $actorIdentifier,
                $prevStatus,
                'approved',
                $mergeIntoSpecialtyId ? "Merged specialty #{$mergeIntoSpecialtyId}" : null,
                $clientIp
            );

            if ($ownTx) {
                $pdo->commit();
                Mailer::flushPendingOutbox($pdo);
            }

            return [
                'business_id' => $businessId,
                'new_status' => 'approved',
                'vetting_rows_purged' => $purgedRows,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
                Mailer::clearPendingOutbox();
            }
            throw $e;
        }
    }

    public static function rejectBusiness(
        PDO $pdo,
        int $businessId,
        string $channel = 'admin_dashboard',
        string $actorIdentifier = 'admin_console',
        ?string $clientIp = null
    ): array {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("SELECT id, specialty_id, status, photos_json FROM businesses WHERE id = :id");
            $stmt->execute([':id' => $businessId]);
            $biz = $stmt->fetch();
            if (!$biz) {
                throw new RuntimeException("Business #{$businessId} not found.");
            }
            $prevStatus = (string)($biz['status'] ?? '');
            if ($prevStatus !== 'pending') {
                throw new RuntimeException("Business #{$businessId} cannot be rejected because its status is '{$prevStatus}' (expected 'pending').");
            }
            $oldPhotosJson = is_string($biz['photos_json'] ?? null) ? $biz['photos_json'] : null;

            $upd = $pdo->prepare("UPDATE businesses SET status = 'rejected', photos_json = '[]', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending'");
            $upd->execute([':id' => $businessId]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException("Business #{$businessId} state changed concurrently; rejection aborted.");
            }

            $pdo->prepare("UPDATE categories SET status = 'rejected' WHERE id = :sid AND status = 'pending_approval'")
                ->execute([':sid' => (int)$biz['specialty_id']]);

            $del = $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id");
            $del->execute([':id' => $businessId]);
            $purgedRows = $del->rowCount();
            Mailer::scrubLegacyVettingPiiFromOutbox($pdo, $businessId);

            self::recordModerationAudit(
                $pdo,
                $businessId,
                'reject',
                $channel,
                $actorIdentifier,
                $prevStatus,
                'rejected',
                null,
                $clientIp
            );

            if ($ownTx) {
                $pdo->commit();
            }

            if ($oldPhotosJson !== null && $oldPhotosJson !== '') {
                PhotoUploader::deleteAllBusinessMedia($businessId, $oldPhotosJson);
            }

            return [
                'business_id' => $businessId,
                'new_status' => 'rejected',
                'vetting_rows_purged' => $purgedRows,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function quarantineBusinessDpa(
        PDO $pdo,
        int $businessId,
        string $channel = 'dpa_takedown',
        string $actorIdentifier = 'owner_or_admin',
        ?string $clientIp = null
    ): void {
        $curStmt = $pdo->prepare("SELECT status, photos_json FROM businesses WHERE id = :id LIMIT 1");
        $curStmt->execute([':id' => $businessId]);
        $row = $curStmt->fetch();
        if (!$row) {
            return;
        }
        $curStatus = (string)($row['status'] ?? '');
        $photosJson = is_string($row['photos_json'] ?? null) ? $row['photos_json'] : null;
        if ($curStatus === '') {
            return;
        }
        if ($curStatus !== 'quarantined_dpa') {
            $pdo->prepare("
                UPDATE businesses
                SET quarantine_prev_status = :prev,
                    status = 'quarantined_dpa',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([
                ':prev' => $curStatus,
                ':id' => $businessId,
            ]);
            self::recordModerationAudit(
                $pdo,
                $businessId,
                'quarantine',
                $channel,
                $actorIdentifier,
                $curStatus,
                'quarantined_dpa',
                null,
                $clientIp
            );
        }
        PhotoUploader::quarantineBusinessPhotos($businessId, $photosJson);
    }

    public static function restoreQuarantinedBusiness(
        PDO $pdo,
        int $businessId,
        string $channel = 'admin_dashboard',
        string $actorIdentifier = 'admin_console',
        ?string $clientIp = null
    ): string {
        $stmt = $pdo->prepare("SELECT status, quarantine_prev_status, verified_at, photos_json FROM businesses WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $businessId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Business #{$businessId} not found.");
        }

        $vetCntStmt = $pdo->prepare("SELECT COUNT(*) FROM business_vetting_queue WHERE business_id = :id");
        $vetCntStmt->execute([':id' => $businessId]);
        $hasPendingVetting = ((int)$vetCntStmt->fetchColumn()) > 0;

        $prev = (string)($row['quarantine_prev_status'] ?? '');
        if ($prev === 'pending' || ($hasPendingVetting && empty($row['verified_at']))) {
            $targetStatus = 'pending';
        } elseif (in_array($prev, ['approved', 'rejected'], true)) {
            $targetStatus = $prev;
        } else {
            $targetStatus = 'approved';
        }

        $pdo->prepare("
            UPDATE businesses
            SET status = :st,
                quarantine_prev_status = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':st' => $targetStatus,
            ':id' => $businessId,
        ]);

        self::recordModerationAudit(
            $pdo,
            $businessId,
            'restore',
            $channel,
            $actorIdentifier,
            (string)($row['status'] ?? 'quarantined_dpa'),
            $targetStatus,
            null,
            $clientIp
        );

        if ($targetStatus !== 'rejected') {
            PhotoUploader::restoreQuarantinedBusinessPhotos($businessId, is_string($row['photos_json'] ?? null) ? $row['photos_json'] : null);
        } else {
            PhotoUploader::deleteAllBusinessMedia($businessId, is_string($row['photos_json'] ?? null) ? $row['photos_json'] : null);
        }

        return $targetStatus;
    }

    /**
     * Record a takedown / DPA objection request without allowing anonymous third parties to nuke live listings or vetting rows (#168).
     *
     * @return array{request_id:int, status:string, raw_token:?string}
     */
    public static function submitTakedownRequest(
        PDO $pdo,
        int $businessId,
        string $requesterName,
        string $requesterEmail,
        string $reason,
        string $clientIp,
        bool $isAuthenticatedOwner = false,
        bool $matchesDesignatedOwnerEmail = false
    ): array {
        $ipHmac = AntiSpamGate::pseudonymizeIp($clientIp);
        $cleanEmail = strtolower(trim($requesterEmail));
        $cleanName = trim($requesterName);
        $cleanReason = trim($reason);

        if ($isAuthenticatedOwner) {
            self::quarantineBusinessDpa($pdo, $businessId);
            $nowUtc = gmdate('Y-m-d H:i:s');
            $ins = $pdo->prepare("
                INSERT INTO takedown_requests (
                    business_id, requester_name, requester_email, reason,
                    verified_at, status, ip_hmac, created_at, resolved_at
                ) VALUES (
                    :bid, :nm, :em, :rsn,
                    :v_at, 'owner_verified_quarantined', :ip, :c_at, :r_at
                )
            ");
            $ins->execute([
                ':bid' => $businessId,
                ':nm' => $cleanName,
                ':em' => $cleanEmail,
                ':rsn' => $cleanReason,
                ':v_at' => $nowUtc,
                ':ip' => $ipHmac,
                ':c_at' => $nowUtc,
                ':r_at' => $nowUtc,
            ]);
            return [
                'request_id' => (int)$pdo->lastInsertId(),
                'status' => 'owner_verified_quarantined',
                'raw_token' => null,
            ];
        }

        $rawToken = null;
        $tokenHash = null;
        $tokenExp = null;
        $status = 'pending_review';

        if ($matchesDesignatedOwnerEmail) {
            $rawToken = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $rawToken);
            $tokenExp = gmdate('Y-m-d H:i:s', time() + 7200);
            $status = 'awaiting_owner_email_confirm';
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare("
            INSERT INTO takedown_requests (
                business_id, requester_name, requester_email, reason,
                verification_token_hash, token_expires_at, status, ip_hmac, created_at
            ) VALUES (
                :bid, :nm, :em, :rsn,
                :thash, :texp, :st, :ip, :c_at
            )
        ");
        $ins->execute([
            ':bid' => $businessId,
            ':nm' => $cleanName,
            ':em' => $cleanEmail,
            ':rsn' => $cleanReason,
            ':thash' => $tokenHash,
            ':texp' => $tokenExp,
            ':st' => $status,
            ':ip' => $ipHmac,
            ':c_at' => $nowUtc,
        ]);

        return [
            'request_id' => (int)$pdo->lastInsertId(),
            'status' => $status,
            'raw_token' => $rawToken,
        ];
    }

    /**
     * Confirm a designated owner's email-verified takedown link (single-use) and quarantine the listing (#168).
     */
    public static function confirmOwnerTakedownByToken(PDO $pdo, string $rawToken): ?array
    {
        $clean = trim($rawToken);
        if (strlen($clean) < 32 || !ctype_xdigit($clean)) {
            return null;
        }
        $tokenHash = hash('sha256', $clean);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            SELECT * FROM takedown_requests
            WHERE verification_token_hash = :th
              AND status = 'awaiting_owner_email_confirm'
              AND verified_at IS NULL
              AND token_expires_at >= :now
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash, ':now' => $nowUtc]);
        $req = $stmt->fetch();
        if (!$req) {
            return null;
        }

        $upd = $pdo->prepare("
            UPDATE takedown_requests
            SET verified_at = :v_at,
                resolved_at = :r_at,
                status = 'owner_verified_quarantined',
                verification_token_hash = NULL
            WHERE id = :id
              AND status = 'awaiting_owner_email_confirm'
        ");
        $upd->execute([
            ':v_at' => $nowUtc,
            ':r_at' => $nowUtc,
            ':id' => (int)$req['id'],
        ]);
        if ($upd->rowCount() === 0) {
            return null;
        }

        $bizId = (int)$req['business_id'];
        self::quarantineBusinessDpa($pdo, $bizId);
        return $req;
    }

    /**
     * Resolve a queued takedown request from the Admin Console ('quarantine' or 'dismiss') (#168).
     */
    public static function resolveTakedownRequestByAdmin(PDO $pdo, int $requestId, string $decision): bool
    {
        $stmt = $pdo->prepare("SELECT * FROM takedown_requests WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $req = $stmt->fetch();
        if (!$req) {
            return false;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $bizId = (int)$req['business_id'];
        if ($decision === 'quarantine') {
            self::quarantineBusinessDpa($pdo, $bizId);
            $newSt = 'admin_quarantined';
        } elseif ($decision === 'dismiss') {
            $newSt = 'dismissed';
        } else {
            return false;
        }

        $pdo->prepare("
            UPDATE takedown_requests
            SET status = :st,
                resolved_at = :r_at,
                verification_token_hash = NULL
            WHERE id = :id
        ")->execute([
            ':st' => $newSt,
            ':r_at' => $nowUtc,
            ':id' => $requestId,
        ]);

        return true;
    }

    /**
     * Kenya DPA 2019 ss. 25(e), 39 Automated Storage-Limitation Ceiling Purge:
     * 1. Hard-deletes any `business_vetting_queue` rows older than $maxPendingDays (default 30 days)
     *    or belonging to finalized (`approved` / `rejected`) businesses (never `quarantined_dpa` pending listings).
     * 2. Redacts `email_outbox.body_html` older than $maxPendingDays plus any legacy vetting PII rows.
     * 3. Purges expired `used_nonces`, `rate_limits`, and expired/revoked `owner_sessions`.
     */
    public static function enforceRetentionCeilings(PDO $pdo, int $maxPendingDays = 30): array
    {
        $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($maxPendingDays * 86400));

        // 1. Delete vetting rows ONLY for approved/rejected businesses or abandoned rows older than cutoff (#168)
        $delVetting = $pdo->prepare("
            DELETE FROM business_vetting_queue
            WHERE created_at < :cutoff
               OR business_id IN (SELECT id FROM businesses WHERE status IN ('approved', 'rejected'))
        ");
        $delVetting->execute([':cutoff' => $cutoffDate]);
        $vettingPurged = $delVetting->rowCount();

        // 2. Redact old email_outbox bodies containing historical vetting alerts
        $redactOutbox = $pdo->prepare("
            UPDATE email_outbox
            SET body_html = '[REDACTED PER KENYA DPA 2019 30-DAY RETENTION POLICY]'
            WHERE created_at < :cutoff
              AND body_html != '[REDACTED PER KENYA DPA 2019 30-DAY RETENTION POLICY]'
        ");
        $redactOutbox->execute([':cutoff' => $cutoffDate]);
        $outboxRedacted = $redactOutbox->rowCount();

        // 3. Purge expired nonces, >24h rate-limit hashes, and expired/revoked persistent owner sessions (#165)
        $nowUtc = gmdate('Y-m-d H:i:s');
        $pdo->prepare("DELETE FROM used_nonces WHERE expires_at < :now")->execute([':now' => time()]);
        $pdo->prepare("DELETE FROM rate_limits WHERE created_at < :cut")->execute([':cut' => time() - 86400]);
        $pdo->prepare("DELETE FROM owner_sessions WHERE expires_at < :now OR revoked_at IS NOT NULL")->execute([':now' => $nowUtc]);
        $metricsCutoff = gmdate('Y-m-d H:i:s', time() - (180 * 86400));
        $pdo->prepare("DELETE FROM usage_metrics WHERE created_at < :mcut")->execute([':mcut' => $metricsCutoff]);

        $mediaSweep = PhotoUploader::sweepOrphanedMedia($pdo, 3600);

        return [
            'vetting_rows_purged' => $vettingPurged,
            'outbox_bodies_redacted' => $outboxRedacted,
            'orphaned_public_media_deleted' => $mediaSweep['orphaned_public_deleted'],
            'orphaned_quarantine_media_deleted' => $mediaSweep['orphaned_quarantine_deleted'],
            'cutoff_utc' => $cutoffDate,
        ];
    }

    /**
     * Record a scheduled execution heartbeat for `bin/purge-dpa-retention.php` (#188).
     */
    public static function recordCronHeartbeat(PDO $pdo, array $summary = []): string
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        \BusherianBiasharas\Config\Database::setSetting($pdo, 'DPA_RETENTION_CRON_LAST_RUN_AT', $nowUtc);
        if ($summary !== []) {
            \BusherianBiasharas\Config\Database::setSetting(
                $pdo,
                'DPA_RETENTION_CRON_LAST_SUMMARY',
                (string)json_encode($summary, JSON_UNESCAPED_SLASHES)
            );
        }
        return $nowUtc;
    }

    /**
     * Inspect whether the scheduled `bin/purge-dpa-retention.php` cron heartbeat is healthy or stale (#188).
     *
     * @return array{healthy: bool, status: string, last_run_utc: ?string, age_hours: ?float, stale_after_hours: int}
     */
    public static function getRetentionCronHealth(PDO $pdo, int $staleAfterHours = 36): array
    {
        $threshold = max(1, $staleAfterHours);
        $raw = trim(\BusherianBiasharas\Config\Database::getSetting($pdo, 'DPA_RETENTION_CRON_LAST_RUN_AT', ''));
        if ($raw === '') {
            return [
                'healthy' => false,
                'status' => 'never_run',
                'last_run_utc' => null,
                'age_hours' => null,
                'stale_after_hours' => $threshold,
            ];
        }
        $ts = strtotime($raw . ' UTC');
        if ($ts === false || $ts <= 0) {
            return [
                'healthy' => false,
                'status' => 'invalid_timestamp',
                'last_run_utc' => $raw,
                'age_hours' => null,
                'stale_after_hours' => $threshold,
            ];
        }
        $ageHours = round(max(0, time() - $ts) / 3600, 2);
        $healthy = $ageHours <= $threshold;
        return [
            'healthy' => $healthy,
            'status' => $healthy ? 'healthy' : 'stale',
            'last_run_utc' => gmdate('Y-m-d H:i:s', $ts),
            'age_hours' => $ageHours,
            'stale_after_hours' => $threshold,
        ];
    }

    /**
     * Permanently delete an existing business record (and any associated vetting, outbox, takedown, link & click rows).
     */
    public static function deleteBusiness(
        PDO $pdo,
        int $businessId,
        string $channel = 'admin_dashboard',
        string $actorIdentifier = 'admin_console',
        ?string $clientIp = null
    ): bool {
        $photoStmt = $pdo->prepare("SELECT status, photos_json FROM businesses WHERE id = :id LIMIT 1");
        $photoStmt->execute([':id' => $businessId]);
        $bizRow = $photoStmt->fetch();
        $photosJson = is_array($bizRow) && is_string($bizRow['photos_json'] ?? null) ? $bizRow['photos_json'] : null;
        $prevStatus = is_array($bizRow) ? (string)($bizRow['status'] ?? '') : null;

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM email_outbox WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM takedown_requests WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM business_owner_links WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM click_events WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM usage_metrics WHERE business_id = :id")->execute([':id' => $businessId]);
            $del = $pdo->prepare("DELETE FROM businesses WHERE id = :id");
            $del->execute([':id' => $businessId]);
            $deleted = $del->rowCount() > 0;
            if ($deleted) {
                self::recordModerationAudit(
                    $pdo,
                    $businessId,
                    'delete',
                    $channel,
                    $actorIdentifier,
                    $prevStatus,
                    'deleted',
                    null,
                    $clientIp
                );
            }
            if ($ownTx) {
                $pdo->commit();
            }
            if ($deleted) {
                PhotoUploader::deleteAllBusinessMedia($businessId, $photosJson);
            }
            return $deleted;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Update an existing business record from the Admin Edit interface.
     */
    public static function updateBusiness(PDO $pdo, int $businessId, array $data): void
    {
        $existingRowStmt = $pdo->prepare("SELECT operating_hours_json, photos_json, latitude, longitude, plus_code, google_maps_url FROM businesses WHERE id = :id LIMIT 1");
        $existingRowStmt->execute([':id' => $businessId]);
        $existingRow = (array)($existingRowStmt->fetch() ?: []);

        $coordsMeta = self::resolveCoordsFromAdminData($data, $existingRow);
        $resolvedLat = $coordsMeta['latitude'];
        $resolvedLng = $coordsMeta['longitude'];
        $hoursJson = isset($data['operating_hours_json']) && trim((string)$data['operating_hours_json']) !== ''
            ? trim((string)$data['operating_hours_json'])
            : HoursEngine::buildScheduleJsonFromPost($data, (string)($existingRow['operating_hours_json'] ?? ''));

        $validationData = $data;
        $validationData['google_maps_url'] = $coordsMeta['google_maps_url'];
        self::validateListingWritePayload($pdo, $validationData, $resolvedLat, $resolvedLng, $hoursJson);

        $previousPhotoUrls = PhotoUploader::decodePhotosJson(isset($existingRow['photos_json']) ? (string)$existingRow['photos_json'] : null);
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($existingRow['photos_json']) ? (string)$existingRow['photos_json'] : null,
            isset($data['remove_photos']) && is_array($data['remove_photos']) ? $data['remove_photos'] : [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );
        $keptPhotoUrls = PhotoUploader::decodePhotosJson($photosJson);
        $newlyWrittenPhotoUrls = array_values(array_diff($keptPhotoUrls, $previousPhotoUrls));

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
        $stmt = $pdo->prepare("
            UPDATE businesses SET
                name = :name,
                tagline = :tagline,
                description = :description,
                school_badge = :school_badge,
                sector_id = :sector_id,
                specialty_id = :specialty_id,
                service_mode = :service_mode,
                alumni_perk = :alumni_perk,
                b2b_ready = :b2b_ready,
                accepts_lpo = :accepts_lpo,
                regulator_name = :regulator_name,
                regulator_license = :regulator_license,
                branch_name = :branch_name,
                building_name = :building_name,
                floor_suite = :floor_suite,
                street_road = :street_road,
                area_neighborhood = :area_neighborhood,
                country = :country,
                city_county = :city_county,
                landmark_directions = :landmark_directions,
                plus_code = :plus_code,
                latitude = :latitude,
                longitude = :longitude,
                google_maps_url = :google_maps_url,
                operating_hours_json = :operating_hours_json,
                photos_json = :photos_json,
                phone_primary = :phone_primary,
                whatsapp_number = :whatsapp_number,
                email_public = :email_public,
                owner_email = :owner_email,
                website_url = :website_url,
                social_instagram = :social_instagram,
                social_linkedin = :social_linkedin,
                social_x = :social_x,
                social_facebook = :social_facebook,
                social_tiktok = :social_tiktok,
                social_youtube = :social_youtube,
                status = :status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $stmt->execute([
            ':name' => trim((string)$data['name']),
            ':tagline' => trim((string)$data['tagline']),
            ':description' => trim((string)$data['description']),
            ':school_badge' => in_array($data['school_badge'] ?? '', ['AHS', 'AGHS', 'BOTH'], true) ? $data['school_badge'] : 'BOTH',
            ':sector_id' => (int)$data['sector_id'],
            ':specialty_id' => (int)$data['specialty_id'],
            ':service_mode' => in_array($data['service_mode'] ?? '', ['physical', 'hybrid', 'online'], true) ? $data['service_mode'] : 'physical',
            ':alumni_perk' => trim((string)($data['alumni_perk'] ?? '')) ?: null,
            ':b2b_ready' => !empty($data['b2b_ready']) ? 1 : 0,
            ':accepts_lpo' => !empty($data['accepts_lpo']) ? 1 : 0,
            ':regulator_name' => trim((string)($data['regulator_name'] ?? '')) ?: null,
            ':regulator_license' => trim((string)($data['regulator_license'] ?? '')) ?: null,
            ':branch_name' => trim((string)($data['branch_name'] ?? '')) ?: null,
            ':building_name' => trim((string)($data['building_name'] ?? '')) ?: null,
            ':floor_suite' => trim((string)($data['floor_suite'] ?? '')) ?: null,
            ':street_road' => trim((string)($data['street_road'] ?? '')) ?: null,
            ':area_neighborhood' => trim((string)($data['area_neighborhood'] ?? '')) ?: null,
            ':country' => LocationEngine::resolvePlaceFromPost($data)['country'],
            ':city_county' => LocationEngine::resolvePlaceFromPost($data)['city_county'],
            ':landmark_directions' => trim((string)($data['landmark_directions'] ?? '')) ?: null,
            ':plus_code' => $coordsMeta['plus_code'],
            ':latitude' => $resolvedLat,
            ':longitude' => $resolvedLng,
            ':google_maps_url' => $coordsMeta['google_maps_url'],
            ':operating_hours_json' => $hoursJson,
            ':photos_json' => $photosJson,
            ':phone_primary' => trim((string)$data['phone_primary']),
            ':whatsapp_number' => trim((string)($data['whatsapp_number'] ?? '')) ?: null,
            ':email_public' => trim((string)($data['email_public'] ?? '')) ?: null,
            ':owner_email' => strtolower(trim((string)($data['owner_email'] ?? ''))) ?: null,
            ':website_url' => trim((string)($data['website_url'] ?? '')) ?: null,
            ':social_instagram' => trim((string)($data['social_instagram'] ?? '')) ?: null,
            ':social_linkedin' => trim((string)($data['social_linkedin'] ?? '')) ?: null,
            ':social_x' => trim((string)($data['social_x'] ?? '')) ?: null,
            ':social_facebook' => trim((string)($data['social_facebook'] ?? '')) ?: null,
            ':social_tiktok' => trim((string)($data['social_tiktok'] ?? '')) ?: null,
            ':social_youtube' => trim((string)($data['social_youtube'] ?? '')) ?: null,
            ':status' => in_array($data['status'] ?? '', ['approved', 'pending', 'rejected', 'quarantined_dpa'], true) ? $data['status'] : 'approved',
            ':id' => $businessId,
        ]);

        // If Admin assigned an owner_email, ensure owner record and link exist immediately
        $assignedOwnerEmail = strtolower(trim((string)($data['owner_email'] ?? '')));
        if ($assignedOwnerEmail !== '' && filter_var($assignedOwnerEmail, FILTER_VALIDATE_EMAIL)) {
            $ownerRec = OwnerAuth::upsertOwner($pdo, $assignedOwnerEmail);
            OwnerAuth::linkOwnerToBusiness($pdo, (int)$ownerRec['id'], $businessId, 'admin_assigned');
        }

        $targetStatus = in_array($data['status'] ?? '', ['approved', 'pending', 'rejected', 'quarantined_dpa'], true)
            ? (string)$data['status']
            : 'approved';
        if (in_array($targetStatus, ['approved', 'rejected'], true)) {
            $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id")->execute([':id' => $businessId]);
        }
        if ($ownTx) {
            $pdo->commit();
        }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newlyWrittenPhotoUrls !== []) {
                PhotoUploader::deleteRemovedPhotoUrls($newlyWrittenPhotoUrls, []);
            }
            throw $e;
        }
        if ($targetStatus === 'quarantined_dpa') {
            PhotoUploader::quarantineBusinessPhotos($businessId, $photosJson);
        } elseif ($targetStatus === 'approved') {
            PhotoUploader::restoreQuarantinedBusinessPhotos($businessId, $photosJson);
        }
        if ($ownTx) {
            PhotoUploader::deleteRemovedPhotoUrls($previousPhotoUrls, $keptPhotoUrls);
        }
    }

    /**
     * Resolve coordinates automatically from explicit lat/lng, Maps links (including short links), Plus Codes, or free-text map URLs.
     *
     * @return array{latitude: ?float, longitude: ?float, plus_code: ?string, google_maps_url: ?string, inferred: bool, network_error: bool}
     */
    private static function resolveCoordsFromAdminData(array $data, ?array $existingRow = null): array
    {
        $allowExternal = (getenv('BBIZ_OFFLINE_ROUTING') !== '1');
        return LocationEngine::resolveCoordinatesFromListingData($data, $allowExternal, null, $existingRow);
    }

    /**
     * Generate a unique URL-safe slug for a business name.
     */
    public static function generateUniqueSlug(PDO $pdo, string $name): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        if ($base === '') {
            $base = 'biashara-' . time();
        }
        $slug = $base;
        $suffix = 2;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE slug = :slug");
        while (true) {
            $chk->execute([':slug' => $slug]);
            if ((int)$chk->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }

    /**
     * Directly create and publish a known Busherian business from the Admin Console
     * WITHOUT going through the public alumni verification queue.
     */
    public static function createBusinessDirectly(PDO $pdo, array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Business Name is required.');
        }

        $tagline = trim((string)($data['tagline'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        if ($tagline === '' && $description !== '') {
            $tagline = mb_substr($description, 0, 160);
        } elseif ($description === '' && $tagline !== '') {
            $description = $tagline;
        } elseif ($tagline === '' && $description === '') {
            $tagline = $name;
            $description = $name;
        }

        $sectorId = max(1, (int)($data['sector_id'] ?? 1));
        $specialtyId = max(1, (int)($data['specialty_id'] ?? 2));
        $coordsMeta = self::resolveCoordsFromAdminData($data);
        $lat = $coordsMeta['latitude'];
        $lng = $coordsMeta['longitude'];
        $plusCode = $coordsMeta['plus_code'];

        $defaultHours = json_encode([
            'mon' => [['08:00', '17:30']],
            'tue' => [['08:00', '17:30']],
            'wed' => [['08:00', '17:30']],
            'thu' => [['08:00', '17:30']],
            'fri' => [['08:00', '17:30']],
            'sat' => [['09:00', '14:00']],
            'sun' => [],
        ]);
        $hoursJson = isset($data['operating_hours_json']) && trim((string)$data['operating_hours_json']) !== ''
            ? trim((string)$data['operating_hours_json'])
            : HoursEngine::buildScheduleJsonFromPost($data, (string)$defaultHours);

        $validationPayload = $data;
        $validationPayload['name'] = $name;
        $validationPayload['tagline'] = mb_substr($tagline, 0, 180);
        $validationPayload['description'] = $description;
        $validationPayload['sector_id'] = $sectorId;
        $validationPayload['specialty_id'] = $specialtyId;
        $validationPayload['google_maps_url'] = $coordsMeta['google_maps_url'];
        self::validateListingWritePayload($pdo, $validationPayload, $lat, $lng, $hoursJson);

        $provenanceNote = trim((string)($data['admin_provenance_note'] ?? ''));
        if (!empty($data['require_provenance_basis'])) {
            $basis = trim((string)($data['admin_provenance_basis'] ?? ''));
            if (!in_array($basis, self::ALLOWED_ADMIN_PROVENANCE_BASES, true) || $provenanceNote === '') {
                throw new \InvalidArgumentException('Please select the DPA lawful-basis source and enter a brief authorization note before publishing.');
            }
            $provenanceNote = mb_substr("[{$basis}] {$provenanceNote}", 0, 255);
        } elseif ($provenanceNote === '') {
            $provenanceNote = 'Admin-curated verified Busherian business listing';
        } else {
            $provenanceNote = mb_substr($provenanceNote, 0, 255);
        }

        $slug = self::generateUniqueSlug($pdo, $name);
        $businessUid = self::generateUniqueBusinessUid($pdo);
        $nextNum = self::allocateNextBusinessNumber($pdo);
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($data['photos_json']) ? (string)$data['photos_json'] : null,
            [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );

        $status = in_array($data['status'] ?? 'approved', ['approved', 'quarantined_dpa', 'pending', 'rejected'], true)
            ? (string)($data['status'] ?? 'approved')
            : 'approved';

        $stmt = $pdo->prepare("
            INSERT INTO businesses (
                business_id, business_number, name, slug, tagline, description, school_badge,
                sector_id, specialty_id, service_mode, alumni_perk,
                b2b_ready, accepts_lpo, regulator_name, regulator_license,
                branch_name, building_name, floor_suite, street_road,
                area_neighborhood, country, city_county, landmark_directions,
                plus_code, latitude, longitude, google_maps_url,
                operating_hours_json, photos_json, phone_primary, whatsapp_number,
                email_public, website_url, social_instagram, social_linkedin,
                social_x, social_facebook, social_tiktok, social_youtube,
                status, privacy_notice_version, listing_provenance, admin_provenance_note,
                verified_at, last_confirmed_at
            ) VALUES (
                :business_id, :business_number, :name, :slug, :tagline, :description, :school_badge,
                :sector_id, :specialty_id, :service_mode, :alumni_perk,
                :b2b_ready, :accepts_lpo, :regulator_name, :regulator_license,
                :branch_name, :building_name, :floor_suite, :street_road,
                :area_neighborhood, :country, :city_county, :landmark_directions,
                :plus_code, :latitude, :longitude, :google_maps_url,
                :operating_hours_json, :photos_json, :phone_primary, :whatsapp_number,
                :email_public, :website_url, :social_instagram, :social_linkedin,
                :social_x, :social_facebook, :social_tiktok, :social_youtube,
                :status, :pver, 'admin_curated', :pnote,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute([
            ':business_id' => $businessUid,
            ':business_number' => $nextNum,
            ':name' => $name,
            ':slug' => $slug,
            ':tagline' => mb_substr($tagline, 0, 180),
            ':description' => $description,
            ':school_badge' => in_array($data['school_badge'] ?? '', ['AHS', 'AGHS', 'BOTH'], true) ? $data['school_badge'] : 'AHS',
            ':sector_id' => $sectorId,
            ':specialty_id' => $specialtyId,
            ':service_mode' => in_array($data['service_mode'] ?? '', ['physical', 'hybrid', 'online'], true) ? $data['service_mode'] : 'physical',
            ':alumni_perk' => trim((string)($data['alumni_perk'] ?? '')) ?: null,
            ':b2b_ready' => !empty($data['b2b_ready']) ? 1 : 0,
            ':accepts_lpo' => !empty($data['accepts_lpo']) ? 1 : 0,
            ':regulator_name' => trim((string)($data['regulator_name'] ?? '')) ?: null,
            ':regulator_license' => trim((string)($data['regulator_license'] ?? '')) ?: null,
            ':branch_name' => trim((string)($data['branch_name'] ?? '')) ?: null,
            ':building_name' => trim((string)($data['building_name'] ?? '')) ?: null,
            ':floor_suite' => trim((string)($data['floor_suite'] ?? '')) ?: null,
            ':street_road' => trim((string)($data['street_road'] ?? '')) ?: null,
            ':area_neighborhood' => trim((string)($data['area_neighborhood'] ?? '')) ?: null,
            ':country' => LocationEngine::resolvePlaceFromPost($data)['country'],
            ':city_county' => LocationEngine::resolvePlaceFromPost($data)['city_county'],
            ':landmark_directions' => trim((string)($data['landmark_directions'] ?? '')) ?: null,
            ':plus_code' => $plusCode,
            ':latitude' => $lat,
            ':longitude' => $lng,
            ':google_maps_url' => $coordsMeta['google_maps_url'],
            ':operating_hours_json' => $hoursJson,
            ':photos_json' => $photosJson,
            ':phone_primary' => trim((string)($data['phone_primary'] ?? '')),
            ':whatsapp_number' => trim((string)($data['whatsapp_number'] ?? '')) ?: null,
            ':email_public' => trim((string)($data['email_public'] ?? '')) ?: null,
            ':website_url' => trim((string)($data['website_url'] ?? '')) ?: null,
            ':social_instagram' => trim((string)($data['social_instagram'] ?? '')) ?: null,
            ':social_linkedin' => trim((string)($data['social_linkedin'] ?? '')) ?: null,
            ':social_x' => trim((string)($data['social_x'] ?? '')) ?: null,
            ':social_facebook' => trim((string)($data['social_facebook'] ?? '')) ?: null,
            ':social_tiktok' => trim((string)($data['social_tiktok'] ?? '')) ?: null,
            ':social_youtube' => trim((string)($data['social_youtube'] ?? '')) ?: null,
            ':status' => $status,
            ':pver' => self::PRIVACY_NOTICE_VERSION,
            ':pnote' => $provenanceNote,
        ]);

        $newId = (int)$pdo->lastInsertId();
        if ($status === 'quarantined_dpa') {
            PhotoUploader::quarantineBusinessPhotos($newId, $photosJson);
        }
        return $newId;
    }

    /**
     * Generate an immutable unique business_id (e.g., BBIZ-7F3A9C12).
     */
    public static function generateUniqueBusinessUid(PDO $pdo): string
    {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE business_id = :uid");
        while (true) {
            $uid = 'BBIZ-' . strtoupper(bin2hex(random_bytes(4)));
            $chk->execute([':uid' => $uid]);
            if ((int)$chk->fetchColumn() === 0) {
                return $uid;
            }
        }
    }

    /**
     * Sequentially renumber all businesses (1 .. N) with zero gaps while preserving immutable business_id.
     */
    public static function renumberBusinesses(PDO $pdo): int
    {
        $rows = $pdo->query("SELECT id, business_id FROM businesses ORDER BY id ASC")->fetchAll();
        $updNum = $pdo->prepare("UPDATE businesses SET business_number = :num WHERE id = :id");
        $updBoth = $pdo->prepare("UPDATE businesses SET business_id = :uid, business_number = :num WHERE id = :id");

        $seq = 0;
        foreach ($rows as $r) {
            $seq++;
            $id = (int)$r['id'];
            $existingUid = trim((string)($r['business_id'] ?? ''));
            if ($existingUid === '') {
                $uid = self::generateUniqueBusinessUid($pdo);
                $updBoth->execute([':uid' => $uid, ':num' => $seq, ':id' => $id]);
            } else {
                $updNum->execute([':num' => $seq, ':id' => $id]);
            }
        }
        return $seq;
    }

    /**
     * Self-service update by an authenticated & linked Business Owner.
     * Goes live immediately; if `name`, `sector_id`, or `specialty_id` changes, notifies Admin.
     */
    public static function updateBusinessByOwner(PDO $pdo, int $businessId, array $owner, array $data): array
    {
        if (!OwnerAuth::canOwnerEditBusiness($pdo, (int)$owner['id'], $businessId)) {
            throw new RuntimeException('You are not authorized to edit this business listing.');
        }

        $oldStmt = $pdo->prepare("
            SELECT b.*, sec.name AS sector_name, spec.name AS specialty_name
            FROM businesses b
            INNER JOIN categories sec ON sec.id = b.sector_id
            INNER JOIN categories spec ON spec.id = b.specialty_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $oldStmt->execute([':id' => $businessId]);
        $oldBiz = $oldStmt->fetch();
        if (!$oldBiz) {
            throw new RuntimeException('Business record not found.');
        }

        $coordsMeta = self::resolveCoordsFromAdminData($data, (array)$oldBiz);
        $resolvedLat = $coordsMeta['latitude'];
        $resolvedLng = $coordsMeta['longitude'];
        $hoursJson = isset($data['operating_hours_json']) && trim((string)$data['operating_hours_json']) !== ''
            ? trim((string)$data['operating_hours_json'])
            : HoursEngine::buildScheduleJsonFromPost($data, (string)$oldBiz['operating_hours_json']);

        $newNameCheck = trim((string)($data['name'] ?? $oldBiz['name']));
        $newSectorCheck = (int)($data['sector_id'] ?? $oldBiz['sector_id']);
        $newSpecCheck = (int)($data['specialty_id'] ?? $oldBiz['specialty_id']);
        $valPayload = array_merge((array)$oldBiz, $data, [
            'name' => $newNameCheck,
            'sector_id' => $newSectorCheck,
            'specialty_id' => $newSpecCheck,
            'google_maps_url' => $coordsMeta['google_maps_url'],
        ]);
        self::validateListingWritePayload($pdo, $valPayload, $resolvedLat, $resolvedLng, $hoursJson);

        $previousPhotoUrls = PhotoUploader::decodePhotosJson(isset($oldBiz['photos_json']) ? (string)$oldBiz['photos_json'] : null);
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($oldBiz['photos_json']) ? (string)$oldBiz['photos_json'] : null,
            isset($data['remove_photos']) && is_array($data['remove_photos']) ? $data['remove_photos'] : [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );
        $keptPhotoUrls = PhotoUploader::decodePhotosJson($photosJson);
        $newlyWrittenPhotoUrls = array_values(array_diff($keptPhotoUrls, $previousPhotoUrls));

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
        $stmt = $pdo->prepare("
            UPDATE businesses SET
                name = :name,
                tagline = :tagline,
                description = :description,
                sector_id = :sector_id,
                specialty_id = :specialty_id,
                service_mode = :service_mode,
                alumni_perk = :alumni_perk,
                b2b_ready = :b2b_ready,
                accepts_lpo = :accepts_lpo,
                regulator_name = :regulator_name,
                branch_name = :branch_name,
                building_name = :building_name,
                floor_suite = :floor_suite,
                street_road = :street_road,
                area_neighborhood = :area_neighborhood,
                country = :country,
                city_county = :city_county,
                landmark_directions = :landmark_directions,
                plus_code = :plus_code,
                latitude = :latitude,
                longitude = :longitude,
                google_maps_url = :google_maps_url,
                operating_hours_json = :operating_hours_json,
                photos_json = :photos_json,
                phone_primary = :phone_primary,
                whatsapp_number = :whatsapp_number,
                email_public = :email_public,
                website_url = :website_url,
                social_instagram = :social_instagram,
                social_linkedin = :social_linkedin,
                social_x = :social_x,
                social_facebook = :social_facebook,
                social_tiktok = :social_tiktok,
                social_youtube = :social_youtube,
                last_confirmed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $newName = trim((string)($data['name'] ?? $oldBiz['name']));
        $newSectorId = (int)($data['sector_id'] ?? $oldBiz['sector_id']);
        $newSpecId = (int)($data['specialty_id'] ?? $oldBiz['specialty_id']);

        $stmt->execute([
            ':name' => $newName,
            ':tagline' => trim((string)($data['tagline'] ?? $oldBiz['tagline'])),
            ':description' => trim((string)($data['description'] ?? $oldBiz['description'])),
            ':sector_id' => $newSectorId,
            ':specialty_id' => $newSpecId,
            ':service_mode' => in_array($data['service_mode'] ?? '', ['physical', 'hybrid', 'online'], true) ? $data['service_mode'] : $oldBiz['service_mode'],
            ':alumni_perk' => trim((string)($data['alumni_perk'] ?? '')) ?: null,
            ':b2b_ready' => !empty($data['b2b_ready']) ? 1 : 0,
            ':accepts_lpo' => !empty($data['accepts_lpo']) ? 1 : 0,
            ':regulator_name' => trim((string)($data['regulator_name'] ?? '')) ?: null,
            ':branch_name' => trim((string)($data['branch_name'] ?? '')) ?: null,
            ':building_name' => trim((string)($data['building_name'] ?? '')) ?: null,
            ':floor_suite' => trim((string)($data['floor_suite'] ?? '')) ?: null,
            ':street_road' => trim((string)($data['street_road'] ?? '')) ?: null,
            ':area_neighborhood' => trim((string)($data['area_neighborhood'] ?? '')) ?: null,
            ':country' => LocationEngine::resolvePlaceFromPost($data)['country'],
            ':city_county' => LocationEngine::resolvePlaceFromPost($data)['city_county'],
            ':landmark_directions' => trim((string)($data['landmark_directions'] ?? '')) ?: null,
            ':plus_code' => $coordsMeta['plus_code'],
            ':latitude' => $resolvedLat,
            ':longitude' => $resolvedLng,
            ':google_maps_url' => $coordsMeta['google_maps_url'],
            ':operating_hours_json' => $hoursJson,
            ':photos_json' => $photosJson,
            ':phone_primary' => trim((string)($data['phone_primary'] ?? $oldBiz['phone_primary'])),
            ':whatsapp_number' => trim((string)($data['whatsapp_number'] ?? '')) ?: null,
            ':email_public' => trim((string)($data['email_public'] ?? '')) ?: null,
            ':website_url' => trim((string)($data['website_url'] ?? '')) ?: null,
            ':social_instagram' => trim((string)($data['social_instagram'] ?? '')) ?: null,
            ':social_linkedin' => trim((string)($data['social_linkedin'] ?? '')) ?: null,
            ':social_x' => trim((string)($data['social_x'] ?? '')) ?: null,
            ':social_facebook' => trim((string)($data['social_facebook'] ?? '')) ?: null,
            ':social_tiktok' => trim((string)($data['social_tiktok'] ?? '')) ?: null,
            ':social_youtube' => trim((string)($data['social_youtube'] ?? '')) ?: null,
            ':id' => $businessId,
        ]);
        if ($ownTx) {
            $pdo->commit();
        }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newlyWrittenPhotoUrls !== []) {
                PhotoUploader::deleteRemovedPhotoUrls($newlyWrittenPhotoUrls, []);
            }
            throw $e;
        }

        if ($ownTx) {
            PhotoUploader::deleteRemovedPhotoUrls($previousPhotoUrls, $keptPhotoUrls);
        }

        $newStmt = $pdo->prepare("
            SELECT b.*, sec.name AS sector_name, spec.name AS specialty_name
            FROM businesses b
            INNER JOIN categories sec ON sec.id = b.sector_id
            INNER JOIN categories spec ON spec.id = b.specialty_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $newStmt->execute([':id' => $businessId]);
        $newBiz = (array)$newStmt->fetch();

        $criticalChanges = [];
        if (strcasecmp((string)$oldBiz['name'], (string)$newBiz['name']) !== 0) {
            $criticalChanges['Business Name'] = [
                'before' => (string)$oldBiz['name'],
                'after' => (string)$newBiz['name'],
            ];
        }
        if ((int)$oldBiz['sector_id'] !== (int)$newBiz['sector_id'] || (int)$oldBiz['specialty_id'] !== (int)$newBiz['specialty_id']) {
            $criticalChanges['Category (Sector -> Specialty)'] = [
                'before' => "{$oldBiz['sector_name']} -> {$oldBiz['specialty_name']}",
                'after' => "{$newBiz['sector_name']} -> {$newBiz['specialty_name']}",
            ];
        }

        $adminNotified = false;
        if (!empty($criticalChanges)) {
            Mailer::notifyAdminOwnerCriticalEdit($pdo, $owner, $oldBiz, $newBiz, $criticalChanges);
            $adminNotified = true;
        }

        return [
            'business' => $newBiz,
            'critical_changes' => $criticalChanges,
            'admin_notified' => $adminNotified,
        ];
    }

    /**
     * Audit all businesses missing latitude or longitude and backfill coordinates from any listed
     * Maps link (including short links like maps.app.goo.gl), Plus Code, or embedded free-text map URL.
     *
     * Isolates per-record failures so one failing row never aborts the batch.
     *
     * @return array{
     *   scanned: int,
     *   updated_count: int,
     *   updated: array<int, array<string, mixed>>,
     *   skipped_no_pin: array<int, array<string, mixed>>,
     *   network_errors: int,
     *   item_errors: array<int, string>
     * }
     */
    public static function backfillMissingCoordinatesFromPins(
        PDO $pdo,
        bool $allowExternal = true,
        ?callable $httpFetcher = null
    ): array {
        $stmt = $pdo->query("
            SELECT
                id, business_number, business_id, name, status,
                city_county, building_name, street_road, area_neighborhood,
                landmark_directions, description, website_url,
                plus_code, google_maps_url, latitude, longitude
            FROM businesses
            WHERE latitude IS NULL OR longitude IS NULL
            ORDER BY business_number ASC, id ASC
        ");
        $rows = $stmt->fetchAll();

        $updStmt = $pdo->prepare("
            UPDATE businesses SET
                latitude = :lat,
                longitude = :lng,
                plus_code = :plus_code,
                google_maps_url = :google_maps_url,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $updated = [];
        $skippedNoPin = [];
        $networkErrors = 0;
        $itemErrors = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            try {
                $res = LocationEngine::resolveCoordinatesFromListingData($row, $allowExternal, $httpFetcher);
                if (!empty($res['network_error'])) {
                    $networkErrors++;
                }
                if ($res['latitude'] !== null && $res['longitude'] !== null) {
                    $updStmt->execute([
                        ':lat' => $res['latitude'],
                        ':lng' => $res['longitude'],
                        ':plus_code' => $res['plus_code'],
                        ':google_maps_url' => $res['google_maps_url'],
                        ':id' => $id,
                    ]);
                    $updated[] = [
                        'id' => $id,
                        'business_number' => (int)($row['business_number'] ?? 0),
                        'name' => (string)$row['name'],
                        'status' => (string)$row['status'],
                        'latitude' => $res['latitude'],
                        'longitude' => $res['longitude'],
                        'plus_code' => $res['plus_code'],
                        'google_maps_url' => $res['google_maps_url'],
                    ];
                } else {
                    $skippedNoPin[] = [
                        'id' => $id,
                        'business_number' => (int)($row['business_number'] ?? 0),
                        'name' => (string)$row['name'],
                        'status' => (string)$row['status'],
                        'google_maps_url' => $row['google_maps_url'] ?: null,
                        'plus_code' => $row['plus_code'] ?: null,
                    ];
                }
            } catch (\Throwable $e) {
                $msg = "Business #{$row['business_number']} ({$row['name']}, id={$id}): " . $e->getMessage();
                $itemErrors[] = $msg;
                error_log('[VettingPurger::backfillMissingCoordinatesFromPins] ' . $msg);
            }
        }

        return [
            'scanned' => count($rows),
            'updated_count' => count($updated),
            'updated' => $updated,
            'skipped_no_pin' => $skippedNoPin,
            'network_errors' => $networkErrors,
            'item_errors' => $itemErrors,
        ];
    }
}
