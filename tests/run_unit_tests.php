<?php
declare(strict_types=1);

putenv('APP_ENV=testing');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . sys_get_temp_dir() . '/bbiz_unit_' . getmypid() . '.sqlite');
putenv('APP_HMAC_SECRET=9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e');
putenv('IP_HASH_SECRET=1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b');
putenv('CSRF_SECRET=5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e');
putenv('SIMULATE_EMAILS=yes');
putenv('ADMIN_ACCESS_TOKEN=test_admin_token_secret_12345');

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Config\Houses;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\PhotoUploader;
use BusherianBiasharas\Services\VettingPurger;
use BusherianBiasharas\Services\WebsiteEnricher;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}
";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}
");
    }
}

$pdo = Database::getConnection();

// 1. Verify Confirmed AHS (13) & AGHS Houses
assertTrue(count(Houses::AHS_HOUSES) === 13, 'AHS has all 13 confirmed houses');
assertTrue(in_array('Livingstone', Houses::AHS_HOUSES, true) && in_array('Maneno', Houses::AHS_HOUSES, true), 'AHS houses include Livingstone and Maneno');
assertTrue(in_array('Watson', Houses::AGHS_HOUSES, true) && in_array('Joan Mjomba', Houses::AGHS_HOUSES, true), 'AGHS houses include Watson and Joan Mjomba');

// 2. Verify 2-Level Taxonomy & Synonym Expansion
$tree = CategoryMatcher::getTaxonomyTree($pdo);
assertTrue(count($tree) === 15, 'Taxonomy has 15 Industry Sectors including Arts, Visual & Performing Arts (#112)');
$totalSpecs = array_sum(array_map(fn($s) => count($s['specialties']), $tree));
assertTrue($totalSpecs >= 100, "Taxonomy has 100+ Level 2 Specialties (found {$totalSpecs})");

$expanded = CategoryMatcher::expandSearchTerms('wakili');
assertTrue(in_array('Advocates', $expanded, true), 'Swahili term "wakili" expands to Advocates');

$fuzzy = CategoryMatcher::findFuzzyMatches($pdo, 'Advocate & Law Firms', null, 0.65);
assertTrue(!empty($fuzzy) && str_contains($fuzzy[0]['name'], 'Advocates'), 'Jaro-Winkler fuzzy matcher detects duplicate custom specialty');

// 3. Verify Spatial Haversine, Google Maps URL & Plus Code Decoder
$distKm = LocationEngine::haversineKm(-1.286389, 36.817223, -1.264700, 36.802100);
assertTrue($distKm > 2.0 && $distKm < 4.0, "Haversine distance CBD to Westlands is ~2.95 km (got {$distKm} km)");

$fromUrl = LocationEngine::parseCoordinatesFromInput('https://www.google.com/maps/place/Test/@-1.2647,36.8021,17z');
assertTrue($fromUrl !== null && abs($fromUrl['latitude'] - (-1.2647)) < 0.001, 'Extracts coordinates from Google Maps @lat,lng URL');

$fromPlus = LocationEngine::decodePlusCode('6GCRPPP3+9M');
assertTrue($fromPlus !== null && abs($fromPlus['latitude'] - (-1.264)) < 0.02, 'Decodes full Open Location Code (Plus Code)');

$fromShortPlus = LocationEngine::decodePlusCode('PPP3+9M Nairobi');
assertTrue($fromShortPlus !== null && abs($fromShortPlus['latitude'] - (-1.264)) < 0.02, 'Decodes short local Plus Code with Nairobi reference');

// 4. Verify Deterministic Africa/Nairobi EAT HoursEngine
$schedule = json_encode(['mon' => [['08:00', '17:30']]]);
$openTime = new DateTimeImmutable('2026-11-09 10:15:00', new DateTimeZone('Africa/Nairobi')); // Monday
$closingSoonTime = new DateTimeImmutable('2026-11-09 17:00:00', new DateTimeZone('Africa/Nairobi'));
$closedTime = new DateTimeImmutable('2026-11-09 20:00:00', new DateTimeZone('Africa/Nairobi'));

assertTrue(HoursEngine::evaluateStatus($schedule, $openTime)['state'] === 'OPEN_NOW', 'HoursEngine returns OPEN_NOW at 10:15 EAT');
assertTrue(HoursEngine::evaluateStatus($schedule, $closingSoonTime)['state'] === 'CLOSING_SOON', 'HoursEngine returns CLOSING_SOON 30m before close');
assertTrue(HoursEngine::evaluateStatus($schedule, $closedTime)['state'] === 'CLOSED', 'HoursEngine returns CLOSED after hours');

