<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

$pdo = Database::getConnection();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, true);

$businessId = (int)($_REQUEST['id'] ?? 0);
if ($businessId <= 0) {
    header('Location: /admin/index.php');
    exit;
}

$savedMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $errorMsg = 'Invalid security token (CSRF). Please reload and try again.';
    } elseif (($_POST['post_action'] ?? '') === 'delete') {
        VettingPurger::deleteBusiness($pdo, $businessId);
        header('Location: /admin/index.php');
        exit;
    } else {
        $postData = $_POST;
        $regSel = trim((string)($postData['regulator_name'] ?? ''));
        $regCus = trim((string)($postData['regulator_name_custom'] ?? ''));
        $postData['regulator_name'] = ($regSel === 'Other Statutory Regulator') ? $regCus : $regSel;
        $isRegSpec = CategoryMatcher::isSpecialtyRegulated($pdo, (int)($postData['specialty_id'] ?? 0));
        if (!$isRegSpec) {
            $postData['regulator_name'] = '';
            $postData['regulator_license'] = '';
        }
        if ($isRegSpec && trim((string)$postData['regulator_name']) === '') {
            $errorMsg = 'Professional Regulator is required for regulated industries.';
        } else {
            try {
                VettingPurger::updateBusiness($pdo, $businessId, $postData);
                $savedMsg = 'Business record updated successfully.';
            } catch (\Throwable $e) {
                $errorMsg = 'Could not update business record. Please check required fields.';
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id");
$stmt->execute([':id' => $businessId]);
$biz = $stmt->fetch();
if (!$biz) {
    header('Location: /admin/index.php');
    exit;
}

$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Business #<?= (int)($biz['business_number'] ?? $biz['id']) ?> (<?= htmlspecialchars((string)($biz['business_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>) — Admin Console</title>
<?= bbiz_head_meta_tags('Edit Business — Busherian Biasharas Admin', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/admin/edit-business.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/admin/index.php" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Edit Business #<?= (int)($biz['business_number'] ?? $biz['id']) ?>: <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="brand-subtitle">Unique Business ID: <code><?= htmlspecialchars((string)($biz['business_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code> • Admin Record Editor &amp; 1-Click Website Re-Extraction</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/admin/index.php" class="btn btn-outline-light">&larr; Back to Admin Console</a>
        <a href="/" class="btn btn-outline-light">Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:920px;margin:1.75rem auto;padding:0 1.25rem;">
    <?php if ($savedMsg !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($savedMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- 1-Click Website URL Re-Extraction inside Admin Editor -->
    <section class="autofill-box">
      <h2 style="margin:0 0 0.4rem 0;font-size:1rem;color:#7B1113;">⚡ Update / Re-Extract Details from Website URL</h2>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <input type="url" id="admin-autofill-url" class="input-control" style="flex:1;"
          value="<?= htmlspecialchars((string)($biz['website_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="https://www.businesswebsite.co.ke">
        <button type="button" id="btn-admin-autofill" class="btn btn-crimson">✨ Extract &amp; Populate Fields</button>
      </div>
      <div id="admin-autofill-status" style="margin-top:0.5rem;font-size:0.82rem;display:none;"></div>
    </section>

    <form method="POST" action="/admin/edit-business.php?id=<?= (int)$biz['id'] ?>" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="post_action" value="update">

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Business Name *</label>
          <input type="text" id="name" name="name" class="input-control" value="<?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Listing Status *</label>
          <select name="status" class="select-control">
            <?php foreach (['approved' => 'Approved (Published)', 'quarantined_dpa' => 'Unpublished / Taken Down', 'pending' => 'Pending Review', 'rejected' => 'Rejected'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $biz['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">School Badge *</label>
          <select name="school_badge" class="select-control">
            <?php foreach (['AHS' => 'AHS', 'AGHS' => 'AGHS', 'BOTH' => 'AHS & AGHS'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $biz['school_badge'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Tagline *</label>
        <input type="text" id="tagline" name="tagline" class="input-control" value="<?= htmlspecialchars((string)$biz['tagline'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Full Description *</label>
        <textarea id="description" name="description" rows="3" class="input-control" required><?= htmlspecialchars((string)$biz['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Level 1: Industry Sector *</label>
          <select id="sector_id" name="sector_id" class="select-control" required>
            <?php foreach ($taxonomyTree as $sec): ?>
              <option value="<?= (int)$sec['id'] ?>" <?= (int)$biz['sector_id'] === (int)$sec['id'] ? 'selected' : '' ?>>
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
            <?php foreach (['physical' => 'Physical', 'hybrid' => 'Hybrid', 'online' => 'Online'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $biz['service_mode'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
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

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Primary Phone</label>
          <input type="text" id="phone_primary" name="phone_primary" class="input-control" value="<?= htmlspecialchars((string)$biz['phone_primary'], ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">WhatsApp Number</label>
          <input type="text" id="whatsapp_number" name="whatsapp_number" class="input-control" value="<?= htmlspecialchars((string)($biz['whatsapp_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Public Email</label>
          <input type="email" id="email_public" name="email_public" class="input-control" value="<?= htmlspecialchars((string)($biz['email_public'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Website URL</label>
          <input type="url" id="website_url" name="website_url" class="input-control" value="<?= htmlspecialchars((string)($biz['website_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Building Name</label>
          <input type="text" name="building_name" class="input-control" value="<?= htmlspecialchars((string)($biz['building_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Street / Road</label>
          <input type="text" id="street_road" name="street_road" class="input-control" value="<?= htmlspecialchars((string)($biz['street_road'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Neighborhood / Area</label>
          <input type="text" id="area_neighborhood" name="area_neighborhood" class="input-control" value="<?= htmlspecialchars((string)($biz['area_neighborhood'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">City / County *</label>
          <input type="text" id="city_county" name="city_county" class="input-control" value="<?= htmlspecialchars((string)$biz['city_county'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Latitude</label>
          <input type="number" step="any" id="latitude" name="latitude" class="input-control" value="<?= htmlspecialchars((string)($biz['latitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Longitude</label>
          <input type="number" step="any" id="longitude" name="longitude" class="input-control" value="<?= htmlspecialchars((string)($biz['longitude'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Alumni Perk</label>
          <input type="text" name="alumni_perk" class="input-control" value="<?= htmlspecialchars((string)($biz['alumni_perk'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Instagram URL</label>
          <input type="url" id="social_instagram" name="social_instagram" class="input-control" value="<?= htmlspecialchars((string)($biz['social_instagram'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">LinkedIn URL</label>
          <input type="url" id="social_linkedin" name="social_linkedin" class="input-control" value="<?= htmlspecialchars((string)($biz['social_linkedin'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">X (Twitter) URL</label>
          <input type="url" id="social_x" name="social_x" class="input-control" value="<?= htmlspecialchars((string)($biz['social_x'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Facebook URL</label>
          <input type="url" id="social_facebook" name="social_facebook" class="input-control" value="<?= htmlspecialchars((string)($biz['social_facebook'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">TikTok URL</label>
          <input type="url" id="social_tiktok" name="social_tiktok" class="input-control" value="<?= htmlspecialchars((string)($biz['social_tiktok'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Google Maps URL (Auto-extracts Pin)</label>
          <input type="url" name="google_maps_url" class="input-control" value="<?= htmlspecialchars((string)($biz['google_maps_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Plus Code</label>
          <input type="text" name="plus_code" class="input-control" value="<?= htmlspecialchars((string)($biz['plus_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Landmark / Directions</label>
          <input type="text" name="landmark_directions" class="input-control" value="<?= htmlspecialchars((string)($biz['landmark_directions'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:0.9rem;">
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="b2b_ready" value="1" <?= !empty($biz['b2b_ready']) ? 'checked' : '' ?>> B2B Corporate Ready
        </label>
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="accepts_lpo" value="1" <?= !empty($biz['accepts_lpo']) ? 'checked' : '' ?>> Accepts Corporate / Institutional LPOs
        </label>
      </div>

      <div style="margin-top:1.35rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <button type="submit" class="btn btn-green" style="padding:0.7rem 1.5rem;">💾 Save Changes</button>
        <a href="/admin/index.php" class="btn btn-outline">Cancel</a>
      </div>
    </form>

    <!-- Permanent Delete Box -->
    <form method="POST" action="/admin/edit-business.php?id=<?= (int)$biz['id'] ?>" style="margin-top:1.5rem;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:12px;padding:1.1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;" onsubmit="return confirm('Permanently DELETE this business record from the database?');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="post_action" value="delete">
      <div>
        <strong style="color:#991B1B;">🗑️ Danger Zone: Permanently Delete Record</strong>
        <div style="font-size:0.82rem;color:#B91C1C;">Completely removes this business from the directory database.</div>
      </div>
      <button type="submit" class="btn btn-crimson">Delete Business Permanently</button>
    </form>
  </main>

  <script>
    (function () {
      const SECTORS = <?= json_encode($taxonomyTree, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
      const INITIAL_SPEC_ID = <?= (int)$biz['specialty_id'] ?>;
      const INITIAL_REGULATOR = <?= json_encode((string)($biz['regulator_name'] ?? ''), JSON_HEX_TAG) ?>;
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

      function updateRegulatorVisibility(preferredReg) {
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
          const targetVal = (preferredReg && preferredReg.trim() !== '') ? preferredReg.trim() : (sp.regulator_default || opts[0]);
          let matched = false;
          opts.forEach(lbl => {
            const opt = document.createElement('option');
            opt.value = lbl;
            opt.textContent = lbl;
            if (lbl === targetVal) {
              opt.selected = true;
              matched = true;
            }
            regNameSel.appendChild(opt);
          });
          if (!matched && preferredReg && preferredReg.trim() !== '') {
            regNameSel.value = 'Other Statutory Regulator';
            regNameCustom.value = preferredReg.trim();
          }
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
      specSel.addEventListener('change', () => updateRegulatorVisibility(''));

      function populateSpecs(sectorId, selectedSpecId, preferredReg) {
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
        updateRegulatorVisibility(preferredReg || '');
      }

      sectorSel.addEventListener('change', () => populateSpecs(sectorSel.value, null, ''));
      populateSpecs(sectorSel.value, INITIAL_SPEC_ID, INITIAL_REGULATOR);

      const btnAuto = document.getElementById('btn-admin-autofill');
      const urlInput = document.getElementById('admin-autofill-url');
      const statusBox = document.getElementById('admin-autofill-status');

      if (btnAuto && urlInput) {
        btnAuto.addEventListener('click', async () => {
          const url = urlInput.value.trim();
          if (!url) return;
          btnAuto.disabled = true;
          statusBox.style.display = 'block';
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
            statusBox.textContent = '✓ Populated fields from ' + url + '. Click Save Changes to persist.';
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
</body>
</html>
