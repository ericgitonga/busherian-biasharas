<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\VettingPurger;
use BusherianBiasharas\Services\WebsiteEnricher;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

$pdo = Database::getConnection();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, true);

$defaultSectorId = !empty($taxonomyTree[0]['id']) ? (int)$taxonomyTree[0]['id'] : 1;
$defaultSpecId   = !empty($taxonomyTree[0]['specialties'][0]['id']) ? (int)$taxonomyTree[0]['specialties'][0]['id'] : 1;

$form = [
    'name' => '',
    'school_badge' => in_array(strtoupper((string)($_GET['school'] ?? '')), ['AHS', 'AGHS', 'BOTH'], true)
        ? strtoupper((string)$_GET['school'])
        : 'AHS',
    'status' => 'approved',
    'tagline' => '',
    'description' => '',
    'sector_id' => $defaultSectorId,
    'specialty_id' => $defaultSpecId,
    'secondary_sector_id' => '',
    'secondary_specialty_id' => '',
    'service_mode' => 'physical',
    'b2b_ready' => 0,
    'accepts_lpo' => 0,
    'alumni_perk' => '',
    'regulator_name' => '',
    'phone_primary' => '',
    'whatsapp_number' => '',
    'email_public' => '',
    'website_url' => trim((string)($_GET['url'] ?? '')),
    'building_name' => '',
    'street_road' => '',
    'area_neighborhood' => '',
    'city_county' => 'Nairobi',
    'landmark_directions' => '',
    'plus_code' => '',
    'google_maps_url' => '',
    'latitude' => '',
    'longitude' => '',
    'social_instagram' => '',
    'social_linkedin' => '',
    'social_x' => '',
    'social_facebook' => '',
    'social_tiktok' => '',
    'social_youtube' => '',
    'admin_provenance_basis' => 'alumni_direct_authorization',
    'admin_provenance_note' => '',
];

$autoExtractMsg = '';
$errorMsg = '';

