<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\EngagementRanker;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhoneNormalizer;
use BusherianBiasharas\Services\PhotoUploader;

bbiz_send_security_headers(false);

$appVersion = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION') ?: '0.32.5');
$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);
$ownerCsrfToken = $currentOwner !== null ? AntiSpamGate::generateCsrfToken() : '';
$isAdminVisitor = AdminAuth::isAuthenticated();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);
$placeHierarchy = LocationEngine::getPlaceHierarchy();

// Biashara Advisor visibility (#375, #376): active in Preprod sandbox (/admin/preprod), automated test suites, or via preview. Disabled on production.
$isAdvisorEnabled = (\BusherianBiasharas\Services\PreprodSandbox::isPreprod()
    || strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development'))) === 'testing'
    || (defined('BBIZ_ENABLE_ADVISOR') && BBIZ_ENABLE_ADVISOR === true)
    || (getenv('BBIZ_ENABLE_ADVISOR') === 'true'))
    && (strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development'))) !== 'production');

// Load bounded initial page-1 approved businesses (#180, #389: LIMIT 25 OFFSET 0, zero N+1, zero vetting columns)
$allowedPageSizes = [25, 50, 100];
$initialPerPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPageSizes, true)
    ? (int)$_GET['per_page']
    : 25;
$totalApprovedCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    WHERE b.status = 'approved'
")->fetchColumn();
$initialTotalPages = max(1, (int)ceil($totalApprovedCount / $initialPerPage));

$initialSort = EngagementRanker::normalizeSortMode((string)($_GET['sort'] ?? ''), false);
$initSortParams = [];
$initOrderBySql = EngagementRanker::buildDirectoryOrderBySql($initialSort, $initSortParams, 'init', false);

$stmt = $pdo->prepare("
    SELECT
        b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
        b.school_badge, b.founder_role, b.founder_name, b.founder_school, b.founder_year,
        b.sector_id, b.specialty_id,
        b.secondary_sector_id, b.secondary_specialty_id,
        sec.name AS sector_name, spec.name AS specialty_name,
        sec2.name AS secondary_sector_name, spec2.name AS secondary_specialty_name,
        b.service_mode, b.alumni_perk, b.b2b_ready, b.accepts_lpo,
        b.regulator_name, b.regulator_license,
        b.branch_name, b.building_name, b.floor_suite, b.street_road,
        b.area_neighborhood, b.country, b.city_county, b.landmark_directions,
        b.plus_code, b.latitude, b.longitude, b.google_maps_url,
        b.operating_hours_json, b.photos_json,
        b.phone_primary, b.whatsapp_number, b.email_public, b.website_url,
        b.booking_enabled, b.booking_url,
        CASE WHEN (b.email_public IS NOT NULL AND b.email_public != '') OR (b.owner_email IS NOT NULL AND b.owner_email != '') THEN 1 ELSE 0 END AS has_booking_email,
        b.social_instagram, b.social_linkedin, b.social_x,
        b.social_facebook, b.social_tiktok, b.social_youtube,
        b.logo_path,
        b.verified_at, b.created_at, b.engagement_score, b.reference_count_90d
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    LEFT JOIN categories sec2 ON sec2.id = b.secondary_sector_id AND sec2.status = 'approved'
    LEFT JOIN categories spec2 ON spec2.id = b.secondary_specialty_id AND spec2.status = 'approved'
    WHERE b.status = 'approved'
    ORDER BY {$initOrderBySql}
    LIMIT {$initialPerPage} OFFSET 0
");
$stmt->execute($initSortParams);
$rows = $stmt->fetchAll();

$initialPageIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);
$editableInitialIds = ($currentOwner !== null && $initialPageIds !== [])
    ? OwnerAuth::getEditableBusinessIdsForOwner($pdo, (int)$currentOwner['id'], $initialPageIds)
    : [];
$isAuthenticatedViewer = ($currentOwner !== null || $isAdminVisitor);
if (!$isAuthenticatedViewer && !headers_sent()) {
    header('X-BBiz-Public-Cacheable: 1');
}
$batchedInitialHints = ($isAuthenticatedViewer && $initialPageIds !== [])
    ? OwnerAuth::getMaskedOwnerEmailHintsForBusinesses($pdo, $initialPageIds)
    : [];

