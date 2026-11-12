<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhotoUploadValidationException;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$owner = OwnerAuth::requireOwner($pdo);
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);

$businessId = (int)($_REQUEST['id'] ?? 0);
if ($businessId <= 0 || !OwnerAuth::canOwnerEditBusiness($pdo, (int)$owner['id'], $businessId)) {
    header('Location: /owner/index.php');
    exit;
}

$savedMsg = '';
$errorMsg = '';
if (isset($_GET['claimed'])) {
    $savedMsg = 'This business is now permanently linked to your Owner Account! You can review and update its details anytime below.';
}

$curNameStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id LIMIT 1");
$curNameStmt->execute([':id' => $businessId]);
$currentBizNameForCheck = (string)($curNameStmt->fetchColumn() ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $errorMsg = 'Invalid security token (CSRF). Please reload and try again.';
    } elseif (($_POST['post_action'] ?? '') === 'delete') {
        $typedConfirm = trim((string)($_POST['confirm_business_name'] ?? ''));
        if ($typedConfirm === '' || $typedConfirm !== trim($currentBizNameForCheck)) {
            $errorMsg = 'Deletion cancelled: You must type the exact business name to confirm permanent deletion.';
        } else {
            VettingPurger::deleteBusiness($pdo, $businessId, 'owner_portal', 'owner:' . (int)$owner['id'], (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
            header('Location: /owner/index.php?deleted=1');
            exit;
        }
    } else {
        $postData = $_POST;
        $regSel = trim((string)($postData['regulator_name'] ?? ''));
        $regCus = trim((string)($postData['regulator_name_custom'] ?? ''));
        $postData['regulator_name'] = ($regSel === 'Other Statutory Regulator') ? $regCus : $regSel;
        $isRegSpec = CategoryMatcher::isSpecialtyRegulated($pdo, (int)($postData['specialty_id'] ?? 0));
        if (!$isRegSpec) {
            $postData['regulator_name'] = '';
        }
        if ($isRegSpec && trim((string)$postData['regulator_name']) === '') {
            $errorMsg = 'Professional Regulator is required for regulated industries.';
        } else {
            try {
                $res = VettingPurger::updateBusinessByOwner($pdo, $businessId, $owner, $postData);
                if (!empty($res['admin_notified'])) {
                    $savedMsg = 'Your changes are live immediately! Because the Business Name or Category was changed, a courtesy notification was also sent to the directory admin.';
                } else {
                    $savedMsg = 'Your business listing has been updated and is live on the directory!';
                }
            } catch (PhotoUploadValidationException|\InvalidArgumentException $e) {
                $errorMsg = $e->getMessage();
            } catch (\Throwable $e) {
                error_log('[owner/edit-business.php] Update failed: ' . $e->getMessage());
                $errorMsg = 'Could not save changes. Please check required fields.';
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $businessId]);
$biz = $stmt->fetch();
if (!$biz) {
    header('Location: /owner/index.php');
    exit;
}

$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?> — Owner Portal</title>
<?= bbiz_head_meta_tags('Edit Business — Busherian Biasharas Owner Portal', 'Update your verified Busherian Biashara listing.', '/owner/edit-business.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/owner/index.php" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Manage Listing: <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="brand-subtitle">Signed in as <?= htmlspecialchars((string)$owner['email'], ENT_QUOTES, 'UTF-8') ?> • Changes Go Live Immediately</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/owner/index.php" class="btn btn-outline-light">&larr; My Businesses</a>
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

    <!-- 1-Click Website URL Auto-Fill -->
    <section class="autofill-box">
      <h2 style="margin:0 0 0.4rem 0;font-size:1rem;color:#7B1113;">⚡ Refresh Details from Your Website URL</h2>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <input type="url" id="owner-autofill-url" class="input-control" style="flex:1;"
          value="<?= htmlspecialchars((string)($biz['website_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
          placeholder="https://www.yourbusiness.co.ke">
        <button type="button" id="btn-owner-autofill" class="btn btn-crimson">✨ Extract from Website</button>
      </div>
      <div id="owner-autofill-status" style="margin-top:0.5rem;font-size:0.82rem;display:none;"></div>
    </section>

    <form method="POST" action="/owner/edit-business.php?id=<?= (int)$biz['id'] ?>" enctype="multipart/form-data" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Business Name *</label>
          <input type="text" id="name" name="name" class="input-control" value="<?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>" required>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Service Mode</label>
          <select name="service_mode" class="select-control">
            <?php foreach (['physical' => 'Physical Location / Walk-In', 'hybrid' => 'Hybrid (Physical & Online)', 'online' => 'Online / Remote Service'] as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $biz['service_mode'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Short Tagline *</label>
        <input type="text" id="tagline" name="tagline" class="input-control" value="<?= htmlspecialchars((string)$biz['tagline'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div style="margin-top:0.9rem;">
        <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Full Business Description *</label>
        <textarea id="description" name="description" rows="3" class="input-control" required><?= htmlspecialchars((string)$biz['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0.9rem;margin-top:0.9rem;">
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Industry Sector *</label>
          <select id="sector_id" name="sector_id" class="select-control" required>
            <?php foreach ($taxonomyTree as $sec): ?>
              <option value="<?= (int)$sec['id'] ?>" <?= (int)$biz['sector_id'] === (int)$sec['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$sec['name'], ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Specialty *</label>
          <select id="specialty_id" name="specialty_id" class="select-control" required></select>
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
        <?= bbiz_render_place_fields((string)($biz['country'] ?? 'Kenya'), (string)($biz['city_county'] ?? 'Nairobi')) ?>
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
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Busherian Alumni Perk / Discount</label>
          <input type="text" name="alumni_perk" class="input-control" value="<?= htmlspecialchars((string)($biz['alumni_perk'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 10% off for fellow Busherians">
        </div>
        <div>
          <label for="google_maps_url" style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Google Maps URL (Auto-fills Latitude &amp; Longitude)</label>
          <input type="url" id="google_maps_url" name="google_maps_url" class="input-control" value="<?= htmlspecialchars((string)($biz['google_maps_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://maps.app.goo.gl/... or https://www.google.com/maps?q=...">
        </div>
        <div>
          <label for="plus_code" style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Google Plus Code (Optional)</label>
          <input type="text" id="plus_code" name="plus_code" class="input-control" value="<?= htmlspecialchars((string)($biz['plus_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. 6GCRPPP3+9M">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.84rem;display:block;margin-bottom:0.25rem;">Landmark / Directions Cue</label>
          <input type="text" name="landmark_directions" class="input-control" value="<?= htmlspecialchars((string)($biz['landmark_directions'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
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

      <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:0.9rem;">
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="b2b_ready" value="1" <?= !empty($biz['b2b_ready']) ? 'checked' : '' ?>> B2B Corporate Ready
        </label>
        <label style="font-size:0.85rem;font-weight:600;display:flex;align-items:center;gap:0.4rem;">
          <input type="checkbox" name="accepts_lpo" value="1" <?= !empty($biz['accepts_lpo']) ? 'checked' : '' ?>> Accepts Corporate / Institutional LPOs
        </label>
      </div>

      <?= bbiz_render_hours_and_photos_fields((string)($biz['operating_hours_json'] ?? ''), isset($biz['photos_json']) ? (string)$biz['photos_json'] : null) ?>

      <div style="margin-top:1.35rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <button type="submit" class="btn btn-green" style="padding:0.7rem 1.5rem;">💾 Save &amp; Publish Changes Immediately</button>
        <a href="/owner/index.php" class="btn btn-outline">Back to My Businesses</a>
      </div>
    </form>

    <!-- GitHub-Style Danger Zone: Delete Listing (#126, #127) -->
    <section id="danger-zone" style="margin-top:1.5rem;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:12px;padding:1.1rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
      <div>
        <strong style="color:#991B1B;">🗑️ Danger Zone: Delete This Business Listing</strong>
        <div style="font-size:0.82rem;color:#B91C1C;">Permanently removes this business listing from the directory. Once deleted, it cannot be recovered.</div>
      </div>
      <button type="button" class="btn btn-crimson"
        data-open-delete-modal="1"
        data-delete-biz-id="<?= (int)$biz['id'] ?>"
        data-delete-biz-name="<?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>"
        data-delete-action-url="/owner/edit-business.php?id=<?= (int)$biz['id'] ?>">
        🗑️ Delete Listing
      </button>
    </section>
    <?= bbiz_render_github_delete_modal($tokens['csrf_token'], '/owner/edit-business.php?id=' . (int)$biz['id'], (int)$biz['id'], (string)$biz['name'], 'post_action', 'delete') ?>
  </main>

  <script>
    const TAXONOMY = <?= json_encode($taxonomyTree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const CURRENT_SPEC_ID = <?= (int)$biz['specialty_id'] ?>;
    const CURRENT_REGULATOR = <?= json_encode((string)($biz['regulator_name'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const sectorSelect = document.getElementById('sector_id');
    const specSelect = document.getElementById('specialty_id');
    const regWrap = document.getElementById('regulated-fields-wrap');
    const regSelect = document.getElementById('regulator_name');
    const regCustom = document.getElementById('regulator_name_custom');

    function syncRegulatorVisibility(savedReg) {
      const secId = Number(sectorSelect.value);
      const specId = Number(specSelect.value);
      const sec = TAXONOMY.find(s => Number(s.id) === secId);
      const sp = (sec && sec.specialties) ? sec.specialties.find(x => Number(x.id) === specId) : null;

      if (!sp || !sp.regulated) {
        regWrap.hidden = true;
        regWrap.style.display = 'none';
        regSelect.required = false;
        regSelect.disabled = true;
        regSelect.innerHTML = '';
        regCustom.required = false;
        regCustom.disabled = true;
        regCustom.style.display = 'none';
        regCustom.value = '';
        return;
      }

      regWrap.hidden = false;
      regWrap.style.display = 'block';
      regSelect.disabled = false;
      regSelect.required = true;

      const opts = Array.isArray(sp.regulator_options) && sp.regulator_options.length
        ? sp.regulator_options.slice()
        : (sp.default_regulator ? [sp.default_regulator] : []);
      if (!opts.includes('Other Statutory Regulator')) {
        opts.push('Other Statutory Regulator');
      }

      regSelect.innerHTML = '';
      opts.forEach(opt => {
        const o = document.createElement('option');
        o.value = opt;
        o.textContent = opt;
        regSelect.appendChild(o);
      });

      const targetVal = (typeof savedReg === 'string' && savedReg.trim() !== '') ? savedReg.trim() : (sp.default_regulator || opts[0]);
      if (opts.includes(targetVal)) {
        regSelect.value = targetVal;
        regCustom.style.display = 'none';
        regCustom.disabled = true;
        regCustom.required = false;
      } else {
        regSelect.value = 'Other Statutory Regulator';
        regCustom.style.display = 'block';
        regCustom.disabled = false;
        regCustom.required = true;
        regCustom.value = targetVal;
      }
    }

    regSelect.addEventListener('change', () => {
      if (regSelect.value === 'Other Statutory Regulator') {
        regCustom.style.display = 'block';
        regCustom.disabled = false;
        regCustom.required = true;
      } else {
        regCustom.style.display = 'none';
        regCustom.disabled = true;
        regCustom.required = false;
        regCustom.value = '';
      }
    });

    function populateSpecialties(selectedSpecId, savedReg) {
      const secId = Number(sectorSelect.value);
      specSelect.innerHTML = '';
      const sec = TAXONOMY.find(s => Number(s.id) === secId);
      if (sec && sec.specialties) {
        sec.specialties.forEach(sp => {
          const opt = document.createElement('option');
          opt.value = sp.id;
          opt.textContent = sp.name;
          if (Number(sp.id) === Number(selectedSpecId)) opt.selected = true;
          specSelect.appendChild(opt);
        });
      }
      syncRegulatorVisibility(savedReg);
    }

    sectorSelect.addEventListener('change', () => populateSpecialties(0, ''));
    specSelect.addEventListener('change', () => syncRegulatorVisibility(''));
    populateSpecialties(CURRENT_SPEC_ID, CURRENT_REGULATOR);

    document.getElementById('btn-owner-autofill').addEventListener('click', async () => {
      const urlInput = document.getElementById('owner-autofill-url');
      const statusBox = document.getElementById('owner-autofill-status');
      const url = urlInput.value.trim();
      if (!url) return;

      statusBox.style.display = 'block';
      statusBox.style.color = '#114B3E';
      statusBox.textContent = 'Extracting metadata from website...';

      try {
        const res = await fetch('/api/autofill-url.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            url,
            csrf_token: document.getElementById('csrf_token').value
          })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
          statusBox.style.color = '#991B1B';
          statusBox.textContent = data.error || 'Could not extract website metadata.';
          return;
        }
        const d = data.data || {};
        document.getElementById('website_url').value = d.website_url || url;
        if (d.tagline) document.getElementById('tagline').value = d.tagline;
        if (d.description) document.getElementById('description').value = d.description;
        if (d.phone) document.getElementById('phone_primary').value = d.phone;
        if (d.email) document.getElementById('email_public').value = d.email;
        statusBox.style.color = '#065F46';
        statusBox.textContent = '✓ Extracted website details into the form below. Click Save Changes to publish.';
      } catch (e) {
        statusBox.style.color = '#991B1B';
        statusBox.textContent = 'Network error while extracting website details.';
      }
    });
  </script>
  <script src="/assets/js/form-enhancements.js"></script>
</body>
</html>