// 5. Verify WebsiteEnricher URL Auto-Fill & SSRF Guard
$ssrfUrls = [
    'http://127.0.0.1/admin',
    'http://localhost:8080/secret',
    'http://169.254.169.254/latest/meta-data/',
    'http://10.0.0.1/internal',
    'http://192.168.1.1/router',
    'file:///etc/passwd',
    'https://example.com:8443/custom-port',
];
foreach ($ssrfUrls as $badUrl) {
    $blocked = false;
    try {
        WebsiteEnricher::validateSafeUrlAndResolveIp($badUrl, fn($h) => ['127.0.0.1']);
    } catch (InvalidArgumentException $e) {
        $blocked = true;
    }
    assertTrue($blocked, "SSRF Guard blocks {$badUrl}");
}

$sampleHtml = '<html><head><title>Simba Dental Clinic | Family Dentist in Kilimani</title>'
    . '<meta name="description" content="Specialist dental clinic, orthodontics, and teeth whitening in Kilimani, Nairobi.">'
    . '</head><body><a href="tel:0722334455">Call</a><a href="mailto:smile@simbadental.co.ke">Email</a>'
    . '<a href="https://wa.me/254722334455">WA</a><a href="https://www.instagram.com/simbadental">IG</a></body></html>';
$enriched = WebsiteEnricher::extractFromHtml($sampleHtml, 'https://www.simbadental.co.ke', $pdo);
assertTrue($enriched['name'] === 'Simba Dental Clinic', 'WebsiteEnricher extracts Business Name from title/meta');
assertTrue($enriched['phone_primary'] === '+254722334455', 'WebsiteEnricher normalizes Kenyan phone to +254 format');
assertTrue($enriched['email_public'] === 'smile@simbadental.co.ke', 'WebsiteEnricher extracts public email');
assertTrue($enriched['area_neighborhood'] === 'Kilimani', 'WebsiteEnricher detects Kenyan neighborhood');
assertTrue($enriched['suggested_category'] !== null && str_contains($enriched['suggested_category']['specialty_name'], 'Dental'), 'WebsiteEnricher auto-suggests Level 1 & Level 2 Dental category');

// 6. Verify 7-Layer Public Form Anti-Spam Gate
$validTokens = AntiSpamGate::generateFormTokens(time() - 10);
$basePost = [
    'website' => '',
    'form_ts' => $validTokens['form_ts'],
    'form_nonce' => $validTokens['form_nonce'],
    'form_sig' => $validTokens['form_sig'],
    'js_interaction_token' => 'human_verified_12345',
    'submitter_name' => 'John Kamau',
    'name' => 'Kamau Engineering Ltd',
    'tagline' => 'Structural & Civil Engineering Consultants in Nairobi',
    'description' => 'Registered civil and structural engineers serving East Africa.',
    'website_url' => 'https://www.kamaueng.co.ke',
];

// Layer 1 Honeypot
$hpPost = $basePost;
$hpPost['website'] = 'http://spambot.ru';
$resHp = AntiSpamGate::verifySubmission($pdo, $hpPost, '198.51.100.10');
assertTrue(!$resHp['passed'] && $resHp['reason'] === 'layer1_honeypot_filled', 'AntiSpam Layer 1 blocks filled honeypot');

// Layer 2 Speed Trap (< 4s)
$fastTokens = AntiSpamGate::generateFormTokens(time() - 1);
$fastPost = array_merge($basePost, [
    'form_ts' => $fastTokens['form_ts'],
    'form_nonce' => $fastTokens['form_nonce'],
    'form_sig' => $fastTokens['form_sig'],
]);
$resFast = AntiSpamGate::verifySubmission($pdo, $fastPost, '198.51.100.11');
assertTrue(!$resFast['passed'] && $resFast['reason'] === 'layer2_submitted_too_fast', 'AntiSpam Layer 2 blocks <4s speed-trap submission');

