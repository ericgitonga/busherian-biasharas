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

            // Link submitter's email as persistent business owner before purging temporary vetting credentials
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
            }

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
        $metricsCutoff = gmdate('Y-m-d H:i:s', time() - (180 * 86400));
        $pdo->prepare("DELETE FROM usage_metrics WHERE created_at < :mcut")->execute([':mcut' => $metricsCutoff]);

        return [
            'vetting_rows_purged' => $vettingPurged,
            'outbox_bodies_redacted' => $outboxRedacted,
            'cutoff_utc' => $cutoffDate,
        ];
    }

    /**
     * Permanently delete an existing business record (and any associated vetting/click rows).
     */
    public static function deleteBusiness(PDO $pdo, int $businessId): bool
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM click_events WHERE business_id = :id")->execute([':id' => $businessId]);
            $pdo->prepare("DELETE FROM usage_metrics WHERE business_id = :id")->execute([':id' => $businessId]);
            $del = $pdo->prepare("DELETE FROM businesses WHERE id = :id");
            $del->execute([':id' => $businessId]);
            $deleted = $del->rowCount() > 0;
            if ($deleted) {
                self::renumberBusinesses($pdo);
            }
            if ($ownTx) {
                $pdo->commit();
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
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($existingRow['photos_json']) ? (string)$existingRow['photos_json'] : null,
            isset($data['remove_photos']) && is_array($data['remove_photos']) ? $data['remove_photos'] : [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );

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

        // If status is approved/rejected/quarantined_dpa, ensure no vetting queue row remains
        if (in_array($data['status'] ?? 'approved', ['approved', 'rejected', 'quarantined_dpa'], true)) {
            $pdo->prepare("DELETE FROM business_vetting_queue WHERE business_id = :id")->execute([':id' => $businessId]);
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

        $slug = self::generateUniqueSlug($pdo, $name);
        $businessUid = self::generateUniqueBusinessUid($pdo);
        $nextNum = ((int)$pdo->query("SELECT COALESCE(MAX(business_number), 0) FROM businesses")->fetchColumn()) + 1;
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
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($data['photos_json']) ? (string)$data['photos_json'] : null,
            [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );

        $status = in_array($data['status'] ?? 'approved', ['approved', 'quarantined_dpa', 'pending', 'rejected'], true)
            ? (string)$data['status']
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
                status, verified_at, last_confirmed_at
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
                :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
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
            ':sector_id' => max(1, (int)($data['sector_id'] ?? 1)),
            ':specialty_id' => max(1, (int)($data['specialty_id'] ?? 1)),
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
        ]);

        $newId = (int)$pdo->lastInsertId();
        self::renumberBusinesses($pdo);
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
        $photosJson = PhotoUploader::processBusinessPhotos(
            isset($oldBiz['photos_json']) ? (string)$oldBiz['photos_json'] : null,
            isset($data['remove_photos']) && is_array($data['remove_photos']) ? $data['remove_photos'] : [],
            $_FILES['business_photos'] ?? null,
            isset($data['business_photos_data_json']) ? (string)$data['business_photos_data_json'] : null
        );

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
