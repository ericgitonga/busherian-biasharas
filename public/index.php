<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhotoUploader;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$currentOwner = OwnerAuth::getCurrentOwner($pdo);
$isAdminVisitor = AdminAuth::isAuthenticated();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);
$placeHierarchy = LocationEngine::getPlaceHierarchy();

// Load bounded initial page-1 approved businesses (#180: LIMIT 10 OFFSET 0, zero N+1, zero vetting columns)
$initialPerPage = 10;
$totalApprovedCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    WHERE b.status = 'approved'
")->fetchColumn();
$initialTotalPages = max(1, (int)ceil($totalApprovedCount / $initialPerPage));

$stmt = $pdo->query("
    SELECT
        b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
        b.school_badge, b.sector_id, b.specialty_id,
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
        b.social_facebook, b.social_tiktok, b.social_youtube
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    LEFT JOIN categories sec2 ON sec2.id = b.secondary_sector_id AND sec2.status = 'approved'
    LEFT JOIN categories spec2 ON spec2.id = b.secondary_specialty_id AND spec2.status = 'approved'
    WHERE b.status = 'approved'
    ORDER BY b.business_number ASC, b.id ASC
    LIMIT 10 OFFSET 0
");
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
        'landmark_directions' => $r['landmark_directions'],
        'plus_code' => $r['plus_code'],
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'google_maps_url' => $r['google_maps_url'],
        'distance_km' => null,
        'hours_status' => HoursEngine::evaluateStatus((string)$r['operating_hours_json']),
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
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS &amp; AGHS)</title>
  <meta name="description" content="Discover and support verified businesses owned by alumni of Alliance High School (AHS) and Alliance Girls High School (AGHS) — Strong to Serve &amp; Walk in the Light.">