$initialMomentumCtx = EngagementRanker::computeMomentumContext($pdo);
$initialBusinesses = [];
foreach ($rows as $r) {
    $bizId = (int)$r['id'];
    $canOwnerEdit = !empty($editableInitialIds[$bizId]);
    $canEdit = $canOwnerEdit || $isAdminVisitor;
    $editUrl = $canOwnerEdit
        ? ('/owner/edit-business.php?id=' . $bizId)
        : ($isAdminVisitor ? ('/admin/edit-business.php?id=' . $bizId) : null);

    $cleanBookingUrl = !empty($r['booking_url']) ? trim((string)$r['booking_url']) : null;
    $isBookable = ((bool)($r['booking_enabled'] ?? false)) || ($cleanBookingUrl !== null && $cleanBookingUrl !== '');

    $rawPhone = (string)($r['phone_primary'] ?? '');
    $rawWa = (string)($r['whatsapp_number'] ?? '');
    $rawLat = $r['latitude'] !== null ? (float)$r['latitude'] : null;
    $rawLng = $r['longitude'] !== null ? (float)$r['longitude'] : null;

    $isSingleDetailRequest = ($requestedBiz !== null && (int)$requestedBiz['id'] === $bizId);
    if ($isSingleDetailRequest) {
        $phonePrimary = $rawPhone;
        $whatsappNumber = $rawWa;
        $latitude = $rawLat;
        $longitude = $rawLng;
        $phoneObfuscated = false;
        $coordsFuzzed = false;
    } else {
        $phonePrimary = PhoneNormalizer::maskPhoneNumber($rawPhone);
        $whatsappNumber = PhoneNormalizer::maskPhoneNumber($rawWa);
        $latitude = $rawLat !== null ? round($rawLat, 3) : null;
        $longitude = $rawLng !== null ? round($rawLng, 3) : null;
        $phoneObfuscated = true;
        $coordsFuzzed = true;
    }

    $bizItem = [
        'id' => $bizId,
        'can_edit' => $canEdit,
        'edit_url' => $editUrl,
        'business_id' => $r['business_id'],
        'business_number' => (int)$r['business_number'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'tagline' => $r['tagline'],
        'description' => $r['description'],
        'school_badge' => $r['school_badge'],
        'logo_path' => !empty($r['logo_path']) ? (string)$r['logo_path'] : null,
        'founder_role' => $r['founder_role'] ?? 'none',
        'founder_name' => $r['founder_name'] ?? null,
        'founder_school' => $r['founder_school'] ?? null,
        'founder_year' => $r['founder_year'] ?? null,
        'sector_id' => (int)$r['sector_id'],
        'sector_name' => $r['sector_name'],
        'specialty_id' => (int)$r['specialty_id'],
        'specialty_name' => $r['specialty_name'],
        'secondary_sector_id' => (!empty($r['secondary_sector_id']) && !empty($r['secondary_sector_name'])) ? (int)$r['secondary_sector_id'] : null,
        'secondary_sector_name' => !empty($r['secondary_sector_name']) ? $r['secondary_sector_name'] : null,
        'secondary_specialty_id' => (!empty($r['secondary_specialty_id']) && !empty($r['secondary_specialty_name'])) ? (int)$r['secondary_specialty_id'] : null,
        'secondary_specialty_name' => !empty($r['secondary_specialty_name']) ? $r['secondary_specialty_name'] : null,
        'service_mode' => $r['service_mode'],
        'alumni_perk' => $r['alumni_perk'],
        'b2b_ready' => (bool)$r['b2b_ready'],
        'accepts_lpo' => (bool)$r['accepts_lpo'],
        'regulator_name' => $r['regulator_name'],
        'regulator_license' => $r['regulator_license'],
        'branch_name' => $r['branch_name'],
        'building_name' => $r['building_name'],
        'floor_suite' => $r['floor_suite'],
        'street_road' => $r['street_road'],
        'area_neighborhood' => $r['area_neighborhood'],
        'country' => $r['country'] ?? 'Kenya',
        'city_county' => $r['city_county'],
        'timezone' => LocationEngine::getTimezoneForCountry($r['country'] ?? null, $r['city_county'] ?? null),
        'landmark_directions' => $r['landmark_directions'],
        'plus_code' => $r['plus_code'],
        'latitude' => $latitude,
        'longitude' => $longitude,
        'phone_primary' => $phonePrimary,
        'whatsapp_number' => $whatsappNumber,
        'phone_obfuscated' => $phoneObfuscated,
        'coords_fuzzed' => $coordsFuzzed,
        'google_maps_url' => $r['google_maps_url'],
        'distance_km' => null,
        'hours_status' => HoursEngine::evaluateStatus((string)$r['operating_hours_json'], null, LocationEngine::getTimezoneForCountry($r['country'] ?? null, $r['city_county'] ?? null)),
        'momentum_badge' => EngagementRanker::resolveMomentumBadge($r, $initialMomentumCtx),
        'photos' => PhotoUploader::decodePhotosJson(isset($r['photos_json']) ? (string)$r['photos_json'] : null),
        'email_public' => $r['email_public'],
        'website_url' => $r['website_url'],
        'booking_enabled' => $isBookable,
        'booking_url' => $cleanBookingUrl,
        'has_booking_email' => (bool)($r['has_booking_email'] ?? false),
        'is_bookable' => $isBookable,
        'socials' => array_filter([
            'instagram' => $r['social_instagram'],
            'linkedin'  => $r['social_linkedin'],
            'x'         => $r['social_x'],
            'facebook'  => $r['social_facebook'],
            'tiktok'    => $r['social_tiktok'],
            'youtube'   => $r['social_youtube'],
        ]),
    ];
    if ($isAuthenticatedViewer) {
        $bizItem['owner_email_hints'] = $batchedInitialHints[$bizId] ?? [];
    }
    $initialBusinesses[] = $bizItem;
}
$initialPagination = [
    'page' => 1,
    'per_page' => $initialPerPage,
    'total' => $totalApprovedCount,
    'total_pages' => $initialTotalPages,
    'sort' => $initialSort,
];

// Pre-compute sector listing counts for left filter panel (#371)
$sectorCountsStmt = $pdo->query("
    SELECT sector_id, COUNT(*) AS cnt
    FROM businesses
    WHERE status = 'approved'
    GROUP BY sector_id
");
$sectorCounts = [];
while ($scRow = $sectorCountsStmt->fetch(PDO::FETCH_ASSOC)) {
    $sectorCounts[(int)$scRow['sector_id']] = (int)$scRow['cnt'];
}

// Canonical Business Profile routing & metadata (#376, #378)
$requestedBizSlug = (!empty($_GET['biz']) && !ctype_digit((string)$_GET['biz'])) ? trim((string)$_GET['biz']) : null;
$requestedBiz = null;
if ($requestedBizSlug !== null) {
    $stmtSlug = $pdo->prepare("
        SELECT
            b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
            b.school_badge, b.founder_role, b.founder_name, b.founder_school, b.founder_year,
            b.sector_id, b.specialty_id,
            b.secondary_sector_id, b.secondary_specialty_id,
            sec.name AS sector_name, spec.name AS specialty_name,
            b.service_mode, b.alumni_perk, b.b2b_ready, b.accepts_lpo,
            b.regulator_name, b.regulator_license,
            b.branch_name, b.building_name, b.floor_suite, b.street_road,
            b.area_neighborhood, b.country, b.city_county, b.landmark_directions,
            b.plus_code, b.latitude, b.longitude, b.google_maps_url,
            b.operating_hours_json, b.photos_json,
            b.phone_primary, b.whatsapp_number, b.email_public, b.website_url,
            b.booking_enabled, b.booking_url,
            b.social_instagram, b.social_linkedin, b.social_x,
            b.social_facebook, b.social_tiktok, b.social_youtube,
            b.logo_path
        FROM businesses b
        INNER JOIN categories sec ON sec.id = b.sector_id
        INNER JOIN categories spec ON spec.id = b.specialty_id
        WHERE b.slug = :slug AND b.status = 'approved'
        LIMIT 1
    ");
    $stmtSlug->execute([':slug' => $requestedBizSlug]);
    $requestedBiz = $stmtSlug->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($requestedBiz) {
        $requestedBiz['photos'] = PhotoUploader::decodePhotosJson($requestedBiz['photos_json'] ?? null);
        $requestedBiz['hours_status'] = HoursEngine::evaluateStatus((string)$requestedBiz['operating_hours_json'], null, LocationEngine::getTimezoneForCountry($requestedBiz['country'] ?? null, $requestedBiz['city_county'] ?? null));
    }
}

$pageTitle = $requestedBiz
    ? htmlspecialchars($requestedBiz['name'] . ' — Busherian Biasharas', ENT_QUOTES, 'UTF-8')
    : 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS &amp; AGHS)';
$pageDescription = $requestedBiz
    ? htmlspecialchars($requestedBiz['tagline'] ?: $requestedBiz['description'], ENT_QUOTES, 'UTF-8')
    : 'Discover and support verified businesses owned by alumni of Alliance High School (AHS) and Alliance Girls High School (AGHS) — Strong to Serve &amp; Walk in the Light.';
$pageCanonicalPath = $requestedBiz
    ? ('/biz/' . urlencode($requestedBiz['slug']))
    : '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <meta name="description" content="<?= $pageDescription ?>">
<?= bbiz_head_meta_tags($pageTitle, $pageDescription, $pageCanonicalPath) ?>
  <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= urlencode($appVersion) ?>">
</head>
<body>
<?php if (\BusherianBiasharas\Services\PreprodSandbox::isPreprod()): ?>
  <div style="background:#FEF3C7;border-bottom:1px solid #F59E0B;color:#92400E;text-align:center;padding:0.45rem 1rem;font-size:0.84rem;font-weight:700;">
    🧪 PREVIEW ENVIRONMENT (preview.busherianbiasharas.com) &bull; Isolated SQLite &bull; Outbound Emails Simulated &bull; <a href="https://www.busherianbiasharas.com/" style="color:#92400E;text-decoration:underline;">Switch to Live Production &rarr;</a>
  </div>
<?php endif; ?>

  <!-- Heritage Header (Heritage Ivory #FBF8F3) -->
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group" onclick="if(window.showDirectoryView){window.showDirectoryView(); return false;}">
        <div class="brand-crest" aria-hidden="true">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Verified Alumni Business Directory of the Alliances (AHS &amp; AGHS)</p>
        </div>
      </a>
      <nav class="header-actions" aria-label="Primary Navigation">
        <button type="button" id="btn-install-app" data-testid="btn-install-app" class="btn btn-outline-light btn-install-pwa header-mobile-only" hidden title="Install Busherian Biasharas on your phone">
          📲 Install App
        </button>
        <button type="button" id="btn-export-shortlist" class="btn btn-outline-light header-desktop-action" title="Export selected B2B vendors as CSV">
          🏢 B2B Shortlist (<span id="shortlist-count">0</span>/5)
        </button>
        <a href="/privacy.php" class="btn btn-outline-light header-desktop-action">🔒 Privacy Notice</a>
        <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php' ?>" class="btn btn-gold btn-list-biashara" data-testid="btn-list-edit-biashara"><?= $currentOwner ? '✏️ My Biasharas (Edit / + List)' : '+ List / Edit Your Biashara' ?></a>
        <?php if ($currentOwner): ?>
        <form method="POST" action="/owner/logout.php" class="header-desktop-action" style="margin:0;display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ownerCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <button type="submit" data-testid="btn-owner-logout" class="btn btn-outline-light" style="border-color:rgba(255,255,255,0.45);font-size:0.85rem;cursor:pointer;" title="Sign out of <?= htmlspecialchars((string)$currentOwner['email'], ENT_QUOTES, 'UTF-8') ?>">🚪 Sign Out</button>
        </form>
        <?php endif; ?>
        <button type="button" id="btn-mobile-menu" data-testid="btn-mobile-menu" class="btn btn-outline-light btn-hamburger header-mobile-only" aria-label="Open Menu &amp; Filters" onclick="toggleMobileDrawer(true)">
          <span style="font-size:18px;line-height:1;">☰</span>
        </button>
      </nav>
    </div>
  </header>

  <!-- Backdrop for Mobile Drawer (#398) -->
  <div id="drawer-backdrop" class="drawer-backdrop" onclick="toggleMobileDrawer(false)"></div>

  <div id="pwa-offline-banner" data-testid="pwa-offline-banner" class="pwa-offline-banner" role="status" aria-live="polite" hidden>
    📶 <strong>Offline Mode:</strong> Showing your saved Busherian Biasharas snapshot. Connect to the internet for live EAT hours, map routes &amp; latest updates.
  </div>

  <!-- MAIN DIRECTORY LAYOUT: Left Filter Panel + Main Column (#370, #371) -->
  <div class="directory-layout-wrapper" id="view-directory-shell" style="<?= $requestedBiz ? 'display:none;' : '' ?>">

    <!-- Left Filter Panel (#371) -->
    <aside class="filters-sidebar" id="filters-sidebar" aria-label="Directory Filters">
      <div class="sidebar-header">
        <span class="sidebar-title"><span class="mobile-drawer-only">Menu &amp; </span>Filters</span>
        <button type="button" class="btn-sidebar-hide desktop-only" id="btn-sidebar-hide" onclick="toggleSidebarFilters()">Hide</button>
        <button type="button" class="btn-sidebar-hide mobile-drawer-only" id="btn-drawer-close" aria-label="Close menu" onclick="toggleMobileDrawer(false)">✕ Close</button>
      </div>

      <!-- Mobile Drawer Search & Advisor Section (#398) -->
      <div class="filter-group mobile-drawer-only">
        <div class="filter-group-title">SEARCH BIASHARAS</div>
        <div class="search-box" style="background:#FFFFFF;border:1.5px solid #E5DEC9;border-radius:999px;padding:0 14px;height:40px;display:flex;align-items:center;">
          <span style="color:#7E8D84;margin-right:8px;font-size:14px;">🔍</span>
          <input type="text" id="drawer-search-q" data-testid="drawer-search-input" class="search-input" style="flex:1;border:none;background:transparent;outline:none;font-size:13px;color:#18201B;"
            placeholder="Search biasharas, services...">
          <button type="button" id="btn-drawer-clear-search" class="search-clear-btn" aria-label="Clear search" style="display:none;background:none;border:none;cursor:pointer;color:#6B7280;font-size:14px;">✕</button>
        </div>
        <?php if ($isAdvisorEnabled): ?>
          <button type="button" class="btn btn-gold" id="btn-drawer-advisor" style="width:100%;margin-top:8px;padding:8px 12px;border-radius:999px;font-size:12.5px;font-weight:700;" onclick="toggleMobileDrawer(false); if(window.toggleConciergeMode){window.toggleConciergeMode(true);}">★ Ask Biashara Advisor</button>
        <?php endif; ?>
      </div>

      <!-- Mobile Drawer Quick Pills (#398) -->
      <div class="filter-group mobile-drawer-only">
        <div class="filter-group-title">QUICK FILTERS</div>
        <div class="quick-pills" style="display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center;">
          <button type="button" class="pill active" data-quick-filter="all">All Verified</button>
          <button type="button" class="pill" data-quick-filter="open_now">🟢 Open Now</button>
          <button type="button" class="pill" data-quick-filter="has_perk">🎁 Perks</button>
          <button type="button" class="pill" data-quick-filter="b2b_ready">🏢 B2B Ready</button>
          <button type="button" class="pill" data-quick-filter="bookable">📅 Bookable</button>
          <button type="button" class="pill" data-quick-filter="AHS">🦅 AHS</button>
          <button type="button" class="pill" data-quick-filter="AGHS">🔦 AGHS</button>
        </div>
      </div>

      <!-- Mobile Drawer Sort By (#398) -->
      <div class="filter-group mobile-drawer-only">
        <div class="filter-group-title">SORT BY</div>
        <select id="drawer-sort-select" class="sidebar-select" onchange="if(document.getElementById('sort-select')){document.getElementById('sort-select').value=this.value;document.getElementById('sort-select').dispatchEvent(new Event('change'));}">
          <option value="most_referenced">Most Referenced</option>
          <option value="trending">Trending This Week</option>
          <option value="newest">Newest Added</option>
          <option value="nearest">Nearest to Me</option>
          <option value="az">Alphabetical (A–Z)</option>
          <option value="directory_number">Directory #</option>
        </select>
      </div>

      <!-- Sector Filter with Counts (Collapsible #400) -->
      <div class="filter-group">
        <button type="button" class="filter-group-title filter-collapse-btn" id="btn-toggle-sector-collapse" data-testid="btn-toggle-sector-collapse" aria-expanded="false" aria-controls="sidebar-sector-nav" onclick="toggleSectorCollapse()">
          <span style="display:inline-flex;align-items:center;gap:6px;">
            <span>SECTOR</span>
            <span id="sector-selected-hint" class="sector-selected-hint" style="display:none;font-size:10px;text-transform:none;letter-spacing:normal;color:#114B3E;background:#E8F2EE;padding:1px 7px;border-radius:999px;font-weight:700;"></span>
          </span>
          <span class="collapse-arrow" id="sector-collapse-arrow" aria-hidden="true">▾</span>
        </button>
        <div class="sector-vertical-nav collapsed" id="sidebar-sector-nav">
          <button type="button" class="sector-nav-item active" data-sector-id="" onclick="filterBySector('')">
            <span>All sectors</span>
            <span class="sector-count"><?= $totalApprovedCount ?></span>
          </button>
          <?php foreach ($taxonomyTree as $sec): ?>
            <?php $secCnt = $sectorCounts[(int)$sec['id']] ?? 0; ?>
            <button type="button" class="sector-nav-item" data-sector-id="<?= (int)$sec['id'] ?>" onclick="filterBySector(<?= (int)$sec['id'] ?>)">
              <span><?= htmlspecialchars((string)$sec['name'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="sector-count"><?= $secCnt ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Location Filter -->
      <div class="filter-group">
        <div class="filter-group-title">LOCATION</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <select id="filter-country" data-testid="filter-country" class="sidebar-select" aria-label="Filter by Country">
            <option value="">🌍 All countries</option>
            <option value="Kenya">Kenya</option>
            <option disabled>──────────</option>
            <?php foreach (array_keys($placeHierarchy) as $cName): ?>
              <?php if ($cName === 'Kenya') continue; ?>
              <option value="<?= htmlspecialchars((string)$cName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$cName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <select id="filter-city" data-testid="filter-city" class="sidebar-select" aria-label="Filter by County, State, Province, or District">
            <option value="">All locations</option>
            <?php foreach (($placeHierarchy['Kenya'] ?? []) as $cityName): ?>
              <option value="<?= htmlspecialchars((string)$cityName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$cityName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" id="btn-sidebar-near-me" class="btn-sidebar-near-me" onclick="triggerNearMeLocation()">
            📍 Near me
          </button>
        </div>
      </div>

      <!-- School Filter (Zero Logo Policy #381) -->
      <div class="filter-group">
        <div class="filter-group-title">SCHOOL</div>
        <div class="school-vertical-nav" id="sidebar-school-nav">
          <button type="button" class="school-nav-item active" data-school="" onclick="filterBySchool('')">
            <span>Both schools</span>
          </button>
          <button type="button" class="school-nav-item ahs" data-school="AHS" onclick="filterBySchool('AHS')">
            <span class="school-color-indicator ahs"></span>
            <span>Alliance High School</span>
          </button>
          <button type="button" class="school-nav-item aghs" data-school="AGHS" onclick="filterBySchool('AGHS')">
            <span class="school-color-indicator aghs"></span>
            <span>Alliance Girls High School</span>
          </button>
        </div>
      </div>

      <!-- Show Only Checkboxes -->
      <div class="filter-group">
        <div class="filter-group-title">SHOW ONLY</div>
        <label class="filter-checkbox-label">
          <input type="checkbox" id="chk-open-now" onchange="toggleQuickCheckbox('open_now', this.checked)">
          <span>Open now</span>
        </label>
        <label class="filter-checkbox-label">
          <input type="checkbox" id="chk-offers-perk" onchange="toggleQuickCheckbox('has_perk', this.checked)">
          <span>Offers an alumni perk</span>
        </label>
        <label class="filter-checkbox-label">
          <input type="checkbox" id="chk-show-map" data-testid="chk-show-map" onchange="toggleMapVisibility(this.checked)">
          <span>Show map at bottom</span>
        </label>
      </div>

      <!-- Mobile Drawer Actions & Account Section (#398) -->
      <div class="filter-group mobile-drawer-only" style="border-top:1px solid #EFE9DC;padding-top:14px;">
        <div class="filter-group-title">ACTIONS &amp; ACCOUNT</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php' ?>" class="btn btn-outline-light" style="border:1.5px solid #D97706;background:#FFFBEB;color:#92400E;font-weight:700;justify-content:flex-start;font-size:13px;padding:8px 12px;" data-testid="drawer-list-edit-biashara">
            <?= $currentOwner ? '✏️ My Biasharas (Edit / + List)' : '+ List / Edit Your Biashara' ?>
          </a>
          <?php if ($currentOwner): ?>
          <form method="POST" action="/owner/logout.php" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($ownerCsrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" data-testid="drawer-owner-logout" class="btn btn-outline-light" style="width:100%;border:1.5px solid #DC2626;background:#FEF2F2;color:#991B1B;font-weight:700;justify-content:flex-start;font-size:13px;padding:8px 12px;cursor:pointer;">
              🚪 Sign Out (<?= htmlspecialchars((string)$currentOwner['email'], ENT_QUOTES, 'UTF-8') ?>)
            </button>
          </form>
          <?php endif; ?>
          <button type="button" id="btn-drawer-shortlist" class="btn btn-outline-light" style="border:1px solid #E5DEC9;background:#FBF8F3;color:#18201B;justify-content:flex-start;font-size:13px;padding:8px 12px;" onclick="if(document.getElementById('btn-export-shortlist')){document.getElementById('btn-export-shortlist').click();}">
            🏢 B2B Shortlist (<span class="drawer-shortlist-count">0</span>/5)
          </button>
          <button type="button" id="btn-install-app-drawer" data-testid="btn-install-app-drawer" class="btn btn-outline-light" style="border:1px solid #E5DEC9;background:#FBF8F3;color:#18201B;justify-content:flex-start;font-size:13px;padding:8px 12px;" hidden >
            📲 Install App
          </button>
          <a href="/privacy.php" class="btn btn-outline-light" style="border:1px solid #E5DEC9;background:#FBF8F3;color:#18201B;justify-content:flex-start;font-size:13px;padding:8px 12px;">
            🔒 Privacy Notice
          </a>
          <a href="/charter.php" class="btn btn-outline-light" style="border:1px solid #E5DEC9;background:#FBF8F3;color:#18201B;justify-content:flex-start;font-size:13px;padding:8px 12px;">
            📜 Business Charter
          </a>
        </div>
      </div>

      <!-- Mobile Drawer Footer Sticky Action (#398) -->
      <div class="mobile-drawer-only" style="margin-top:auto;padding-top:12px;border-top:1px solid #EFE9DC;">
        <button type="button" class="btn btn-green" style="width:100%;font-weight:800;padding:10px;font-size:13.5px;" onclick="toggleMobileDrawer(false)">
          Show Biasharas &rarr;
        </button>
      </div>
    </aside>

    <!-- Main Directory Column -->
    <div class="directory-main-col">

      <!-- Mobile Search & Filter Controls (#405) -->
      <div class="mobile-search-controls" id="mobile-search-controls">
        <div class="mobile-search-capsule">
          <div class="mobile-search-input-wrap">
            <span class="mobile-search-icon">🔍</span>
            <input type="text" id="mobile-search-q" data-testid="mobile-search-input" class="mobile-search-input" placeholder="Search biasharas, services, areas..." autocomplete="off">
            <button type="button" id="btn-mobile-clear-search" class="mobile-search-clear" style="display:none;" aria-label="Clear search">✕</button>
          </div>
          <button type="button" id="btn-mobile-filters-trigger" class="btn-mobile-filters-trigger" onclick="openMobileFilterSheet()" aria-label="Open directory filters">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
            <span>Filters</span>
            <span id="mobile-active-filter-badge" class="filter-count-badge" style="display:none;">0</span>
          </button>
        </div>

        <div class="mobile-quick-rail" id="mobile-quick-rail" role="region" aria-label="Quick filters">
          <button type="button" class="rail-pill active" data-rail-filter="all">All Verified</button>
          <button type="button" class="rail-pill" data-rail-filter="open_now">🟢 Open Now</button>
          <button type="button" class="rail-pill" data-rail-filter="has_perk">🎁 Perks</button>
          <button type="button" class="rail-pill" data-rail-filter="b2b_ready">🏢 B2B Ready</button>
          <button type="button" class="rail-pill" data-rail-filter="bookable">📅 Bookable</button>
          <button type="button" class="rail-pill" data-rail-filter="AHS">🦅 AHS</button>
          <button type="button" class="rail-pill" data-rail-filter="AGHS">🔦 AGHS</button>
        </div>
      </div>

      <!-- Desktop Search Controls (Search box, Advisor, Quick Pills) (#398) -->
      <div class="desktop-search-controls" id="desktop-search-controls">
        <!-- Keyword Search Input & Preprod Advisor Toggle -->
        <div style="display:flex;gap:10px;align-items:center;width:100%;">
          <div class="search-box" style="flex:1;background:#FFFFFF;border:1.5px solid #E5DEC9;border-radius:999px;padding:0 16px;height:42px;display:flex;align-items:center;">
            <span style="color:#7E8D84;margin-right:10px;font-size:15px;">🔍</span>
            <input type="text" id="search-q" data-testid="search-input" class="search-input" style="flex:1;border:none;background:transparent;outline:none;font-size:13.5px;color:#18201B;"
              placeholder="Search biasharas, services, locations (e.g. Mjengo, wakili, Ruiru)...">
            <button type="button" id="btn-clear-search" data-testid="search-clear-btn" class="search-clear-btn" aria-label="Clear search" title="Clear search" style="display:none;background:none;border:none;cursor:pointer;color:#6B7280;font-size:14px;">✕</button>
          </div>
          <?php if ($isAdvisorEnabled): ?>
            <button type="button" class="btn btn-gold pill-concierge-toggle" id="btn-concierge-toggle" data-testid="concierge-toggle-pill" aria-pressed="false" style="white-space:nowrap;height:42px;padding:0 16px;border-radius:999px;font-size:13px;font-weight:700;" title="Switch to natural-language AI advisor mode">★ Ask Biashara Advisor</button>
          <?php endif; ?>
        </div>

        <div class="quick-pills" data-testid="quick-pills" style="margin-top:0.75rem;display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
          <button type="button" class="pill active" data-quick-filter="all" data-testid="quick-filter-all">All Verified</button>
          <button type="button" class="pill" data-quick-filter="open_now" data-testid="quick-filter-open-now">🟢 Open Now (EAT)</button>
          <button type="button" class="pill" data-quick-filter="has_perk" data-testid="quick-filter-has-perk">🎁 Busherian Alumni Perks</button>
          <button type="button" class="pill" data-quick-filter="b2b_ready" data-testid="quick-filter-b2b">🏢 B2B &amp; LPO Ready</button>
          <button type="button" class="pill" data-quick-filter="bookable" data-testid="quick-filter-bookable">📅 Book Appointment</button>
          <button type="button" class="pill" data-quick-filter="AHS" data-testid="quick-filter-AHS">🦅 AHS</button>
          <button type="button" class="pill" data-quick-filter="AGHS" data-testid="quick-filter-AGHS">🔦 AGHS</button>
        </div>

        <?php if ($isAdvisorEnabled): ?>
        <!-- Biashara Advisor Panel (#344) -->
        <div id="concierge-panel" data-testid="concierge-panel" class="concierge-panel" hidden>
          <div class="concierge-panel-inner">
            <div class="concierge-panel-header">
              <div class="concierge-panel-title">
                <span class="concierge-badge">★ Biashara Advisor</span>
                <span class="concierge-subtitle">Describe your business challenge, project, or journey in plain language</span>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <button type="button" id="btn-concierge-reset-consent" data-testid="btn-concierge-reset-consent" style="background:transparent;border:none;color:#6B7280;font-size:0.75rem;cursor:pointer;text-decoration:underline;" title="Withdraw AI consent and reset Advisor preferences">Withdraw AI Consent</button>
                <button type="button" id="btn-concierge-exit" data-testid="btn-concierge-exit" class="btn-concierge-exit" title="Return to standard keyword search">✕ Exit Advisor Mode</button>
              </div>
            </div>
            <div class="concierge-query-bar" data-testid="concierge-query-bar">
              <div class="concierge-query-input-wrap">
                <label for="concierge-panel-input" style="display:none;">Describe your business need or project</label>
                <input type="text" id="concierge-panel-input" data-testid="concierge-panel-input" class="input-control concierge-panel-input"
                  placeholder="Type your project or business challenge (e.g. Planning a company launch, commercial build, corporate retreat)..." />
              </div>
              <button type="button" id="btn-concierge-panel-submit" data-testid="btn-concierge-panel-submit" class="btn btn-gold btn-concierge-submit">★ Ask Advisor</button>
            </div>
            <div class="concierge-starter-prompts" data-testid="concierge-starter-prompts">
              <span class="concierge-starter-label">Try asking:</span>
              <button type="button" class="starter-prompt-chip" data-prompt="Launching a new enterprise or company">🚀 Launching a new company</button>
              <button type="button" class="starter-prompt-chip" data-prompt="Looking for an architect & contractor for a commercial build">🏗️ Commercial architecture &amp; build</button>
              <button type="button" class="starter-prompt-chip" data-prompt="Planning a corporate leadership offsite or retreat">🏕️ Corporate retreat or offsite</button>
              <button type="button" class="starter-prompt-chip" data-prompt="Exporting avocados & organic produce to Europe">🥑 Agricultural export &amp; logistics</button>
              <button type="button" class="starter-prompt-chip" data-prompt="Publishing an industry whitepaper & book launch">📚 Book publishing &amp; communications</button>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Top View Switcher Toolbar (#372, #398) -->
      <div class="view-switcher-bar">
        <div class="view-switcher-left" style="display:flex;align-items:center;gap:10px;">
          <button type="button" class="btn-toggle-filters-top desktop-only-filter-btn" id="btn-toggle-filters-top" onclick="toggleSidebarFilters()">
            <span>☰</span> <span>Filters</span>
          </button>
          <div class="view-segment-group">
            <button type="button" class="view-segment-btn active" id="btn-view-grid" onclick="switchDirectoryView('grid')">
              <span>⊞</span> <span>Cards</span>
            </button>
            <button type="button" class="view-segment-btn" id="btn-view-list" onclick="switchDirectoryView('list')">
              <span>☰</span> <span>List</span>
            </button>
            <button type="button" class="view-segment-btn" id="btn-view-sector" onclick="switchDirectoryView('sector')">
              <span>🗂️</span> <span>By Industry</span>
            </button>
          </div>
        </div>

        <div class="view-controls-right">
          <span class="count-badge" id="results-count" data-testid="results-count">
            <?= $totalApprovedCount > 0 ? ('Showing 1–' . count($initialBusinesses) . ' of ' . $totalApprovedCount . ' Verified Busherian Biasharas') : '0 Verified Busherian Biasharas' ?>
          </span>
          <label for="sort-select" style="display:none;">Sort directory listings</label>
          <select id="sort-select" data-testid="sort-select" class="select-control" aria-label="Sort directory listings" style="width:auto;padding:0.32rem 0.6rem;font-size:0.8rem;">
            <option value="most_referenced"<?= $initialSort === 'most_referenced' ? ' selected' : '' ?>>Most Referenced</option>
            <option value="trending"<?= $initialSort === 'trending' ? ' selected' : '' ?>>Trending This Week</option>
            <option value="newest"<?= $initialSort === 'newest' ? ' selected' : '' ?>>Newest Added</option>
            <option value="nearest"<?= $initialSort === 'nearest' ? ' selected' : '' ?>>Nearest to Me</option>
            <option value="az"<?= $initialSort === 'az' ? ' selected' : '' ?>>Alphabetical (A–Z)</option>
            <option value="directory_number"<?= $initialSort === 'directory_number' ? ' selected' : '' ?>>Directory #</option>
          </select>
          <label for="per-page-select" style="display:none;">Listings per page</label>
          <select id="per-page-select" data-testid="per-page-select" class="select-control" aria-label="Listings per page" style="width:auto;padding:0.32rem 0.6rem;font-size:0.8rem;">
            <option value="25"<?= $initialPerPage === 25 ? ' selected' : '' ?>>25 per page</option>
            <option value="50"<?= $initialPerPage === 50 ? ' selected' : '' ?>>50 per page</option>
            <option value="100"<?= $initialPerPage === 100 ? ' selected' : '' ?>>100 per page</option>
          </select>
        </div>
      </div>

      <!-- 1. Grid Container (#373) -->
      <div id="cards-grid" data-testid="cards-grid" class="business-grid"></div>

      <!-- 2. List Container (#372) -->
      <div id="container-list" style="display:none;width:100%;"></div>

      <!-- 3. By Sector Container (#372) -->
      <div id="container-sector" style="display:none;flex-direction:column;gap:20px;width:100%;"></div>

      <nav id="directory-pagination" data-testid="directory-pagination" aria-label="Directory Pagination" style="margin-top:1rem;"></nav>

      <!-- Bottom Map Toggle Bar (#397) -->
      <div id="map-toggle-bar" class="map-toggle-bar" data-testid="map-toggle-bar" style="margin-top:1.5rem;background:#FFFFFF;border:1.5px solid #E5DEC9;border-radius:12px;padding:0.9rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <label for="chk-show-map-bottom" style="display:inline-flex;align-items:center;gap:0.55rem;font-size:0.9rem;font-weight:700;color:#114B3E;cursor:pointer;user-select:none;margin:0;">
          <input type="checkbox" id="chk-show-map-bottom" data-testid="chk-show-map-bottom" style="width:17px;height:17px;cursor:pointer;accent-color:#114B3E;" onchange="toggleMapVisibility(this.checked)">
          <span>🗺️ Show Busherian Proximity &amp; Pin Map</span>
        </label>
        <span style="font-size:0.76rem;color:#6B7280;">Check to view interactive location pins, clusters &amp; route directions</span>
      </div>

      <!-- Sticky Proximity & Pin Map Dock -->
      <aside id="pane-map" class="map-dock" style="margin-top:1rem;display:none;" aria-label="Interactive Business Map">
        <div class="map-toolbar">
          <div>
            <strong style="font-size:0.85rem;color:#114B3E;">🗺️ Live Busherian Proximity &amp; Pin Map</strong>
            <div style="font-size:0.72rem;color:#4B5563;display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap;margin-top:0.15rem;">
              <span style="display:inline-flex;align-items:center;gap:0.25rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#114B3E;border:1px solid #D4AF37;"></span>Green = AHS</span>
              <span>•</span>
              <span style="display:inline-flex;align-items:center;gap:0.25rem;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%);border:1px solid #D4AF37;"></span>Green &amp; Maroon = AGHS</span>
            </div>
          </div>
          <div id="map-focus-actions" style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;"></div>
        </div>
        <div id="map-route-drawer" class="map-route-drawer" style="display:none;"></div>
        <div id="map-canvas" data-testid="map-canvas" class="map-canvas"></div>
      </aside>

      <!-- Hidden compatibility elements to support existing automated test suite & background engines -->
      <div style="display:none;" aria-hidden="true">
        <select id="filter-sector" data-testid="filter-sector">
          <option value="">All Industry Sectors</option>
          <?php foreach ($taxonomyTree as $sector): ?>
            <option value="<?= (int)$sector['id'] ?>"><?= htmlspecialchars((string)$sector['name'], ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <select id="filter-specialty" data-testid="filter-specialty"><option value="">All Specialties</option></select>
        <select id="filter-radius"><option value="">Any Distance</option></select>
        <button type="button" id="btn-near-me"></button>
      </div>

    </div><!-- /.directory-main-col -->
  </div><!-- /#view-directory-shell -->

  <!-- CANONICAL PROFILE PAGE VIEW: Matching Kanyi Mockup (#375, #377) -->
  <article id="view-biz-detail" class="biz-detail-view" style="<?= $requestedBiz ? 'display:flex;' : 'display:none;' ?>max-width:1440px;margin:0 auto;padding:20px;">
    <button type="button" class="btn-back-nav" onclick="showDirectoryView()">
      <span>&larr;</span> Back to all businesses
    </button>

    <!-- Hero Header -->
    <div class="detail-hero">
      <div class="detail-hero-body">
        <div class="detail-meta-row">
          <span id="detail-meta-sector"><?= $requestedBiz ? htmlspecialchars($requestedBiz['sector_name'] . ' / ' . $requestedBiz['specialty_name'], ENT_QUOTES, 'UTF-8') : '' ?></span>
          <span id="detail-meta-area"><?= $requestedBiz ? htmlspecialchars(trim(($requestedBiz['area_neighborhood'] ?? '') . ' ' . ($requestedBiz['city_county'] ?? '')), ENT_QUOTES, 'UTF-8') : '' ?></span>
        </div>

        <h1 id="detail-name"><?= $requestedBiz ? htmlspecialchars($requestedBiz['name'], ENT_QUOTES, 'UTF-8') : '' ?></h1>
        <p class="detail-lead" id="detail-tagline">
          <?= $requestedBiz ? htmlspecialchars($requestedBiz['tagline'] ?: $requestedBiz['description'], ENT_QUOTES, 'UTF-8') : '' ?>
        </p>

        <div class="founder-attribution" id="detail-founder" style="max-width:fit-content;margin-bottom:0;<?= ($requestedBiz && in_array($requestedBiz['founder_role'], ['founder', 'co_founder'], true)) ? '' : 'display:none;' ?>">
          <span>👤</span>
          <span id="detail-founder-text">
            <?php if ($requestedBiz && in_array($requestedBiz['founder_role'], ['founder', 'co_founder'], true)): ?>
              <?php
                $fPrefix = ($requestedBiz['founder_role'] === 'co_founder') ? 'Co-Founded by ' : 'Founded by ';
                $fMeta = [];
                if (!empty($requestedBiz['founder_school'])) {
                    $fMeta[] = htmlspecialchars((string)$requestedBiz['founder_school'], ENT_QUOTES, 'UTF-8');
                }
                if (!empty($requestedBiz['founder_year'])) {
                    $fMeta[] = "'" . ltrim(htmlspecialchars((string)$requestedBiz['founder_year'], ENT_QUOTES, 'UTF-8'), "'");
                }
                $fMetaStr = !empty($fMeta) ? ' (' . implode(' ', $fMeta) . ')' : '';
              ?>
              <?= htmlspecialchars($fPrefix, ENT_QUOTES, 'UTF-8') . htmlspecialchars((string)$requestedBiz['founder_name'], ENT_QUOTES, 'UTF-8') . $fMetaStr ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="detail-chips-row">
          <span class="detail-chip status-on" id="detail-hours-chip" style="display:none;"></span>
          <span class="detail-chip school" id="detail-school-chip">🎓 Verified Alum · <?= $requestedBiz ? htmlspecialchars((string)$requestedBiz['school_badge'], ENT_QUOTES, 'UTF-8') : 'AHS' ?></span>
          <span class="detail-chip perk" id="detail-perk-chip" style="<?= ($requestedBiz && !empty($requestedBiz['alumni_perk'])) ? '' : 'display:none;' ?>">
            🎁 <?= $requestedBiz ? htmlspecialchars((string)$requestedBiz['alumni_perk'], ENT_QUOTES, 'UTF-8') : '' ?>
          </span>
        </div>

        <div class="detail-cta-bar">
          <?php
          $hasReqWa = $requestedBiz && !empty($requestedBiz['whatsapp_number']) && strlen(preg_replace('/[^0-9]/', '', (string)$requestedBiz['whatsapp_number'])) >= 7;
          $waReqDigits = $hasReqWa ? preg_replace('/[^0-9]/', '', (string)$requestedBiz['whatsapp_number']) : '';
          $waReqUrl = $hasReqWa ? ('https://wa.me/' . $waReqDigits . '?text=' . urlencode('Hello ' . $requestedBiz['name'])) : '#';
          $hasReqWebsite = $requestedBiz && !empty($requestedBiz['website_url']) && preg_match('#^https?://#i', (string)$requestedBiz['website_url']);
          $reqWebsiteUrl = '#';
          if ($hasReqWebsite) {
              $rawWeb = trim((string)$requestedBiz['website_url']);
              if (strpos($rawWeb, 'utm_source') === false) {
                  $sep = (strpos($rawWeb, '?') !== false) ? '&' : '?';
                  $rawWeb .= $sep . 'utm_source=busherianbiasharas.com&utm_medium=referral&utm_campaign=alumni_directory';
              }
              $reqWebsiteUrl = htmlspecialchars($rawWeb, ENT_QUOTES, 'UTF-8');
          }
          ?>
          <a href="<?= $waReqUrl ?>" id="detail-wa-btn" class="btn-action-primary btn-action-whatsapp<?= $hasReqWa ? '' : ' btn-action-disabled' ?>" style="padding:0 20px;height:42px;" target="_blank" rel="noopener"<?= $hasReqWa ? '' : ' aria-disabled="true" title="No WhatsApp line provided"' ?>>
            <span>💬</span> WhatsApp
          </a>
          <a href="<?= ($requestedBiz && !empty($requestedBiz['phone_primary'])) ? ('tel:' . htmlspecialchars((string)$requestedBiz['phone_primary'], ENT_QUOTES, 'UTF-8')) : '#' ?>" id="detail-call-btn" class="btn-action-primary btn-action-call" style="padding:0 20px;height:42px;">
            <span>📞</span> Call
          </a>
          <a href="<?= $reqWebsiteUrl ?>" id="detail-website-btn" data-testid="detail-website-btn" target="_blank" rel="noopener noreferrer" data-track="website" class="btn btn-outline" style="display:<?= $hasReqWebsite ? 'inline-flex' : 'none' ?>;align-items:center;gap:6px;height:42px;">
            <span>🌐</span> Website
          </a>
          <a href="<?= ($requestedBiz && !empty($requestedBiz['google_maps_url']) && preg_match('#^https?://#i', (string)$requestedBiz['google_maps_url'])) ? htmlspecialchars((string)$requestedBiz['google_maps_url'], ENT_QUOTES, 'UTF-8') : '#' ?>" id="detail-route-btn" target="_blank" rel="noopener" class="btn btn-outline" style="display:inline-flex;align-items:center;gap:6px;height:42px;">
            <span>🧭</span> Directions
          </a>
          <button type="button" id="detail-share-btn" class="btn btn-outline" style="display:inline-flex;align-items:center;gap:6px;height:42px;">
            <span>🔗</span> Share
          </button>
        </div>
      </div>
    </div>

    <!-- Airbnb-Style Top Photographs Showcase (#405) -->
    <?php
    $reqPhotos = ($requestedBiz && !empty($requestedBiz['photos'])) ? $requestedBiz['photos'] : [];
    $photosCount = count($reqPhotos);
    $hasMultiplePhotos = $photosCount > 3;
    ?>
    <section class="detail-photos-showcase" id="detail-photos-showcase" aria-label="Business photographs">
      <div class="photos-nav-controls" id="detail-photos-nav" style="<?= $hasMultiplePhotos ? 'display:flex;' : 'display:none;' ?>">
        <button type="button" class="btn-gallery-nav btn-gallery-prev" id="btn-photos-scroll-left" aria-label="Scroll photos left" title="Scroll left">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>
        <button type="button" class="btn-gallery-nav btn-gallery-next" id="btn-photos-scroll-right" aria-label="Scroll photos right" title="Scroll right">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
      </div>

      <div class="photos-carousel-track airbnb-photos-grid photos-count-<?= $photosCount ?>" id="detail-photos-grid" tabindex="0" role="region" aria-label="Photos carousel">
        <?php if ($photosCount > 0): ?>
          <?php foreach ($reqPhotos as $idx => $photoUrl): ?>
            <?php $isExtra = $idx >= 5; ?>
            <div class="photo-slot<?= $idx === 0 ? ' photo-featured' : '' ?><?= $isExtra ? ' photo-overflow-hidden' : '' ?>" data-photo-index="<?= (int)$idx ?>" role="button" tabindex="0" aria-label="Enlarge photo <?= (int)($idx + 1) ?> of <?= $photosCount ?>">
              <img src="<?= htmlspecialchars((string)$photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$requestedBiz['name'], ENT_QUOTES, 'UTF-8') ?> photo <?= (int)($idx + 1) ?>" loading="lazy">
              <div class="photo-expand-badge" aria-hidden="true" title="Click to enlarge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="photo-branded-banner" role="img" aria-label="Business crest banner">
            <div class="banner-crest-motif">
              <?php if (!empty($requestedBiz['logo_path'])): ?>
                <img src="<?= htmlspecialchars((string)$requestedBiz['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$requestedBiz['name'], ENT_QUOTES, 'UTF-8') ?> logo" class="detail-banner-logo" data-testid="detail-banner-logo">
              <?php else: ?>
                <span id="detail-initials"><?= $requestedBiz ? htmlspecialchars(substr($requestedBiz['name'], 0, 2), ENT_QUOTES, 'UTF-8') : 'BB' ?></span>
              <?php endif; ?>
            </div>
            <div class="banner-crest-meta">
              <span class="banner-badge">Verified Busherian Biashara</span>
              <span class="banner-hint">Photographs &amp; premises gallery coming soon</span>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Floating "Show all photos" Pill Button (#405) -->
      <button type="button" class="btn-show-all-photos" id="btn-show-all-photos" style="<?= $photosCount > 0 ? 'display:inline-flex;' : 'display:none;' ?>" onclick="if(window.openAllDetailPhotos){window.openAllDetailPhotos();}">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
        <span>Show all <span id="detail-photos-count"><?= $photosCount ?></span> photos</span>
      </button>

      <!-- Mobile Photo Index Counter Badge (#405) -->
      <div class="mobile-photo-counter" id="mobile-photo-counter" style="<?= $photosCount > 1 ? 'display:inline-flex;' : 'display:none;' ?>">
        <span id="mobile-photo-index">1</span> / <span id="mobile-photo-total"><?= $photosCount ?></span>
      </div>
    </section>

    <!-- 2 Columns Grid (3fr / 2fr as in Kanyi prototype) -->
    <div class="detail-cols-grid">
      <!-- Column 1: Services, Recommendations, About -->
      <div style="display:flex;flex-direction:column;gap:20px;">
        <section class="detail-panel">
          <h2>About &amp; Overview</h2>
          <p id="detail-desc" style="font-size:14px;line-height:1.6;color:#374151;margin:0;">
            <?= $requestedBiz ? nl2br(htmlspecialchars((string)$requestedBiz['description'], ENT_QUOTES, 'UTF-8')) : '' ?>
          </p>
        </section>

        <section class="detail-panel">
          <h2>Services &amp; Capabilities</h2>
          <ul class="services-tag-list" id="detail-services-list">
            <?php if ($requestedBiz): ?>
              <li><?= htmlspecialchars((string)$requestedBiz['specialty_name'], ENT_QUOTES, 'UTF-8') ?></li>
              <?php if (!empty($requestedBiz['secondary_specialty_name'])): ?>
                <li><?= htmlspecialchars((string)$requestedBiz['secondary_specialty_name'], ENT_QUOTES, 'UTF-8') ?></li>
              <?php endif; ?>
            <?php endif; ?>
          </ul>
        </section>

        <section class="recs-card" id="detail-recs-panel">
          <h2 class="recs-card-title">Recommended by Busherians</h2>
          <div class="recs-card-summary" id="detail-recs-summary">
            <span class="note">No recommendations yet. Be the first.</span>
          </div>
          <button type="button" class="btn-recommend" id="btn-toggle-recommend" data-biz-id="<?= $requestedBiz ? (int)$requestedBiz['id'] : 0 ?>" onclick="if(window.handleRecommendButtonClick){window.handleRecommendButtonClick();}">
            I have used them and recommend them
          </button>
        </section>
      </div>

      <!-- Column 2: Contact & Location, Opening Hours, Share -->
      <div style="display:flex;flex-direction:column;gap:20px;">
        <section class="detail-panel">
          <h2>Contact &amp; Location</h2>
          <div style="display:flex;flex-direction:column;gap:8px;font-size:13.5px;">
            <div id="detail-phone-row">
              <strong><?= $requestedBiz ? htmlspecialchars((string)$requestedBiz['phone_primary'], ENT_QUOTES, 'UTF-8') : '' ?></strong>
              <div style="font-size:12px;color:#7E8D84;">Primary business contact and verified WhatsApp</div>
            </div>
            <div id="detail-website-row" data-testid="detail-website-row" style="<?= $hasReqWebsite ? '' : 'display:none;' ?>">
              <a href="<?= $reqWebsiteUrl ?>" id="detail-website-link" target="_blank" rel="noopener noreferrer" data-track="website" style="color:#114B3E;font-weight:700;word-break:break-all;text-decoration:underline;">
                <?= $hasReqWebsite ? htmlspecialchars((string)$requestedBiz['website_url'], ENT_QUOTES, 'UTF-8') : '' ?>
              </a>
              <div style="font-size:12px;color:#7E8D84;">Official business website</div>
            </div>
            <div id="detail-loc-row">
              <strong><?= $requestedBiz ? htmlspecialchars(trim(($requestedBiz['street_road'] ?? '') . ', ' . ($requestedBiz['city_county'] ?? '')), ENT_QUOTES, 'UTF-8') : '' ?></strong>
              <div style="font-size:12px;color:#7E8D84;"><?= $requestedBiz ? htmlspecialchars((string)($requestedBiz['landmark_directions'] ?? 'Directions available via route link'), ENT_QUOTES, 'UTF-8') : '' ?></div>
            </div>
          </div>
        </section>

        <section class="detail-panel">
          <h2>Opening Hours</h2>
          <div id="detail-hours-summary" style="font-size:13.5px;font-weight:700;color:#114B3E;">
            Weekdays &amp; Saturday Operating Schedule
          </div>
          <div id="detail-hours-table-wrap"></div>
        </section>

        <section class="detail-panel">
          <h2>Share This Business</h2>
          <p style="font-size:12.5px;color:#57645C;margin:0 0 8px 0;">
            This page has its own canonical address, perfect for sending to a WhatsApp group or alumni chat.
          </p>
          <div style="display:flex;gap:8px;">
            <a href="#" id="detail-share-wa-btn" target="_blank" rel="noopener" class="btn-action-primary btn-action-whatsapp" style="font-size:12px;height:36px;padding:0 14px;">
              💬 Share on WhatsApp
            </a>
            <button type="button" id="detail-copy-link-btn" class="btn btn-outline" style="font-size:12px;height:36px;">
              Copy link
            </button>
          </div>
        </section>
      </div>
    </div>

    <!-- More in Sector Panel -->
    <section class="detail-panel" id="detail-more-section" style="margin-top:10px;">
      <h2>More in This Sector</h2>
      <div class="more-biasharas-grid" id="detail-more-grid"></div>
    </section>

    <!-- Confirmation, Owner Claim & Report Footnote Strip (#Mockup) -->
    <div class="detail-foot-note" id="detail-foot-note">
      <span>Details confirmed by the owner in <span id="detail-confirmed-date"><?= $requestedBiz && !empty($requestedBiz['verified_at']) ? date('F Y', strtotime((string)$requestedBiz['verified_at'])) : date('F Y') ?></span>.</span>
      <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php' ?>" id="detail-claim-link">Own this business?</a>
      <a href="/takedown.php<?= $requestedBiz ? '?biz=' . urlencode((string)$requestedBiz['slug']) : '' ?>" id="detail-report-link">Report a problem</a>
    </div>
  </article>

  <!-- Rich Business Detail Modal -->
  <div id="biz-modal-backdrop" data-testid="biz-modal-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-label="Business Details">
    <div id="biz-modal-content" data-testid="biz-modal-content" class="modal-dialog"></div>
  </div>

  <!-- Lightweight Alumni Recommendation Modal (#Mockup) -->
  <div id="modal-recommend" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-recommend-title" hidden>
    <div class="modal-dialog" style="max-width:440px;background:#18201B;color:#FFFFFF;border:1px solid #2D3A31;border-radius:14px;padding:24px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 id="modal-recommend-title" style="margin:0;font-size:1.15rem;color:#FDF8F3;font-weight:700;">Recommend this Biashara</h3>
        <button type="button" class="modal-close" id="btn-close-recommend-modal" onclick="if(window.closeRecommendationModal){window.closeRecommendationModal();}" style="color:#C5D0C9;font-size:22px;background:none;border:none;cursor:pointer;line-height:1;">&times;</button>
      </div>
      <p style="font-size:0.86rem;color:#C5D0C9;margin:0 0 16px 0;line-height:1.5;">
        Add your name and school so fellow Busherians know who has used and vouches for this business.
      </p>
      <form id="form-recommend" onsubmit="event.preventDefault(); if(window.submitRecommendationModal){window.submitRecommendationModal();}">
        <input type="hidden" id="rec-biz-id" value="">
        <div style="margin-bottom:14px;">
          <label for="rec-name" style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:4px;color:#E5DEC9;">Your Name</label>
          <input type="text" id="rec-name" required placeholder="e.g. Akinyi or Onyango" style="width:100%;padding:9px 12px;background:#243028;border:1px solid #3B4B40;border-radius:7px;color:#FFFFFF;font-size:14px;box-sizing:border-box;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">
          <div>
            <label for="rec-school" style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:4px;color:#E5DEC9;">School</label>
            <select id="rec-school" style="width:100%;padding:9px 10px;background:#243028;border:1px solid #3B4B40;border-radius:7px;color:#FFFFFF;font-size:13.5px;box-sizing:border-box;">
              <option value="AHS">Alliance High (AHS)</option>
              <option value="AGHS">Alliance Girls (AGHS)</option>
            </select>
          </div>
          <div>
            <label for="rec-year" style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:4px;color:#E5DEC9;">Year Left (Optional)</label>
            <input type="number" id="rec-year" min="1940" max="2027" placeholder="e.g. 2011" style="width:100%;padding:9px 12px;background:#243028;border:1px solid #3B4B40;border-radius:7px;color:#FFFFFF;font-size:14px;box-sizing:border-box;">
          </div>
        </div>
        <button type="submit" class="btn" style="width:100%;background:#22C55E;color:#0B332A;font-weight:700;padding:11px;font-size:14.5px;border:none;border-radius:8px;cursor:pointer;">
          Vouch &amp; Recommend &rarr;
        </button>
      </form>
    </div>
  </div>

  <!-- Airbnb-Style Photo Lightbox Modal (#403) -->
  <div id="bb-photo-lightbox" class="photo-lightbox" role="dialog" aria-modal="true" aria-label="Photo Gallery" hidden>
    <div class="photo-lightbox-backdrop" id="lightbox-backdrop"></div>
    <div class="photo-lightbox-container">
      <div class="photo-lightbox-header">
        <div class="photo-lightbox-counter" id="lightbox-counter">1 / 1</div>
        <div class="photo-lightbox-title" id="lightbox-title">Photo Gallery</div>
        <button type="button" class="photo-lightbox-close" id="btn-close-lightbox" aria-label="Close photo viewer">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          <span>Close</span>
        </button>
      </div>

      <div class="photo-lightbox-viewport" id="lightbox-viewport">
        <button type="button" class="photo-lightbox-nav photo-lightbox-prev" id="btn-lightbox-prev" aria-label="Previous photograph" title="Previous photo (Left arrow)">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        </button>

        <div class="photo-lightbox-stage" id="lightbox-stage">
          <img id="lightbox-img" src="" alt="Enlarged photograph" class="photo-lightbox-image">
        </div>

        <button type="button" class="photo-lightbox-nav photo-lightbox-next" id="btn-lightbox-next" aria-label="Next photograph" title="Next photo (Right arrow)">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
        </button>
      </div>

      <div class="photo-lightbox-footer">
        <div class="photo-lightbox-thumbs" id="lightbox-thumbs"></div>
      </div>
    </div>
  </div>

  <!-- PWA Home-Screen Install Guide Modal (#290) -->
  <div id="pwa-install-modal" data-testid="pwa-install-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="pwa-install-modal-title" hidden>
    <div class="modal-dialog" style="max-width:540px;">
      <div class="modal-header">
        <div>
          <span class="badge badge-ahs" style="margin-bottom:0.35rem;display:inline-block;">📲 In Your Pocket</span>
          <h2 id="pwa-install-modal-title" style="margin:0;font-size:1.2rem;color:#111827;">Install Busherian Biasharas on Your Device</h2>
        </div>
        <button type="button" id="btn-close-pwa-modal" data-testid="btn-close-pwa-modal" class="modal-close" aria-label="Close install instructions">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin:0 0 0.9rem 0;font-size:0.88rem;color:#374151;line-height:1.5;">
          Add <strong>Biasharas</strong> directly to your home screen for one-tap full-screen launch and offline access to verified alumni contacts, WhatsApp links, and building directions — with zero app-store bloat.
        </p>
        <div class="pwa-install-steps-grid">
          <div id="pwa-guide-ios" data-testid="pwa-guide-ios" class="pwa-platform-card">
            <div class="pwa-platform-title">🍎 iPhone &amp; iPad (Safari)</div>
            <ol class="pwa-platform-steps">
              <li>Tap the <strong>Share</strong> icon (<span aria-hidden="true">⬆️</span> square with upward arrow) in Safari's toolbar.</li>
              <li>Scroll down and tap <strong>&ldquo;Add to Home Screen&rdquo;</strong>, then tap <strong>Add</strong>.</li>
            </ol>
          </div>
          <div id="pwa-guide-other" data-testid="pwa-guide-other" class="pwa-platform-card">
            <div class="pwa-platform-title">🤖 Android &amp; 💻 Desktop Browser</div>
            <ol class="pwa-platform-steps">
              <li>Open your browser menu (<strong>⋮</strong>) or click the install icon in the address bar.</li>
              <li>Tap <strong>&ldquo;Install app&rdquo;</strong> or <strong>&ldquo;Add to Home screen&rdquo;</strong>.</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Community Charter Quick-View Modal (#298, #300) -->
  <div id="community-charter-modal" data-testid="community-charter-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="community-charter-modal-title" hidden>
    <div class="modal-dialog" style="max-width:680px;">
      <div class="modal-header">
        <div>
          <span class="badge badge-ahs" style="margin-bottom:0.35rem;display:inline-block;">📜 Community Charter</span>
          <h2 id="community-charter-modal-title" style="margin:0;font-size:1.2rem;color:#111827;">Free-Forever Core Guarantee &amp; Platform Charter</h2>
        </div>
        <button type="button" id="btn-close-charter-modal" data-testid="btn-close-charter-modal" class="modal-close" aria-label="Close Community Charter">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin:0 0 0.95rem 0;font-size:0.88rem;color:#374151;line-height:1.5;">
          <strong>Busherian Biasharas</strong> keeps the front door 100% free for verified <strong>AHS &amp; AGHS</strong> alumni founders while maintaining strict verification and privacy boundaries:
        </p>
<?= bbiz_render_community_charter_clauses() ?>
        <div style="margin-top:1.1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.65rem;">
          <a href="/charter.php" data-testid="modal-full-charter-link" style="font-size:0.82rem;font-weight:700;color:#114B3E;text-decoration:underline;">Open standalone Charter &amp; Founder FAQ page &rarr;</a>
          <a href="/submit.php" class="btn btn-crimson" style="font-size:0.82rem;padding:0.45rem 0.9rem;">+ List Your Biashara (Free)</a>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isAdvisorEnabled): ?>
  <!-- Biashara Advisor Kenya DPA Consent Modal (#344) -->
  <div id="concierge-consent-modal" data-testid="concierge-consent-modal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="concierge-consent-modal-title" hidden>
    <div class="modal-dialog" style="max-width:560px;">
      <div class="modal-header">
        <div>
          <span class="badge badge-ahs" style="margin-bottom:0.35rem;display:inline-block;">🛡️ Kenya DPA 2019 Transparency</span>
          <h2 id="concierge-consent-modal-title" style="margin:0;font-size:1.2rem;color:#111827;">Ask Biashara Advisor</h2>
        </div>
        <button type="button" id="btn-close-concierge-modal" data-testid="btn-close-concierge-modal" class="modal-close" aria-label="Close modal">&times;</button>
      </div>
      <div class="modal-body">
        <p style="margin:0 0 0.85rem 0;font-size:0.9rem;color:#374151;line-height:1.5;">
          <strong>Biashara Advisor</strong> uses natural-language synthesis to match your projects with verified alumni businesses and adjacent partners (legal counsel, commercial contractors, tax accountants, marketing, logistics).
        </p>
        <div style="background:#F0FDF4;border:1px solid #86EFAC;border-radius:8px;padding:0.85rem;margin-bottom:1rem;font-size:0.82rem;color:#166534;line-height:1.45;">
          <strong>🔒 Your Privacy is Protected (Kenya DPA ss. 25, 32, 48–49):</strong>
          <ul style="margin:0.4rem 0 0 1.1rem;padding:0;">
            <li>An automated privacy filter strips all phone numbers, email addresses, national IDs, and URLs before processing.</li>
            <li>Zero personal profiles are retained or shared with external AI services.</li>
            <li>All recommended contacts are hydrated strictly from verified alumni directory records.</li>
          </ul>
        </div>
        <div class="concierge-adult-check" style="margin:0.85rem 0 1rem 0;padding:0.65rem 0.75rem;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:6px;">
          <label style="display:flex;align-items:flex-start;gap:0.5rem;cursor:pointer;font-size:0.82rem;color:#1E293B;">
            <input type="checkbox" id="concierge-adult-attestation" data-testid="concierge-adult-attestation" style="margin-top:0.15rem;" checked>
            <span><strong>Adult Attestation (Kenya DPA 2019 s. 33):</strong> I confirm that I am 18 years of age or older, and agree to the privacy terms and temporary query processing described above.</span>
          </label>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.6rem;align-items:center;">
          <button type="button" id="btn-concierge-consent-cancel" data-testid="concierge-consent-cancel" class="btn btn-outline" style="font-size:0.85rem;">Cancel</button>
          <button type="button" id="btn-concierge-consent-accept" data-testid="concierge-consent-accept" class="btn btn-green" style="font-size:0.85rem;">Agree &amp; Open Advisor</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 4-Column Heritage Footer (#379 - #381) -->
  <footer class="foot-site">
    <div class="foot-inner">
      <div class="footer-charter-strip" data-testid="footer-charter-strip">
        <span>
          📜 <strong>100% Free-Forever Core Directory:</strong> Verified AHS &amp; AGHS standard listings, map pins, direct contacts &amp; self-served bookings carry <strong>zero listing or commission fees</strong>.
        </span>
        <a href="/charter.php" id="btn-open-charter-modal" data-testid="btn-open-charter-modal" class="footer-charter-btn">Read Community Charter</a>
      </div>

      <div class="foot-cols">
        <nav class="foot-col" aria-label="The directory">
          <h2>THE DIRECTORY</h2>
          <a href="/charter.php" data-testid="footer-charter-link">About and who runs it</a>
          <a href="/charter.php">How vetting works</a>
          <a href="/charter.php">Community Charter</a>
          <span class="foot-note">Core listings are free, forever.</span>
        </nav>
        <nav class="foot-col" aria-label="For business owners">
          <h2>FOR BUSINESS OWNERS</h2>
          <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php?from=list_edit' ?>">List or edit a biashara</a>
          <a href="/submit.php">Direct submission form</a>
          <a href="/takedown.php">Remove my listing</a>
        </nav>
        <nav class="foot-col" aria-label="Help and trust">
          <h2>HELP AND TRUST</h2>
          <a href="mailto:contact@busherianbiasharas.com">Contact the team</a>
          <a href="/takedown.php">Report a listing</a>
          <a href="/privacy.php">Privacy Notice</a>
          <a href="/privacy.php#cookies">Cookie choices</a>
        </nav>
        <nav class="foot-col" aria-label="Community">
          <h2>COMMUNITY</h2>
          <a href="https://www.alliancehighschool.ac.ke/" target="_blank" rel="noopener">Alliance High School &nearr;</a>
          <a href="https://aghs.sc.ke/" target="_blank" rel="noopener">Alliance Girls High School &nearr;</a>
          <a href="#invite" onclick="event.preventDefault(); if(window.copyInviteText){window.copyInviteText();}">Invite a Busherian</a>
          <a href="#install" onclick="event.preventDefault(); const m = document.getElementById('pwa-install-modal'); if (m) m.hidden=false;">Install app</a>
        </nav>
      </div>

      <div class="foot-base">
        <div class="foot-crests">
          <div class="brand-crest" style="width:38px;height:38px;font-size:16px;">BB</div>
          <a href="https://www.alliancehighschool.ac.ke/" target="_blank" rel="noopener" class="school-badge ahs" style="text-decoration:none;padding:5px 12px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Visit Alliance High School (ahs.ac.ke)">
            <span>AHS</span> <span>&nearr;</span>
          </a>
          <a href="https://aghs.sc.ke/" target="_blank" rel="noopener" class="school-badge aghs" style="text-decoration:none;padding:5px 12px;font-size:12px;display:inline-flex;align-items:center;gap:4px;" title="Visit Alliance Girls High School (aghs.sc.ke)">
            <span>AGHS</span> <span>&nearr;</span>
          </a>
        </div>
        <div class="foot-base-text">
          <strong>Strong to Serve. Walk in the Light.</strong>
          <div class="note">2026 Busherian Biasharas. An independent alumni community initiative connecting Alliance High School and Alliance Girls High School enterprises worldwide. Not officially affiliated with Alliance High School or Alliance Girls High School.</div>
        </div>
      </div>
    </div>
  </footer>

  <!-- Streamlined Mobile Filter Bottom Sheet Modal (#405) -->
  <div id="mobile-filter-sheet-backdrop" class="sheet-backdrop" onclick="closeMobileFilterSheet()"></div>
  <div id="mobile-filter-sheet" class="mobile-filter-sheet" role="dialog" aria-modal="true" aria-label="Directory Filters">
    <div class="sheet-handle-bar"><div class="sheet-handle"></div></div>
    <div class="sheet-header">
      <div class="sheet-title-group">
        <h3 class="sheet-title">Filters</h3>
        <span class="sheet-subtitle">Refine biasharas by industry, location or school</span>
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <button type="button" class="sheet-reset-btn" id="btn-sheet-reset" onclick="resetAllMobileFilters()">Reset All</button>
        <button type="button" class="sheet-close-btn" onclick="closeMobileFilterSheet()" aria-label="Close filters">✕</button>
      </div>
    </div>
    <div class="sheet-body">
      <!-- Section 1: Sort By -->
      <div class="sheet-section">
        <div class="sheet-section-title">SORT BY</div>
        <div class="sheet-segmented-group" id="sheet-sort-group">
          <button type="button" class="sheet-segment-btn active" data-sheet-sort="most_referenced">Referenced</button>
          <button type="button" class="sheet-segment-btn" data-sheet-sort="trending">Trending</button>
          <button type="button" class="sheet-segment-btn" data-sheet-sort="newest">Newest</button>
          <button type="button" class="sheet-segment-btn" data-sheet-sort="nearest">Nearest</button>
          <button type="button" class="sheet-segment-btn" data-sheet-sort="az">A–Z</button>
        </div>
      </div>

      <!-- Section 2: Industry / Sector -->
      <div class="sheet-section">
        <div class="sheet-section-title">INDUSTRY / SECTOR</div>
        <select id="sheet-filter-sector" class="sheet-select" aria-label="Filter by Sector">
          <option value="">All Sectors (<?= $totalApprovedCount ?>)</option>
          <?php foreach ($taxonomyTree as $sec): ?>
            <?php $secCnt = $sectorCounts[(int)$sec['id']] ?? 0; ?>
            <option value="<?= (int)$sec['id'] ?>"><?= htmlspecialchars((string)$sec['name'], ENT_QUOTES, 'UTF-8') ?> (<?= $secCnt ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Section 3: Location -->
      <div class="sheet-section">
        <div class="sheet-section-title">LOCATION</div>
        <div class="sheet-location-row" style="display:flex;gap:8px;">
          <select id="sheet-filter-country" class="sheet-select" style="flex:1;" aria-label="Filter by Country">
            <option value="">🌍 All Countries</option>
            <option value="Kenya">Kenya</option>
            <option disabled>──────────</option>
            <?php foreach (array_keys($placeHierarchy) as $cName): ?>
              <?php if ($cName === 'Kenya') continue; ?>
              <option value="<?= htmlspecialchars((string)$cName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$cName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
          <select id="sheet-filter-city" class="sheet-select" style="flex:1;" aria-label="Filter by County, State, or Province">
            <option value="">All Counties / Cities</option>
          </select>
        </div>
      </div>

      <!-- Section 4: Alumni School -->
      <div class="sheet-section">
        <div class="sheet-section-title">ALUMNI SCHOOL</div>
        <div class="sheet-segmented-group" id="sheet-school-group">
          <button type="button" class="sheet-segment-btn active" data-sheet-school="">All Schools</button>
          <button type="button" class="sheet-segment-btn" data-sheet-school="AHS">🦅 AHS</button>
          <button type="button" class="sheet-segment-btn" data-sheet-school="AGHS">🔦 AGHS</button>
        </div>
      </div>
    </div>
    <div class="sheet-footer">
      <button type="button" class="btn btn-green sheet-apply-btn" id="btn-sheet-apply" onclick="closeMobileFilterSheet()">
        Show Biasharas
      </button>
    </div>
  </div>

  <script>
    window.INITIAL_SECTORS = <?= bbiz_json_for_html_script($taxonomyTree) ?>;
    window.PLACE_HIERARCHY = <?= bbiz_json_for_html_script($placeHierarchy) ?>;
    window.PLACE_UNIT_LABELS = <?= bbiz_json_for_html_script(LocationEngine::getPlaceUnitLabels()) ?>;
    window.INITIAL_BUSINESSES = <?= bbiz_json_for_html_script($initialBusinesses) ?>;
    window.INITIAL_PAGINATION = <?= bbiz_json_for_html_script($initialPagination) ?>;
    window.REQUESTED_BIZ_SLUG = <?= json_encode($requestedBizSlug) ?>;
    window.REQUESTED_BIZ = <?= json_encode($requestedBiz) ?>;
  </script>
  <script src="/assets/vendor/leaflet/leaflet.js"></script>
  <script src="/assets/js/consent-metrics.js?v=<?= urlencode($appVersion) ?>"></script>
  <script src="/assets/js/directory.js?v=<?= urlencode($appVersion) ?>"></script>
</body>
</html>
