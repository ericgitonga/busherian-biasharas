<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhotoUploader;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $apiBudget = AntiSpamGate::checkAuthAttemptAllowed($pdo, $clientIp, 'api_businesses_read', '', 240, 3000, 240, 3000);
    if (!$apiBudget['allowed']) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['status' => 'error', 'message' => 'Too many directory queries from this network. Please wait a moment and try again.']);
        exit;
    }

    $currentOwner = OwnerAuth::getCurrentOwner($pdo);
    $isAdminVisitor = AdminAuth::isAuthenticated();
    $isAuthenticatedViewer = ($currentOwner !== null || $isAdminVisitor);

    $q          = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
    $sectorId   = isset($_GET['sector_id']) && $_GET['sector_id'] !== '' ? (int)$_GET['sector_id'] : null;
    $specId     = isset($_GET['specialty_id']) && $_GET['specialty_id'] !== '' ? (int)$_GET['specialty_id'] : null;
    $country    = mb_substr(trim((string)($_GET['country'] ?? '')), 0, 80);
    $cityCounty = mb_substr(trim((string)($_GET['city_county'] ?? '')), 0, 100);
    $school     = strtoupper(trim((string)($_GET['school'] ?? '')));
    $mode       = strtolower(trim((string)($_GET['service_mode'] ?? '')));
    $openNow    = !empty($_GET['open_now']) && $_GET['open_now'] !== '0' && $_GET['open_now'] !== 'false';
    $hasPerk    = !empty($_GET['has_perk']) && $_GET['has_perk'] !== '0' && $_GET['has_perk'] !== 'false';
    $b2bReady   = !empty($_GET['b2b_ready']) && $_GET['b2b_ready'] !== '0' && $_GET['b2b_ready'] !== 'false';
    $userLat    = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $userLng    = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $radiusKm   = isset($_GET['radius_km']) && $_GET['radius_km'] !== '' ? max(0.0, min(20000.0, (float)$_GET['radius_km'])) : null;
    if ($userLat !== null && (!is_finite($userLat) || $userLat < -90.0 || $userLat > 90.0)) {
        $userLat = null;
    }
    if ($userLng !== null && (!is_finite($userLng) || $userLng < -180.0 || $userLng > 180.0)) {
        $userLng = null;
    }

    $page    = isset($_GET['page']) ? min(250, max(1, (int)$_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : 10;

    // STRICT PRIVACY GUARANTEE: Explicit column whitelist from `businesses` and `categories` ONLY.
    // `business_vetting_queue` is NEVER joined or queried in public APIs.
    $fromWhereSql = "
        FROM businesses b
        INNER JOIN categories sec ON sec.id = b.sector_id
        INNER JOIN categories spec ON spec.id = b.specialty_id
        WHERE b.status = 'approved'
    ";

    $params = [];

    if ($sectorId !== null && $sectorId > 0) {
        $fromWhereSql .= " AND b.sector_id = :sector_id";
        $params[':sector_id'] = $sectorId;
    }
    if ($specId !== null && $specId > 0) {
        $fromWhereSql .= " AND b.specialty_id = :specialty_id";
        $params[':specialty_id'] = $specId;
    }
    if ($country !== '') {
        $fromWhereSql .= " AND LOWER(b.country) = LOWER(:country)";
        $params[':country'] = $country;
    }
    if ($cityCounty !== '') {
        $fromWhereSql .= " AND LOWER(b.city_county) = LOWER(:city_county)";
        $params[':city_county'] = $cityCounty;
    }
    if (in_array($school, ['AHS', 'AGHS'], true)) {
        $fromWhereSql .= " AND (b.school_badge = :school OR b.school_badge = 'BOTH')";
        $params[':school'] = $school;
    }
    if (in_array($mode, ['physical', 'hybrid', 'online'], true)) {
        $fromWhereSql .= " AND b.service_mode = :mode";
        $params[':mode'] = $mode;
    }
    if ($hasPerk) {
        $fromWhereSql .= " AND b.alumni_perk IS NOT NULL AND b.alumni_perk != ''";
    }
    if ($b2bReady) {
        $fromWhereSql .= " AND b.b2b_ready = 1";
    }

    $hasCoordsQuery = ($userLat !== null && $userLng !== null);
    if ($hasCoordsQuery && $radiusKm !== null && $radiusKm > 0) {
        $bbox = LocationEngine::boundingBox($userLat, $userLng, $radiusKm);
        $fromWhereSql .= " AND b.latitude IS NOT NULL AND b.longitude IS NOT NULL"
            . " AND b.latitude BETWEEN :min_lat AND :max_lat AND b.longitude BETWEEN :min_lng AND :max_lng";
        $params[':min_lat'] = $bbox['minLat'];
        $params[':max_lat'] = $bbox['maxLat'];
        $params[':min_lng'] = $bbox['minLng'];
        $params[':max_lng'] = $bbox['maxLng'];
    }

    if ($q !== '') {
        $expandedTerms = CategoryMatcher::expandSearchTerms($q);
        $orClauses = [];
        $colsToSearch = ['b.name', 'b.tagline', 'b.description', 'sec.name', 'spec.name', 'b.area_neighborhood', 'b.country', 'b.city_county', 'b.street_road'];
        foreach ($expandedTerms as $idx => $term) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
            $val = '%' . $escaped . '%';
            $colParts = [];
            foreach ($colsToSearch as $cIdx => $colName) {
                $pk = ":q_{$idx}_{$cIdx}";
                $colParts[] = "{$colName} LIKE {$pk}";
                $params[$pk] = $val;
            }
            $orClauses[] = '(' . implode(' OR ', $colParts) . ')';
        }
        if ($orClauses) {
            $fromWhereSql .= " AND (" . implode(' OR ', $orClauses) . ")";
        }
    }

    $selectCols = "
        SELECT
            b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
            b.school_badge, b.sector_id, b.specialty_id,
            sec.name AS sector_name, sec.slug AS sector_slug,
            spec.name AS specialty_name, spec.slug AS specialty_slug,
            b.service_mode, b.alumni_perk, b.b2b_ready, b.accepts_lpo,
            b.regulator_name, b.regulator_license,
            b.branch_name, b.building_name, b.floor_suite, b.street_road,
            b.area_neighborhood, b.country, b.city_county, b.landmark_directions,
            b.plus_code, b.latitude, b.longitude, b.google_maps_url,
            b.operating_hours_json, b.photos_json,
            b.phone_primary, b.whatsapp_number, b.email_public, b.website_url,
            b.social_instagram, b.social_linkedin, b.social_x,
            b.social_facebook, b.social_tiktok, b.social_youtube,
            b.logo_path, b.verified_at
    ";

    // SQL Haversine helper using distinct named parameters for MySQL 8.0 native prepared-statement compatibility (#218 M-10)
    $buildHaversineExpr = static function (string $prefix, array &$bindTarget) use ($userLat, $userLng): string {
        $bindTarget[":{$prefix}_lat1"] = $userLat;
        $bindTarget[":{$prefix}_lng1"] = $userLng;
        $bindTarget[":{$prefix}_lat2"] = $userLat;
        $bindTarget[":{$prefix}_lat3"] = $userLat;
        $bindTarget[":{$prefix}_lng2"] = $userLng;
        $bindTarget[":{$prefix}_lat4"] = $userLat;
        $bindTarget[":{$prefix}_lat5"] = $userLat;
        $bindTarget[":{$prefix}_lng3"] = $userLng;
        $bindTarget[":{$prefix}_lat6"] = $userLat;
        $c1 = "(COS(RADIANS(:{$prefix}_lat1)) * COS(RADIANS(b.latitude)) * COS(RADIANS(b.longitude) - RADIANS(:{$prefix}_lng1)) + SIN(RADIANS(:{$prefix}_lat2)) * SIN(RADIANS(b.latitude)))";
        $c2 = "(COS(RADIANS(:{$prefix}_lat3)) * COS(RADIANS(b.latitude)) * COS(RADIANS(b.longitude) - RADIANS(:{$prefix}_lng2)) + SIN(RADIANS(:{$prefix}_lat4)) * SIN(RADIANS(b.latitude)))";
        $c3 = "(COS(RADIANS(:{$prefix}_lat5)) * COS(RADIANS(b.latitude)) * COS(RADIANS(b.longitude) - RADIANS(:{$prefix}_lng3)) + SIN(RADIANS(:{$prefix}_lat6)) * SIN(RADIANS(b.latitude)))";
        return "(6371.0 * ACOS(CASE WHEN {$c1} > 1.0 THEN 1.0 WHEN {$c2} < -1.0 THEN -1.0 ELSE {$c3} END))";
    };

    if ($hasCoordsQuery && $radiusKm !== null && $radiusKm > 0) {
        $whereHavExpr = $buildHaversineExpr('wh', $params);
        $fromWhereSql .= " AND {$whereHavExpr} <= :exact_radius_km";
        $params[':exact_radius_km'] = $radiusKm;
    }

    if (!$openNow) {
        // Pure SQL pagination & distance sorting (#218 M-10): zero full-table fetch into PHP memory
        $cntStmt = $pdo->prepare("SELECT COUNT(*) " . $fromWhereSql);
        $cntStmt->execute($params);
        $totalCount = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $pageParams = $params;
        if ($hasCoordsQuery) {
            $selHavExpr = $buildHaversineExpr('sh', $pageParams);
            $pageSql = $selectCols . ", CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL THEN ROUND({$selHavExpr}, 2) ELSE NULL END AS _sql_dist "
                . $fromWhereSql
                . " ORDER BY CASE WHEN b.latitude IS NULL OR b.longitude IS NULL THEN 1 ELSE 0 END ASC, _sql_dist ASC, b.business_number ASC, b.id ASC"
                . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        } else {
            $pageSql = $selectCols . $fromWhereSql . " ORDER BY b.business_number ASC, b.id ASC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
        }
        $stmt = $pdo->prepare($pageSql);
        $stmt->execute($pageParams);
        $pagedRows = $stmt->fetchAll();
        foreach ($pagedRows as &$pRow) {
            if (array_key_exists('_sql_dist', $pRow)) {
                $pRow['_eval_dist'] = $pRow['_sql_dist'] !== null ? (float)$pRow['_sql_dist'] : null;
            }
        }
        unset($pRow);
    } else {
        // Bounded Open-Now evaluation (#218 M-10): cap SQL candidate scan to 500 rows (with SQL distance order when coords supplied)
        $openParams = $params;
        if ($hasCoordsQuery) {
            $openHavExpr = $buildHaversineExpr('oh', $openParams);
            $openSql = $selectCols . ", CASE WHEN b.latitude IS NOT NULL AND b.longitude IS NOT NULL THEN ROUND({$openHavExpr}, 2) ELSE NULL END AS _sql_dist "
                . $fromWhereSql
                . " ORDER BY CASE WHEN b.latitude IS NULL OR b.longitude IS NULL THEN 1 ELSE 0 END ASC, _sql_dist ASC, b.business_number ASC, b.id ASC LIMIT 500";
        } else {
            $openSql = $selectCols . $fromWhereSql . " ORDER BY b.business_number ASC, b.id ASC LIMIT 500";
        }
        $stmt = $pdo->prepare($openSql);
        $stmt->execute($openParams);
        $candidateRows = $stmt->fetchAll();
        $filteredRows = [];
        foreach ($candidateRows as $row) {
            $hoursStatus = HoursEngine::evaluateStatus((string)$row['operating_hours_json']);
            if (!$hoursStatus['is_open']) {
                continue;
            }
            $distanceKm = isset($row['_sql_dist']) && $row['_sql_dist'] !== null ? (float)$row['_sql_dist'] : null;
            $row['_eval_hours'] = $hoursStatus;
            $row['_eval_dist'] = $distanceKm;
            $filteredRows[] = $row;
        }
        $totalCount = count($filteredRows);
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($filteredRows, $offset, $perPage);
    }

    // Batch ownership check in a SINGLE query (#180: zero N+1 queries)
    $pageBizIds = array_map(static fn(array $r): int => (int)$r['id'], $pagedRows);
    $editableBizIds = ($currentOwner !== null && $pageBizIds !== [])
        ? OwnerAuth::getEditableBusinessIdsForOwner($pdo, (int)$currentOwner['id'], $pageBizIds)
        : [];

    $results = [];
    foreach ($pagedRows as $row) {
        $bizId = (int)$row['id'];
        $hoursStatus = $row['_eval_hours'] ?? HoursEngine::evaluateStatus((string)$row['operating_hours_json']);
        $distanceKm = array_key_exists('_eval_dist', $row) ? $row['_eval_dist'] : null;

        $canOwnerEdit = !empty($editableBizIds[$bizId]);
        $canEdit = $canOwnerEdit || $isAdminVisitor;
        $editUrl = $canOwnerEdit
            ? ('/owner/edit-business.php?id=' . $bizId)
            : ($isAdminVisitor ? ('/admin/edit-business.php?id=' . $bizId) : null);

        $item = [
            'id' => $bizId,
            'can_edit' => $canEdit,
            'edit_url' => $editUrl,
            'business_id' => $row['business_id'],
            'business_number' => (int)$row['business_number'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'tagline' => $row['tagline'],
            'description' => $row['description'],
            'school_badge' => $row['school_badge'],
            'sector_id' => (int)$row['sector_id'],
            'sector_name' => $row['sector_name'],
            'specialty_id' => (int)$row['specialty_id'],
            'specialty_name' => $row['specialty_name'],
            'service_mode' => $row['service_mode'],
            'alumni_perk' => $row['alumni_perk'],
            'b2b_ready' => (bool)$row['b2b_ready'],
            'accepts_lpo' => (bool)$row['accepts_lpo'],
            'regulator_name' => $row['regulator_name'],
            'regulator_license' => $row['regulator_license'],
            'branch_name' => $row['branch_name'],
            'building_name' => $row['building_name'],
            'floor_suite' => $row['floor_suite'],
            'street_road' => $row['street_road'],
            'area_neighborhood' => $row['area_neighborhood'],
            'country' => $row['country'] ?? 'Kenya',
            'city_county' => $row['city_county'],
            'landmark_directions' => $row['landmark_directions'],
            'plus_code' => $row['plus_code'],
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'google_maps_url' => $row['google_maps_url'],
            'distance_km' => $distanceKm,
            'hours_status' => $hoursStatus,
            'photos' => PhotoUploader::decodePhotosJson(isset($row['photos_json']) ? (string)$row['photos_json'] : null),
            'phone_primary' => $row['phone_primary'],
            'whatsapp_number' => $row['whatsapp_number'],
            'email_public' => $row['email_public'],
            'website_url' => $row['website_url'],
            'socials' => array_filter([
                'instagram' => $row['social_instagram'],
                'linkedin'  => $row['social_linkedin'],
                'x'         => $row['social_x'],
                'facebook'  => $row['social_facebook'],
                'tiktok'    => $row['social_tiktok'],
                'youtube'   => $row['social_youtube'],
            ]),
        ];

        // Privacy (#180): Omit owner_email_hints from unauthenticated public directory feeds
        if ($isAuthenticatedViewer) {
            $item['owner_email_hints'] = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $bizId);
        }

        $results[] = $item;
    }

    echo json_encode([
        'status' => 'ok',
        'count' => count($results),
        'total_count' => $totalCount,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalCount,
            'total_pages' => $totalPages,
        ],
        'businesses' => $results,
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to query businesses.']);
}