// Layer 3 Missing JS Interaction Token
$noJsTokens = AntiSpamGate::generateFormTokens(time() - 10);
$noJsPost = array_merge($basePost, [
    'form_ts' => $noJsTokens['form_ts'],
    'form_nonce' => $noJsTokens['form_nonce'],
    'form_sig' => $noJsTokens['form_sig'],
    'js_interaction_token' => '',
]);
$resNoJs = AntiSpamGate::verifySubmission($pdo, $noJsPost, '198.51.100.12');
assertTrue(!$resNoJs['passed'] && $resNoJs['reason'] === 'layer3_missing_human_interaction_token', 'AntiSpam Layer 3 blocks missing JS interaction token');

// Layer 4 CamelCase Bot Name
$ccTokens = AntiSpamGate::generateFormTokens(time() - 10);
$ccPost = array_merge($basePost, [
    'form_ts' => $ccTokens['form_ts'],
    'form_nonce' => $ccTokens['form_nonce'],
    'form_sig' => $ccTokens['form_sig'],
    'submitter_name' => 'EdwardWaw',
]);
$resCc = AntiSpamGate::verifySubmission($pdo, $ccPost, '198.51.100.13');
assertTrue(!$resCc['passed'] && $resCc['reason'] === 'layer4_camelcase_bot_name', 'AntiSpam Layer 4 blocks single-word CamelCase bot names');

// Layer 6 CSV Formula Sanitization
assertTrue(AntiSpamGate::sanitizeForCsv("=CMD|'/C calc'!A0") === "'=CMD|'/C calc'!A0", 'AntiSpam Layer 6 prefixes leading = formula for CSV safety');

// 7. Verify VettingPurger Immediate Hard-Delete & Zero Vetting Columns on `businesses`
$cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
$colNames = array_column($cols, 'name');
assertTrue(!in_array('house_name', $colNames, true) && !in_array('year_left', $colNames, true) && !in_array('school_number', $colNames, true), 'businesses table has ZERO columns for house_name, year_left, or school_number');