// If admin navigated with ?url=..., extract metadata server-side immediately on load!
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $form['website_url'] !== '') {
    try {
        $lowerUrl = strtolower($form['website_url']);
        if (str_contains($lowerUrl, 'busherian-demo.co.ke')) {
            $demoHtml = '<!DOCTYPE html><html><head><title>Wakili Peak Advocates LLP</title>'
                . '<meta property="og:description" content="Corporate & Conveyancing Law Firm in Upper Hill, Nairobi."></head><body></body></html>';
            $extracted = WebsiteEnricher::extractFromHtml($demoHtml, $form['website_url'], $pdo);
        } else {
            $extracted = WebsiteEnricher::fetchAndExtract($form['website_url'], $pdo);
        }
        foreach ([
            'name', 'tagline', 'description', 'phone_primary', 'whatsapp_number',
            'email_public', 'website_url', 'street_road', 'area_neighborhood',
            'city_county', 'latitude', 'longitude', 'social_instagram',
            'social_linkedin', 'social_x', 'social_facebook', 'social_tiktok', 'social_youtube'
        ] as $k) {
            if (!empty($extracted[$k])) {
                $form[$k] = (string)$extracted[$k];
            }
        }
        if (!empty($extracted['suggested_category']['sector_id'])) {
            $form['sector_id'] = (int)$extracted['suggested_category']['sector_id'];
            $form['specialty_id'] = (int)$extracted['suggested_category']['specialty_id'];
        }
        $autoExtractMsg = 'Extracted ' . count($extracted['populated_fields'] ?? []) . ' fields from ' . $form['website_url'] . '. Review below and click "Publish Immediately".';
    } catch (\Throwable $e) {
        $errorMsg = 'Could not auto-extract from that URL (' . $e->getMessage() . '). You can still fill the details manually below.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $k) {
        if (isset($_POST[$k])) {
            $form[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
        }
    }
    $form['b2b_ready'] = !empty($_POST['b2b_ready']) ? 1 : 0;
    $form['accepts_lpo'] = !empty($_POST['accepts_lpo']) ? 1 : 0;
    $regSel = trim((string)($_POST['regulator_name'] ?? ''));
    $regCus = trim((string)($_POST['regulator_name_custom'] ?? ''));
    $form['regulator_name'] = ($regSel === 'Other Statutory Regulator') ? $regCus : $regSel;
    $isRegSpec = CategoryMatcher::isSpecialtyRegulated($pdo, (int)$form['specialty_id'])
        || CategoryMatcher::isSpecialtyRegulated($pdo, (int)($form['secondary_specialty_id'] ?? 0));
    if (!$isRegSpec) {
        $form['regulator_name'] = '';
    }

    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $errorMsg = 'Invalid security token (CSRF). Please reload and try again.';
    } elseif (trim((string)$form['name']) === '') {
        $errorMsg = 'Business Name is required.';
    } elseif ($isRegSpec && trim((string)$form['regulator_name']) === '') {
        $errorMsg = 'Professional Regulator is required for regulated industries.';
    } else {
        try {
            $newId = VettingPurger::createBusinessDirectly($pdo, array_merge($_POST, $form, ['require_provenance_basis' => true, 'require_adult_attestation' => true]));
            header('Location: /admin/index.php?added=' . $newId);
            exit;
        } catch (\Throwable $e) {
            $errorMsg = 'Could not save business: ' . $e->getMessage();
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Direct Add Business — Admin Console</title>
<?= bbiz_head_meta_tags('Direct Add Business — Busherian Biasharas Admin', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/admin/add-business.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/admin/index.php" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Direct Add Known Busherian Business</h1>
          <p class="brand-subtitle">Auto-Extract from Website URL &amp; Publish Directly (Bypasses Public Verification Queue)</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/admin/index.php" class="btn btn-outline-light">&larr; Back to Admin Console</a>
        <a href="/" class="btn btn-outline-light">Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:960px;margin:1.75rem auto;padding:0 1.25rem;">
    <?php if ($autoExtractMsg !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✨ <?= htmlspecialchars($autoExtractMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Step 1: Optional 1-Click Website URL Auto-Fill -->
    <section class="autofill-box">
      <h2 style="margin:0 0 0.35rem 0;font-size:1.02rem;color:#7B1113;">⚡ 1-Click Website URL Auto-Fill (Optional — Skip if Business Has No Website)</h2>
      <p style="margin:0 0 0.65rem 0;font-size:0.82rem;color:#4B5563;">
        Paste the business website URL to automatically pull its name, tagline, description, category, phone, email, address, coordinates, and social links.
      </p>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <input type="url" id="admin-autofill-url" class="input-control" style="flex:1;"
          value="<?= htmlspecialchars((string)$form['website_url'], ENT_QUOTES, 'UTF-8') ?>"
          placeholder="https://www.businesswebsite.co.ke">
        <button type="button" id="btn-admin-autofill" class="btn btn-crimson">✨ Extract &amp; Populate Fields</button>
      </div>
      <div id="admin-autofill-status" style="margin-top:0.5rem;font-size:0.82rem;display:none;"></div>
    </section>

    <form method="POST" action="/admin/add-business.php" enctype="multipart/form-data" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Business Name *</label>
          <input type="text" id="name" name="name" class="input-control" value="<?= htmlspecialchars((string)$form['name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">School Affiliation *</label>
          <select name="school_badge" class="select-control">
            <?php foreach (['AHS' => 'AHS', 'AGHS' => 'AGHS', 'BOTH' => 'AHS & AGHS'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $form['school_badge'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Initial Status</label>
          <select name="status" class="select-control">
            <option value="approved" <?= $form['status'] === 'approved' ? 'selected' : '' ?>>Approved (Publish Live Immediately)</option>
            <option value="quarantined_dpa" <?= $form['status'] === 'quarantined_dpa' ? 'selected' : '' ?>>Draft / Unpublished</option>
          </select>
        </div>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Short Tagline *</label>
        <input type="text" id="tagline" name="tagline" class="input-control" value="<?= htmlspecialchars((string)$form['tagline'], ENT_QUOTES, 'UTF-8') ?>" placeholder="One-sentence summary of what the business offers" required>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Full Description *</label>
        <textarea id="description" name="description" rows="3" class="input-control" required><?= htmlspecialchars((string)$form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <?= bbiz_render_specialty_finder($taxonomyTree) ?>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Level 1: Industry Sector *</label>
          <select id="sector_id" name="sector_id" class="select-control" required>
            <?php foreach ($taxonomyTree as $sec): ?>
              <option value="<?= (int)$sec['id'] ?>" <?= (int)$form['sector_id'] === (int)$sec['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$sec['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Level 2: Specialty *</label>
          <select id="specialty_id" name="specialty_id" class="select-control" required></select>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Service Mode</label>
          <select name="service_mode" class="select-control">
            <?php foreach (['physical' => 'Physical Premises', 'hybrid' => 'Hybrid (Physical + Online)', 'online' => 'Online / Cloud / Mobile'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $form['service_mode'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="regulated-fields-wrap" hidden style="display:none;margin-top:0.9rem;background:#F0FDF4;border:1px solid #059669;border-radius:10px;padding:0.85rem;">
        <label for="regulator_name" style="font-weight:700;font-size:0.84rem;color:#065F46;display:block;margin-bottom:0.25rem;">
          🛡️ Professional Regulator / Statutory Body * (Required for Regulated Industry)
        </label>
        <select id="regulator_name" name="regulator_name" class="select-control" disabled></select>
        <input type="text" id="regulator_name_custom" name="regulator_name_custom" class="input-control"
          placeholder="Specify statutory regulator..." style="margin-top:0.45rem;display:none;" disabled>
      </div>

      <?= bbiz_render_secondary_specialty_fields($taxonomyTree, !empty($form['secondary_sector_id']) ? (int)$form['secondary_sector_id'] : null, !empty($form['secondary_specialty_id']) ? (int)$form['secondary_specialty_id'] : null, (string)($form['regulator_name'] ?? '')) ?>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Primary Phone</label>
          <input type="text" id="phone_primary" name="phone_primary" class="input-control" value="<?= htmlspecialchars((string)$form['phone_primary'], ENT_QUOTES, 'UTF-8') ?>" placeholder="+2547...">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">WhatsApp Number</label>
          <input type="text" id="whatsapp_number" name="whatsapp_number" class="input-control" value="<?= htmlspecialchars((string)$form['whatsapp_number'], ENT_QUOTES, 'UTF-8') ?>" placeholder="+2547...">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Public Email</label>
          <input type="email" id="email_public" name="email_public" class="input-control" value="<?= htmlspecialchars((string)$form['email_public'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Website URL</label>
          <input type="url" id="website_url" name="website_url" class="input-control" value="<?= htmlspecialchars((string)$form['website_url'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Building / Premise</label>
          <input type="text" name="building_name" class="input-control" value="<?= htmlspecialchars((string)$form['building_name'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Street / Road</label>
          <input type="text" id="street_road" name="street_road" class="input-control" value="<?= htmlspecialchars((string)$form['street_road'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Neighborhood / Area</label>
          <input type="text" id="area_neighborhood" name="area_neighborhood" class="input-control" value="<?= htmlspecialchars((string)$form['area_neighborhood'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <?= bbiz_render_place_fields((string)($form['country'] ?? 'Kenya'), (string)($form['city_county'] ?? 'Nairobi')) ?>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label for="google_maps_url" style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Google Maps URL (Auto-extracts Pin)</label>
          <input type="url" id="google_maps_url" name="google_maps_url" class="input-control" value="<?= htmlspecialchars((string)$form['google_maps_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://maps.app.goo.gl/... or https://maps.google.com/?q=...">
        </div>
        <div>
          <label for="plus_code" style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Plus Code (Optional)</label>
          <input type="text" id="plus_code" name="plus_code" class="input-control" value="<?= htmlspecialchars((string)$form['plus_code'], ENT_QUOTES, 'UTF-8') ?>" placeholder="6GCRPPP3+M6">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Latitude</label>
          <input type="number" step="any" id="latitude" name="latitude" class="input-control" value="<?= htmlspecialchars((string)$form['latitude'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Longitude</label>
          <input type="number" step="any" id="longitude" name="longitude" class="input-control" value="<?= htmlspecialchars((string)$form['longitude'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Alumni Perk (Optional)</label>
          <input type="text" name="alumni_perk" class="input-control" value="<?= htmlspecialchars((string)$form['alumni_perk'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Instagram URL</label>
          <input type="url" id="social_instagram" name="social_instagram" class="input-control" value="<?= htmlspecialchars((string)$form['social_instagram'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">LinkedIn URL</label>
          <input type="url" id="social_linkedin" name="social_linkedin" class="input-control" value="<?= htmlspecialchars((string)$form['social_linkedin'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">X (Twitter) URL</label>
          <input type="url" id="social_x" name="social_x" class="input-control" value="<?= htmlspecialchars((string)$form['social_x'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Facebook URL</label>
          <input type="url" id="social_facebook" name="social_facebook" class="input-control" value="<?= htmlspecialchars((string)$form['social_facebook'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">TikTok URL</label>
          <input type="url" id="social_tiktok" name="social_tiktok" class="input-control" value="<?= htmlspecialchars((string)$form['social_tiktok'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:0.95rem;">
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="b2b_ready" value="1" <?= !empty($form['b2b_ready']) ? 'checked' : '' ?>> B2B Corporate Ready
        </label>
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="accepts_lpo" value="1" <?= !empty($form['accepts_lpo']) ? 'checked' : '' ?>> Accepts Corporate / Institutional LPOs
        </label>
      </div>

      <?= bbiz_render_hours_and_photos_fields() ?>

      <div style="margin-top:1.1rem;background:#F0FDF4;border:1px solid #059669;border-radius:10px;padding:1rem;">
        <div style="font-weight:800;font-size:0.88rem;color:#065F46;margin-bottom:0.45rem;">
          🔒 Kenya DPA 2019 Lawful Basis &amp; Listing Provenance Record *
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0.85rem;">
          <div>
            <label for="admin_provenance_basis" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Authorization / Source Basis *</label>
            <select id="admin_provenance_basis" name="admin_provenance_basis" class="select-control" required>
              <?php foreach ([
                  'alumni_direct_authorization' => 'Direct Alumni Owner Authorization',
                  'owner_written_request' => 'Written Owner Listing Request',
                  'public_alumni_business_registry' => 'Verified Public Corporate / Alumni Business Registry',
                  'verified_event_or_chapter_directory' => 'Verified Alumni Chapter / Trade Showcase Directory',
              ] as $bKey => $bLabel): ?>
                <option value="<?= $bKey ?>" <?= ($form['admin_provenance_basis'] ?? '') === $bKey ? 'selected' : '' ?>><?= htmlspecialchars($bLabel, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="admin_provenance_note" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Source / Consent Audit Note *</label>
            <input type="text" id="admin_provenance_note" name="admin_provenance_note" class="input-control"
              value="<?= htmlspecialchars((string)($form['admin_provenance_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              placeholder="e.g. Confirmed with founder via WhatsApp / email on 2026-11-12" required>
          </div>
        </div>
        <label style="display:flex;align-items:flex-start;gap:0.5rem;margin-top:0.75rem;font-size:0.82rem;color:#065F46;cursor:pointer;">
          <input type="checkbox" name="admin_adult_subject_attested" data-testid="admin-adult-attestation-checkbox" value="1" <?= !empty($_POST['admin_adult_subject_attested']) ? 'checked' : '' ?> required style="margin-top:0.2rem;">
          <span><strong>Adult-Only Data Attestation (Kenya DPA s. 33):</strong> I confirm that this curated listing and its uploaded media contain only adult (18+) business contacts/subjects and zero personal data or identifiable images of minors under 18. *</span>
        </label>
      </div>

      <div style="margin-top:1.35rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <button type="submit" class="btn btn-green" style="padding:0.75rem 1.6rem;font-size:0.95rem;">✓ Add &amp; Publish Immediately</button>
        <a href="/admin/index.php" class="btn btn-outline">Cancel</a>
      </div>
    </form>
  </main>

  <script>
    (function () {
      const SECTORS = <?= bbiz_json_for_html_script($taxonomyTree) ?>;
      const INITIAL_SPEC_ID = <?= (int)$form['specialty_id'] ?>;
      const sectorSel = document.getElementById('sector_id');
      const specSel = document.getElementById('specialty_id');

      const regulatedWrap = document.getElementById('regulated-fields-wrap');
      const regNameSel = document.getElementById('regulator_name');
      const regNameCustom = document.getElementById('regulator_name_custom');

      function syncCustomReg() {
        if (!regNameSel || !regNameCustom) return;
        const isOther = !regNameSel.disabled && regNameSel.value === 'Other Statutory Regulator';
        regNameCustom.style.display = isOther ? 'block' : 'none';
        regNameCustom.disabled = !isOther;
        regNameCustom.required = isOther;
        if (!isOther) regNameCustom.value = '';
      }

      function updateRegulatorVisibility() {
        if (!regulatedWrap || !regNameSel || !regNameCustom) return;
        const sec = SECTORS.find(s => String(s.id) === String(sectorSel.value));
        const sp = sec && Array.isArray(sec.specialties)
          ? sec.specialties.find(x => String(x.id) === String(specSel.value))
          : null;
        if (sp && sp.is_regulated) {
          regulatedWrap.style.display = 'block';
          regulatedWrap.removeAttribute('hidden');
          regNameSel.innerHTML = '';
          const opts = Array.isArray(sp.regulator_options) && sp.regulator_options.length > 0
            ? sp.regulator_options
            : [sp.regulator_default || 'Other Statutory Regulator'];
          opts.forEach(lbl => {
            const opt = document.createElement('option');
            opt.value = lbl;
            opt.textContent = lbl;
            if (sp.regulator_default && lbl === sp.regulator_default) opt.selected = true;
            regNameSel.appendChild(opt);
          });
          regNameSel.disabled = false;
          regNameSel.required = true;
          syncCustomReg();
        } else {
          regulatedWrap.style.display = 'none';
          regulatedWrap.setAttribute('hidden', 'hidden');
          regNameSel.innerHTML = '';
          regNameSel.value = '';
          regNameSel.disabled = true;
          regNameSel.required = false;
          regNameCustom.value = '';
          regNameCustom.style.display = 'none';
          regNameCustom.disabled = true;
          regNameCustom.required = false;
        }
      }

      if (regNameSel) regNameSel.addEventListener('change', syncCustomReg);
      specSel.addEventListener('change', updateRegulatorVisibility);

      function populateSpecs(sectorId, selectedSpecId) {
        specSel.innerHTML = '';
        const sec = SECTORS.find(s => String(s.id) === String(sectorId));
        if (!sec || !Array.isArray(sec.specialties)) return;
        sec.specialties.forEach(sp => {
          const opt = document.createElement('option');
          opt.value = sp.id;
          opt.textContent = sp.name;
          if (String(sp.id) === String(selectedSpecId)) opt.selected = true;
          specSel.appendChild(opt);
        });
        updateRegulatorVisibility();
      }

      sectorSel.addEventListener('change', () => populateSpecs(sectorSel.value, null));
      populateSpecs(sectorSel.value, INITIAL_SPEC_ID);

      const btnAuto = document.getElementById('btn-admin-autofill');
      const urlInput = document.getElementById('admin-autofill-url');
      const statusBox = document.getElementById('admin-autofill-status');

      if (btnAuto && urlInput) {
        btnAuto.addEventListener('click', async () => {
          const url = urlInput.value.trim();
          if (!url) return;
          btnAuto.disabled = true;
          statusBox.style.display = 'block';
          statusBox.style.color = '#114B3E';
          statusBox.textContent = 'Extracting details from website...';
          try {
            const csrf = document.getElementById('csrf_token').value;
            const res = await fetch('/api/autofill-url.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ url: url, csrf_token: csrf }),
            });
            const payload = await res.json();
            if (!res.ok || payload.status !== 'ok') throw new Error(payload.message || 'Extraction failed');
            const d = payload.data || {};
            ['name', 'tagline', 'description', 'phone_primary', 'whatsapp_number', 'email_public', 'website_url', 'street_road', 'area_neighborhood', 'city_county', 'latitude', 'longitude', 'social_instagram', 'social_linkedin', 'social_x', 'social_facebook', 'social_tiktok'].forEach(f => {
              const el = document.getElementById(f);
              if (el && d[f]) el.value = d[f];
            });
            if (d.suggested_category && d.suggested_category.sector_id) {
              sectorSel.value = String(d.suggested_category.sector_id);
              populateSpecs(d.suggested_category.sector_id, d.suggested_category.specialty_id);
            }
            statusBox.style.color = '#065F46';
            statusBox.textContent = '✓ Populated fields from ' + url + '. Review and click "Add & Publish Immediately".';
          } catch (e) {
            statusBox.style.color = '#DC2626';
            statusBox.textContent = '⚠️ ' + e.message;
          } finally {
            btnAuto.disabled = false;
          }
        });
      }
    })();
  </script>
  <script src="/assets/js/form-enhancements.js"></script>
</body>
</html>
