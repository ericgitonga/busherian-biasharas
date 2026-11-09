<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\HoursEngine;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);

// Load initial approved businesses (strictly zero vetting columns)
$stmt = $pdo->query("
    SELECT
        b.id, b.name, b.slug, b.tagline, b.description,
        b.school_badge, b.sector_id, b.specialty_id,
        sec.name AS sector_name, spec.name AS specialty_name,
        b.service_mode, b.alumni_perk, b.b2b_ready, b.accepts_lpo,
        b.regulator_name, b.regulator_license,
        b.branch_name, b.building_name, b.floor_suite, b.street_road,
        b.area_neighborhood, b.city_county, b.landmark_directions,
        b.plus_code, b.latitude, b.longitude, b.google_maps_url,
        b.operating_hours_json,
        b.phone_primary, b.whatsapp_number, b.email_public, b.website_url,
        b.social_instagram, b.social_linkedin, b.social_x,
        b.social_facebook, b.social_tiktok, b.social_youtube
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    WHERE b.status = 'approved'
    ORDER BY b.id ASC
");
$rows = $stmt->fetchAll();

$initialBusinesses = [];
foreach ($rows as $r) {
    $initialBusinesses[] = [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'tagline' => $r['tagline'],
        'description' => $r['description'],
        'school_badge' => $r['school_badge'],
        'sector_id' => (int)$r['sector_id'],
        'sector_name' => $r['sector_name'],
        'specialty_id' => (int)$r['specialty_id'],
        'specialty_name' => $r['specialty_name'],
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
        'city_county' => $r['city_county'],
        'landmark_directions' => $r['landmark_directions'],
        'plus_code' => $r['plus_code'],
        'latitude' => $r['latitude'] !== null ? (float)$r['latitude'] : null,
        'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
        'google_maps_url' => $r['google_maps_url'],
        'distance_km' => null,
        'hours_status' => HoursEngine::evaluateStatus((string)$r['operating_hours_json']),
        'phone_primary' => $r['phone_primary'],
        'whatsapp_number' => $r['whatsapp_number'],
        'email_public' => $r['email_public'],
        'website_url' => $r['website_url'],
        'socials' => array_filter([
            'instagram' => $r['social_instagram'],
            'linkedin'  => $r['social_linkedin'],
            'x'         => $r['social_x'],
            'facebook'  => $r['social_facebook'],
            'tiktok'    => $r['social_tiktok'],
            'youtube'   => $r['social_youtube'],
        ]),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS &amp; AGHS)</title>
  <meta name="description" content="Discover and support verified businesses owned by alumni of Alliance High School (AHS) and Alliance Girls High School (AGHS) — Strong to Serve &amp; Walk in the Light.">
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
          <p class="brand-subtitle">Verified Alumni Business Directory of the Alliances (AHS &amp; AGHS — Bush)</p>
        </div>
      </a>
      <nav class="header-actions" aria-label="Primary Navigation">
        <button type="button" id="btn-export-shortlist" class="btn btn-outline-light" title="Export selected B2B vendors as CSV">
          🏢 B2B Shortlist (<span id="shortlist-count">0</span>/5)
        </button>
        <a href="/privacy.php" class="btn btn-outline-light">🔒 Privacy &amp; DPA Notice</a>
        <a href="/submit.php" class="btn btn-gold">+ List Your Biashara</a>
      </nav>
    </div>
  </header>

  <!-- Search & 2-Level Category Filter Dock -->
  <section class="filter-dock" aria-label="Directory Search and Category Filters">
    <div class="filter-inner">
      <div class="search-grid">
        <div>
          <label for="search-q" style="display:none;">Search by keyword, service, or neighborhood</label>
          <input type="search" id="search-q" class="input-control"
            placeholder="Search businesses, services, or Swahili terms (e.g., wakili, daktari, solar, coffee, Westlands)...">
        </div>
        <div>
          <label for="filter-sector" style="display:none;">Level 1 Industry Sector</label>
          <select id="filter-sector" class="select-control">
            <option value="">All 14 Industry Sectors (Level 1)</option>
            <?php foreach ($taxonomyTree as $sector): ?>
              <option value="<?= (int)$sector['id'] ?>"><?= htmlspecialchars((string)$sector['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="filter-specialty" style="display:none;">Level 2 Specialty</label>
          <select id="filter-specialty" class="select-control">
            <option value="">All Specialties (Level 2)</option>
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

      <div class="quick-pills">
        <button type="button" class="pill active" data-quick-filter="all">All Verified</button>
        <button type="button" class="pill" data-quick-filter="open_now">🟢 Open Now (EAT)</button>
        <button type="button" class="pill" data-quick-filter="has_perk">🎁 Busherian Alumni Perks</button>
        <button type="button" class="pill" data-quick-filter="b2b_ready">🏢 B2B &amp; LPO Ready</button>
        <button type="button" class="pill" data-quick-filter="AHS">🦅 AHS — Bush</button>
        <button type="button" class="pill" data-quick-filter="AGHS">🔦 AGHS — Bush</button>
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
        <strong id="results-count" style="font-size:0.95rem;color:#114B3E;">
          <?= count($initialBusinesses) ?> Verified Busherian Biasharas
        </strong>
        <span style="font-size:0.78rem;color:#6B7280;">
          Click any card or map pin for Website, WhatsApp, Call, Hours &amp; Exact Pin
        </span>
      </div>

      <div id="cards-grid" class="cards-grid"></div>
    </section>

    <!-- Right Pane: Sticky Interactive Map -->
    <aside id="pane-map" class="map-dock mobile-hidden" aria-label="Interactive Business Map">
      <div class="map-toolbar">
        <strong style="font-size:0.85rem;color:#114B3E;">🗺️ Live Busherian Proximity &amp; Pin Map</strong>
        <span style="font-size:0.74rem;color:#6B7280;">Green = Open Now • Crimson = Closed</span>
      </div>
      <div id="map-canvas" class="map-canvas"></div>
    </aside>
  </main>

  <!-- Rich Business Detail Modal -->
  <div id="biz-modal-backdrop" class="modal-backdrop" role="dialog" aria-modal="true" aria-label="Business Details">
    <div id="biz-modal-content" class="modal-dialog"></div>
  </div>

  <!-- Footer -->
  <footer style="max-width:1440px;margin:2rem auto 0 auto;padding:1.5rem 1.25rem;border-top:1px solid #E5E7EB;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;font-size:0.82rem;color:#6B7280;">
    <div>
      <strong>Busherian Biasharas</strong> — Strong to Serve &amp; Walk in the Light.
      <span>Strictly zero public display or retention of Alumni House, Year Left, or School Number.</span>
    </div>
    <div style="display:flex;gap:1rem;">
      <a href="/submit.php" style="color:#7B1113;font-weight:600;">Register a Business</a>
      <a href="/privacy.php">Kenya DPA 2019 Privacy Notice</a>
      <a href="/takedown.php">Section 40 Takedown / Rectification</a>
      <a href="/admin/index.php">Admin Triage</a>
    </div>
  </footer>

  <script>
    window.INITIAL_SECTORS = <?= json_encode($taxonomyTree, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
    window.INITIAL_BUSINESSES = <?= json_encode($initialBusinesses, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="/assets/js/directory.js"></script>
</body>
</html>
