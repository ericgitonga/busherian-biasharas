<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\EngagementRanker;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhotoUploader;

bbiz_send_security_headers(false);

$appVersion = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION') ?: '0.32.5');
$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);
$isAdminVisitor = AdminAuth::isAuthenticated();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);
$placeHierarchy = LocationEngine::getPlaceHierarchy();

// Biashara Advisor visibility (#375, #376): active in Preprod sandbox (/admin/preprod), automated test suites, or via on-demand preview (?advisor=1)
$isAdvisorEnabled = \BusherianBiasharas\Services\PreprodSandbox::isPreprod()
    || strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development'))) === 'testing'
    || (defined('BBIZ_ENABLE_ADVISOR') && BBIZ_ENABLE_ADVISOR === true)
    || (getenv('BBIZ_ENABLE_ADVISOR') === 'true')
    || (isset($_GET['advisor']) && $_GET['advisor'] === '1')
    || (isset($_GET['preprod']) && $_GET['preprod'] === '1');

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
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'google_maps_url' => $r['google_maps_url'],
        'distance_km' => null,
        'hours_status' => HoursEngine::evaluateStatus((string)$r['operating_hours_json'], null, LocationEngine::getTimezoneForCountry($r['country'] ?? null, $r['city_county'] ?? null)),
        'momentum_badge' => EngagementRanker::resolveMomentumBadge($r, $initialMomentumCtx),
        'photos' => PhotoUploader::decodePhotosJson(isset($r['photos_json']) ? (string)$r['photos_json'] : null),
        'phone_primary' => $r['phone_primary'],
        'whatsapp_number' => $r['whatsapp_number'],
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
$requestedBizSlug = !empty($_GET['biz']) ? trim((string)$_GET['biz']) : null;
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
            b.social_facebook, b.social_tiktok, b.social_youtube
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
        <button type="button" id="btn-install-app" data-testid="btn-install-app" class="btn btn-outline-light btn-install-pwa" hidden title="Install Busherian Biasharas on your phone or desktop">
          📲 Install App
        </button>
        <button type="button" id="btn-export-shortlist" class="btn btn-outline-light" title="Export selected B2B vendors as CSV">
          🏢 B2B Shortlist (<span id="shortlist-count">0</span>/5)
        </button>
        <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php' ?>" class="btn btn-outline-light" style="border-color:#FCD34D;font-weight:700;">✏️ <?= $currentOwner ? 'My Biasharas (Edit)' : 'Owner Sign In / Edit Listing' ?></a>
        <a href="/privacy.php" class="btn btn-outline-light">🔒 Privacy Notice</a>
        <a href="/submit.php" class="btn btn-gold">+ List Your Biashara</a>
      </nav>
    </div>
  </header>

  <div id="pwa-offline-banner" data-testid="pwa-offline-banner" class="pwa-offline-banner" role="status" aria-live="polite" hidden>
    📶 <strong>Offline Mode:</strong> Showing your saved Busherian Biasharas snapshot. Connect to the internet for live EAT hours, map routes &amp; latest updates.
  </div>

  <!-- MAIN DIRECTORY LAYOUT: Left Filter Panel + Main Column (#370, #371) -->
  <div class="directory-layout-wrapper" id="view-directory-shell" style="<?= $requestedBiz ? 'display:none;' : '' ?>">

    <!-- Left Filter Panel (#371) -->
    <aside class="filters-sidebar" id="filters-sidebar" aria-label="Directory Filters">
      <div class="sidebar-header">
        <span class="sidebar-title">Filters</span>
        <button type="button" class="btn-sidebar-hide" id="btn-sidebar-hide" onclick="toggleSidebarFilters()">Hide</button>
      </div>

      <!-- Sector Filter with Counts -->
      <div class="filter-group">
        <div class="filter-group-title">SECTOR</div>
        <div class="sector-vertical-nav" id="sidebar-sector-nav">
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
        <select id="filter-city" data-testid="filter-city" class="sidebar-select" aria-label="Filter by City, County, or State">
          <option value="">All locations</option>
          <?php foreach (($placeHierarchy['Kenya'] ?? []) as $cityName): ?>
            <option value="<?= htmlspecialchars((string)$cityName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$cityName, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" id="btn-sidebar-near-me" class="btn-sidebar-near-me" style="margin-top:4px;" onclick="triggerNearMeLocation()">
          📍 Near me
        </button>
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
      </div>
    </aside>

    <!-- Main Directory Column -->
    <div class="directory-main-col">

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

      <!-- Top View Switcher Toolbar (#372) -->
      <div class="view-switcher-bar">
        <div style="display:flex;align-items:center;gap:10px;">
          <button type="button" class="btn-toggle-filters-top" id="btn-toggle-filters-top" onclick="toggleSidebarFilters()">
            <span>☰</span> <span>Filters</span>
          </button>
          <div class="view-segment-group">
            <button type="button" class="view-segment-btn active" id="btn-view-grid" onclick="switchDirectoryView('grid')">
              <span>⊞</span> <span>Grid View</span>
            </button>
            <button type="button" class="view-segment-btn" id="btn-view-list" onclick="switchDirectoryView('list')">
              <span>☰</span> <span>List View</span>
            </button>
            <button type="button" class="view-segment-btn" id="btn-view-sector" onclick="switchDirectoryView('sector')">
              <span>🗂️</span> <span>By Sector</span>
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

      <!-- Sticky Proximity & Pin Map Dock -->
      <aside id="pane-map" class="map-dock mobile-hidden" style="margin-top:1.5rem;" aria-label="Interactive Business Map">
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
        <select id="filter-country" data-testid="filter-country"><option value="">All Countries</option></select>
        <select id="filter-radius"><option value="">Any Distance</option></select>
        <button type="button" id="btn-near-me"></button>
        <div class="quick-pills" data-testid="quick-pills">
          <button type="button" class="pill active" data-quick-filter="all" data-testid="quick-filter-all">All Verified</button>
          <button type="button" class="pill" data-quick-filter="open_now" data-testid="quick-filter-open-now">🟢 Open Now (EAT)</button>
          <button type="button" class="pill" data-quick-filter="has_perk" data-testid="quick-filter-has-perk">🎁 Busherian Alumni Perks</button>
          <button type="button" class="pill" data-quick-filter="b2b_ready" data-testid="quick-filter-b2b">🏢 B2B &amp; LPO Ready</button>
          <button type="button" class="pill" data-quick-filter="bookable" data-testid="quick-filter-bookable">📅 Book Appointment</button>
          <button type="button" class="pill" data-quick-filter="AHS" data-testid="quick-filter-AHS">🦅 AHS</button>
          <button type="button" class="pill" data-quick-filter="AGHS" data-testid="quick-filter-AGHS">🔦 AGHS</button>
        </div>
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
      <div class="detail-banner" role="img" aria-label="Photo or logo slot">
        <span id="detail-initials"><?= $requestedBiz ? htmlspecialchars(substr($requestedBiz['name'], 0, 2), ENT_QUOTES, 'UTF-8') : 'BB' ?></span>
        <small>Cover photo or logo goes here</small>
      </div>

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
              <?= ($requestedBiz['founder_role'] === 'co_founder' ? 'Co-Founded by ' : 'Founded by ') . htmlspecialchars((string)$requestedBiz['founder_name'], ENT_QUOTES, 'UTF-8') . ($requestedBiz['founder_school'] ? (' (' . htmlspecialchars((string)$requestedBiz['founder_school'], ENT_QUOTES, 'UTF-8') . ($requestedBiz['founder_year'] ? (' \'' . htmlspecialchars((string)$requestedBiz['founder_year'], ENT_QUOTES, 'UTF-8')) : '') . ')') : '') ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="detail-chips-row">
          <span class="detail-chip status-on" id="detail-hours-chip">🟢 Open Now</span>
          <span class="detail-chip school" id="detail-school-chip">🎓 Verified Alum · <?= $requestedBiz ? htmlspecialchars((string)$requestedBiz['school_badge'], ENT_QUOTES, 'UTF-8') : 'AHS' ?></span>
          <span class="detail-chip perk" id="detail-perk-chip" style="<?= ($requestedBiz && !empty($requestedBiz['alumni_perk'])) ? '' : 'display:none;' ?>">
            🎁 <?= $requestedBiz ? htmlspecialchars((string)$requestedBiz['alumni_perk'], ENT_QUOTES, 'UTF-8') : '' ?>
          </span>
        </div>

        <div class="detail-cta-bar">
          <a href="<?= ($requestedBiz && !empty($requestedBiz['whatsapp_number'])) ? ('https://wa.me/' . preg_replace('/[^0-9]/', '', (string)$requestedBiz['whatsapp_number']) . '?text=' . urlencode('Hello ' . $requestedBiz['name'])) : '#' ?>" id="detail-wa-btn" class="btn-action-primary btn-action-whatsapp" style="padding:0 20px;height:42px;" target="_blank" rel="noopener">
            <span>💬</span> WhatsApp
          </a>
          <a href="<?= ($requestedBiz && !empty($requestedBiz['phone_primary'])) ? ('tel:' . htmlspecialchars((string)$requestedBiz['phone_primary'], ENT_QUOTES, 'UTF-8')) : '#' ?>" id="detail-call-btn" class="btn-action-primary btn-action-call" style="padding:0 20px;height:42px;">
            <span>📞</span> Call
          </a>
          <a href="<?= ($requestedBiz && !empty($requestedBiz['google_maps_url'])) ? htmlspecialchars((string)$requestedBiz['google_maps_url'], ENT_QUOTES, 'UTF-8') : '#' ?>" id="detail-route-btn" target="_blank" rel="noopener" class="btn btn-outline" style="display:inline-flex;align-items:center;gap:6px;height:42px;">
            <span>🧭</span> Directions
          </a>
          <button type="button" id="detail-share-btn" class="btn btn-outline" style="display:inline-flex;align-items:center;gap:6px;height:42px;">
            <span>🔗</span> Share
          </button>
        </div>
      </div>
    </div>

    <!-- 2 Columns Grid (3fr / 2fr as in Kanyi prototype) -->
    <div class="detail-cols-grid">
      <!-- Column 1: Services, Photos, Alumni Recommendations -->
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

        <section class="detail-panel">
          <h2>Photos &amp; Premises</h2>
          <div class="photos-grid-3" id="detail-photos-grid">
            <div class="photo-ph-slot"><span>🏢</span><strong>Premises / Office</strong></div>
            <div class="photo-ph-slot"><span>🏗️</span><strong>Work &amp; Projects</strong></div>
            <div class="photo-ph-slot"><span>📐</span><strong>Team &amp; Craft</strong></div>
          </div>
          <span style="font-size:12px;color:#7E8D84;">Three to five photos of the premises, work, or products.</span>
        </section>

        <section class="detail-panel">
          <h2>Recommended by Busherians</h2>
          <div style="font-size:14px;color:#18201B;">
            <strong>Verified Alumni Biashara</strong>
            <div style="font-size:12.5px;color:#57645C;margin-top:2px;">Peer-vetted under the Busherian Biasharas Community Charter.</div>
          </div>
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
  </article>

  <!-- Rich Business Detail Modal -->
  <div id="biz-modal-backdrop" data-testid="biz-modal-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-label="Business Details">
    <div id="biz-modal-content" data-testid="biz-modal-content" class="modal-dialog"></div>
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
          <a href="/submit.php">List your biashara</a>
          <a href="<?= $currentOwner ? '/owner/index.php' : '/owner/login.php' ?>">Owner's page</a>
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
          <a href="javascript:void(0)" onclick="if(window.copyInviteText){window.copyInviteText();}">Invite a Busherian</a>
          <a href="javascript:void(0)" onclick="document.getElementById('pwa-install-modal').hidden=false">Install app</a>
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