<?= bbiz_head_meta_tags('Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS)', 'Discover and support verified businesses owned by alumni of Alliance High School (AHS) and Alliance Girls High School (AGHS) — Strong to Serve & Walk in the Light.', '/') ?>
  <link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

  <!-- Heritage Header -->
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
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

  <!-- Search & 2-Level Category Filter Dock -->
  <div id="pwa-offline-banner" data-testid="pwa-offline-banner" class="pwa-offline-banner" role="status" aria-live="polite" hidden>
    📶 <strong>Offline Mode:</strong> Showing your saved Busherian Biasharas snapshot. Connect to the internet for live EAT hours, map routes &amp; latest updates.
  </div>
  <section class="filter-dock" aria-label="Directory Search and Category Filters">
    <div class="filter-inner">
      <div class="search-grid">
        <div class="search-input-wrap">
          <label for="search-q" style="display:none;">Search by keyword, service, or neighborhood</label>
          <input type="text" id="search-q" data-testid="search-input" class="input-control search-input-with-clear"
            placeholder="Search businesses, services, or Swahili terms (e.g., wakili, daktari, solar, coffee, Westlands)...">
          <button type="button" id="btn-clear-search" data-testid="search-clear-btn" class="search-clear-btn" aria-label="Clear search" title="Clear search" style="display:none;">✕</button>
        </div>
        <div>
          <label for="filter-sector" style="display:none;">Industry Sector</label>
          <select id="filter-sector" data-testid="filter-sector" class="select-control">
            <option value="">All Industry Sectors</option>
            <?php foreach ($taxonomyTree as $sector): ?>
              <option value="<?= (int)$sector['id'] ?>"><?= htmlspecialchars((string)$sector['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="filter-specialty" style="display:none;">Specialty</label>
          <select id="filter-specialty" data-testid="filter-specialty" class="select-control">
            <option value="">All Specialties</option>
          </select>
        </div>
        <div>
          <label for="filter-country" style="display:none;">Country</label>
          <select id="filter-country" data-testid="filter-country" class="select-control" aria-label="Filter by Country">
            <option value="">🌍 All Countries</option>
            <?php foreach (array_keys($placeHierarchy) as $cName): ?>
              <option value="<?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="filter-city" style="display:none;">City / County / State</label>
          <select id="filter-city" data-testid="filter-city" class="select-control" aria-label="Filter by City, County, or State">
            <option value="">📍 All Cities / Counties / States</option>
          </select>
        </div>
        <div style="display:flex;gap:0.45rem;">
          <button type="button" id="btn-near-me" class="btn btn-green" style="white-space:nowrap;">📍 Near Me</button>
          <select id="filter-radius" class="select-control" aria-label="Distance Radius" style="width:auto;">
            <option value="">Any Distance</option>
            <option value="2">Within 2 km</option>
            <option value="5">Within 5 km</option>
            <option value="10">Within 10 km</option>
            <option value="25">Within 25 km</option>
          </select>
        </div>
      </div>

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
  </section>

  <!-- Main Split-Screen Workspace -->
  <main class="directory-workspace">
    <div class="mobile-view-toggle">
      <button type="button" id="btn-mobile-list" class="btn btn-crimson" style="flex:1;">📋 Directory Cards</button>
      <button type="button" id="btn-mobile-map" class="btn btn-outline" style="flex:1;">🗺️ Interactive Map</button>
    </div>

    <!-- Left Pane: Minimalist Scannable Cards -->
    <section id="pane-results" class="results-pane" aria-label="Business Results">
      <div class="results-header">
        <div>
          <strong id="results-count" data-testid="results-count" style="font-size:0.95rem;color:#114B3E;display:block;">
            <?= $totalApprovedCount > 0 ? ('Showing 1–' . count($initialBusinesses) . ' of ' . $totalApprovedCount . ' Verified Busherian Biasharas') : '0 Verified Busherian Biasharas' ?>
          </strong>
          <span style="font-size:0.76rem;color:#6B7280;">
            Click any card or map pin for Website, WhatsApp, Call, Hours &amp; Exact Pin
          </span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
          <label for="per-page-select" style="font-size:0.78rem;font-weight:700;color:#374151;">Show:</label>
          <select id="per-page-select" data-testid="per-page-select" class="select-control" aria-label="Listings per page" style="width:auto;padding:0.32rem 0.6rem;font-size:0.8rem;">
            <option value="10" selected>10 per page</option>
            <option value="25">25 per page</option>
            <option value="50">50 per page</option>
            <option value="100">100 per page</option>
          </select>
        </div>
      </div>

      <div id="cards-grid" data-testid="cards-grid" class="cards-grid"></div>
      <nav id="directory-pagination" data-testid="directory-pagination" aria-label="Directory Pagination" style="margin-top:1rem;"></nav>
    </section>

    <!-- Right Pane: Sticky Interactive Map -->
    <aside id="pane-map" class="map-dock mobile-hidden" aria-label="Interactive Business Map">
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
  </main>

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

  <!-- Footer -->
  <footer style="max-width:1440px;margin:2rem auto 0 auto;padding:1.5rem 1.25rem;border-top:1px solid #E5E7EB;font-size:0.82rem;color:#6B7280;">
    <div class="footer-charter-strip" data-testid="footer-charter-strip">
      <span>
        📜 <strong>100% Free-Forever Core Directory:</strong> Verified AHS &amp; AGHS standard listings, map pins, direct contacts &amp; self-served bookings carry <strong>zero listing or commission fees</strong>.
      </span>
      <a href="/charter.php" id="btn-open-charter-modal" data-testid="btn-open-charter-modal" class="footer-charter-btn">Read Community Charter</a>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
      <div>
        <strong>Busherian Biasharas</strong> — Strong to Serve &amp; Walk in the Light.
      </div>
      <div style="display:flex;gap:1rem;flex-wrap:wrap;">
        <a href="/submit.php" style="color:#7B1113;font-weight:600;">Register a Business</a>
        <a href="/charter.php" data-testid="footer-charter-link">Community Charter</a>
        <a href="/privacy.php">Privacy Notice</a>
        <a href="/takedown.php">Report or Remove a Listing</a>
      </div>
    </div>
  </footer>

  <script>
    window.INITIAL_SECTORS = <?= bbiz_json_for_html_script($taxonomyTree) ?>;
    window.PLACE_HIERARCHY = <?= bbiz_json_for_html_script($placeHierarchy) ?>;
    window.PLACE_UNIT_LABELS = <?= bbiz_json_for_html_script(LocationEngine::getPlaceUnitLabels()) ?>;
    window.INITIAL_BUSINESSES = <?= bbiz_json_for_html_script($initialBusinesses) ?>;
    window.INITIAL_PAGINATION = <?= bbiz_json_for_html_script($initialPagination) ?>;
  </script>
  <script src="/assets/vendor/leaflet/leaflet.js"></script>
  <script src="/assets/js/consent-metrics.js"></script>
  <script src="/assets/js/directory.js"></script>
</body>
</html>
