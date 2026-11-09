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
}
