<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;

bbiz_send_security_headers(true);

try {
    $pdo = Database::getConnection();

    $q          = trim((string)($_GET['q'] ?? ''));
    $sectorId   = isset($_GET['sector_id']) && $_GET['sector_id'] !== '' ? (int)$_GET['sector_id'] : null;
    $specId     = isset($_GET['specialty_id']) && $_GET['specialty_id'] !== '' ? (int)$_GET['specialty_id'] : null;
    $school     = strtoupper(trim((string)($_GET['school'] ?? '')));
    $mode       = strtolower(trim((string)($_GET['service_mode'] ?? '')));
    $openNow    = !empty($_GET['open_now']) && $_GET['open_now'] !== '0' && $_GET['open_now'] !== 'false';
    $hasPerk    = !empty($_GET['has_perk']) && $_GET['has_perk'] !== '0' && $_GET['has_perk'] !== 'false';
    $b2bReady   = !empty($_GET['b2b_ready']) && $_GET['b2b_ready'] !== '0' && $_GET['b2b_ready'] !== 'false';
    $userLat    = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
    $userLng    = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
    $radiusKm   = isset($_GET['radius_km']) && $_GET['radius_km'] !== '' ? (float)$_GET['radius_km'] : null;

    // STRICT PRIVACY GUARANTEE: Explicit column whitelist from `businesses` and `categories` ONLY.
    // `business_vetting_queue` is NEVER joined or queried in public APIs.
    $sql = "
        SELECT
            b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
            b.school_badge, b.sector_id, b.specialty_id,
            sec.name AS sector_name, sec.slug AS sector_slug,
            spec.name AS specialty_name, spec.slug AS specialty_slug,
            b.service_mode, b.alumni_perk, b.b2b_ready, b.accepts_lpo,
            b.regulator_name, b.regulator_license,
            b.branch_name, b.building_name, b.floor_suite, b.street_road,
            b.area_neighborhood, b.city_county, b.landmark_directions,
            b.plus_code, b.latitude, b.longitude, b.google_maps_url,
            b.operating_hours_json,
            b.phone_primary, b.whatsapp_number, b.email_public, b.website_url,
            b.social_instagram, b.social_linkedin, b.social_x,
            b.social_facebook, b.social_tiktok, b.social_youtube,
            b.logo_path, b.verified_at
        FROM businesses b
        INNER JOIN categories sec ON sec.id = b.sector_id
        INNER JOIN categories spec ON spec.id = b.specialty_id
        WHERE b.status = 'approved'
    ";

    $params = [];

    if ($sectorId !== null && $sectorId > 0) {
        $sql .= " AND b.sector_id = :sector_id";
        $params[':sector_id'] = $sectorId;
    }
    if ($specId !== null && $specId > 0) {
        $sql .= " AND b.specialty_id = :specialty_id";
        $params[':specialty_id'] = $specId;
    }
    if (in_array($school, ['AHS', 'AGHS'], true)) {
        $sql .= " AND (b.school_badge = :school OR b.school_badge = 'BOTH')";
        $params[':school'] = $school;
    }
    if (in_array($mode, ['physical', 'hybrid', 'online'], true)) {
        $sql .= " AND b.service_mode = :mode";
        $params[':mode'] = $mode;
    }
    if ($hasPerk) {
        $sql .= " AND b.alumni_perk IS NOT NULL AND b.alumni_perk != ''";
    }
    if ($b2bReady) {
        $sql .= " AND b.b2b_ready = 1";
    }

    // Bounding-box pre-filter when user coordinates + radius_km are provided
    if ($userLat !== null && $userLng !== null && $radiusKm !== null && $radiusKm > 0) {
        $bbox = LocationEngine::boundingBox($userLat, $userLng, $radiusKm);
        $sql .= " AND b.latitude BETWEEN :min_lat AND :max_lat AND b.longitude BETWEEN :min_lng AND :max_lng";
        $params[':min_lat'] = $bbox['minLat'];
        $params[':max_lat'] = $bbox['maxLat'];
        $params[':min_lng'] = $bbox['minLng'];
        $params[':max_lng'] = $bbox['maxLng'];
    }

    // Keyword + Kenyan/Swahili Synonym Search (escaping LIKE wildcards % and _)
    if ($q !== '') {
        $expandedTerms = CategoryMatcher::expandSearchTerms($q);
        $orClauses = [];
        $colsToSearch = ['b.name', 'b.tagline', 'b.description', 'sec.name', 'spec.name', 'b.area_neighborhood', 'b.city_county', 'b.street_road'];
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
            $sql .= " AND (" . implode(' OR ', $orClauses) . ")";
        }
    }

    $sql .= " ORDER BY b.business_number ASC, b.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $results = [];
    foreach ($rows as $row) {
        $hoursStatus = HoursEngine::evaluateStatus((string)$row['operating_hours_json']);
        if ($openNow && !$hoursStatus['is_open']) {
            continue;
        }

        $distanceKm = null;
        if ($userLat !== null && $userLng !== null && $row['latitude'] !== null && $row['longitude'] !== null) {
            $distanceKm = LocationEngine::haversineKm($userLat, $userLng, (float)$row['latitude'], (float)$row['longitude']);
            if ($radiusKm !== null && $radiusKm > 0 && $distanceKm > $radiusKm) {
                continue;
            }
        }

        $results[] = [
            'id' => (int)$row['id'],
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
            'city_county' => $row['city_county'],
            'landmark_directions' => $row['landmark_directions'],
            'plus_code' => $row['plus_code'],
            'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
            'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
            'google_maps_url' => $row['google_maps_url'],
            'distance_km' => $distanceKm,
            'hours_status' => $hoursStatus,
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
    }

    if ($userLat !== null && $userLng !== null) {
        usort($results, static function (array $a, array $b): int {
            $da = $a['distance_km'] ?? 999999.0;
            $db = $b['distance_km'] ?? 999999.0;
            return $da <=> $db;
        });
    }

    echo json_encode([
        'status' => 'ok',
        'count' => count($results),
        'businesses' => $results,
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to query businesses.']);
}
