<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Config\Houses;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\VettingPurger;
use BusherianBiasharas\Services\WebsiteEnricher;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();
$taxonomyTree = CategoryMatcher::getTaxonomyTree($pdo, false);

$errors = [];
$successMessage = '';
$submittedBusinessId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrfToken)) {
        $errors[] = 'Invalid or expired security token (CSRF). Please reload the form and try again.';
    } else {
        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $spamCheck = AntiSpamGate::verifySubmission($pdo, $_POST, $clientIp, 'business_submit');

        if (!$spamCheck['passed']) {
            if ($spamCheck['silent_drop']) {
                // Silent 200 drop for automated bots/rate-limit trips to protect SMTP reputation
                $successMessage = 'Thank you! Your Busherian Biashara submission has been received for alumni vetting.';
            } else {
                $reasonMap = [
                    'layer2_invalid_signature' => 'Form security signature could not be verified. Please reload and try again.',
                    'layer2_form_expired' => 'This registration form session expired (older than 2 hours). Please reload and submit again.',
                    'layer2_nonce_replayed' => 'This form has already been submitted.',
                    'layer4_excessive_urls_in_description' => 'Please include at most 1 URL inside the business description.',
                    'layer4_all_caps_shouting' => 'Please use normal sentence/title capitalization rather than ALL-CAPS.',
                ];
                $errors[] = $reasonMap[$spamCheck['reason']] ?? 'Submission blocked by security verification (' . $spamCheck['reason'] . ').';
            }
        } else {
            // Validate Step 1: One-Time Alumni Vetting
            $schoolCode    = strtoupper(trim((string)($_POST['school_code'] ?? '')));
            $houseSelect   = trim((string)($_POST['house_name'] ?? ''));
            $houseCustom   = trim((string)($_POST['house_custom'] ?? ''));
            $houseName     = ($houseSelect === 'Other / Unlisted House' && $houseCustom !== '') ? $houseCustom : $houseSelect;
            $yearLeft      = (int)($_POST['year_left'] ?? 0);
            $schoolNumber  = trim((string)($_POST['school_number'] ?? '')); // Strictly optional "School Number"
            $submitterName = trim((string)($_POST['submitter_name'] ?? ''));
            $submitterEmail = strtolower(trim((string)($_POST['submitter_email'] ?? '')));
            $submitterPhone = WebsiteEnricher::normalizeKenyanPhone((string)($_POST['submitter_phone'] ?? ''));

            if (!in_array($schoolCode, ['AHS', 'AGHS'], true)) {
                $errors[] = 'Please select your School (AHS or AGHS).';
            } elseif (!Houses::isValidHouse($schoolCode, $houseName)) {
                $errors[] = 'Please select a valid confirmed House for your school.';
            }

            $currentYear = (int)date('Y');
            if ($yearLeft < 1940 || $yearLeft > $currentYear) {
                $errors[] = "Please enter a valid Year Left (between 1940 and {$currentYear}).";
            }
            if ($submitterName === '' || mb_strlen($submitterName) < 2) {
                $errors[] = 'Please provide your full name for vetting.';
            }
            if (!filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please provide a valid submitter email address.';
            }
            if ($submitterPhone === '') {
                $errors[] = 'Please provide your contact phone number for vetting.';
            }

            // Validate Kenya DPA 2019 s.33 Age Gate & s.29 Unbundled Consent
            if (empty($_POST['age_attestation_18'])) {
                $errors[] = 'You must confirm that you are 18 years of age or older (Kenya DPA 2019 s.33).';
            }
            if (empty($_POST['dpa_consent_directory'])) {
                $errors[] = 'Please confirm your consent to publish the business contact details in the public directory.';
            }

            // Validate Step 2: Business Identity & 2-Level Category
            $bizName     = trim((string)($_POST['name'] ?? ''));
            $tagline     = trim((string)($_POST['tagline'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sectorId    = (int)($_POST['sector_id'] ?? 0);
            $specialtyId = (string)($_POST['specialty_id'] ?? '');
            $customSpec  = trim((string)($_POST['custom_specialty_name'] ?? ''));
            $serviceMode = strtolower(trim((string)($_POST['service_mode'] ?? 'physical')));
            $alumniPerk  = trim((string)($_POST['alumni_perk'] ?? ''));
            $b2bReady    = !empty($_POST['b2b_ready']) ? 1 : 0;
            $acceptsLpo  = !empty($_POST['accepts_lpo']) ? 1 : 0;
            $regSelect   = trim((string)($_POST['regulator_name'] ?? ''));
            $regCustom   = trim((string)($_POST['regulator_name_custom'] ?? ''));
            $regName     = ($regSelect === 'Other Statutory Regulator') ? $regCustom : $regSelect;
            $regLicense  = '';

            if ($bizName === '' || mb_strlen($bizName) < 2) {
                $errors[] = 'Please enter the Business Name.';
            }
            if ($tagline === '') {
                $errors[] = 'Please enter a short 1-2 line Business Tagline.';
            }
            if ($description === '') {
                $errors[] = 'Please enter a Business Description.';
            }
            if ($sectorId <= 0) {
                $errors[] = 'Please select a Level 1 Industry Sector.';
            }
            if ($specialtyId === '__custom__') {
                if ($customSpec === '' || mb_strlen($customSpec) < 3) {
                    $errors[] = 'Please enter the name of your custom Level 2 Specialty.';
                }
                $regName = '';
            } elseif ((int)$specialtyId <= 0) {
                $errors[] = 'Please select a Level 2 Specialty (or choose "+ Add New Specialty").';
                $regName = '';
            } else {
                $regInfo = CategoryMatcher::getSpecialtyRegulationById($pdo, (int)$specialtyId);
                if ($regInfo !== null) {
                    if ($regName === '') {
                        $errors[] = 'Please confirm your Professional Regulator / Statutory Body (required for regulated businesses).';
                    }
                } else {
                    // Unregulated business specialty: regulator field is hidden and cleared
                    $regName = '';
                }
            }

            // Validate Step 3 & 4: Location & Direct Contacts
            $phonePrimary = WebsiteEnricher::normalizeKenyanPhone((string)($_POST['phone_primary'] ?? ''));
            $whatsappNum  = WebsiteEnricher::normalizeKenyanPhone((string)($_POST['whatsapp_number'] ?? $phonePrimary));
            $emailPublic  = strtolower(trim((string)($_POST['email_public'] ?? '')));
            $websiteUrl   = trim((string)($_POST['website_url'] ?? ''));

            if ($phonePrimary === '') {
                $errors[] = 'Please enter a primary business phone number.';
            }

            $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
            $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
            $plusCode = trim((string)($_POST['plus_code'] ?? ''));
            $gmapsUrl = trim((string)($_POST['google_maps_url'] ?? ''));

            if (($lat === null || $lng === null) && ($gmapsUrl !== '' || $plusCode !== '')) {
                $parsed = LocationEngine::parseCoordinatesFromInput($gmapsUrl ?: $plusCode);
                if ($parsed) {
                    $lat = $parsed['latitude'];
                    $lng = $parsed['longitude'];
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();
                try {
                    $createdCustomCategory = null;
                    $finalSpecialtyId = (int)$specialtyId;

                    if ($specialtyId === '__custom__') {
                        $customSlug = CategoryMatcher::slugify($customSpec) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                        $insCat = $pdo->prepare("
                            INSERT INTO categories (parent_id, name, slug, status, submitted_by_email)
                            VALUES (:pid, :name, :slug, 'pending_approval', :email)
                        ");
                        $insCat->execute([
                            ':pid' => $sectorId,
                            ':name' => $customSpec,
                            ':slug' => $customSlug,
                            ':email' => $submitterEmail,
                        ]);
                        $finalSpecialtyId = (int)$pdo->lastInsertId();
                        $createdCustomCategory = ['id' => $finalSpecialtyId, 'name' => $customSpec];
                    }

                    $weekdayOpen  = trim((string)($_POST['hours_weekday_open'] ?? '08:00')) ?: '08:00';
                    $weekdayClose = trim((string)($_POST['hours_weekday_close'] ?? '17:30')) ?: '17:30';
                    $satOpen      = trim((string)($_POST['hours_sat_open'] ?? '09:00'));
                    $satClose     = trim((string)($_POST['hours_sat_close'] ?? '14:00'));

                    $hoursJson = json_encode([
                        'mon' => [[$weekdayOpen, $weekdayClose]],
                        'tue' => [[$weekdayOpen, $weekdayClose]],
                        'wed' => [[$weekdayOpen, $weekdayClose]],
                        'thu' => [[$weekdayOpen, $weekdayClose]],
                        'fri' => [[$weekdayOpen, $weekdayClose]],
                        'sat' => ($satOpen !== '' && $satClose !== '') ? [[$satOpen, $satClose]] : [],
                        'sun' => [],
                    ]);

                    $slugBase = CategoryMatcher::slugify($bizName);
                    $slug = $slugBase . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                    $bizUid = VettingPurger::generateUniqueBusinessUid($pdo);
                    $nextBizNum = ((int)$pdo->query("SELECT COALESCE(MAX(business_number), 0) FROM businesses")->fetchColumn()) + 1;

                    // Insert into `businesses` (STRICTLY ZERO House, Year Left, or School Number columns)
                    $insBiz = $pdo->prepare("
                        INSERT INTO businesses (
                            business_id, business_number, name, slug, tagline, description, school_badge,
                            sector_id, specialty_id, service_mode, alumni_perk,
                            b2b_ready, accepts_lpo, regulator_name, regulator_license,
                            branch_name, building_name, floor_suite, street_road,
                            area_neighborhood, city_county, landmark_directions,
                            plus_code, latitude, longitude, google_maps_url,
                            operating_hours_json, phone_primary, whatsapp_number,
                            email_public, website_url,
                            social_instagram, social_linkedin, social_x,
                            social_facebook, social_tiktok, social_youtube,
                            status
                        ) VALUES (
                            :business_id, :business_number, :name, :slug, :tagline, :description, :school_badge,
                            :sector_id, :specialty_id, :service_mode, :alumni_perk,
                            :b2b_ready, :accepts_lpo, :regulator_name, :regulator_license,
                            :branch_name, :building_name, :floor_suite, :street_road,
                            :area_neighborhood, :city_county, :landmark_directions,
                            :plus_code, :latitude, :longitude, :google_maps_url,
                            :operating_hours_json, :phone_primary, :whatsapp_number,
                            :email_public, :website_url,
                            :social_instagram, :social_linkedin, :social_x,
                            :social_facebook, :social_tiktok, :social_youtube,
                            'pending'
                        )
                    ");

                    $insBiz->execute([
                        ':business_id' => $bizUid,
                        ':business_number' => $nextBizNum,
                        ':name' => $bizName,
                        ':slug' => $slug,
                        ':tagline' => $tagline,
                        ':description' => $description,
                        ':school_badge' => $schoolCode,
                        ':sector_id' => $sectorId,
                        ':specialty_id' => $finalSpecialtyId,
                        ':service_mode' => in_array($serviceMode, ['physical', 'hybrid', 'online'], true) ? $serviceMode : 'physical',
                        ':alumni_perk' => $alumniPerk !== '' ? $alumniPerk : null,
                        ':b2b_ready' => $b2bReady,
                        ':accepts_lpo' => $acceptsLpo,
                        ':regulator_name' => $regName !== '' ? $regName : null,
                        ':regulator_license' => $regLicense !== '' ? $regLicense : null,
                        ':branch_name' => trim((string)($_POST['branch_name'] ?? '')) ?: null,
                        ':building_name' => trim((string)($_POST['building_name'] ?? '')) ?: null,
                        ':floor_suite' => trim((string)($_POST['floor_suite'] ?? '')) ?: null,
                        ':street_road' => trim((string)($_POST['street_road'] ?? '')) ?: null,
                        ':area_neighborhood' => trim((string)($_POST['area_neighborhood'] ?? '')) ?: null,
                        ':city_county' => trim((string)($_POST['city_county'] ?? 'Nairobi')) ?: 'Nairobi',
                        ':landmark_directions' => trim((string)($_POST['landmark_directions'] ?? '')) ?: null,
                        ':plus_code' => $plusCode !== '' ? $plusCode : null,
                        ':latitude' => $lat,
                        ':longitude' => $lng,
                        ':google_maps_url' => $gmapsUrl !== '' ? $gmapsUrl : null,
                        ':operating_hours_json' => $hoursJson,
                        ':phone_primary' => $phonePrimary,
                        ':whatsapp_number' => $whatsappNum !== '' ? $whatsappNum : null,
                        ':email_public' => $emailPublic !== '' ? $emailPublic : null,
                        ':website_url' => $websiteUrl !== '' ? $websiteUrl : null,
                        ':social_instagram' => trim((string)($_POST['social_instagram'] ?? '')) ?: null,
                        ':social_linkedin' => trim((string)($_POST['social_linkedin'] ?? '')) ?: null,
                        ':social_x' => trim((string)($_POST['social_x'] ?? '')) ?: null,
                        ':social_facebook' => trim((string)($_POST['social_facebook'] ?? '')) ?: null,
                        ':social_tiktok' => trim((string)($_POST['social_tiktok'] ?? '')) ?: null,
                        ':social_youtube' => trim((string)($_POST['social_youtube'] ?? '')) ?: null,
                    ]);

                    $submittedBusinessId = (int)$pdo->lastInsertId();

                    // Insert ephemeral vetting record into `business_vetting_queue` (hard-deleted upon review)
                    $insVet = $pdo->prepare("
                        INSERT INTO business_vetting_queue (
                            business_id, school_code, house_name, year_left,
                            school_number, submitter_name, submitter_email, submitter_phone
                        ) VALUES (
                            :bid, :school, :house, :year,
                            :snum, :sname, :semail, :sphone
                        )
                    ");
                    $insVet->execute([
                        ':bid' => $submittedBusinessId,
                        ':school' => $schoolCode,
                        ':house' => $houseName,
                        ':year' => $yearLeft,
                        ':snum' => $schoolNumber !== '' ? $schoolNumber : null,
                        ':sname' => $submitterName,
                        ':semail' => $submitterEmail,
                        ':sphone' => $submitterPhone,
                    ]);

                    // Notify admin@busherianbiasharas.com via durable outbox + signed 1-click links
                    Mailer::notifyAdminNewSubmission(
                        $pdo,
                        ['id' => $submittedBusinessId, 'name' => $bizName],
                        [
                            'school_code' => $schoolCode,
                            'house_name' => $houseName,
                            'year_left' => $yearLeft,
                            'school_number' => $schoolNumber,
                            'submitter_name' => $submitterName,
                            'submitter_email' => $submitterEmail,
                        ],
                        $createdCustomCategory
                    );

                    $pdo->commit();
                    $successMessage = 'Your business has been submitted for one-time alumni vetting! Once verified by the admin team, your listing will go live and your House, Year Left, and School Number will be permanently deleted.';
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'Could not save submission due to a temporary server error. Please verify your entries and try again.';
                }
            }
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();
$ahsHouses = Houses::AHS_HOUSES;
$aghsHouses = Houses::AGHS_HOUSES;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>List Your Biashara — Busherian Biasharas (AHS &amp; AGHS Alumni Directory)</title>
  <meta name="description" content="Register your verified AHS or AGHS alumni business on the Busherian Biasharas directory.">
<?= bbiz_head_meta_tags('List Your Biashara — Busherian Biasharas (AHS & AGHS Alumni Directory)', 'Register your verified AHS or AGHS alumni business on the Busherian Biasharas directory.', '/submit.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas</h1>
          <p class="brand-subtitle">Register a Verified Busherian Business (AHS &amp; AGHS)</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/" class="btn btn-outline-light">&larr; Back to Directory</a>
        <a href="/privacy.php" class="btn btn-outline-light">🔒 Section 29 Privacy Notice</a>
      </nav>
    </div>
  </header>

  <main style="max-width:920px;margin:1.75rem auto;padding:0 1.25rem;">

    <?php if ($successMessage !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:14px;padding:1.5rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 0.5rem 0;color:#065F46;">✓ Submission Received for One-Time Alumni Vetting</h2>
        <p style="margin:0 0 1rem 0;color:#047857;"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
          <a href="/" class="btn btn-green">Return to Public Directory</a>
          <a href="/submit.php" class="btn btn-outline">Register Another Business</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:14px;padding:1.25rem;margin-bottom:1.5rem;">
        <h3 style="margin:0 0 0.5rem 0;color:#991B1B;">Please correct the following:</h3>
        <ul style="margin:0;padding-left:1.25rem;color:#B91C1C;">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ONE-CLICK WEBSITE URL AUTO-FILL BAR -->
    <section class="autofill-box" aria-label="One-Click Website Auto-Fill">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.55rem;">
        <h2 style="margin:0;font-size:1.08rem;color:#7B1113;">⚡ Have a Business Website? Auto-Fill Your Listing in 1 Click</h2>
        <button type="button" id="btn-try-demo-url" class="btn btn-outline" style="font-size:0.75rem;padding:0.25rem 0.65rem;">
          Try Sample Business Website
        </button>
      </div>
      <p style="margin:0 0 0.75rem 0;font-size:0.86rem;color:#4B5563;">
        Type in your business website URL below and click <strong>Extract &amp; Auto-Fill from Website</strong> to automatically populate your Business Name, Tagline, Description, Category, Phone, WhatsApp, Email, Social Media links, and Location!
      </p>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <input type="url" id="autofill-url-input" class="input-control" style="flex:1;min-width:240px;"
          placeholder="https://www.yourbusiness.co.ke">
        <button type="button" id="btn-autofill-extract" class="btn btn-crimson">
          ✨ Extract &amp; Auto-Fill from Website
        </button>
      </div>
      <div id="autofill-status" style="margin-top:0.65rem;font-size:0.84rem;display:none;"></div>
    </section>

    <form method="POST" action="/submit.php" id="business-submit-form" style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.5rem;box-shadow:0 4px 16px rgba(0,0,0,0.05);">
      <input type="hidden" name="csrf_token" id="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="form_ts" value="<?= (int)$tokens['form_ts'] ?>">
      <input type="hidden" name="form_nonce" value="<?= htmlspecialchars($tokens['form_nonce'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="form_sig" value="<?= htmlspecialchars($tokens['form_sig'], ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="js_interaction_token" id="js_interaction_token" value="">

      <!-- Layer 1 Anti-Spam: Disguised Off-Screen Text Honeypot (Never Checkbox-Only) -->
      <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
        <label for="field_website_ref">Official Corporate Portal Reference</label>
        <input type="text" id="field_website_ref" name="website" value="" tabindex="-1" autocomplete="nope">
      </div>

      <!-- STEP 1: ONE-TIME ALUMNI VETTING (STRICT PRIVACY & IMMEDIATE DISPOSAL) -->
      <fieldset style="border:none;padding:0;margin:0 0 1.75rem 0;">
        <legend style="font-size:1.15rem;font-weight:800;color:#7B1113;margin-bottom:0.65rem;">
          Step 1 of 4: One-Time Busherian Alumni Vetting
        </legend>

        <div class="vetting-privacy-banner">
          <strong style="color:#114B3E;font-size:0.92rem;display:block;margin-bottom:0.25rem;">
            🔒 Strict Alumni Privacy &amp; Immediate Data Disposal Guarantee (Kenya DPA 2019 s.29)
          </strong>
          <p style="margin:0;font-size:0.84rem;color:#1F2937;">
            Your <strong>House</strong>, <strong>Year Left</strong>, and optional <strong>School Number</strong> are collected <strong>strictly for one-time alumni vetting</strong>.
            <strong>Houses and Years DO NOT feature anywhere in the public directory</strong>, are never shown to visitors, and are <strong>permanently deleted from our database (`DELETE FROM business_vetting_queue`) the instant your listing is reviewed</strong>.
          </p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;">
          <div>
            <label for="school_code" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">School *</label>
            <select id="school_code" name="school_code" class="select-control" required>
              <option value="AHS">AHS (Alliance High School)</option>
              <option value="AGHS">AGHS (Alliance Girls High School)</option>
            </select>
          </div>

          <div>
            <label for="house_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">House * (Compulsory for Vetting Only)</label>
            <select id="house_name" name="house_name" class="select-control" required></select>
            <input type="text" id="house_custom" name="house_custom" class="input-control"
              placeholder="Enter your AGHS House name..." style="margin-top:0.45rem;display:none;">
          </div>

          <div>
            <label for="year_left" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Year Left * (Compulsory for Vetting Only)</label>
            <input type="number" id="year_left" name="year_left" class="input-control" min="1940" max="<?= (int)date('Y') ?>" placeholder="e.g. 2004" required>
          </div>

          <div>
            <label for="school_number" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">School Number (Optional — Vetting Only)</label>
            <input type="text" id="school_number" name="school_number" class="input-control" placeholder="Optional School Number (e.g. 6412)">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.9rem;margin-top:0.9rem;">
          <div>
            <label for="submitter_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Full Name *</label>
            <input type="text" id="submitter_name" name="submitter_name" class="input-control" placeholder="e.g. Eng. Jane Wanjiku" required>
          </div>
          <div>
            <label for="submitter_email" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Email (For Approval Notification) *</label>
            <input type="email" id="submitter_email" name="submitter_email" class="input-control" placeholder="you@company.co.ke" required>
          </div>
          <div>
            <label for="submitter_phone" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Your Direct Phone *</label>
            <input type="tel" id="submitter_phone" name="submitter_phone" class="input-control" placeholder="+254722000000" required>
          </div>
        </div>
      </fieldset>

      <!-- STEP 2: BUSINESS IDENTITY & 2-LEVEL CATEGORY -->
      <fieldset style="border:none;padding:1.25rem 0 0 0;margin:0 0 1.75rem 0;border-top:1px solid #E5E7EB;">
        <legend style="font-size:1.15rem;font-weight:800;color:#7B1113;margin-bottom:0.65rem;">
          Step 2 of 4: Business Identity &amp; 2-Level Category
        </legend>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.9rem;">
          <div>
            <label for="biz_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Business Name *</label>
            <input type="text" id="biz_name" name="name" class="input-control" placeholder="e.g. Kikuyu Springs Dental Clinic" required>
          </div>
          <div>
            <label for="service_mode" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Service Mode *</label>
            <select id="service_mode" name="service_mode" class="select-control">
              <option value="physical">Physical Premises / Walk-In</option>
              <option value="hybrid">Hybrid (Physical Office + Online Delivery)</option>
              <option value="online">Online / Remote Service Only</option>
            </select>
          </div>
        </div>

        <div style="margin-top:0.9rem;">
          <label for="biz_tagline" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Short Tagline (Shown on Directory Card — max 160 chars) *</label>
          <input type="text" id="biz_tagline" name="tagline" maxlength="175" class="input-control"
            placeholder="One crisp sentence describing what you offer..." required>
        </div>

        <div style="margin-top:0.9rem;">
          <label for="biz_description" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Full Business Description (Shown in Detail Modal) *</label>
          <textarea id="biz_description" name="description" rows="3" class="input-control"
            placeholder="Describe your products, services, target clients, and key strengths..." required></textarea>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:0.9rem;margin-top:0.9rem;">
          <div>
            <label for="sector_id" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Level 1: Industry Sector *</label>
            <select id="sector_id" name="sector_id" class="select-control" required>
              <option value="">Select Industry Sector...</option>
              <?php foreach ($taxonomyTree as $sec): ?>
                <option value="<?= (int)$sec['id'] ?>"><?= htmlspecialchars((string)$sec['name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="specialty_id" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Level 2: Specialty / Product or Service Type *</label>
            <select id="specialty_id" name="specialty_id" class="select-control" required>
              <option value="">Select Level 1 Sector first...</option>
            </select>
          </div>
        </div>

        <!-- Inline Self-Service Custom Category Addition (+ Add New) -->
        <div id="custom-specialty-wrap" style="display:none;margin-top:0.85rem;background:#FFFBEB;border:1px solid #F59E0B;border-radius:10px;padding:0.9rem;">
          <label for="custom_specialty_name" style="font-weight:700;font-size:0.85rem;color:#92400E;display:block;margin-bottom:0.3rem;">
            + Propose a New Level 2 Specialty (Subject to Admin Review)
          </label>
          <input type="text" id="custom_specialty_name" name="custom_specialty_name" class="input-control"
            placeholder="e.g. Aviation Charter &amp; Helicopter Services">
          <div id="fuzzy-suggestion-box" style="margin-top:0.45rem;font-size:0.8rem;color:#78350F;"></div>
        </div>

        <!-- Conditionally shown & required ONLY when a regulated Level 2 specialty is selected -->
        <div id="regulated-fields-wrap" hidden style="display:none;margin-top:0.9rem;background:#F0FDF4;border:1px solid #059669;border-radius:10px;padding:0.9rem;">
          <label for="regulator_name" style="font-weight:700;font-size:0.85rem;color:#065F46;display:block;margin-bottom:0.3rem;">
            🛡️ Professional Regulator / Statutory Body * (Required for Regulated Industry)
          </label>
          <select id="regulator_name" name="regulator_name" class="select-control" disabled></select>
          <input type="text" id="regulator_name_custom" name="regulator_name_custom" class="input-control"
            placeholder="Specify statutory regulator..." style="margin-top:0.45rem;display:none;" disabled>
        </div>

        <div style="margin-top:0.9rem;">
          <label for="alumni_perk" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Busherian Alumni Perk / Discount (Optional)</label>
          <input type="text" id="alumni_perk" name="alumni_perk" class="input-control" placeholder="e.g. 10% off for fellow Busherians">
        </div>

        <div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:0.75rem;font-size:0.85rem;">
          <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
            <input type="checkbox" name="b2b_ready" value="1"> <strong>B2B Corporate Vendor Ready</strong>
          </label>
          <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer;">
            <input type="checkbox" name="accepts_lpo" value="1"> <strong>Accepts Corporate LPO / Invoice</strong>
          </label>
        </div>
      </fieldset>

      <!-- STEP 3: LOCATION PIN, PLUS CODE & OPERATING HOURS -->
      <fieldset style="border:none;padding:1.25rem 0 0 0;margin:0 0 1.75rem 0;border-top:1px solid #E5E7EB;">
        <legend style="font-size:1.15rem;font-weight:800;color:#7B1113;margin-bottom:0.65rem;">
          Step 3 of 4: Physical Location, Map Pin &amp; Operating Hours (EAT)
        </legend>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;">
          <div>
            <label for="building_name" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Building / Plaza Name</label>
            <input type="text" id="building_name" name="building_name" class="input-control" placeholder="e.g. Britam Tower">
          </div>
          <div>
            <label for="floor_suite" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Floor / Suite / Stall</label>
            <input type="text" id="floor_suite" name="floor_suite" class="input-control" placeholder="e.g. 5th Floor, Suite 5B">
          </div>
          <div>
            <label for="street_road" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Street / Road</label>
            <input type="text" id="street_road" name="street_road" class="input-control" placeholder="e.g. Waiyaki Way">
          </div>
          <div>
            <label for="area_neighborhood" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Neighborhood / Area</label>
            <input type="text" id="area_neighborhood" name="area_neighborhood" class="input-control" placeholder="e.g. Westlands">
          </div>
          <div>
            <label for="city_county" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">City / County *</label>
            <input type="text" id="city_county" name="city_county" class="input-control" value="Nairobi" required>
          </div>
          <div>
            <label for="landmark_directions" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Landmark Directions</label>
            <input type="text" id="landmark_directions" name="landmark_directions" class="input-control" placeholder="e.g. Opposite KNH Gate">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-top:0.9rem;">
          <div>
            <label for="google_maps_url" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Google Maps Share Link</label>
            <input type="url" id="google_maps_url" name="google_maps_url" class="input-control" placeholder="https://www.google.com/maps?q=-1.2647,36.8021">
          </div>
          <div>
            <label for="plus_code" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Google Plus Code (Optional)</label>
            <input type="text" id="plus_code" name="plus_code" class="input-control" placeholder="e.g. 6GCRPPP3+9M or PPP3+9M Nairobi">
          </div>
          <div>
            <label for="latitude" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Latitude</label>
            <input type="number" step="any" id="latitude" name="latitude" class="input-control" placeholder="-1.286389">
          </div>
          <div>
            <label for="longitude" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Longitude</label>
            <input type="number" step="any" id="longitude" name="longitude" class="input-control" placeholder="36.817223">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.9rem;margin-top:0.9rem;">
          <div>
            <label for="hours_weekday_open" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Mon–Fri Open (EAT)</label>
            <input type="time" id="hours_weekday_open" name="hours_weekday_open" class="input-control" value="08:00">
          </div>
          <div>
            <label for="hours_weekday_close" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Mon–Fri Close (EAT)</label>
            <input type="time" id="hours_weekday_close" name="hours_weekday_close" class="input-control" value="17:30">
          </div>
          <div>
            <label for="hours_sat_open" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Saturday Open</label>
            <input type="time" id="hours_sat_open" name="hours_sat_open" class="input-control" value="09:00">
          </div>
          <div>
            <label for="hours_sat_close" style="font-weight:700;font-size:0.82rem;display:block;margin-bottom:0.25rem;">Saturday Close</label>
            <input type="time" id="hours_sat_close" name="hours_sat_close" class="input-control" value="14:00">
          </div>
        </div>
      </fieldset>

      <!-- STEP 4: DIRECT CONTACTS, SOCIAL MEDIA & KENYA DPA 2019 CONSENT -->
      <fieldset style="border:none;padding:1.25rem 0 0 0;margin:0;border-top:1px solid #E5E7EB;">
        <legend style="font-size:1.15rem;font-weight:800;color:#7B1113;margin-bottom:0.65rem;">
          Step 4 of 4: Public Contacts, Social Media &amp; DPA Consent
        </legend>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:0.9rem;">
          <div>
            <label for="phone_primary" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Public Business Phone *</label>
            <input type="tel" id="phone_primary" name="phone_primary" class="input-control" placeholder="+254722100200" required>
          </div>
          <div>
            <label for="whatsapp_number" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">WhatsApp Business Number</label>
            <input type="tel" id="whatsapp_number" name="whatsapp_number" class="input-control" placeholder="+254722100200">
          </div>
          <div>
            <label for="email_public" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Public Business Email</label>
            <input type="email" id="email_public" name="email_public" class="input-control" placeholder="info@yourbusiness.co.ke">
          </div>
          <div>
            <label for="website_url" style="font-weight:700;font-size:0.85rem;display:block;margin-bottom:0.3rem;">Business Website URL</label>
            <input type="url" id="website_url" name="website_url" class="input-control" placeholder="https://www.yourbusiness.co.ke">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:0.9rem;margin-top:0.9rem;">
          <div>
            <label for="social_instagram" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">Instagram URL</label>
            <input type="url" id="social_instagram" name="social_instagram" class="input-control" placeholder="https://www.instagram.com/...">
          </div>
          <div>
            <label for="social_linkedin" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">LinkedIn URL</label>
            <input type="url" id="social_linkedin" name="social_linkedin" class="input-control" placeholder="https://www.linkedin.com/company/...">
          </div>
          <div>
            <label for="social_x" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">X (Twitter) URL</label>
            <input type="url" id="social_x" name="social_x" class="input-control" placeholder="https://x.com/...">
          </div>
          <div>
            <label for="social_facebook" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">Facebook URL</label>
            <input type="url" id="social_facebook" name="social_facebook" class="input-control" placeholder="https://www.facebook.com/...">
          </div>
          <div>
            <label for="social_tiktok" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">TikTok URL</label>
            <input type="url" id="social_tiktok" name="social_tiktok" class="input-control" placeholder="https://www.tiktok.com/@...">
          </div>
          <div>
            <label for="social_youtube" style="font-weight:600;font-size:0.8rem;display:block;margin-bottom:0.25rem;">YouTube URL</label>
            <input type="url" id="social_youtube" name="social_youtube" class="input-control" placeholder="https://www.youtube.com/@...">
          </div>
        </div>

        <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:1rem;margin-top:1.15rem;font-size:0.84rem;">
          <label style="display:flex;align-items:flex-start;gap:0.55rem;margin-bottom:0.55rem;cursor:pointer;">
            <input type="checkbox" name="age_attestation_18" value="1" required style="margin-top:0.2rem;">
            <span><strong>Age Attestation (Kenya DPA 2019 s.33):</strong> I confirm that I am 18 years of age or older and authorized to list this business. *</span>
          </label>
          <label style="display:flex;align-items:flex-start;gap:0.55rem;cursor:pointer;">
            <input type="checkbox" name="dpa_consent_directory" value="1" required style="margin-top:0.2rem;">
            <span><strong>Section 29 Notice &amp; Unbundled Consent:</strong> I have read the <a href="/privacy.php" target="_blank" style="color:#7B1113;text-decoration:underline;">Kenya DPA 2019 Privacy Notice</a>. I understand that my House, Year Left, and School Number are used strictly once for alumni vetting, are never displayed publicly, and are permanently deleted upon review. *</span>
          </label>
        </div>

        <div style="margin-top:1.35rem;display:flex;justify-content:flex-end;gap:0.75rem;">
          <button type="submit" class="btn btn-crimson" style="padding:0.75rem 1.65rem;font-size:0.95rem;">
            Submit Business for Alumni Vetting &rarr;
          </button>
        </div>
      </fieldset>
    </form>
  </main>

  <script>
    (function () {
      const AHS_HOUSES = <?= json_encode($ahsHouses, JSON_HEX_TAG) ?>;
      const AGHS_HOUSES = <?= json_encode($aghsHouses, JSON_HEX_TAG) ?>;
      const SECTORS = <?= json_encode($taxonomyTree, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;

      const schoolSel = document.getElementById('school_code');
      const houseSel = document.getElementById('house_name');
      const houseCustom = document.getElementById('house_custom');
      const sectorSel = document.getElementById('sector_id');
      const specSel = document.getElementById('specialty_id');
      const customWrap = document.getElementById('custom-specialty-wrap');
      const customInput = document.getElementById('custom_specialty_name');
      const fuzzyBox = document.getElementById('fuzzy-suggestion-box');
      const jsTokenInput = document.getElementById('js_interaction_token');

      // Layer 3 Anti-Spam: Populate human interaction token only after genuine user interaction
      const markHumanInteraction = () => {
        if (jsTokenInput && !jsTokenInput.value) {
          jsTokenInput.value = 'human_verified_' + Date.now();
        }
      };
      ['focusin', 'keydown', 'pointerdown', 'touchstart'].forEach(ev => {
        document.addEventListener(ev, markHumanInteraction, { once: true, passive: true });
      });

      function updateHousesDropdown() {
        const school = schoolSel.value;
        const list = school === 'AGHS' ? AGHS_HOUSES : AHS_HOUSES;
        houseSel.innerHTML = '<option value="">Select Your House...</option>';
        list.forEach(h => {
          const opt = document.createElement('option');
          opt.value = h;
          opt.textContent = h;
          houseSel.appendChild(opt);
        });
        houseCustom.style.display = 'none';
      }

      schoolSel.addEventListener('change', updateHousesDropdown);
      houseSel.addEventListener('change', () => {
        houseCustom.style.display = (houseSel.value === 'Other / Unlisted House') ? 'block' : 'none';
      });
      updateHousesDropdown();

      const regulatedWrap = document.getElementById('regulated-fields-wrap');
      const regNameSel = document.getElementById('regulator_name');
      const regNameCustom = document.getElementById('regulator_name_custom');

      function findSelectedSpecialtyObj() {
        const sec = SECTORS.find(s => String(s.id) === String(sectorSel.value));
        if (!sec || !Array.isArray(sec.specialties)) return null;
        return sec.specialties.find(sp => String(sp.id) === String(specSel.value)) || null;
      }

      function syncCustomRegulatorInput() {
        if (!regNameSel || !regNameCustom) return;
        const isOther = !regNameSel.disabled && regNameSel.value === 'Other Statutory Regulator';
        regNameCustom.style.display = isOther ? 'block' : 'none';
        regNameCustom.disabled = !isOther;
        regNameCustom.required = isOther;
        if (!isOther) {
          regNameCustom.value = '';
        }
      }

      function updateRegulatorVisibility() {
        if (!regulatedWrap || !regNameSel || !regNameCustom) return;
        const sp = findSelectedSpecialtyObj();
        if (sp && sp.is_regulated) {
          regulatedWrap.style.display = 'block';
          regulatedWrap.removeAttribute('hidden');
          regNameSel.innerHTML = '';
          const opts = Array.isArray(sp.regulator_options) && sp.regulator_options.length > 0
            ? sp.regulator_options
            : [sp.regulator_default || 'Other Statutory Regulator'];
          opts.forEach(regLabel => {
            const opt = document.createElement('option');
            opt.value = regLabel;
            opt.textContent = regLabel;
            if (sp.regulator_default && regLabel === sp.regulator_default) {
              opt.selected = true;
            }
            regNameSel.appendChild(opt);
          });
          regNameSel.disabled = false;
          regNameSel.required = true;
          syncCustomRegulatorInput();
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

      if (regNameSel) {
        regNameSel.addEventListener('change', syncCustomRegulatorInput);
      }

      function populateSpecialties(sectorId, selectedSpecialtyId) {
        specSel.innerHTML = '<option value="">Select Specialty (Level 2)...</option>';
        const sec = SECTORS.find(s => String(s.id) === String(sectorId));
        if (sec && Array.isArray(sec.specialties)) {
          sec.specialties.forEach(sp => {
            const opt = document.createElement('option');
            opt.value = sp.id;
            opt.textContent = sp.name;
            if (selectedSpecialtyId && String(sp.id) === String(selectedSpecialtyId)) {
              opt.selected = true;
            }
            specSel.appendChild(opt);
          });
        }
        const addOpt = document.createElement('option');
        addOpt.value = '__custom__';
        addOpt.textContent = '+ Add New Specialty (Not Listed Above)';
        specSel.appendChild(addOpt);
        updateRegulatorVisibility();
      }

      sectorSel.addEventListener('change', () => {
        populateSpecialties(sectorSel.value, null);
        customWrap.style.display = 'none';
      });

      specSel.addEventListener('change', () => {
        customWrap.style.display = (specSel.value === '__custom__') ? 'block' : 'none';
        updateRegulatorVisibility();
      });
      updateRegulatorVisibility();

      // Live Jaro-Winkler Fuzzy Duplicate Check for custom category
      if (customInput) {
        let fTimer = null;
        customInput.addEventListener('input', () => {
          clearTimeout(fTimer);
          const val = customInput.value.trim();
          if (val.length < 3) {
            fuzzyBox.innerHTML = '';
            return;
          }
          fTimer = setTimeout(async () => {
            const res = await fetch(`/api/categories.php?check_custom=${encodeURIComponent(val)}&parent_id=${encodeURIComponent(sectorSel.value)}`);
            const data = await res.json();
            if (data && Array.isArray(data.fuzzy_matches) && data.fuzzy_matches.length > 0) {
              const top = data.fuzzy_matches[0];
              fuzzyBox.innerHTML = `💡 <strong>Similar existing specialty found:</strong> "${top.name}" (${Math.round(top.similarity * 100)}% match). <button type="button" id="btn-use-suggested-cat" class="btn btn-outline" style="padding:0.15rem 0.5rem;font-size:0.75rem;margin-left:0.4rem;">Use Existing Specialty</button>`;
              const btnUse = document.getElementById('btn-use-suggested-cat');
              if (btnUse) {
                btnUse.addEventListener('click', () => {
                  sectorSel.value = String(top.parent_id);
                  populateSpecialties(top.parent_id, top.id);
                  customWrap.style.display = 'none';
                });
              }
            } else {
              fuzzyBox.innerHTML = '✓ New specialty name looks unique — will be queued for admin approval.';
            }
          }, 250);
        });
      }

      // ONE-CLICK WEBSITE URL AUTO-FILL HANDLER
      const urlInput = document.getElementById('autofill-url-input');
      const btnExtract = document.getElementById('btn-autofill-extract');
      const btnDemo = document.getElementById('btn-try-demo-url');
      const statusBox = document.getElementById('autofill-status');

      if (btnDemo && urlInput) {
        btnDemo.addEventListener('click', () => {
          urlInput.value = 'https://www.wakilipeak.busherian-demo.co.ke';
          btnExtract.click();
        });
      }

      if (btnExtract && urlInput) {
        btnExtract.addEventListener('click', async () => {
          markHumanInteraction();
          const url = urlInput.value.trim();
          if (!url) {
            statusBox.style.display = 'block';
            statusBox.style.color = '#DC2626';
            statusBox.textContent = 'Please enter a website URL first.';
            return;
          }
          btnExtract.disabled = true;
          btnExtract.textContent = '⏳ Extracting from Website...';
          statusBox.style.display = 'block';
          statusBox.style.color = '#114B3E';
          statusBox.textContent = 'Fetching website metadata, Schema.org JSON-LD, OpenGraph tags, and contact links...';

          try {
            const csrf = document.getElementById('csrf_token').value;
            const res = await fetch('/api/autofill-url.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
              body: JSON.stringify({ url: url, csrf_token: csrf }),
            });
            const payload = await res.json();
            if (!res.ok || payload.status !== 'ok') {
              throw new Error(payload.message || 'Could not extract website details.');
            }

            const d = payload.data || {};
            const setField = (id, val) => {
              const el = document.getElementById(id);
              if (el && val !== null && val !== undefined && String(val).trim() !== '') {
                el.value = String(val).trim();
                el.style.borderColor = '#059669';
                el.style.backgroundColor = '#F0FDF4';
              }
            };

            setField('biz_name', d.name);
            setField('biz_tagline', d.tagline);
            setField('biz_description', d.description);
            setField('phone_primary', d.phone_primary);
            setField('whatsapp_number', d.whatsapp_number);
            setField('email_public', d.email_public);
            setField('website_url', d.website_url || url);
            setField('street_road', d.street_road);
            setField('area_neighborhood', d.area_neighborhood);
            setField('city_county', d.city_county);
            setField('latitude', d.latitude);
            setField('longitude', d.longitude);
            setField('google_maps_url', d.google_maps_url);
            setField('social_instagram', d.social_instagram);
            setField('social_linkedin', d.social_linkedin);
            setField('social_x', d.social_x);
            setField('social_facebook', d.social_facebook);
            setField('social_tiktok', d.social_tiktok);
            setField('social_youtube', d.social_youtube);

            if (d.suggested_category && d.suggested_category.sector_id) {
              sectorSel.value = String(d.suggested_category.sector_id);
              populateSpecialties(d.suggested_category.sector_id, d.suggested_category.specialty_id);
            }

            statusBox.style.color = '#065F46';
            statusBox.innerHTML = `✅ <strong>${payload.message}</strong> Review the highlighted fields below and complete Step 1 Vetting to submit.`;
          } catch (err) {
            statusBox.style.color = '#DC2626';
            statusBox.textContent = '⚠️ ' + err.message;
          } finally {
            btnExtract.disabled = false;
            btnExtract.textContent = '✨ Extract & Auto-Fill from Website';
          }
        });
      }
    })();
  </script>
  <script src="/assets/js/consent-metrics.js"></script>
</body>
</html>