$pdo->exec("
    INSERT INTO businesses (name, slug, tagline, description, school_badge, sector_id, specialty_id, service_mode, city_county, operating_hours_json, phone_primary, status)
    VALUES ('Test Vetting Biz', 'test-vetting-biz', 'Tagline', 'Desc', 'AHS', 1, 2, 'physical', 'Nairobi', '{}', '+254700111222', 'pending')
");
$testBizId = (int)$pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO business_vetting_queue (business_id, school_code, house_name, year_left, school_number, submitter_name, submitter_email, submitter_phone)
    VALUES (:id, 'AHS', 'Livingstone', 2005, '7123', 'Test Alumnus', 'alumnus@test.co.ke', '+254700111222')
")->execute([':id' => $testBizId]);

$beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM business_vetting_queue WHERE business_id = {$testBizId}")->fetchColumn();
assertTrue($beforeCount === 1, 'Pending business has 1 row in business_vetting_queue before review');

VettingPurger::approveBusiness($pdo, $testBizId);

$afterCount = (int)$pdo->query("SELECT COUNT(*) FROM business_vetting_queue WHERE business_id = {$testBizId}")->fetchColumn();
assertTrue($afterCount === 0, 'VettingPurger IMMEDIATELY hard-deletes House, Year Left & School Number upon approval');


// 8. Verify Ticket #10: AdminAuth constant-time credential verification
assertTrue(!AdminAuth::verifyCredentials('wrong_token'), 'AdminAuth rejects invalid admin token');
assertTrue(AdminAuth::verifyCredentials('test_admin_token_secret_12345'), 'AdminAuth accepts valid admin token in testing');

// 9. Verify Ticket #13: VettingPurger::enforceRetentionCeilings (>30-day storage limitation purge)
$pdo->exec("
    INSERT INTO businesses (name, slug, tagline, description, school_badge, sector_id, specialty_id, service_mode, city_county, operating_hours_json, phone_primary, status)
    VALUES ('Stale Pending Biz', 'stale-pending-biz', 'Tagline', 'Desc', 'AGHS', 1, 2, 'physical', 'Nairobi', '{}', '+254700999888', 'pending')
");
$staleBizId = (int)$pdo->lastInsertId();
$pdo->prepare("
    INSERT INTO business_vetting_queue (business_id, school_code, house_name, year_left, school_number, submitter_name, submitter_email, submitter_phone, created_at)
    VALUES (:id, 'AGHS', 'Watson', 2008, '8821', 'Stale Submitter', 'stale@test.co.ke', '+254700999888', '2020-01-01 00:00:00')
")->execute([':id' => $staleBizId]);

$purgeSummary = VettingPurger::enforceRetentionCeilings($pdo, 30);
$staleRemaining = (int)$pdo->query("SELECT COUNT(*) FROM business_vetting_queue WHERE business_id = {$staleBizId}")->fetchColumn();
assertTrue($staleRemaining === 0 && $purgeSummary['vetting_rows_purged'] >= 1, 'VettingPurger::enforceRetentionCeilings purges >30-day abandoned vetting rows');

// 10. Verify Ticket #14: WebsiteEnricher field_provenance tagging
assertTrue(!empty($enriched['field_provenance']['name']) && !empty($enriched['field_provenance']['suggested_category']), 'WebsiteEnricher attaches INDRA field_provenance tags to auto-filled fields');


// 11. Verify Ticket #20: VettingPurger::updateBusiness & VettingPurger::deleteBusiness + Mjenzi removal
$mjenziCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE slug = 'mjenzi'")->fetchColumn();
assertTrue($mjenziCount === 0, 'Mjenzi is removed from the seeded directory listings');

$bizToEdit = $pdo->query("SELECT * FROM businesses WHERE id = {$testBizId}")->fetch();
$bizToEdit['name'] = 'Updated Test Vetting Biz';
$bizToEdit['tagline'] = 'Updated Tagline';
VettingPurger::updateBusiness($pdo, $testBizId, $bizToEdit);
$updatedName = (string)$pdo->query("SELECT name FROM businesses WHERE id = {$testBizId}")->fetchColumn();
assertTrue($updatedName === 'Updated Test Vetting Biz', 'VettingPurger::updateBusiness updates existing business record');

$deletedOk = VettingPurger::deleteBusiness($pdo, $testBizId);
$afterDelCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE id = {$testBizId}")->fetchColumn();
assertTrue($deletedOk && $afterDelCount === 0, 'VettingPurger::deleteBusiness permanently deletes business record');

// 12. Verify Ticket #33: Regulated Level 2 Specialties & Conditional Pre-Filled Regulator Metadata
$advReg = CategoryMatcher::getRegulationInfoForSpecialty('Advocates & Law Firm');
assertTrue($advReg !== null && str_contains($advReg['default'], 'LSK'), 'Advocates & Law Firm is mapped to LSK statutory regulator');

$dentalReg = CategoryMatcher::getRegulationInfoForSpecialty('Dental Clinic & Orthodontics');
assertTrue($dentalReg !== null && str_contains($dentalReg['default'], 'KMPDC'), 'Dental Clinic & Orthodontics is mapped to KMPDC statutory regulator');

$unregulatedGym = CategoryMatcher::getRegulationInfoForSpecialty('Gym, Fitness & Yoga Studio');
assertTrue($unregulatedGym === null, 'Gym, Fitness & Yoga Studio is unregulated (no statutory regulator box)');

$unregulatedPizza = CategoryMatcher::getRegulationInfoForSpecialty('Restaurant, Nyama Choma & Bistro');
assertTrue($unregulatedPizza === null, 'Restaurant, Nyama Choma & Bistro is unregulated (no statutory regulator box)');

$advSpecId = (int)$pdo->query("SELECT id FROM categories WHERE name = 'Advocates & Law Firm' LIMIT 1")->fetchColumn();
$pizzaSpecId = (int)$pdo->query("SELECT id FROM categories WHERE name LIKE 'Restaurant, Nyama Choma%' LIMIT 1")->fetchColumn();
assertTrue(CategoryMatcher::isSpecialtyRegulated($pdo, $advSpecId), 'CategoryMatcher::isSpecialtyRegulated returns true for Advocates & Law Firm');
assertTrue(!CategoryMatcher::isSpecialtyRegulated($pdo, $pizzaSpecId), 'CategoryMatcher::isSpecialtyRegulated returns false for Restaurant specialty');

// 13. Verify Ticket #34: Favicon, OG Preview Assets & bbiz_head_meta_tags()
$headMeta = bbiz_head_meta_tags('Test Title', 'Test Description', '/');
assertTrue(str_contains($headMeta, '/favicon.svg') && str_contains($headMeta, '/assets/img/og-preview.png') && str_contains($headMeta, '/assets/img/bb-icon-512.png'), 'bbiz_head_meta_tags emits favicon.svg, og-preview.png, and bb-icon-512.png for WhatsApp & Search previews');
assertTrue(is_file(dirname(__DIR__) . '/public/favicon.svg') && is_file(dirname(__DIR__) . '/public/favicon.ico') && is_file(dirname(__DIR__) . '/public/assets/img/og-preview.png'), 'All favicon and OG preview image assets exist on disk');

// 14. Verify Tickets #35, #39 & #40: Kenyan Left/Right Route Directions (No Compass Bearings) & Kenyan Street Geocoder
$offlineRoute = LocationEngine::calculateRouteAndDirections(-1.344470, 36.728656, -1.278800, 36.771200, false);
$combinedInstr = strtolower(implode(' | ', array_column($offlineRoute['steps'], 'instruction')));
assertTrue(
    (str_contains($combinedInstr, 'turn left') || str_contains($combinedInstr, 'turn right'))
    && !preg_match('/\b(north|south|east|west|northwest|northeast|southwest|southeast)\b/i', $combinedInstr),
    'LocationEngine::calculateRouteAndDirections uses Kenyan left/right turns and omits compass bearings'
);

$maristGeo = LocationEngine::geocodeKenyanLocation('Marist Lane, Karen', false);
assertTrue(
    $maristGeo !== null
    && abs($maristGeo['latitude'] - (-1.344470)) < 0.01
    && abs($maristGeo['longitude'] - 36.728656) < 0.01
    && str_contains($maristGeo['label'], 'Marist Lane'),
    'LocationEngine::geocodeKenyanLocation resolves Marist Lane, Karen accurately'
);

// 15. Verify Ticket #42: Owner SSO / Magic-Link Auth, Post-Registration Corporate Domain & Claim Linking, and Self-Service Edits
$branfootOwner = OwnerAuth::upsertOwner($pdo, 'director@branfoot.com', 'Branfoot Director');
$branfootBizId = (int)$pdo->query("SELECT id FROM businesses WHERE slug = 'branfoot-enterprises' LIMIT 1")->fetchColumn();
assertTrue(
    $branfootBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$branfootOwner['id'], $branfootBizId),
    'Owner signing in with @branfoot.com is automatically linked to pre-populated Branfoot Enterprises Limited'
);

$bustaniOwner = OwnerAuth::upsertOwner($pdo, 'bustanibistro@gmail.com', 'Bustani Owner');
$bustaniBizId = (int)$pdo->query("SELECT id FROM businesses WHERE slug = 'bustani-bistro' LIMIT 1")->fetchColumn();
assertTrue(
    $bustaniBizId > 0 && OwnerAuth::canOwnerEditBusiness($pdo, (int)$bustaniOwner['id'], $bustaniBizId),
    'Owner signing in with bustanibistro@gmail.com is automatically linked via public_email_match'
);
assertTrue(
    !OwnerAuth::canOwnerEditBusiness($pdo, (int)$bustaniOwner['id'], $branfootBizId),
    'Owner of Bustani Bistro cannot edit unrelated Branfoot Enterprises listing'
);

// Verify signed 1-click DM Claim Link & Magic Link single-use consumption
$sasaBizId = (int)$pdo->query("SELECT id FROM businesses WHERE slug = 'sasasignal' LIMIT 1")->fetchColumn();
$claimUrl = OwnerAuth::generateClaimInviteUrl($sasaBizId);
$parsedClaim = [];
parse_str((string)parse_url($claimUrl, PHP_URL_QUERY), $parsedClaim);
assertTrue(
    OwnerAuth::verifyClaimInviteSignature((int)$parsedClaim['biz_id'], (int)$parsedClaim['exp'], (string)$parsedClaim['sig']),
    'OwnerAuth::generateClaimInviteUrl produces a valid HMAC-signed DM claim URL'
);

$magic = OwnerAuth::createMagicLink($pdo, 'personal.founder@gmail.com', $sasaBizId);
$consumed1 = OwnerAuth::verifyAndConsumeMagicLink($pdo, $magic['raw_token']);
$consumed2 = OwnerAuth::verifyAndConsumeMagicLink($pdo, $magic['raw_token']);
assertTrue(
    $consumed1 !== null && $consumed2 === null && OwnerAuth::canOwnerEditBusiness($pdo, (int)$consumed1['owner']['id'], $sasaBizId),
    'Single-use Magic Link authenticates owner, links claimed pre-populated business, and blocks replay'
);

// Verify Owner self-service edit: normal edit goes live immediately without admin alert; name/category edit alerts admin
$sasaBefore = $pdo->query("SELECT * FROM businesses WHERE id = {$sasaBizId}")->fetch();
$sasaBefore['tagline'] = 'Updated by Owner Without Admin Delay';
$editRes1 = VettingPurger::updateBusinessByOwner($pdo, $sasaBizId, $consumed1['owner'], $sasaBefore);
assertTrue(
    $editRes1['admin_notified'] === false && $editRes1['business']['tagline'] === 'Updated by Owner Without Admin Delay',
    'Owner contact/tagline edit goes live immediately without alerting admin'
);

$sasaBefore['name'] = 'SasaSignal Communications Ltd';
$editRes2 = VettingPurger::updateBusinessByOwner($pdo, $sasaBizId, $consumed1['owner'], $sasaBefore);
assertTrue(
    $editRes2['admin_notified'] === true && $editRes2['business']['name'] === 'SasaSignal Communications Ltd',
    'Owner Business Name change goes live and dispatches before/after notification to Admin'
);

// 16. Verify Ticket #44: Database::setSetting / getSetting for Google OAuth Client ID & Secret
assertTrue(!OwnerAuth::isGoogleSsoConfigured($pdo), 'Google SSO is initially unconfigured when no env/db credentials exist');
Database::setSetting($pdo, 'GOOGLE_CLIENT_ID', '123456789-test.apps.googleusercontent.com');
Database::setSetting($pdo, 'GOOGLE_CLIENT_SECRET', 'GOCSPX-testsecret123');
assertTrue(OwnerAuth::isGoogleSsoConfigured($pdo), 'Google SSO becomes active immediately when GOOGLE_CLIENT_ID & GOOGLE_CLIENT_SECRET are saved in app_settings');
assertTrue(str_contains(OwnerAuth::getGoogleAuthorizationUrl('state123', $pdo), '123456789-test.apps.googleusercontent.com'), 'getGoogleAuthorizationUrl uses DB-stored GOOGLE_CLIENT_ID');
Database::setSetting($pdo, 'GOOGLE_CLIENT_ID', '');
Database::setSetting($pdo, 'GOOGLE_CLIENT_SECRET', '');

// 17. Verify Tickets #46, #88, #89: Sunday operating hours in HoursEngine::buildScheduleJsonFromPost & extractFormValues
$sunSchedJson = HoursEngine::buildScheduleJsonFromPost([
    'hours_weekday_open' => '08:00',
    'hours_weekday_close' => '17:30',
    'hours_sat_open' => '09:00',
    'hours_sat_close' => '15:00',
    'hours_sun_open' => '10:00',
    'hours_sun_close' => '16:00',
]);
$extractedHours = HoursEngine::extractFormValues($sunSchedJson);
assertTrue(
    $extractedHours['sun_closed'] === false
    && $extractedHours['sun_open'] === '10:00'
    && $extractedHours['sun_close'] === '16:00',
    'HoursEngine::buildScheduleJsonFromPost & extractFormValues persist and restore Sunday hours'
);
$sundayNoon = new DateTimeImmutable('2026-11-08 12:30:00', new DateTimeZone('Africa/Nairobi')); // Sunday
$sunEval = HoursEngine::evaluateStatus($sunSchedJson, $sundayNoon);
assertTrue(
    $sunEval['is_open'] === true && $sunEval['state'] === 'OPEN_NOW',
    'HoursEngine::evaluateStatus reports OPEN_NOW on Sunday when Sunday hours are configured'
);

// 18. Verify Tickets #90, #91, #92, #93: PhotoUploader GD re-encoding, EXIF stripping, 5-photo cap & removal
$testDataUrls = [];
for ($i = 0; $i < 6; $i++) {
    $im = imagecreatetruecolor(40, 30);
    $col = imagecolorallocate($im, 20 * $i, 100, 150);
    imagefilledrectangle($im, 0, 0, 40, 30, $col);
    ob_start();
    imagejpeg($im, null, 85);
    $jpegBytes = (string)ob_get_clean();
    imagedestroy($im);
    $testDataUrls[] = 'data:image/jpeg;base64,' . base64_encode($jpegBytes);
}
$savedPhotosJson = PhotoUploader::processBusinessPhotos(null, [], null, json_encode($testDataUrls));
$decodedPhotos = PhotoUploader::decodePhotosJson($savedPhotosJson);
assertTrue(
    count($decodedPhotos) === 5 && str_starts_with($decodedPhotos[0], '/uploads/businesses/bbiz_'),
    'PhotoUploader::processBusinessPhotos sanitizes images via GD and enforces a strict cap of 5 photos'
);
$afterRemoveJson = PhotoUploader::processBusinessPhotos($savedPhotosJson, [$decodedPhotos[0]], null, null);
$afterRemoveList = PhotoUploader::decodePhotosJson($afterRemoveJson);
assertTrue(
    count($afterRemoveList) === 4 && !in_array($decodedPhotos[0], $afterRemoveList, true),
    'PhotoUploader::processBusinessPhotos removes checked photos accurately'
);
foreach ($decodedPhotos as $relUrl) {
    $fPath = dirname(__DIR__) . '/public' . $relUrl;
    if (is_file($fPath)) {
        @unlink($fPath);
    }
}

// 19. Verify Tickets #96, #97, #98: 2-Level Place Taxonomy & Resolution (Country -> City/County/State)
$placeHierarchy = LocationEngine::getPlaceHierarchy();
assertTrue(
    isset($placeHierarchy['Kenya']) && count($placeHierarchy['Kenya']) >= 47,
    'LocationEngine::getPlaceHierarchy includes Kenya with all 47 counties + key towns'
);
assertTrue(
    isset($placeHierarchy['United Kingdom']) && in_array('London', $placeHierarchy['United Kingdom'], true),
    'LocationEngine::getPlaceHierarchy includes diaspora countries and cities'
);
$resolvedPreset = LocationEngine::resolvePlaceFromPost(['country' => 'Kenya', 'city_county' => 'Mombasa']);
assertTrue(
    $resolvedPreset['country'] === 'Kenya' && $resolvedPreset['city_county'] === 'Mombasa',
    'LocationEngine::resolvePlaceFromPost resolves preset country and city/county'
);
$resolvedCustom = LocationEngine::resolvePlaceFromPost([
    'country' => '__custom__',
    'country_custom' => 'Switzerland',
    'city_county' => '__custom__',
    'city_county_custom' => 'Geneva',
]);
assertTrue(
    $resolvedCustom['country'] === 'Switzerland' && $resolvedCustom['city_county'] === 'Geneva',
    'LocationEngine::resolvePlaceFromPost resolves custom country and city/state'
);

// 20. Verify Ticket #99: AGHS Houses include Joan Mjomba & Rebecca Karanja
assertTrue(
    in_array('Joan Mjomba', Houses::AGHS_HOUSES, true) && in_array('Rebecca Karanja', Houses::AGHS_HOUSES, true),
    'AGHS houses include both Joan Mjomba and Rebecca Karanja (#99)'
);
assertTrue(
    Houses::isValidHouse('AGHS', 'Joan Mjomba') && Houses::isValidHouse('AGHS', 'Rebecca Karanja'),
    'Houses::isValidHouse accepts Joan Mjomba and Rebecca Karanja for AGHS'
);

// 21. Verify Tickets #109, #110, #111: Lay-friendly copy across the site (zero <code>, SQL, or submit.php jargon)
$submitHtml = (string)file_get_contents(dirname(__DIR__) . '/public/submit.php');
$ownerLoginHtml = (string)file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
$privacyHtml = (string)file_get_contents(dirname(__DIR__) . '/public/privacy.php');
$takedownHtml = (string)file_get_contents(dirname(__DIR__) . '/public/takedown.php');

assertTrue(
    !str_contains($submitHtml, 'DELETE FROM business_vetting_queue') && !str_contains($submitHtml, 'Schema.org JSON-LD'),
    'submit.php contains zero SQL DELETE commands or Schema.org JSON-LD developer jargon (#110)'
);
assertTrue(
    !str_contains($ownerLoginHtml, '<code>') && !str_contains($ownerLoginHtml, '/submit.php</code>'),
    'owner/login.php contains zero <code> tags or /submit.php filename jargon (#110)'
);
assertTrue(
    !str_contains($privacyHtml, '<code>') && !str_contains($privacyHtml, 'DELETE FROM') && !str_contains($privacyHtml, 'HMAC-SHA256'),
    'privacy.php contains zero <code> tags, SQL commands, or cryptographic jargon (#111)'
);
assertTrue(
    !str_contains($takedownHtml, '<code>') && !str_contains($takedownHtml, 'quarantined_dpa</code>'),
    'takedown.php contains zero <code> tags or internal status identifiers (#111)'
);

// 22. Verify Tickets #112, #113, #114: Arts, Visual & Performing Arts sector, specialties & search synonyms
$sectorNames = array_map(fn($s) => $s['name'], $tree);
assertTrue(
    in_array('Arts, Visual & Performing Arts', $sectorNames, true),
    'Taxonomy includes Arts, Visual & Performing Arts sector (#113)'
);
$artsSector = array_values(array_filter($tree, fn($s) => $s['name'] === 'Arts, Visual & Performing Arts'))[0] ?? null;
$artsSpecs = $artsSector ? array_map(fn($sp) => $sp['name'], $artsSector['specialties']) : [];
assertTrue(
    in_array('Visual Arts, Fine Art & Galleries', $artsSpecs, true) &&
    in_array('Performing Arts, Theatre & Dance', $artsSpecs, true) &&
    in_array('Music, Live Bands, DJs & Sound Production', $artsSpecs, true),
    'Arts sector includes Visual Arts, Performing Arts, and Music specialties (#113)'
);
$sanaaExpanded = CategoryMatcher::expandSearchTerms('sanaa');
assertTrue(
    in_array('Visual Arts', $sanaaExpanded, true) && in_array('Performing Arts', $sanaaExpanded, true),
    'Swahili term "sanaa" expands to Visual Arts and Performing Arts (#113)'
);

// 23. Verify Ticket #115: "Same as Direct Phone above" checkboxes on /submit.php & form-enhancements.js
$feJs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/form-enhancements.js');
assertTrue(
    str_contains($submitHtml, 'id="same_as_direct_phone_primary"') &&
    str_contains($submitHtml, 'id="same_as_direct_phone_whatsapp"') &&
    str_contains($feJs, 'bindSameAsDirectPhone'),
    'submit.php and form-enhancements.js wire Same as Direct Phone checkboxes for Public Phone and WhatsApp (#115)'
);

// 24. Verify Ticket #116: All 197 world countries included in LocationEngine::getPlaceHierarchy
assertTrue(
    count($placeHierarchy) >= 195 &&
    isset($placeHierarchy['Afghanistan']) &&
    isset($placeHierarchy['Japan']) &&
    isset($placeHierarchy['Brazil']) &&
    isset($placeHierarchy['Zimbabwe']) &&
    count($placeHierarchy['United States']) === 51,
    'LocationEngine::getPlaceHierarchy includes all 197 world countries and all 50 US states + D.C. (#116)'
);

// 25. Verify Tickets #118, #119, #120: Owner Password Auth + Rolling Session Renewal
$pwOwner = OwnerAuth::upsertOwner($pdo, 'owner-pw@branfoot.com', 'Pw Owner', '+254722111222');
OwnerAuth::setOwnerPassword($pdo, (int)$pwOwner['id'], 'BusherianStrong2026!');
$authOk = OwnerAuth::authenticateWithPassword($pdo, 'owner-pw@branfoot.com', 'BusherianStrong2026!');
assertTrue(
    $authOk !== null && (int)$authOk['id'] === (int)$pwOwner['id'],
    'OwnerAuth::authenticateWithPassword authenticates valid owner password across devices (#119)'
);
$authBad = OwnerAuth::authenticateWithPassword($pdo, 'owner-pw@branfoot.com', 'WrongPassword999');
assertTrue(
    $authBad === null,
    'OwnerAuth::authenticateWithPassword rejects invalid password (#119)'
);

// 26. Verify Ticket #122: School-themed map markers (AHS green #114B3E, AGHS green-maroon gradient blend)
$dirJs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
$appCss = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
assertTrue(
    str_contains($dirJs, 'bbiz-pin-aghs') &&
    str_contains($dirJs, 'bbiz-pin-ahs') &&
    str_contains($dirJs, 'linear-gradient(135deg, #114B3E 0%, #1F513F 32%, #582428 68%, #7B1113 100%)') &&
    str_contains($appCss, '.bbiz-pin-aghs'),
    'Map markers use solid green (#114B3E) for AHS and the green-maroon logo gradient blend for AGHS (#122)'
);

@unlink(getenv('DB_SQLITE_PATH'));



if ($failed > 0) {
    exit(1);
}
echo "
All {$passed} PHP unit assertions passed!
";
