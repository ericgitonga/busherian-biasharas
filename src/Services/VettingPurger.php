<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;
use RuntimeException;

final class VettingPurger
{
    /**
     * Approve a pending business listing and IMMEDIATELY hard-delete its alumni vetting credentials
     * (`school_code`, `house_name`, `year_left`, `school_number`) from `business_vetting_queue`.
     */
    public static function approveBusiness(PDO $pdo, int $businessId, ?int $mergeIntoSpecialtyId = null): array
    {
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

            if ($mergeIntoSpecialtyId !== null && $mergeIntoSpecialtyId > 0) {
                // Re-point business to canonical specialty and mark custom specialty rejected
                $specStmt = $pdo->prepare("SELECT id, parent_id FROM categories WHERE id = :sid");
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
                // Approve any custom specialty submitted with this business
                $pdo->prepare("UPDATE categories SET status = 'approved' WHERE id = :sid AND status = 'pending_approval'")
                    ->execute([':sid' => (int)$biz['specialty_id']]);
            }

            // Transition business to approved
            $pdo->prepare("
                UPDATE businesses
                SET status = 'approved',
                    verified_at = CURRENT_TIMESTAMP,
                    last_confirmed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ")->execute([':id' => $businessId]);

            // STRICT PRIVACY GUARANTEE: Permanently hard-delete House, Year Left & School Number
            $del = $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id");
            $del->execute([':id' => $businessId]);
            $purgedRows = $del->rowCount();

            if ($ownTx) {
                $pdo->commit();
            }

            return [
                'business_id' => $businessId,
                'new_status' => 'approved',
                'vetting_rows_purged' => $purgedRows,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reject a pending business listing and IMMEDIATELY hard-delete its alumni vetting credentials.
     */
    public static function rejectBusiness(PDO $pdo, int $businessId): array
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare("SELECT id, specialty_id FROM businesses WHERE id = :id");
            $stmt->execute([':id' => $businessId]);
            $biz = $stmt->fetch();
            if (!$biz) {
                throw new RuntimeException("Business #{$businessId} not found.");
            }

            $pdo->prepare("UPDATE businesses SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':id' => $businessId]);

            $pdo->prepare("UPDATE categories SET status = 'rejected' WHERE id = :sid AND status = 'pending_approval'")
                ->execute([':sid' => (int)$biz['specialty_id']]);

            // Permanently hard-delete House, Year Left & School Number
            $del = $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id");
            $del->execute([':id' => $businessId]);
            $purgedRows = $del->rowCount();

            if ($ownTx) {
                $pdo->commit();
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

    /**
     * Kenya DPA 2019 s.40 Instant Quarantine on Takedown / Erasure Request
     */
    public static function quarantineBusinessDpa(PDO $pdo, int $businessId): void
    {
        $pdo->prepare("UPDATE businesses SET status = 'quarantined_dpa', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $businessId]);
        $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id")
            ->execute([':id' => $businessId]);
    }

    /**
     * Kenya DPA 2019 ss. 25(e), 39 Automated Storage-Limitation Ceiling Purge:
     * 1. Hard-deletes any `business_vetting_queue` rows older than $maxPendingDays (default 30 days)
     *    or belonging to already-reviewed businesses.
     * 2. Redacts `email_outbox.body_html` older than $maxPendingDays so temporary vetting details
     *    inside admin notification emails are also scrubbed.
     * 3. Purges expired `used_nonces` and `rate_limits`.
     */
    public static function enforceRetentionCeilings(PDO $pdo, int $maxPendingDays = 30): array
    {
        $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($maxPendingDays * 86400));

        // 1. Delete vetting rows for reviewed businesses or abandoned rows older than cutoff
        $delVetting = $pdo->prepare("
            DELETE FROM business_vetting_queue
            WHERE created_at < :cutoff
               OR business_id IN (SELECT id FROM businesses WHERE status IN ('approved', 'rejected', 'quarantined_dpa'))
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

        // 3. Purge expired nonces and >24h rate-limit hashes
        $pdo->prepare("DELETE FROM used_nonces WHERE expires_at < :now")->execute([':now' => time()]);
        $pdo->prepare("DELETE FROM rate_limits WHERE created_at < :cut")->execute([':cut' => time() - 86400]);

        return [
            'vetting_rows_purged' => $vettingPurged,
            'outbox_bodies_redacted' => $outboxRedacted,
            'cutoff_utc' => $cutoffDate,
        ];
    }
}

