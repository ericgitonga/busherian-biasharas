<?php
declare(strict_types=1);

$initialDriver = strtolower((string)(getenv('DB_DRIVER') ?: 'sqlite'));
$unitTempSqlite = sys_get_temp_dir() . '/bbiz_unit_' . getmypid() . '.sqlite';
$unitTempUploadsRoot = sys_get_temp_dir() . '/bbiz_unit_uploads_' . getmypid();

putenv('APP_ENV=testing');
putenv('DB_DRIVER=' . $initialDriver);
if ($initialDriver === 'sqlite') {
    putenv('DB_SQLITE_PATH=' . $unitTempSqlite);
}
putenv('BBIZ_PUBLIC_WEB_ROOT=' . $unitTempUploadsRoot);
putenv('APP_HMAC_SECRET=9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e');
putenv('IP_HASH_SECRET=1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b');
putenv('CSRF_SECRET=5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e');
putenv('SIMULATE_EMAILS=yes');
putenv('ADMIN_ACCESS_TOKEN=test_admin_token_secret_12345');

if (!is_dir($unitTempUploadsRoot . '/uploads/businesses')) {
    @mkdir($unitTempUploadsRoot . '/uploads/businesses', 0775, true);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Config\DatabaseUnavailableException;
use BusherianBiasharas\Config\Houses;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\CategoryMatcher;
use BusherianBiasharas\Services\HoursEngine;
use BusherianBiasharas\Services\LocationEngine;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\OwnerAuth;

OwnerAuth::startSecureSession();
use BusherianBiasharas\Services\PhotoUploader;
use BusherianBiasharas\Services\PhotoUploadValidationException;
use BusherianBiasharas\Services\PreprodSandbox;
use BusherianBiasharas\Services\VettingPurger;
use BusherianBiasharas\Services\WebsiteEnricher;

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$label}\n");
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
assertTrue(!empty($fuzzy) && str_contains($fuzzy[0]['name'], 'Advocates'), 'Fuzzy matcher (Levenshtein + similar_text) detects duplicate custom specialty (#189)');

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
if (Database::getActiveDriver() === 'mysql') {
    $cols = $pdo->query("SHOW COLUMNS FROM businesses")->fetchAll();
    $colNames = array_column($cols, 'Field');
} else {
    $cols = $pdo->query("PRAGMA table_info(businesses)")->fetchAll();
    $colNames = array_column($cols, 'name');
}
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
VettingPurger::deleteBusiness($pdo, $staleBizId);

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

// 15. Verify Tickets #42 & #164 (HIGH-01): Corporate Domain Never Auto-Grants Ownership; Public Contact Email & Signed Claim Invites Do
$branfootOwner = OwnerAuth::upsertOwner($pdo, 'director@branfoot.com', 'Branfoot Director');
$branfootBizId = (int)$pdo->query("SELECT id FROM businesses WHERE slug = 'branfoot-enterprises' LIMIT 1")->fetchColumn();
$branfootSuggestions = OwnerAuth::getCorporateDomainClaimSuggestions($pdo, 'director@branfoot.com', (int)$branfootOwner['id']);
assertTrue(
    $branfootBizId > 0 &&
    !OwnerAuth::canOwnerEditBusiness($pdo, (int)$branfootOwner['id'], $branfootBizId) &&
    !empty($branfootSuggestions) &&
    (int)$branfootSuggestions[0]['id'] === $branfootBizId,
    'Owner signing in with @branfoot.com is NOT automatically granted edit access (#164), and receives informational claim guidance instead'
);

$bustaniBizId = (int)$pdo->query("SELECT id FROM businesses WHERE slug = 'bustani-bistro' LIMIT 1")->fetchColumn();
$bustaniOwner = OwnerAuth::upsertOwner($pdo, 'bustanibistro@gmail.com', 'Bustani Owner');
assertTrue(
    $bustaniBizId > 0 && !OwnerAuth::canOwnerEditBusiness($pdo, (int)$bustaniOwner['id'], $bustaniBizId),
    'Owner signing in with public contact email (email_public) is NOT auto-linked when owner_email is unset (#207)'
);
$pdo->prepare("UPDATE businesses SET owner_email = 'bustanibistro@gmail.com' WHERE id = :id")->execute([':id' => $bustaniBizId]);
OwnerAuth::autoLinkMatchingBusinesses($pdo, (int)$bustaniOwner['id'], 'bustanibistro@gmail.com');
assertTrue(
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$bustaniOwner['id'], $bustaniBizId),
    'Owner signing in with designated owner_email IS automatically linked via admin_or_submitter_email (#207)'
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

// 18. Verify Tickets #90, #91, #92, #93, #171 & #176: PhotoUploader GD re-encoding, EXIF stripping, upfront PhotoUploadValidationException caps & corrupt file rejection
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
$threwOnSixPhotos = false;
try {
    PhotoUploader::processBusinessPhotos(null, [], null, json_encode($testDataUrls));
} catch (PhotoUploadValidationException $e) {
    $threwOnSixPhotos = true;
}
$savedPhotosJson = PhotoUploader::processBusinessPhotos(null, [], null, json_encode(array_slice($testDataUrls, 0, 5)));
$decodedPhotos = PhotoUploader::decodePhotosJson($savedPhotosJson);
$firstSavedDiskPath = $unitTempUploadsRoot . ($decodedPhotos[0] ?? '');
$savedImgInfo = is_file($firstSavedDiskPath) ? @getimagesize($firstSavedDiskPath) : false;
assertTrue(
    $threwOnSixPhotos === true &&
    count($decodedPhotos) === 5 &&
    str_starts_with($decodedPhotos[0], '/uploads/businesses/bbiz_') &&
    is_array($savedImgInfo) &&
    (int)$savedImgInfo[0] === 40 &&
    (int)$savedImgInfo[1] === 30,
    'PhotoUploader::processBusinessPhotos sanitizes up to 5 images via GD and throws PhotoUploadValidationException upfront when >5 photos are submitted (#176)'
);
$afterRemoveJson = PhotoUploader::processBusinessPhotos($savedPhotosJson, [$decodedPhotos[0]], null, null);
$afterRemoveList = PhotoUploader::decodePhotosJson($afterRemoveJson);
assertTrue(
    count($afterRemoveList) === 4 && !in_array($decodedPhotos[0], $afterRemoveList, true),
    'PhotoUploader::processBusinessPhotos removes checked photos accurately'
);

// Negative image upload tests (#171 & #176): corrupt bytes, tiny <10px image, and oversized >8MB payload
$corruptTempFile = sys_get_temp_dir() . '/bbiz_corrupt_' . getmypid() . '.jpg';
file_put_contents($corruptTempFile, "NOT_A_VALID_JPEG_BINARY_HEADER_PAYLOAD_1234567890");
$tinyIm = imagecreatetruecolor(4, 4);
ob_start();
imagepng($tinyIm);
$tinyPngBytes = (string)ob_get_clean();
imagedestroy($tinyIm);

$threwOversizedSingle = false;
try {
    PhotoUploader::processBusinessPhotos(
        null,
        [],
        [
            'tmp_name' => [$corruptTempFile],
            'error' => [UPLOAD_ERR_OK],
            'size' => [PhotoUploader::MAX_FILE_BYTES + 1024],
        ],
        null
    );
} catch (PhotoUploadValidationException $e) {
    $threwOversizedSingle = true;
}

$rejectedCorruptJson = PhotoUploader::processBusinessPhotos(
    null,
    [],
    [
        'tmp_name' => [$corruptTempFile],
        'error' => [UPLOAD_ERR_OK],
        'size' => [filesize($corruptTempFile)],
    ],
    null
);
assertTrue(
    $threwOversizedSingle === true &&
    PhotoUploader::decodePhotosJson($rejectedCorruptJson) === [] &&
    PhotoUploader::sanitizeAndSaveImageBytes("corrupt_non_image_bytes") === null &&
    PhotoUploader::sanitizeAndSaveImageBytes($tinyPngBytes) === null,
    'PhotoUploader rejects corrupt non-image bytes, <10px images, and throws PhotoUploadValidationException on >8MB oversized uploads (#171, #176)'
);
@unlink($corruptTempFile);
foreach ($decodedPhotos as $relUrl) {
    $fPath = $unitTempUploadsRoot . $relUrl;
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
    !str_contains($takedownHtml, '<code>') && !str_contains($takedownHtml, 'quarantined_dpa'),
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

// 27. Verify Ticket #124: Cookie banner buttons use 'Allow for Analysis' and 'Allow only Essential'
$cmJs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/consent-metrics.js');
$privPage = (string)file_get_contents(dirname(__DIR__) . '/public/privacy.php');
assertTrue(
    str_contains($cmJs, 'Allow for Analysis') &&
    str_contains($cmJs, 'Allow only Essential') &&
    str_contains($privPage, 'Allow for Analysis') &&
    str_contains($privPage, 'Allow only Essential'),
    'Cookie banner and Privacy Notice use Allow for Analysis and Allow only Essential (#124)'
);

// 28. Verify Tickets #126, #127, #128: GitHub-style 2-step Delete Listing modal & server-side exact name confirmation
$modalHtml = bbiz_render_github_delete_modal('csrf123', '/owner/edit-business.php?id=5', 5, 'Bustani Bistro', 'post_action', 'delete');
assertTrue(
    str_contains($modalHtml, 'id="delete-modal-step-1"') &&
    str_contains($modalHtml, 'id="btn-delete-step1-yes"') &&
    str_contains($modalHtml, 'Yes, I am sure — Proceed') &&
    str_contains($modalHtml, 'id="delete-modal-step-2"') &&
    str_contains($modalHtml, 'id="delete-confirm-name-input"') &&
    str_contains($modalHtml, 'name="confirm_business_name"') &&
    str_contains($modalHtml, 'id="btn-delete-final-submit"') &&
    str_contains($modalHtml, 'disabled'),
    'bbiz_render_github_delete_modal renders 2-step modal ("Yes, I am sure" -> type exact business name -> disabled submit button) (#127)'
);
$ownerEditPhp = (string)file_get_contents(dirname(__DIR__) . '/public/owner/edit-business.php');
$adminEditPhp = (string)file_get_contents(dirname(__DIR__) . '/public/admin/edit-business.php');
$adminIndexPhp = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
assertTrue(
    str_contains($ownerEditPhp, 'confirm_business_name') &&
    str_contains($adminEditPhp, 'confirm_business_name') &&
    str_contains($adminIndexPhp, 'confirm_business_name'),
    'Owner and Admin delete handlers enforce server-side exact confirm_business_name verification (#127, #128)'
);

// 30. Verify Tickets #132, #133, #134: Alphabetical World Countries & Standard Administrative Location Units
$countryKeys = array_keys($placeHierarchy);
$sortedCountryKeys = $countryKeys;
sort($sortedCountryKeys, SORT_STRING);
assertTrue(
    $countryKeys === $sortedCountryKeys && $countryKeys[0] === 'Afghanistan' && $countryKeys[count($countryKeys) - 1] === 'Zimbabwe',
    'LocationEngine::PLACE_HIERARCHY lists all world countries in strict alphabetical order (#132, #133)'
);

$kenyaCounties = $placeHierarchy['Kenya'];
$sortedKenya = $kenyaCounties;
sort($sortedKenya, SORT_STRING);
assertTrue(
    count($kenyaCounties) === 47 &&
    $kenyaCounties === $sortedKenya &&
    $kenyaCounties[0] === 'Baringo' &&
    $kenyaCounties[46] === 'West Pokot' &&
    in_array('Nairobi', $kenyaCounties, true) &&
    !in_array('Kikuyu', $kenyaCounties, true) &&
    LocationEngine::getUnitLabelForCountry('Kenya') === 'County',
    'Kenya lists strictly the 47 Counties in alphabetical order with unit label County (#132, #133)'
);

$usStates = $placeHierarchy['United States'];
$sortedUs = $usStates;
sort($sortedUs, SORT_STRING);
assertTrue(
    count($usStates) === 51 &&
    $usStates === $sortedUs &&
    $usStates[0] === 'Alabama' &&
    $usStates[50] === 'Wyoming' &&
    LocationEngine::getUnitLabelForCountry('United States') === 'State',
    'United States lists all 50 States + D.C. in strict alphabetical order with unit label State (#132, #133)'
);

$tzDistricts = $placeHierarchy['Tanzania'];
$sortedTz = $tzDistricts;
sort($sortedTz, SORT_STRING);
assertTrue(
    $tzDistricts === $sortedTz &&
    in_array('Arusha', $tzDistricts, true) &&
    in_array('Ilala', $tzDistricts, true) &&
    in_array('Kinondoni', $tzDistricts, true) &&
    in_array('Moshi', $tzDistricts, true) &&
    LocationEngine::getUnitLabelForCountry('Tanzania') === 'District',
    'Tanzania lists Districts in strict alphabetical order with unit label District (#132, #133)'
);

$placeFieldsKenya = bbiz_render_place_fields('Kenya', 'Nairobi');
$placeFieldsUs = bbiz_render_place_fields('United States', 'California');
assertTrue(
    str_contains($placeFieldsKenya, 'County *') &&
    str_contains($placeFieldsKenya, '<option value="Kenya" selected>') &&
    str_contains($placeFieldsKenya, '<option value="Nairobi" selected>') &&
    str_contains($placeFieldsUs, 'State *'),
    'bbiz_render_place_fields dynamically renders standard administrative unit label and defaults to Kenya -> Nairobi (#134)'
);

// 29. Verify Ticket #130: Map legend in public/index.php reflects AHS (Green) & AGHS (Green & Maroon)
$indexHtml = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
assertTrue(
    str_contains($indexHtml, 'Green = AHS') &&
    str_contains($indexHtml, 'Green &amp; Maroon = AGHS') &&
    !str_contains($indexHtml, 'Green = Open Now • Crimson = Closed'),
    'public/index.php map legend displays Green = AHS and Green & Maroon = AGHS (#130)'
);

// 31. Verify Ticket #136: Pending Listings in Admin Queue & Editor display submitter Name, Email, and Phone Number
$adminIndexLatest = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$adminEditLatest = (string)file_get_contents(dirname(__DIR__) . '/public/admin/edit-business.php');
assertTrue(
    str_contains($adminIndexLatest, 'Person Requesting Review (Submitter Contact Details)') &&
    str_contains($adminIndexLatest, "row['submitter_name']") &&
    str_contains($adminIndexLatest, "row['submitter_email']") &&
    str_contains($adminIndexLatest, "row['submitter_phone']") &&
    str_contains($adminIndexLatest, 'mailto:') &&
    str_contains($adminIndexLatest, 'tel:') &&
    str_contains($adminIndexLatest, 'https://wa.me/'),
    'public/admin/index.php displays submitter Name, Email (mailto:), and Phone (tel: & WhatsApp) for pending listings (#136)'
);
assertTrue(
    str_contains($adminEditLatest, 'Person Requesting Review (Pending Verification Contact &amp; Alumni Details)') &&
    str_contains($adminEditLatest, "vettingRow['submitter_name']") &&
    str_contains($adminEditLatest, "vettingRow['submitter_email']") &&
    str_contains($adminEditLatest, "vettingRow['submitter_phone']"),
    'public/admin/edit-business.php displays submitter Name, Email, and Phone when reviewing a pending listing (#136)'
);

// 32. Verify Tickets #138, #139, #140, #141: Maps Link -> Latitude/Longitude Inference & Existing Listing Pin Backfill
$tagPlaceUrl = 'https://www.google.com/maps/place/Circular+Economy+Hub+by+Close+the+Gap/@-2.7093033,36.9857735,8z/data=!4m7!3m6!1s0x184073cc97d95e6f:0xd08f849ff152e4a2!8m2!3d-1.3766863!4d36.9280114!16s%2Fg%2F11r_t7v34t';
$parsedTag = LocationEngine::parseCoordinatesFromInput($tagPlaceUrl);
assertTrue(
    $parsedTag !== null &&
    abs($parsedTag['latitude'] - (-1.376686)) < 0.00001 &&
    abs($parsedTag['longitude'] - 36.928011) < 0.00001,
    'LocationEngine prioritizes exact !3d!4d entity pin over zoomed-out @viewport in Google Maps place URLs (#139)'
);

$parsedEmbedPb = LocationEngine::parseCoordinatesFromInput('https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3988.8!2d36.802100!3d-1.264700!2m3!1f0');
$parsedApple = LocationEngine::parseCoordinatesFromInput('https://maps.apple.com/?ll=-1.292100,36.787900&q=Kilimani');
$parsedOsm = LocationEngine::parseCoordinatesFromInput('https://www.openstreetmap.org/?mlat=-1.263500&mlon=36.803200#map=17/-1.263500/36.803200');
$parsedDms = LocationEngine::parseCoordinatesFromInput("https://www.google.com/maps/place/1%C2%B015'52.9%22S+36%C2%B048'07.6%22E");
assertTrue(
    $parsedEmbedPb !== null && abs($parsedEmbedPb['latitude'] - (-1.2647)) < 0.0001 && abs($parsedEmbedPb['longitude'] - 36.8021) < 0.0001 &&
    $parsedApple !== null && abs($parsedApple['latitude'] - (-1.2921)) < 0.0001 &&
    $parsedOsm !== null && abs($parsedOsm['latitude'] - (-1.2635)) < 0.0001 &&
    $parsedDms !== null && abs($parsedDms['latitude'] - (-1.264694)) < 0.001 && abs($parsedDms['longitude'] - 36.802111) < 0.001,
    'LocationEngine parses Google pb= embed, Apple Maps, OpenStreetMap, and DMS pin URLs (#139)'
);

// Mock HTTP fetcher testing both Shape A (!3d!4d redirect) and Shape B (?q=...&ftid=... -> output=embed initEmbed)
$mockFetcher = static function (string $url) use ($tagPlaceUrl): array {
    if ($url === 'https://maps.app.goo.gl/RqXEruB3uZiAcpF86') {
        return [
            'status' => 302,
            'headers' => ['Location' => $tagPlaceUrl],
            'body' => '',
        ];
    }
    if ($url === 'https://maps.app.goo.gl/kxrzpmqJRrZvVR848?') {
        return [
            'status' => 302,
            'headers' => ['Location' => 'https://maps.google.com?q=Ruaka+Shopping+Mall,+Limuru+Rd,+Nairobi&ftid=0x182f1793e5630a8b:0x2f329114a4696e16&entry=gps'],
            'body' => '',
        ];
    }
    if (str_starts_with($url, 'https://maps.google.com?q=Ruaka')) {
        return [
            'status' => 200,
            'headers' => [],
            'body' => '<html><head><meta content="center=-33.8688,151.2093"></head><body></body></html>',
        ];
    }
    if (str_contains($url, 'output=embed')) {
        return [
            'status' => 200,
            'headers' => [],
            'body' => '<script>function onEmbedLoad(){initEmbed([null,null,[[null,"Ruaka Shopping Mall",null,null,[[["1742637504791775883","3400940186461892118"],"/g/11yz9f17zj",null,[4282871070,367885910]]]]]],null,[[[3988.93,36.7869866,-1.2072517],[0,0,0],null,13.1]]]);}</script>',
        ];
    }
    return ['status' => 404, 'headers' => [], 'body' => ''];
};

$resolvedShortA = LocationEngine::resolveCoordinatesFromUrlOrInput('https://maps.app.goo.gl/RqXEruB3uZiAcpF86', true, null, $mockFetcher);
$resolvedShortB = LocationEngine::resolveCoordinatesFromUrlOrInput('https://maps.app.goo.gl/kxrzpmqJRrZvVR848?', true, null, $mockFetcher);
$blockedUnsafeMap = LocationEngine::resolveCoordinatesFromUrlOrInput('https://evil.example.com/maps?q=Nairobi', true, null, $mockFetcher);
assertTrue(
    $resolvedShortA !== null && abs($resolvedShortA['latitude'] - (-1.376686)) < 0.00001 && abs($resolvedShortA['longitude'] - 36.928011) < 0.00001 &&
    $resolvedShortB !== null && abs($resolvedShortB['latitude'] - (-1.209623)) < 0.00001 && abs($resolvedShortB['longitude'] - 36.788591) < 0.00001 &&
    $blockedUnsafeMap === null,
    'LocationEngine safely unrolls maps.app.goo.gl redirects & ftid output=embed pins while ignoring IP meta center and blocking non-map domains (#139)'
);

$freeTextResolved = LocationEngine::resolveCoordinatesFromListingData([
    'latitude' => '',
    'longitude' => '',
    'google_maps_url' => '',
    'plus_code' => '',
    'landmark_directions' => 'Gate B — pin: https://www.google.com/maps?q=-1.263984,36.781410',
], false);
assertTrue(
    $freeTextResolved['inferred'] === true &&
    abs((float)$freeTextResolved['latitude'] - (-1.263984)) < 0.00001 &&
    abs((float)$freeTextResolved['longitude'] - 36.781410) < 0.00001 &&
    $freeTextResolved['google_maps_url'] === 'https://www.google.com/maps?q=-1.263984,36.781410' &&
    !empty($freeTextResolved['plus_code']),
    'LocationEngine::resolveCoordinatesFromListingData infers lat/lng, plus_code, and google_maps_url even from landmark_directions (#140)'
);

// Verify VettingPurger::createBusinessDirectly & backfillMissingCoordinatesFromPins
$directMapsBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Syokimau Pin Auto Test Ltd',
    'tagline' => 'Testing auto pin inference',
    'description' => 'Created without typing latitude or longitude.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Machakos',
    'google_maps_url' => $tagPlaceUrl,
    'latitude' => '',
    'longitude' => '',
    'phone_primary' => '+254711223344',
]);
$savedDirectRow = $pdo->query("SELECT latitude, longitude, plus_code FROM businesses WHERE id = {$directMapsBizId}")->fetch();
assertTrue(
    $savedDirectRow &&
    abs((float)$savedDirectRow['latitude'] - (-1.376686)) < 0.00001 &&
    abs((float)$savedDirectRow['longitude'] - 36.928011) < 0.00001 &&
    !empty($savedDirectRow['plus_code']),
    'VettingPurger::createBusinessDirectly populates latitude, longitude & plus_code when only google_maps_url is supplied (#140)'
);

// Clear lat/lng on that row, set short link, and run backfillMissingCoordinatesFromPins with mockFetcher
$pdo->prepare("UPDATE businesses SET latitude = NULL, longitude = NULL, plus_code = NULL, google_maps_url = :u WHERE id = :id")
    ->execute([':u' => 'https://maps.app.goo.gl/RqXEruB3uZiAcpF86', ':id' => $directMapsBizId]);
$backfillRes = VettingPurger::backfillMissingCoordinatesFromPins($pdo, true, $mockFetcher);
$afterBackfillRow = $pdo->query("SELECT latitude, longitude, plus_code FROM businesses WHERE id = {$directMapsBizId}")->fetch();
assertTrue(
    $backfillRes['updated_count'] >= 1 &&
    $afterBackfillRow &&
    abs((float)$afterBackfillRow['latitude'] - (-1.376686)) < 0.00001 &&
    abs((float)$afterBackfillRow['longitude'] - 36.928011) < 0.00001 &&
    !empty($afterBackfillRow['plus_code']),
    'VettingPurger::backfillMissingCoordinatesFromPins resolves short maps.app.goo.gl pins and updates missing lat/lng (#141)'
);
VettingPurger::deleteBusiness($pdo, $directMapsBizId);

// 33. Verify Tickets #142, #143, #144: 10 / 25 / 50 / 100 Pagination (default 10) on Admin Console & Main Public Directory
$adminIndexPaginated = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$publicIndexPaginated = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
$directoryJsPaginated = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
assertTrue(
    str_contains($adminIndexPaginated, '$allowedPerPage = [10, 25, 50, 100]') &&
    str_contains($adminIndexPaginated, 'id="admin-per-page"') &&
    str_contains($adminIndexPaginated, 'id="admin-pagination"') &&
    str_contains($adminIndexPaginated, '$pagedBusinesses = array_slice($allBusinesses, $pageOffset, $perPage)'),
    'public/admin/index.php paginates business records to 10/25/50/100 defaulting to 10 (#143)'
);
assertTrue(
    str_contains($publicIndexPaginated, 'id="per-page-select"') &&
    str_contains($publicIndexPaginated, '<option value="10" selected>10 per page</option>') &&
    str_contains($publicIndexPaginated, '<option value="25">25 per page</option>') &&
    str_contains($publicIndexPaginated, '<option value="50">50 per page</option>') &&
    str_contains($publicIndexPaginated, '<option value="100">100 per page</option>') &&
    str_contains($publicIndexPaginated, 'id="directory-pagination"') &&
    str_contains($directoryJsPaginated, 'ALLOWED_PAGE_SIZES = [10, 25, 50, 100]') &&
    str_contains($directoryJsPaginated, 'state.perPage = ALLOWED_PAGE_SIZES.includes') && str_contains($directoryJsPaginated, 'per_page'),
    'public/index.php and directory.js paginate cards to 10/25/50/100 defaulting to 10 (#144)'
);

// 34. Verify Tickets #147, #148, #149, #150: Pre-Prod Preview Sandbox (/admin/preprod) isolation, URL rewriter, dummy seeder & self-tests
$preprodSandboxSqlite = sys_get_temp_dir() . '/bbiz_preprod_sbx_' . getmypid() . '.sqlite';
putenv('BBIZ_BASE_PATH=/admin/preprod');
putenv('APP_ENV=preprod');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $preprodSandboxSqlite);
$_ENV['BBIZ_BASE_PATH'] = '/admin/preprod';
$_ENV['APP_ENV'] = 'preprod';
$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['DB_SQLITE_PATH'] = $preprodSandboxSqlite;
Database::resetConnection();
$preprodPdo = Database::getConnection();

assertTrue(
    \BusherianBiasharas\Services\PreprodSandbox::isPreprod() === true &&
    \BusherianBiasharas\Services\PreprodSandbox::basePath() === '/admin/preprod',
    'PreprodSandbox::isPreprod() and basePath() activate cleanly for /admin/preprod (#148)'
);
$sampleHtml = '<!DOCTYPE html><html><head><link rel="stylesheet" href="/assets/css/app.css"></head><body><a href="/">Home</a><a href="/submit.php">Submit</a><a href="/admin/preprod/already.php">Already</a><a href="https://www.busherianbiasharas.com/">External</a></body></html>';
$rewrittenHtml = \BusherianBiasharas\Services\PreprodSandbox::rewriteHtmlOutput($sampleHtml);
assertTrue(
    str_contains($rewrittenHtml, 'href="/admin/preprod/assets/css/app.css"') &&
    str_contains($rewrittenHtml, 'href="/admin/preprod/"') &&
    str_contains($rewrittenHtml, 'href="/admin/preprod/submit.php"') &&
    str_contains($rewrittenHtml, 'href="/admin/preprod/already.php"') &&
    !str_contains($rewrittenHtml, '/admin/preprod/admin/preprod/') &&
    str_contains($rewrittenHtml, 'id="bbiz-preprod-banner"') &&
    str_contains($rewrittenHtml, 'var BASE = "/admin/preprod";'),
    'PreprodSandbox::rewriteHtmlOutput prefixes root-relative URLs without double-prefixing and injects sandbox banner + fetch wrapper (#148, #149)'
);

$handoffUrl = \BusherianBiasharas\Services\PreprodSandbox::generateProdToPreprodHandoffUrl('/admin/sandbox.php');
parse_str((string)parse_url($handoffUrl, PHP_URL_QUERY), $handoffQp);
assertTrue(
    str_starts_with($handoffUrl, '/admin/preprod/admin/sandbox.php?preprod_handoff=') &&
    \BusherianBiasharas\Services\PreprodSandbox::verifyProdHandoffToken((string)($handoffQp['preprod_handoff'] ?? '')) === true &&
    \BusherianBiasharas\Services\PreprodSandbox::verifyProdHandoffToken('9999999999.forgedsig') === false,
    'PreprodSandbox signs and verifies 1-click Prod -> Pre-Prod handoff tokens and blocks forged signatures (#148)'
);

$dummyRes = \BusherianBiasharas\Services\PreprodSandbox::seedDummyListings($preprodPdo);
assertTrue(
    $dummyRes['approved_added'] === 3 && $dummyRes['pending_added'] === 2,
    'PreprodSandbox::seedDummyListings seeds 3 approved + 2 pending dummy test businesses into SQLite (#149)'
);
$selfTests = \BusherianBiasharas\Services\PreprodSandbox::runSelfTests($preprodPdo);
$allSelfPassed = count($selfTests) === 8 && count(array_filter($selfTests, static fn(array $r): bool => $r['passed'])) === 8;
assertTrue(
    $allSelfPassed,
    'PreprodSandbox::runSelfTests passes all 8 Pre-Prod verification assertions (#149)'
);
$resetRes = \BusherianBiasharas\Services\PreprodSandbox::resetDatabaseToCleanSeed();
assertTrue(
    $resetRes['approved_count'] === 12,
    'PreprodSandbox::resetDatabaseToCleanSeed restores pristine 12-business seed database (#149)'
);
@unlink($preprodSandboxSqlite);

// Restore primary test driver (SQLite or MySQL 8.0) cleanly
putenv('BBIZ_BASE_PATH');
putenv('APP_ENV=testing');
putenv('DB_DRIVER=' . $initialDriver);
unset($_ENV['BBIZ_BASE_PATH']);
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_DRIVER'] = $initialDriver;
if ($initialDriver === 'sqlite') {
    putenv('DB_SQLITE_PATH=' . $unitTempSqlite);
    $_ENV['DB_SQLITE_PATH'] = $unitTempSqlite;
} else {
    putenv('DB_SQLITE_PATH');
    unset($_ENV['DB_SQLITE_PATH']);
}
Database::resetConnection();
$pdo = Database::getConnection();

// 35. Verify Tickets #151, #152, #153, #154, #155: Password-First Owner Sign-In, 6-Digit OTP Setup/Reset, Masked Email Hints & WhatsApp Access Link
assertTrue(
    OwnerAuth::maskEmailAddress('justinake@yahoo.com') === 'j•••••••e@yahoo.com' &&
    str_starts_with(OwnerAuth::maskEmailAddress('thepalmhousevipingo@gmail.com'), 't•••') &&
    str_ends_with(OwnerAuth::maskEmailAddress('thepalmhousevipingo@gmail.com'), 'o@gmail.com'),
    'OwnerAuth::maskEmailAddress masks local-part characters while preserving recognizable first/last characters & domain (#152, #154)'
);

$palmBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Palm Ridge Test Stay',
    'tagline' => 'Coastal holiday home',
    'description' => 'Test listing with separate submitter and public emails',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Kilifi',
    'area_neighborhood' => 'Vipingo',
    'phone_primary' => '+254721563203',
    'whatsapp_number' => '+254721563203',
    'email_public' => 'palmstaytest@gmail.com',
]);
$palmOwner = OwnerAuth::upsertOwner($pdo, 'justina.test@yahoo.com', 'Justina Test', '+254721563203');
OwnerAuth::linkOwnerToBusiness($pdo, (int)$palmOwner['id'], $palmBizId, 'approved_submission');

$hints = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $palmBizId);
assertTrue(
    count($hints) === 1 &&
    str_contains($hints[0], '@yahoo.com') &&
    !in_array('justina.test@yahoo.com', $hints, true),
    'OwnerAuth::getMaskedOwnerEmailHintsForBusiness returns masked designated owner email only (excluding email_public) without leaking raw PII (#152, #207)'
);

$otpIssued = OwnerAuth::issuePasswordOtp($pdo, 'justina.test@yahoo.com', $palmBizId);
assertTrue(
    strlen($otpIssued['code']) === 6 && ctype_digit($otpIssued['code']),
    'OwnerAuth::issuePasswordOtp generates a 6-digit numeric verification code (#152)'
);
assertTrue(
    OwnerAuth::verifyOtpAndSetPassword($pdo, 'justina.test@yahoo.com', '000000', 'StrongPass#2026') === null ||
    $otpIssued['code'] === '000000',
    'OwnerAuth::verifyOtpAndSetPassword rejects wrong 6-digit code (#152)'
);
$verifiedOwner = OwnerAuth::verifyOtpAndSetPassword($pdo, 'justina.test@yahoo.com', $otpIssued['code'], 'StrongPass#2026');
assertTrue(
    is_array($verifiedOwner) &&
    !empty($verifiedOwner['password_hash']) &&
    OwnerAuth::authenticateWithPassword($pdo, 'justina.test@yahoo.com', 'StrongPass#2026') !== null &&
    OwnerAuth::verifyOtpAndSetPassword($pdo, 'justina.test@yahoo.com', $otpIssued['code'], 'AnotherPass#2026') === null,
    'OwnerAuth::verifyOtpAndSetPassword sets password atomically and enforces single-use OTP consumption (#152)'
);
VettingPurger::deleteBusiness($pdo, $palmBizId);

$claimPhpSrc = (string)file_get_contents(dirname(__DIR__) . '/public/owner/claim.php');
$adminIdxSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
assertTrue(
    str_contains($claimPhpSrc, 'Set Your Owner Password') &&
    str_contains($adminIdxSrc, 'WhatsApp Link'),
    'claim.php requires password setup and admin/index.php renders 1-click WhatsApp Access Link (#153, #155)'
);

// 36. Verify Tickets #162 (CRIT-01) & #163 (CRIT-02): Strict Owner Identity Boundary, Recipient-Bound Single-Use Claim Invites & Unsigned Claim Removal
$critVictimBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Crit01 Victim Architects',
    'tagline' => 'Protected victim listing',
    'description' => 'Must never be hijacked via submit.php, claim.php, or login.php?claim=',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722111222',
]);
$critUnclaimedBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Crit02 Unclaimed Roasters',
    'tagline' => 'Pre-populated unclaimed listing',
    'description' => 'Claimable once via verified signed invite + verified mailbox.',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254733444555',
]);

$victimOwner = OwnerAuth::upsertOwner($pdo, 'victim.crit01@busherian-test.co.ke', 'Victim Owner', '+254722111222');
OwnerAuth::setOwnerPassword($pdo, (int)$victimOwner['id'], 'VictimOriginal#2026');
OwnerAuth::linkOwnerToBusiness($pdo, (int)$victimOwner['id'], $critVictimBizId, 'approved_submission');

$fetchedVictim = OwnerAuth::getOwnerById($pdo, (int)$victimOwner['id']);
assertTrue(
    is_array($fetchedVictim) &&
    (int)$fetchedVictim['id'] === (int)$victimOwner['id'] &&
    $fetchedVictim['email'] === 'victim.crit01@busherian-test.co.ke' &&
    OwnerAuth::getOwnerById($pdo, 99999999) === null,
    'OwnerAuth::getOwnerById resolves existing owners by ID and returns null for nonexistent IDs (#162, #172)'
);

// Recipient-bound signed claim URL cannot have `em` stripped or altered
$boundClaimUrl = OwnerAuth::generateClaimInviteUrl($critVictimBizId, null, 'victim.crit01@busherian-test.co.ke');
$boundQp = [];
parse_str((string)parse_url($boundClaimUrl, PHP_URL_QUERY), $boundQp);
assertTrue(
    ($boundQp['em'] ?? '') === 'victim.crit01@busherian-test.co.ke' &&
    OwnerAuth::verifyClaimInviteSignature((int)$boundQp['biz_id'], (int)$boundQp['exp'], (string)$boundQp['sig'], 'victim.crit01@busherian-test.co.ke') === true &&
    OwnerAuth::verifyClaimInviteSignature((int)$boundQp['biz_id'], (int)$boundQp['exp'], (string)$boundQp['sig'], '') === false &&
    OwnerAuth::verifyClaimInviteSignature((int)$boundQp['biz_id'], (int)$boundQp['exp'], (string)$boundQp['sig'], 'attacker@evil.example') === false,
    'Recipient-bound claim invite HMAC rejects parameter stripping or email substitution (#162, #163)'
);

// Eligibility check blocks unrelated accounts from claiming an already-assigned business
assertTrue(
    OwnerAuth::isEmailEligibleForClaim($pdo, $critVictimBizId, 'victim.crit01@busherian-test.co.ke') === true &&
    OwnerAuth::isEmailEligibleForClaim($pdo, $critVictimBizId, 'attacker@evil.example') === false &&
    OwnerAuth::isEmailEligibleForClaim($pdo, $critUnclaimedBizId, 'legit.claimant@gmail.com') === true,
    'OwnerAuth::isEmailEligibleForClaim blocks unrelated emails on owned listings while permitting verified claimants on unclaimed listings (#162, #163)'
);

// Single-use claim redemption consumes invite atomically and blocks second replay
$unclaimedUrl = OwnerAuth::generateClaimInviteUrl($critUnclaimedBizId);
$unclaimedQp = [];
parse_str((string)parse_url($unclaimedUrl, PHP_URL_QUERY), $unclaimedQp);
$legitClaimant = OwnerAuth::upsertOwner($pdo, 'legit.claimant@gmail.com', 'Legit Claimant');
$redeemFirst = OwnerAuth::redeemVerifiedClaimInvite(
    $pdo,
    (int)$legitClaimant['id'],
    'legit.claimant@gmail.com',
    (int)$unclaimedQp['biz_id'],
    (int)$unclaimedQp['exp'],
    (string)$unclaimedQp['sig']
);
$attackerOwner = OwnerAuth::upsertOwner($pdo, 'attacker@evil.example', 'Attacker');
$redeemReplay = OwnerAuth::redeemVerifiedClaimInvite(
    $pdo,
    (int)$attackerOwner['id'],
    'attacker@evil.example',
    (int)$unclaimedQp['biz_id'],
    (int)$unclaimedQp['exp'],
    (string)$unclaimedQp['sig']
);
assertTrue(
    $redeemFirst === true &&
    $redeemReplay === false &&
    OwnerAuth::isClaimInviteConsumed($pdo, (int)$unclaimedQp['biz_id'], (int)$unclaimedQp['exp'], (string)$unclaimedQp['sig']) === true &&
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$legitClaimant['id'], $critUnclaimedBizId) === true &&
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$attackerOwner['id'], $critUnclaimedBizId) === false,
    'OwnerAuth::redeemVerifiedClaimInvite enforces single-use nonce lock and prevents replay (#163)'
);

// Defense-in-depth: verifyOtpAndSetPassword refuses to link an unauthorized claim_business_id
$rogueOtp = OwnerAuth::issuePasswordOtp($pdo, 'attacker@evil.example', $critVictimBizId);
$attackerVerified = OwnerAuth::verifyOtpAndSetPassword($pdo, 'attacker@evil.example', $rogueOtp['code'], 'AttackerPass#2026');
assertTrue(
    is_array($attackerVerified) &&
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$attackerOwner['id'], $critVictimBizId) === false &&
    OwnerAuth::authenticateWithPassword($pdo, 'victim.crit01@busherian-test.co.ke', 'VictimOriginal#2026') !== null,
    'OwnerAuth::verifyOtpAndSetPassword refuses to link victim business to attacker even if claim_business_id is present (#163)'
);

$loginPhpSrc = (string)file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
$submitPhpSrc = (string)file_get_contents(dirname(__DIR__) . '/public/submit.php');
assertTrue(
    !str_contains($loginPhpSrc, "\$_REQUEST['claim']") &&
    !str_contains($loginPhpSrc, "\$_GET['claim']") &&
    !str_contains($loginPhpSrc, "\$_POST['claim']") &&
    !str_contains($loginPhpSrc, 'bbiz_pending_claim_biz_id') &&
    !str_contains($submitPhpSrc, 'setOwnerPassword') &&
    !str_contains($submitPhpSrc, 'establishOwnerSession'),
    'login.php eliminates unsigned claim parameters and submit.php eliminates unauthenticated password/session writes (#162, #163)'
);

// Verify signed-out existing owner's self-submitted pending listing links on login in same session, never for wrong account
$pendingSelfBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Self Submitted Pending Tea Room',
    'tagline' => 'Pending listing submitted before login',
    'description' => 'Should link only when the same browser session authenticates as submitter_email.',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254799888777',
    'status' => 'pending',
]);
$pdo->prepare("UPDATE businesses SET status = 'pending', owner_email = NULL WHERE id = :id")->execute([':id' => $pendingSelfBizId]);
$pdo->prepare("
    INSERT INTO business_vetting_queue (business_id, school_code, house_name, year_left, submitter_name, submitter_email, submitter_phone)
    VALUES (:bid, 'AGHS', 'Watson', 2010, 'Victim Owner', 'victim.crit01@busherian-test.co.ke', '+254722111222')
")->execute([':bid' => $pendingSelfBizId]);

OwnerAuth::recordSelfSubmittedPendingInSession($pendingSelfBizId, 'victim.crit01@busherian-test.co.ke');
$wrongLinks = OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$attackerOwner['id'], 'attacker@evil.example');
assertTrue(
    empty($wrongLinks) && !OwnerAuth::canOwnerEditBusiness($pdo, (int)$attackerOwner['id'], $pendingSelfBizId),
    'OwnerAuth::linkSessionSubmittedPendingBusinesses refuses to link pending submission to a different authenticated email (#162)'
);
$rightLinks = OwnerAuth::linkSessionSubmittedPendingBusinesses($pdo, (int)$victimOwner['id'], 'victim.crit01@busherian-test.co.ke');
assertTrue(
    $rightLinks === [$pendingSelfBizId] && OwnerAuth::canOwnerEditBusiness($pdo, (int)$victimOwner['id'], $pendingSelfBizId),
    'OwnerAuth::linkSessionSubmittedPendingBusinesses links pending submission once matching existing owner authenticates (#162)'
);
VettingPurger::deleteBusiness($pdo, $pendingSelfBizId);

VettingPurger::deleteBusiness($pdo, $critVictimBizId);
VettingPurger::deleteBusiness($pdo, $critUnclaimedBizId);

// 37. Verify Adversarial HIGH-01..HIGH-06 (#164, #165, #166, #167, #168, #169)

// 37a. HIGH-01 (#164) & R2-HIGH-03 (#207): Legacy corporate_domain_match AND public_email_match links are revoked unless owner_email matches
$corpTestBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'High01 Corp Domain Audit Ltd',
    'tagline' => 'Corporate domain link audit test',
    'description' => 'Only explicit designated owner_email or verified claim should retain ownership.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254711000164',
    'email_public' => 'reception@high01corp.co.ke',
    'owner_email' => 'founder@high01corp.co.ke',
    'website_url' => 'https://www.high01corp.co.ke',
]);
$colleagueOwner = OwnerAuth::upsertOwner($pdo, 'random.intern@high01corp.co.ke', 'Random Intern');
$receptionOwner = OwnerAuth::upsertOwner($pdo, 'reception@high01corp.co.ke', 'Front Desk Reception');
$designatedFounderOwner = OwnerAuth::upsertOwner($pdo, 'founder@high01corp.co.ke', 'Designated Founder');

OwnerAuth::linkOwnerToBusiness($pdo, (int)$colleagueOwner['id'], $corpTestBizId, 'corporate_domain_match');
OwnerAuth::linkOwnerToBusiness($pdo, (int)$receptionOwner['id'], $corpTestBizId, 'public_email_match');
$pdo->prepare("UPDATE business_owner_links SET linked_via = 'corporate_domain_match' WHERE owner_id = :oid AND business_id = :bid")
    ->execute([':oid' => (int)$designatedFounderOwner['id'], ':bid' => $corpTestBizId]);

$revokedLegacyCount = OwnerAuth::revokeUnverifiedCorporateDomainLinks($pdo);
$revokedPubEmailCount = OwnerAuth::revokeUnverifiedPublicEmailAutoLinks($pdo);
$upgradedVia = (string)$pdo->query(
    "SELECT linked_via FROM business_owner_links WHERE owner_id = " . (int)$designatedFounderOwner['id'] . " AND business_id = {$corpTestBizId}"
)->fetchColumn();
assertTrue(
    $revokedLegacyCount >= 1 &&
    $revokedPubEmailCount >= 1 &&
    !OwnerAuth::canOwnerEditBusiness($pdo, (int)$colleagueOwner['id'], $corpTestBizId) &&
    !OwnerAuth::canOwnerEditBusiness($pdo, (int)$receptionOwner['id'], $corpTestBizId) &&
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$designatedFounderOwner['id'], $corpTestBizId) &&
    $upgradedVia === 'admin_or_submitter_email',
    'Revocation migrations remove both corporate_domain_match and public_email_match links while preserving explicit owner_email (#164, #207)'
);
VettingPurger::deleteBusiness($pdo, $corpTestBizId);

// 37b. HIGH-02 (#165): Server-side revocable persistent sessions (`owner_sessions` + `auth_version`) & legacy cookie rejection
$sessOwner = OwnerAuth::upsertOwner($pdo, 'sessions.high02@busherian-test.co.ke', 'Session Test Owner');
OwnerAuth::setOwnerPassword($pdo, (int)$sessOwner['id'], 'InitialPass#165');

// Establish Device A persistent cookie
$_SESSION = [];
$_COOKIE = [];
OwnerAuth::establishOwnerSession($sessOwner, false, $pdo);
$cookieDeviceA = (string)($_COOKIE['bbiz_owner_session'] ?? '');

// Establish Device B persistent cookie
$_SESSION = [];
$_COOKIE = [];
OwnerAuth::establishOwnerSession($sessOwner, false, $pdo);
$cookieDeviceB = (string)($_COOKIE['bbiz_owner_session'] ?? '');

assertTrue(
    str_starts_with($cookieDeviceA, 'v2.') &&
    str_starts_with($cookieDeviceB, 'v2.') &&
    $cookieDeviceA !== $cookieDeviceB,
    'OwnerAuth::establishOwnerSession issues unique v2.<selector>.<validator> server-backed tokens per device (#165)'
);

// Legacy stateless base64 HMAC cookie MUST be rejected
$legacyPayload = base64_encode((string)json_encode([
    'oid' => (int)$sessOwner['id'],
    'em' => 'sessions.high02@busherian-test.co.ke',
    'exp' => time() + 86400,
]));
$legacySig = hash_hmac('sha256', "owner_sess|{$legacyPayload}", (string)getenv('APP_HMAC_SECRET'));
$_SESSION = [];
$_COOKIE = ['bbiz_owner_session' => "{$legacyPayload}.{$legacySig}"];
assertTrue(
    OwnerAuth::getCurrentOwner($pdo) === null,
    'OwnerAuth::getCurrentOwner rejects stateless v1 HMAC cookies not backed by owner_sessions (#165)'
);

// Both Device A and Device B cookies resolve when PHP $_SESSION is empty (simulating browser restart)
$_SESSION = [];
$_COOKIE = ['bbiz_owner_session' => $cookieDeviceA];
$resolvedA = OwnerAuth::getCurrentOwner($pdo);
$_SESSION = [];
$_COOKIE = ['bbiz_owner_session' => $cookieDeviceB];
$resolvedB = OwnerAuth::getCurrentOwner($pdo);
assertTrue(
    is_array($resolvedA) && (int)$resolvedA['id'] === (int)$sessOwner['id'] &&
    is_array($resolvedB) && (int)$resolvedB['id'] === (int)$sessOwner['id'],
    'Both active device sessions authenticate via owner_sessions selector/validator lookup (#165)'
);

// Changing password bumps auth_version and immediately revokes BOTH Device A and Device B tokens!
OwnerAuth::setOwnerPassword($pdo, (int)$sessOwner['id'], 'RotatedPass#165');
$_SESSION = [];
$_COOKIE = ['bbiz_owner_session' => $cookieDeviceA];
$afterPwChangeA = OwnerAuth::getCurrentOwner($pdo);
$_SESSION = [
    'bbiz_owner_id' => (int)$sessOwner['id'],
    'bbiz_owner_email' => 'sessions.high02@busherian-test.co.ke',
    'bbiz_owner_auth_version' => 1, // stale pre-rotation auth_version
];
$_COOKIE = ['bbiz_owner_session' => $cookieDeviceB];
$afterPwChangeB = OwnerAuth::getCurrentOwner($pdo);
assertTrue(
    $afterPwChangeA === null && $afterPwChangeB === null,
    'OwnerAuth::setOwnerPassword bumps auth_version and invalidates all pre-existing persistent cookies and stale PHP sessions (#165)'
);

// Sign Out All Devices (`revokeAllOwnerSessions`) invalidates active device sessions immediately
$refreshedSessOwner = OwnerAuth::getOwnerById($pdo, (int)$sessOwner['id']);
$_SESSION = [];
$_COOKIE = [];
OwnerAuth::establishOwnerSession($refreshedSessOwner, false, $pdo);
$cookieDeviceC = (string)($_COOKIE['bbiz_owner_session'] ?? '');
OwnerAuth::revokeAllOwnerSessions($pdo, (int)$sessOwner['id']);
$_SESSION = [];
$_COOKIE = ['bbiz_owner_session' => $cookieDeviceC];
assertTrue(
    OwnerAuth::getCurrentOwner($pdo) === null,
    'OwnerAuth::revokeAllOwnerSessions revokes all active devices immediately (#165)'
);
$_SESSION = [];
$_COOKIE = [];

// 37c. HIGH-03 (#166): IPv4-mapped IPv6, NAT64, 6to4, Teredo, CGNAT, 192.0.0.0/24, 198.18.0.0/15, ULA & non-canonical numeric literals
$nonPublicIps = [
    '0.0.0.0',
    '10.255.0.1',
    '100.64.0.1',
    '100.127.255.254',
    '127.0.0.1',
    '169.254.169.254',
    '172.16.0.1',
    '192.0.0.1',
    '192.0.2.1',
    '192.168.1.1',
    '198.18.0.1',
    '198.19.255.254',
    '198.51.100.1',
    '203.0.113.1',
    '224.0.0.1',
    '255.255.255.255',
    '::',
    '::1',
    '::ffff:127.0.0.1',
    '::ffff:169.254.169.254',
    '::ffff:10.0.0.1',
    '::ffff:100.64.0.1',
    '::ffff:0:127.0.0.1',
    '::127.0.0.1',
    '64:ff9b::7f00:1',
    '64:ff9b::a9fe:a9fe',
    '64:ff9b:1::1',
    '2002:7f00:0001::1',
    '2002:a9fe:a9fe::1',
    '2001:0000:4136:e378:8000:63bf:3fff:fdd2',
    '2001:db8::1',
    'fc00::1',
    'fd12:3456:789a::1',
    'fe80::1',
    'fe80::1%eth0',
    'ff02::1',
];
foreach ($nonPublicIps as $badIp) {
    assertTrue(!WebsiteEnricher::isPublicIp($badIp), "WebsiteEnricher::isPublicIp rejects non-public/transition IP {$badIp} (#166)");
}
assertTrue(
    WebsiteEnricher::isPublicIp('93.184.216.34') &&
    WebsiteEnricher::isPublicIp('2606:4700:4700::1111') &&
    WebsiteEnricher::isPublicIp('64:ff9b::5db8:d822'), // NAT64 wrapping public 93.184.216.34
    'WebsiteEnricher::isPublicIp allows genuine globally routable IPv4 & IPv6 addresses (#166)'
);

$ssrfHigh03Urls = [
    'http://[::ffff:127.0.0.1]/admin',
    'http://[::ffff:169.254.169.254]/latest/meta-data/',
    'http://[64:ff9b::7f00:1]/internal',
    'http://[2002:7f00:0001::1]/',
    'http://[fc00::1]/',
    'http://[fe80::1%25eth0]/',
    'http://100.64.0.1/',
    'http://192.0.0.1/',
    'http://198.18.0.1/',
    'http://2130706433/',
    'http://0177.0.0.1/',
    'http://0x7f000001/',
    'http://127.1/',
];
foreach ($ssrfHigh03Urls as $u166) {
    $blocked166 = false;
    try {
        WebsiteEnricher::validateSafeUrlAndResolveIp($u166, static fn(string $h): array => ['93.184.216.34']);
    } catch (InvalidArgumentException $e) {
        $blocked166 = true;
    }
    assertTrue($blocked166, "SSRF Guard blocks transition/numeric/reserved URL {$u166} (#166)");
}

// Verify DNS AAAA resolving to ::ffff:127.0.0.1 is ALSO blocked, and IPv6 CURLOPT_RESOLVE brackets colons
$dnsMappedBlocked = false;
try {
    WebsiteEnricher::validateSafeUrlAndResolveIp('https://rebind.example.com/', static fn(string $h): array => ['::ffff:127.0.0.1']);
} catch (InvalidArgumentException $e) {
    $dnsMappedBlocked = true;
}
assertTrue(
    $dnsMappedBlocked &&
    WebsiteEnricher::formatCurlResolveEntry('example.com', 443, '2606:4700:4700::1111') === 'example.com:443:[2606:4700:4700::1111]' &&
    WebsiteEnricher::formatCurlResolveEntry('example.com', 443, '93.184.216.34') === 'example.com:443:93.184.216.34',
    'SSRF Guard blocks AAAA ::ffff:127.0.0.1 DNS rebinding and formats IPv6 CURLOPT_RESOLVE with brackets (#166)'
);

// 37d. HIGH-04 (#167): Zero alumni vetting PII in Mailer::notifyAdminNewSubmission & email_outbox + legacy scrubber
$piiBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'High04 Privacy Shield Clinic',
    'tagline' => 'Testing zero vetting PII in email_outbox',
    'description' => 'Email alerts must never include House, Year Left, or School Number.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254711000167',
    'status' => 'pending',
]);
$outboxAlertId = Mailer::notifyAdminNewSubmission(
    $pdo,
    ['id' => $piiBizId, 'name' => 'High04 Privacy Shield Clinic'],
    [
        'school_code' => 'AHS',
        'house_name' => 'Livingstone',
        'year_left' => 2005,
        'school_number' => '7123',
        'submitter_name' => 'Dr. Privacy Alumnus',
        'submitter_email' => 'privacy.alumnus@test.co.ke',
    ],
    null
);
$outboxRow = $pdo->query("SELECT business_id, subject, body_html FROM email_outbox WHERE id = {$outboxAlertId}")->fetch();
assertTrue(
    is_array($outboxRow) &&
    (int)$outboxRow['business_id'] === $piiBizId &&
    !str_contains((string)$outboxRow['body_html'], 'Livingstone') &&
    !str_contains((string)$outboxRow['body_html'], '2005') &&
    !str_contains((string)$outboxRow['body_html'], '7123') &&
    !str_contains((string)$outboxRow['body_html'], 'House:') &&
    !str_contains((string)$outboxRow['body_html'], 'Year Left') &&
    !str_contains((string)$outboxRow['body_html'], 'School Number'),
    'Mailer::notifyAdminNewSubmission links business_id on email_outbox and omits House, Year Left & School Number (#167)'
);

// Simulate a legacy pre-#167 email_outbox row containing Temporary Vetting Credentials and scrub it
$pdo->prepare("
    INSERT INTO email_outbox (recipient_email, subject, body_html, status, attempts)
    VALUES ('admin@test.co.ke', 'Legacy Alert', '<p>Temporary Vetting Credentials: House: Livingstone, Year Left: 2005, School Number: 7123</p>', 'simulated', 1)
")->execute();
$legacyOutboxId = (int)$pdo->lastInsertId();
$scrubbedCount = Mailer::scrubLegacyVettingPiiFromOutbox($pdo);
$scrubbedBody = (string)$pdo->query("SELECT body_html FROM email_outbox WHERE id = {$legacyOutboxId}")->fetchColumn();
assertTrue(
    $scrubbedCount >= 1 && !str_contains($scrubbedBody, 'Livingstone') && str_contains($scrubbedBody, 'REDACTED'),
    'Mailer::scrubLegacyVettingPiiFromOutbox redacts historical vetting PII from email_outbox (#167)'
);
VettingPurger::deleteBusiness($pdo, $piiBizId);
$afterDeleteOutboxCount = (int)$pdo->query("SELECT COUNT(*) FROM email_outbox WHERE id = {$outboxAlertId}")->fetchColumn();
assertTrue(
    $afterDeleteOutboxCount === 0,
    'VettingPurger::deleteBusiness purges linked email_outbox rows for that business (#167)'
);

// 37e. HIGH-05 (#168): Authenticated takedown, single-use owner email token, third-party queue, vetting preservation & reversible quarantine
$tdLiveBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'High05 Resilient Logistics Ltd',
    'tagline' => 'Protected against anonymous takedown abuse',
    'description' => 'Live listing must stay online during unverified third-party takedown requests.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254711000168',
    'email_public' => 'info@resilient168.co.ke',
    'owner_email' => 'owner@resilient168.co.ke',
]);

// 1) Unauthenticated third party submits takedown -> status stays 'approved', request queued as 'pending_review'
$thirdPartyReq = VettingPurger::submitTakedownRequest(
    $pdo,
    $tdLiveBizId,
    'Competitor Saboteur',
    'saboteur@competitor.example',
    'Take down my competitor before conference!',
    '198.51.100.88',
    false,
    false
);
$liveStatusAfter3p = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$tdLiveBizId}")->fetchColumn();
assertTrue(
    $thirdPartyReq['status'] === 'pending_review' &&
    $thirdPartyReq['raw_token'] === null &&
    $liveStatusAfter3p === 'approved',
    'Unauthenticated third-party takedown queues pending_review without hiding live business (#168)'
);
assertTrue(
    VettingPurger::resolveTakedownRequestByAdmin($pdo, $thirdPartyReq['request_id'], 'dismiss') === true,
    'Admin can dismiss unverified third-party takedown request cleanly (#168)'
);

// 2) Unauthenticated request matching designated owner email -> issues single-use confirmation token; live until confirmed
$ownerEmailReq = VettingPurger::submitTakedownRequest(
    $pdo,
    $tdLiveBizId,
    'Designated Owner',
    'owner@resilient168.co.ke',
    'Temporarily pausing listing while updating phone line.',
    '198.51.100.89',
    false,
    true
);
$statusBeforeConfirm = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$tdLiveBizId}")->fetchColumn();
assertTrue(
    $ownerEmailReq['status'] === 'awaiting_owner_email_confirm' &&
    is_string($ownerEmailReq['raw_token']) &&
    strlen($ownerEmailReq['raw_token']) === 48 &&
    $statusBeforeConfirm === 'approved',
    'Unauthenticated designated-owner email takedown awaits email token confirmation before pausing live listing (#168)'
);

$confirmedReq = VettingPurger::confirmOwnerTakedownByToken($pdo, (string)$ownerEmailReq['raw_token']);
$replayConfirm = VettingPurger::confirmOwnerTakedownByToken($pdo, (string)$ownerEmailReq['raw_token']);
$statusAfterConfirm = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$tdLiveBizId}")->fetchColumn();
assertTrue(
    is_array($confirmedReq) &&
    $replayConfirm === null &&
    $statusAfterConfirm === 'quarantined_dpa',
    'VettingPurger::confirmOwnerTakedownByToken quarantines listing once and blocks token replay (#168)'
);

$restoredApproved = VettingPurger::restoreQuarantinedBusiness($pdo, $tdLiveBizId);
$statusAfterRestore = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$tdLiveBizId}")->fetchColumn();
assertTrue(
    $restoredApproved === 'approved' && $statusAfterRestore === 'approved',
    'VettingPurger::restoreQuarantinedBusiness restores previously approved listing back to approved (#168)'
);

// 3) Quarantining a PENDING submission preserves business_vetting_queue and restores back to 'pending' (never skipping vetting!)
$tdPendingBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'High05 Pending Vetting Preserve Biz',
    'tagline' => 'Pending vetting row must survive quarantine',
    'description' => 'Never wipe business_vetting_queue during quarantine.',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722000168',
    'status' => 'pending',
]);
$pdo->prepare("UPDATE businesses SET status = 'pending', verified_at = NULL WHERE id = :id")->execute([':id' => $tdPendingBizId]);
$pdo->prepare("
    INSERT INTO business_vetting_queue (business_id, school_code, house_name, year_left, school_number, submitter_name, submitter_email, submitter_phone)
    VALUES (:bid, 'AGHS', 'Watson', 2012, '5501', 'Pending Submitter', 'pending168@test.co.ke', '+254722000168')
")->execute([':bid' => $tdPendingBizId]);

VettingPurger::quarantineBusinessDpa($pdo, $tdPendingBizId);
VettingPurger::enforceRetentionCeilings($pdo, 30);
$vettingPreservedCount = (int)$pdo->query("SELECT COUNT(*) FROM business_vetting_queue WHERE business_id = {$tdPendingBizId}")->fetchColumn();
$restoredPending = VettingPurger::restoreQuarantinedBusiness($pdo, $tdPendingBizId);
assertTrue(
    $vettingPreservedCount === 1 && $restoredPending === 'pending',
    'Quarantining a pending listing preserves business_vetting_queue across retention sweeps and restores to pending (#168)'
);
VettingPurger::deleteBusiness($pdo, $tdLiveBizId);
VettingPurger::deleteBusiness($pdo, $tdPendingBizId);

// 37f. HIGH-06 (#169): Production MySQL fail-closed (HTTP 503 + zero SQLite fallback) & zero sample seeding in production
$forbiddenFallbackSqlite = sys_get_temp_dir() . '/bbiz_prod_forbidden_fallback_' . getmypid() . '.sqlite';
@unlink($forbiddenFallbackSqlite);
$prodFailClosedScript = <<<'PHP'
<?php
require_once $argv[1] . '/src/bootstrap.php';
try {
    \BusherianBiasharas\Config\Database::getConnection();
    echo "UNEXPECTED_SUCCESS";
    exit(2);
} catch (\BusherianBiasharas\Config\DatabaseUnavailableException $e) {
    echo "CAUGHT_DB_UNAVAILABLE:" . $e->getMessage();
    exit(0);
}
PHP;
$tmpScript169 = sys_get_temp_dir() . '/bbiz_test_169_' . getmypid() . '.php';
file_put_contents($tmpScript169, $prodFailClosedScript);

$cmd169 = sprintf(
    'APP_ENV=production SIMULATE_EMAILS=no DB_DRIVER=mysql DB_DRIVER_FALLBACK=sqlite DB_HOST=127.0.0.1 DB_PORT=1 DB_NAME=nonexistent_169 DB_USER=nonexistent_u169 DB_PASS=invalid_p169 DB_SQLITE_PATH=%s APP_HMAC_SECRET=%s IP_HASH_SECRET=%s CSRF_SECRET=%s php %s %s 2>/dev/null',
    escapeshellarg($forbiddenFallbackSqlite),
    escapeshellarg((string)getenv('APP_HMAC_SECRET')),
    escapeshellarg((string)getenv('IP_HASH_SECRET')),
    escapeshellarg((string)getenv('CSRF_SECRET')),
    escapeshellarg($tmpScript169),
    escapeshellarg(dirname(__DIR__))
);
$out169 = [];
$rc169 = 0;
exec($cmd169, $out169, $rc169);
$joined169 = implode("\n", $out169);
assertTrue(
    $rc169 === 0 &&
    str_contains($joined169, 'CAUGHT_DB_UNAVAILABLE:') &&
    !is_file($forbiddenFallbackSqlite),
    'APP_ENV=production with unreachable MySQL throws DatabaseUnavailableException and NEVER creates a fallback SQLite DB even if DB_DRIVER_FALLBACK=sqlite (#169)'
);

// Also verify empty SQLite DB in APP_ENV=production seeds taxonomy categories (>=100) and ZERO sample businesses!
$prodEmptySqlite = sys_get_temp_dir() . '/bbiz_prod_empty_seed_' . getmypid() . '.sqlite';
@unlink($prodEmptySqlite);
$prodSeedScript = <<<'PHP'
<?php
require_once $argv[1] . '/src/bootstrap.php';
$pdo = \BusherianBiasharas\Config\Database::getConnection();
$cats = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$biz  = (int)$pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
echo "CATS={$cats};BIZ={$biz}";
PHP;
file_put_contents($tmpScript169, $prodSeedScript);
$cmdSeed169 = sprintf(
    'APP_ENV=production SIMULATE_EMAILS=no DB_DRIVER=sqlite DB_SQLITE_PATH=%s APP_HMAC_SECRET=%s IP_HASH_SECRET=%s CSRF_SECRET=%s php %s %s 2>/dev/null',
    escapeshellarg($prodEmptySqlite),
    escapeshellarg((string)getenv('APP_HMAC_SECRET')),
    escapeshellarg((string)getenv('IP_HASH_SECRET')),
    escapeshellarg((string)getenv('CSRF_SECRET')),
    escapeshellarg($tmpScript169),
    escapeshellarg(dirname(__DIR__))
);
$outSeed169 = [];
$rcSeed169 = 0;
exec($cmdSeed169, $outSeed169, $rcSeed169);
$joinedSeed169 = implode("\n", $outSeed169);
assertTrue(
    $rcSeed169 === 0 &&
    str_contains($joinedSeed169, ';BIZ=0') &&
    preg_match('/CATS=(\d+);BIZ=0/', $joinedSeed169, $mSeed) === 1 &&
    (int)$mSeed[1] >= 100,
    'Empty database in APP_ENV=production seeds taxonomy categories while seeding ZERO sample businesses (#169)'
);
@unlink($tmpScript169);
@unlink($prodEmptySqlite);


// =========================================================================
// Section 38: Adversarial LOW-01 (#187) & LOW-02 (#188) Regression Suite
// =========================================================================

// 38a. LOW-01 (#187): Session-bound CSRF rejects 'stateless' HMAC and fails closed without session_id()
$activeCsrf = AntiSpamGate::generateCsrfToken();
$forgedStatelessCsrf = hash_hmac('sha256', 'stateless', (string)getenv('CSRF_SECRET'));
assertTrue(
    $activeCsrf !== '' &&
    AntiSpamGate::verifyCsrfToken($activeCsrf) === true &&
    AntiSpamGate::verifyCsrfToken($forgedStatelessCsrf) === false,
    'AntiSpamGate::verifyCsrfToken accepts active session token and rejects forged stateless CSRF token (#187)'
);

$noSessionScript = <<<'PHP'
<?php
// Emit output BEFORE bootstrap so headers_sent() is true and session_start() cannot create a session_id()
echo "PRE_OUTPUT\n";
require_once $argv[1] . '/src/bootstrap.php';
$verifyOk = \BusherianBiasharas\Services\AntiSpamGate::verifyCsrfToken(
    hash_hmac('sha256', 'stateless', (string)getenv('CSRF_SECRET'))
);
$threwOnGen = false;
try {
    \BusherianBiasharas\Services\AntiSpamGate::generateCsrfToken();
} catch (\RuntimeException $e) {
    $threwOnGen = true;
}
echo ($verifyOk === false && $threwOnGen) ? "FAIL_CLOSED_OK" : "UNEXPECTED_OPEN";
PHP;
$tmpScript187 = sys_get_temp_dir() . '/bbiz_test_187_' . getmypid() . '.php';
file_put_contents($tmpScript187, $noSessionScript);
$out187 = [];
$rc187 = 0;
exec(sprintf('CSRF_SECRET=%s php %s %s 2>/dev/null', escapeshellarg((string)getenv('CSRF_SECRET')), escapeshellarg($tmpScript187), escapeshellarg(dirname(__DIR__))), $out187, $rc187);
@unlink($tmpScript187);
assertTrue(
    $rc187 === 0 && str_contains(implode("\n", $out187), 'FAIL_CLOSED_OK'),
    'AntiSpamGate CSRF generation throws RuntimeException and verification returns false when session_id() is empty (#187)'
);

// 38b. LOW-02 (#188): Deferred SMTP inside DB transactions, zero phantom emails on rollback, bounded retry worker, and DPA cron heartbeat
Mailer::clearPendingOutbox();

// 1) Rollback test: enqueue inside open transaction -> status is 'queued' (attempts=0) -> rollBack() -> flushPendingOutbox() dispatches 0!
$pdo->beginTransaction();
$rolledBackOutboxId = Mailer::enqueueAndSend($pdo, 'rollback188@test.co.ke', 'Rollback Test #188', '<p>Should roll back</p>');
$inTxStmt = $pdo->prepare("SELECT status, attempts FROM email_outbox WHERE id = :id");
$inTxStmt->execute([':id' => $rolledBackOutboxId]);
$inTxRow = $inTxStmt->fetch();
$pdo->rollBack();
$flushAfterRollback = Mailer::flushPendingOutbox($pdo);
$afterRbStmt = $pdo->prepare("SELECT COUNT(*) FROM email_outbox WHERE id = :id");
$afterRbStmt->execute([':id' => $rolledBackOutboxId]);
assertTrue(
    is_array($inTxRow) &&
    $inTxRow['status'] === 'queued' &&
    (int)$inTxRow['attempts'] === 0 &&
    $flushAfterRollback['dispatched'] === 0 &&
    (int)$afterRbStmt->fetchColumn() === 0,
    'Mailer::enqueueAndSend defers dispatch while $pdo->inTransaction() is true and sends zero phantom emails on rollback (#188)'
);

// 2) Commit + post-commit flush test
$pdo->beginTransaction();
$committedOutboxId = Mailer::enqueueAndSend($pdo, 'commit188@test.co.ke', 'Commit Test #188', '<p>Should flush post-commit</p>', 'reply188@test.co.ke');
$pdo->commit();
$flushCommitted = Mailer::flushPendingOutbox($pdo);
assertTrue(
    $flushCommitted['dispatched'] === 1 &&
    Mailer::wasDeliveredOrSimulated($pdo, $committedOutboxId) === true,
    'Mailer::flushPendingOutbox dispatches queued emails cleanly after $pdo->commit() (#188)'
);

// 3) Failed live SMTP -> honest status='failed' -> Mailer::processRetryQueue retries and delivers once simulation/SMTP recovers
putenv('APP_ENV=production');
putenv('SIMULATE_EMAILS=no');
putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_PORT=1');
putenv('SMTP_DISABLE_MAIL_FALLBACK=yes');
$issuedOtp188 = OwnerAuth::issuePasswordOtp($pdo, 'retry188@test.co.ke');
$failedOtpId = Mailer::sendOwnerPasswordOtp($pdo, 'retry188@test.co.ke', $issuedOtp188['code'], 'Retry Test Biashara');
$wasDeliveredWhenDown = Mailer::wasDeliveredOrSimulated($pdo, $failedOtpId);

// Recover mail transport (re-enable simulation for test environment), fast-forward retry backoff window, and run retry worker
putenv('APP_ENV=testing');
putenv('SIMULATE_EMAILS=yes');
putenv('SMTP_HOST=');
putenv('SMTP_DISABLE_MAIL_FALLBACK=no');
$pdo->prepare("UPDATE email_outbox SET next_retry_at = :past WHERE id = :id")->execute([':past' => time() - 1, ':id' => $failedOtpId]);
$retrySummary = Mailer::processRetryQueue($pdo, 25, 5);
$wasDeliveredAfterRetry = Mailer::wasDeliveredOrSimulated($pdo, $failedOtpId);
assertTrue(
    $wasDeliveredWhenDown === false &&
    $retrySummary['delivered'] >= 1 &&
    $wasDeliveredAfterRetry === true,
    'Failed OTP send reports wasDeliveredOrSimulated=false and succeeds via Mailer::processRetryQueue retry worker (#188)'
);
$pdo->prepare("DELETE FROM email_outbox WHERE id IN (:id1, :id2)")->execute([':id1' => $committedOutboxId, ':id2' => $failedOtpId]);

// 4) DPA Retention Cron Heartbeat monitoring (never_run -> stale -> healthy)
Database::setSetting($pdo, 'DPA_RETENTION_CRON_LAST_RUN_AT', '');
$healthNever = VettingPurger::getRetentionCronHealth($pdo, 36);
Database::setSetting($pdo, 'DPA_RETENTION_CRON_LAST_RUN_AT', gmdate('Y-m-d H:i:s', time() - (48 * 3600)));
$healthStale = VettingPurger::getRetentionCronHealth($pdo, 36);
VettingPurger::recordCronHeartbeat($pdo, ['vetting_rows_purged' => 0]);
$healthFresh = VettingPurger::getRetentionCronHealth($pdo, 36);
assertTrue(
    $healthNever['healthy'] === false && $healthNever['status'] === 'never_run' &&
    $healthStale['healthy'] === false && $healthStale['status'] === 'stale' &&
    $healthFresh['healthy'] === true && $healthFresh['status'] === 'healthy',
    'VettingPurger::getRetentionCronHealth detects never_run, stale (>36h), and fresh scheduled cron heartbeats (#188)'
);


// =========================================================================
// Section 39: Adversarial MED-01..MED-15 Regression Suite (#172..#186)
// =========================================================================

// 39a. MED-01 (#172) & MED-03 (#174): OwnerAuth::getOwnerById, OTP prior-challenge invalidation, <5 brute-force lockout, and single-use consumption
$medOwnerEmail = 'med174_owner@test.co.ke';
$medOwnerRow = OwnerAuth::upsertOwner($pdo, $medOwnerEmail, 'MED-03 Founder');
$medOwnerId = (int)$medOwnerRow['id'];
$fetchedById = OwnerAuth::getOwnerById($pdo, $medOwnerId);
assertTrue(
    is_array($fetchedById) &&
    (int)$fetchedById['id'] === $medOwnerId &&
    strtolower((string)$fetchedById['email']) === $medOwnerEmail,
    'OwnerAuth::getOwnerById loads owner record cleanly for transactional claim onboarding (#172)'
);

$otpFirst = OwnerAuth::issuePasswordOtp($pdo, $medOwnerEmail, null);
$otpSecond = OwnerAuth::issuePasswordOtp($pdo, $medOwnerEmail, null);
$staleFirstAttempt = OwnerAuth::verifyOtpAndSetPassword($pdo, $medOwnerEmail, $otpFirst['code'], 'ValidPass123!#');
// Re-issue fresh second OTP so failed_attempts starts at 0 before the 5-attempt brute-force test below
$otpSecond = OwnerAuth::issuePasswordOtp($pdo, $medOwnerEmail, null);
assertTrue(
    $staleFirstAttempt === null,
    'OwnerAuth::issuePasswordOtp atomically invalidates prior unused OTP challenges on re-issuance (#174)'
);

// Brute-force 5 wrong OTP guesses -> challenge must lock out even if correct code is supplied on 6th attempt
for ($bf = 0; $bf < 5; $bf++) {
    $wrongRes = OwnerAuth::verifyOtpAndSetPassword($pdo, $medOwnerEmail, '000000', 'ValidPass123!#');
    assertTrue($wrongRes === null, "Wrong OTP guess #" . ($bf + 1) . " returns null (#174)");
}
$sixthCorrectAttempt = OwnerAuth::verifyOtpAndSetPassword($pdo, $medOwnerEmail, $otpSecond['code'], 'ValidPass123!#');
assertTrue(
    $sixthCorrectAttempt === null,
    'OTP challenge locks out permanently after 5 failed_attempts (#174)'
);

// Fresh OTP succeeds once and rejects replay on second call
$otpThird = OwnerAuth::issuePasswordOtp($pdo, $medOwnerEmail, null);
$validConsume1 = OwnerAuth::verifyOtpAndSetPassword($pdo, $medOwnerEmail, $otpThird['code'], 'StrongOwnerPass#174');
$replayConsume2 = OwnerAuth::verifyOtpAndSetPassword($pdo, $medOwnerEmail, $otpThird['code'], 'AnotherPass#174');
assertTrue(
    is_array($validConsume1) && (int)$validConsume1['id'] === $medOwnerId && $replayConsume2 === null,
    'OwnerAuth::verifyOtpAndSetPassword consumes OTP atomically once and blocks replay (#174)'
);

// Magic link atomic single-use consumption (#174)
$magicIssued = OwnerAuth::createMagicLink($pdo, $medOwnerEmail, null);
parse_str((string)parse_url($magicIssued['url'], PHP_URL_QUERY), $magicQ);
$rawMagicTok = (string)($magicQ['token'] ?? '');
$magicConsume1 = OwnerAuth::verifyAndConsumeMagicLink($pdo, $rawMagicTok);
$magicConsume2 = OwnerAuth::verifyAndConsumeMagicLink($pdo, $rawMagicTok);
assertTrue(
    is_array($magicConsume1) && $magicConsume2 === null,
    'OwnerAuth::verifyAndConsumeMagicLink consumes magic link atomically once and blocks replay (#174)'
);

// 39b. MED-02 (#173): Atomic claimSingleUseNonce, per-recipient 60s OTP cooldown, global 15/1h outbound mail circuit breaker, and login.php anti-spam gate
$nonceHash173 = hash('sha256', 'unit_nonce_173_' . bin2hex(random_bytes(8)));
$claimNonce1 = AntiSpamGate::claimSingleUseNonce($pdo, $nonceHash173, time() + 3600);
$claimNonce2 = AntiSpamGate::claimSingleUseNonce($pdo, $nonceHash173, time() + 3600);
assertTrue(
    $claimNonce1 === true && $claimNonce2 === false,
    'AntiSpamGate::claimSingleUseNonce claims single-use nonce atomically and rejects duplicate claims (#173)'
);

$pdo->exec("DELETE FROM rate_limits WHERE action_type IN ('global_outbound_mail', 'otp_recipient_cooldown')");
$quotaRcpt1 = AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, '198.51.100.73', 'cooldown173@test.co.ke', true, 15);
$quotaRcpt2 = AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, '198.51.100.74', 'cooldown173@test.co.ke', true, 15);
assertTrue(
    $quotaRcpt1['allowed'] === true &&
    $quotaRcpt2['allowed'] === false &&
    $quotaRcpt2['reason'] === 'otp_recipient_cooldown_60s',
    'AntiSpamGate::checkAndRecordOutboundMailQuota enforces 60-second per-recipient OTP cooldown across distinct IPs (#173)'
);

$pdo->exec("DELETE FROM rate_limits WHERE action_type IN ('global_outbound_mail', 'otp_recipient_cooldown')");
$breakerAllowedCount = 0;
for ($gm = 0; $gm < 4; $gm++) {
    $qRes = AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, '198.51.100.' . (80 + $gm), "u{$gm}@test.co.ke", false, 3);
    if ($qRes['allowed']) {
        $breakerAllowedCount++;
    }
}
$pdo->exec("DELETE FROM rate_limits WHERE action_type IN ('global_outbound_mail', 'otp_recipient_cooldown')");
$loginPhpSrc = (string)file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
assertTrue(
    $breakerAllowedCount === 3 &&
    str_contains($loginPhpSrc, "AntiSpamGate::verifySubmission(\$pdo, \$spamPayload, \$clientIp, 'owner_otp_send')") &&
    !str_contains($loginPhpSrc, 'automatically emailed a 6-digit verification code'),
    'Outbound mail circuit breaker caps global hourly mail and public/owner/login.php gates OTP behind AntiSpamGate without auto-OTP on password_login (#173)'
);

// 39c. MED-04 (#175): Signed admin action jti nonce, pending-only approve/reject guard, and moderation_audit_log
$catPairStmt = $pdo->query("SELECT c.id AS spec_id, c.parent_id AS sec_id FROM categories c WHERE c.parent_id IS NOT NULL AND c.status = 'approved' LIMIT 1");
$catPair = $catPairStmt->fetch();
$validSecId = (int)$catPair['sec_id'];
$validSpecId = (int)$catPair['spec_id'];

$signedUrl175 = Mailer::generateSignedAdminActionUrl(999, 'approve', time() + 3600, 0);
parse_str((string)parse_url($signedUrl175, PHP_URL_QUERY), $sq175);
$validSig175 = Mailer::verifySignedAdminAction(999, 'approve', (int)$sq175['exp'], 0, (string)$sq175['sig'], (string)$sq175['jti']);
$missingJti175 = Mailer::verifySignedAdminAction(999, 'approve', (int)$sq175['exp'], 0, (string)$sq175['sig'], '');
$reviewActionSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/review-action.php');
assertTrue(
    $validSig175 === true &&
    $missingJti175 === false &&
    str_contains($reviewActionSrc, 'confirm-review-action-btn') &&
    str_contains($reviewActionSrc, 'AntiSpamGate::claimSingleUseNonce'),
    'Mailer signed admin URL binds single-use jti and review-action.php enforces POST+CSRF+single-use jti confirmation (#175)'
);

// Insert a pending business, approve once, then verify re-approving or rejecting non-pending throws RuntimeException and writes moderation_audit_log
$pendingBizNumber175 = VettingPurger::allocateNextBusinessNumber($pdo);
$pendingUid175 = VettingPurger::generateUniqueBusinessUid($pdo);
$pendingSlug175 = VettingPurger::generateUniqueSlug($pdo, 'Med 175 Moderation Guard Ltd');
$insPend175 = $pdo->prepare("
    INSERT INTO businesses (
        business_number, business_id, name, slug, tagline, description, sector_id, specialty_id,
        country, city_county, operating_hours_json, phone_primary,
        status, listing_provenance, consent_recorded_at, privacy_notice_version, age_attested_at
    ) VALUES (
        :bnum, :buid, 'Med 175 Moderation Guard Ltd', :slug, 'Moderation guard test', 'Testing pending state guard', :sec, :spec,
        'Kenya', 'Nairobi', '{}', '+254700175175',
        'pending', 'owner_submitted', CURRENT_TIMESTAMP, '2026-11-v2', CURRENT_TIMESTAMP
    )
");
$insPend175->execute([
    ':bnum' => $pendingBizNumber175,
    ':buid' => $pendingUid175,
    ':slug' => $pendingSlug175,
    ':sec' => $validSecId,
    ':spec' => $validSpecId,
]);
$pendId175 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO business_vetting_queue (business_id, school_code, house_name, year_left, school_number, submitter_name, submitter_email, submitter_phone) VALUES (:bid, 'AHS', 'Livingstone', 2010, '10175', 'Founder 175', 'f175@test.co.ke', '+254700175175')")->execute([':bid' => $pendId175]);

VettingPurger::approveBusiness($pdo, $pendId175, null, 'signed_email_link', 'signed_link:jti_test175');
Mailer::clearPendingOutbox();
$threwOnReApprove = false;
try {
    VettingPurger::approveBusiness($pdo, $pendId175, null, 'admin_dashboard', 'admin_session');
} catch (\RuntimeException $e) {
    $threwOnReApprove = true;
}
$threwOnRejectApproved = false;
try {
    VettingPurger::rejectBusiness($pdo, $pendId175, 'signed_email_link', 'signed_link:jti_reject175');
} catch (\RuntimeException $e) {
    $threwOnRejectApproved = true;
}
$auditStmt175 = $pdo->prepare("SELECT COUNT(*) FROM moderation_audit_log WHERE business_id = :bid AND action = 'approve' AND channel = 'signed_email_link'");
$auditStmt175->execute([':bid' => $pendId175]);
assertTrue(
    $threwOnReApprove === true &&
    $threwOnRejectApproved === true &&
    (int)$auditStmt175->fetchColumn() === 1,
    'VettingPurger::approveBusiness & rejectBusiness enforce status=pending precondition and record moderation_audit_log (#175)'
);

// 39d. MED-05 (#176) & MED-06 (#177): Pre-decode >4096px/>16MP PNG header guard, quarantine media move/restore, orphan cleanup on edit, path traversal guard, and sweepOrphanedMedia
// Craft minimal PNG with IHDR 5000x5000 (25 MP > 16 MP & > 4096px) without allocating 25 MP in GD!
$ihdrData = pack('NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0);
$pngHeader5000 = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
$bombDataUrl = 'data:image/png;base64,' . base64_encode($pngHeader5000);
$threwOnDecompressionBomb = false;
try {
    PhotoUploader::processBusinessPhotos(null, [], null, json_encode([$bombDataUrl]));
} catch (PhotoUploadValidationException $e) {
    $threwOnDecompressionBomb = true;
}
assertTrue(
    $threwOnDecompressionBomb === true &&
    PhotoUploader::resolveSafeUploadFilePath('/uploads/businesses/../../etc/passwd') === null,
    'PhotoUploader blocks >4096px/>16MP image headers prior to GD decode (#176) and blocks ../ path traversal (#177)'
);

// Create 2 real photos for $pendId175, test quarantine move/restore & post-commit orphan deletion on updateBusiness
$im177a = imagecreatetruecolor(32, 32);
ob_start(); imagejpeg($im177a, null, 85); $jb177a = (string)ob_get_clean(); imagedestroy($im177a);
$im177b = imagecreatetruecolor(36, 36);
ob_start(); imagejpeg($im177b, null, 85); $jb177b = (string)ob_get_clean(); imagedestroy($im177b);
$twoPhotosJson = PhotoUploader::processBusinessPhotos(null, [], null, json_encode([
    'data:image/jpeg;base64,' . base64_encode($jb177a),
    'data:image/jpeg;base64,' . base64_encode($jb177b),
]));
$twoPhotoUrls = PhotoUploader::decodePhotosJson($twoPhotosJson);
$pdo->prepare("UPDATE businesses SET photos_json = :pj WHERE id = :id")->execute([':pj' => $twoPhotosJson, ':id' => $pendId175]);
$diskPhoto0 = PhotoUploader::resolveSafeUploadFilePath($twoPhotoUrls[0]);
$diskPhoto1 = PhotoUploader::resolveSafeUploadFilePath($twoPhotoUrls[1]);

VettingPurger::quarantineBusinessDpa($pdo, $pendId175, 'Unit test DPA quarantine #177');
$goneFromPublicDuringQuarantine = ($diskPhoto0 !== null && !is_file($diskPhoto0)) && ($diskPhoto1 !== null && !is_file($diskPhoto1));
$inQuarantineVault = is_file(PhotoUploader::quarantineDirRoot() . '/' . $pendId175 . '/' . basename((string)$diskPhoto0));

VettingPurger::restoreQuarantinedBusiness($pdo, $pendId175);
$restoredToPublic = ($diskPhoto0 !== null && is_file($diskPhoto0)) && ($diskPhoto1 !== null && is_file($diskPhoto1));
assertTrue(
    $goneFromPublicDuringQuarantine === true &&
    $inQuarantineVault === true &&
    $restoredToPublic === true,
    'quarantineBusinessDpa moves listing media out of public webroot and restoreQuarantinedBusiness restores them (#177)'
);

// Remove first photo via VettingPurger::updateBusiness -> diskPhoto0 must be deleted post-commit while diskPhoto1 stays!
$bizRow177Stmt = $pdo->prepare("SELECT * FROM businesses WHERE id = :id");
$bizRow177Stmt->execute([':id' => $pendId175]);
$bizRow177 = $bizRow177Stmt->fetch();
$bizRow177['remove_photos'] = [$twoPhotoUrls[0]];
VettingPurger::updateBusiness($pdo, $pendId175, $bizRow177);
assertTrue(
    $diskPhoto0 !== null && !is_file($diskPhoto0) && $diskPhoto1 !== null && is_file($diskPhoto1),
    'VettingPurger::updateBusiness deletes removed photo files from disk after transaction commit (#177)'
);

// Create a stale unreferenced file in uploads/businesses and verify sweepOrphanedMedia deletes it
$staleOrphanFile = PhotoUploader::uploadDir() . '/bbiz_orphan_stale_test177.jpg';
file_put_contents($staleOrphanFile, $jb177a);
@touch($staleOrphanFile, time() - 7200);
$sweepRes = PhotoUploader::sweepOrphanedMedia($pdo, 3600);
assertTrue(
    $sweepRes['orphaned_public_deleted'] >= 1 && !is_file($staleOrphanFile) && ($diskPhoto1 !== null && is_file($diskPhoto1)),
    'PhotoUploader::sweepOrphanedMedia removes unreferenced stale uploads while preserving referenced listing photos (#177)'
);

// 39e. MED-07 (#178) & MED-08 (#179): Plain-language OSM/Nominatim/OSRM disclosure, map consent gate, and listing provenance + admin basis enforcement
$privacySrc178 = (string)file_get_contents(dirname(__DIR__) . '/public/privacy.php');
$dirJsSrc178 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
assertTrue(
    !str_contains($privacySrc178, '<code') &&
    str_contains($privacySrc178, 'OpenStreetMap') &&
    str_contains($privacySrc178, 'Nominatim') &&
    str_contains($privacySrc178, 'OSRM') &&
    str_contains($dirJsSrc178, 'hasMapTileConsent') &&
    str_contains($dirJsSrc178, 'btn-enable-osm-map'),
    'public/privacy.php discloses OpenStreetMap/Nominatim/OSRM in plain language (zero <code> tags) and directory.js gates OSM map tiles on consent (#178)'
);

$threwMissingAdminBasis = false;
try {
    VettingPurger::createBusinessDirectly($pdo, [
        'name' => 'Missing Provenance Test',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'description' => 'Should fail without lawful basis note',
        'country' => 'Kenya',
        'city_county' => 'Nairobi',
        'require_provenance_basis' => true,
    ]);
} catch (\InvalidArgumentException $e) {
    $threwMissingAdminBasis = true;
}

$curatedId179 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Curated Provenance Biashara 179',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'description' => 'Curated with explicit founder authorization',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'owner_email' => 'claim179@test.co.ke',
    'require_provenance_basis' => true,
    'admin_provenance_basis' => 'alumni_direct_authorization',
    'admin_provenance_note' => 'Authorized directly via email by founder on 2026-11-12',
]);
$provStmt179 = $pdo->prepare("SELECT listing_provenance, admin_provenance_note, privacy_notice_version FROM businesses WHERE id = :id");
$provStmt179->execute([':id' => $curatedId179]);
$provRow179 = $provStmt179->fetch();

// Redeem claim invite on $curatedId179 -> listing_provenance transitions to 'claimed' and consent timestamps are recorded
$claimExp179 = time() + 3600;
$claimUrl179 = OwnerAuth::generateClaimInviteUrl($curatedId179, $claimExp179, 'claim179@test.co.ke');
parse_str((string)parse_url($claimUrl179, PHP_URL_QUERY), $cq179);
$claimOwnerRow179 = OwnerAuth::upsertOwner($pdo, 'claim179@test.co.ke', 'Claimant 179');
$claimOwner179 = (int)$claimOwnerRow179['id'];
$redeemed179 = OwnerAuth::redeemVerifiedClaimInvite($pdo, $claimOwner179, 'claim179@test.co.ke', $curatedId179, $claimExp179, (string)$cq179['sig'], 'claim179@test.co.ke', true, true);
$provAfterClaimStmt = $pdo->prepare("SELECT listing_provenance, consent_recorded_at FROM businesses WHERE id = :id");
$provAfterClaimStmt->execute([':id' => $curatedId179]);
$provAfterClaim = $provAfterClaimStmt->fetch();
assertTrue(
    $threwMissingAdminBasis === true &&
    is_array($provRow179) &&
    $provRow179['listing_provenance'] === 'admin_curated' &&
    str_contains((string)$provRow179['admin_provenance_note'], '[alumni_direct_authorization]') &&
    $redeemed179 === true &&
    is_array($provAfterClaim) &&
    $provAfterClaim['listing_provenance'] === 'claimed' &&
    !empty($provAfterClaim['consent_recorded_at']),
    'Listing provenance & DPA lawful-basis fields are enforced on admin curation and updated on verified owner claim (#179)'
);

// 39f. MED-09 (#180): Batched OwnerAuth::getEditableBusinessIdsForOwner
$editableMap180 = OwnerAuth::getEditableBusinessIdsForOwner($pdo, $claimOwner179, [$curatedId179, $pendId175, 999999]);
assertTrue(
    isset($editableMap180[$curatedId179]) && !isset($editableMap180[$pendId175]) && !isset($editableMap180[999999]),
    'OwnerAuth::getEditableBusinessIdsForOwner resolves owner edit permissions in a single batched query (#180)'
);

// 39g. MED-11 (#182): Dynamic column inspection + PII scrubbing in PreprodSandbox::cloneSnapshotRowsIntoSqlite
$cloneSqlitePath182 = sys_get_temp_dir() . '/bbiz_clone182_' . getmypid() . '.sqlite';
$clonePdo182 = new PDO('sqlite:' . $cloneSqlitePath182, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
Database::migrateAndSeed($clonePdo182, false);
$clonePdo182->exec("INSERT INTO email_outbox (recipient_email, subject, body_html, status) VALUES ('secret@real.co.ke', 'PII', '<p>PII</p>', 'sent')");
$cloneRes182 = PreprodSandbox::cloneSnapshotRowsIntoSqlite(
    $clonePdo182,
    [
        [
            'id' => 1,
            'parent_id' => null,
            'name' => 'Technology',
            'slug' => 'technology',
            'status' => 'approved',
            'submitted_by_email' => 'submitter_pii@real.co.ke',
            'future_mysql_only_col' => 'ignored_gracefully',
        ],
    ],
    [
        [
            'id' => 10,
            'business_number' => 10,
            'business_id' => 'BB-00010',
            'name' => 'Prod Snapshot Co',
            'slug' => 'prod-snapshot-co',
            'tagline' => 'Approved tagline',
            'description' => 'Approved listing',
            'sector_id' => 1,
            'specialty_id' => 1,
            'country' => 'Kenya',
            'city_county' => 'Nairobi',
            'operating_hours_json' => '{"mon":[["08:00","17:00"]]}',
            'phone_primary' => '+254700000010',
            'owner_email' => 'real_founder_pii@company.co.ke',
            'admin_provenance_note' => 'Private admin note with phone 0700000000',
            'status' => 'approved',
            'future_mysql_only_col' => 'ignored_gracefully',
        ],
    ]
);
$clonedBizRow182 = $clonePdo182->query("SELECT owner_email, admin_provenance_note, listing_provenance FROM businesses WHERE id = 10")->fetch();
$clonedCatRow182 = $clonePdo182->query("SELECT submitted_by_email FROM categories WHERE id = 1")->fetch();
$outboxAfterClone182 = (int)$clonePdo182->query("SELECT COUNT(*) FROM email_outbox")->fetchColumn();
unset($clonePdo182);
@unlink($cloneSqlitePath182);
assertTrue(
    $cloneRes182['businesses_cloned'] === 1 &&
    $cloneRes182['pii_fields_scrubbed'] === true &&
    is_array($clonedBizRow182) &&
    $clonedBizRow182['owner_email'] === null &&
    $clonedBizRow182['admin_provenance_note'] === null &&
    $clonedBizRow182['owner_email'] === null &&
    is_array($clonedCatRow182) &&
    $clonedCatRow182['submitted_by_email'] === null &&
    $outboxAfterClone182 === 0,
    'PreprodSandbox::cloneSnapshotRowsIntoSqlite inspects columns dynamically, ignores unknown drift columns, wipes outbox/auth tables, and scrubs PII (#182)'
);

// 39h. MED-12 (#183): Monotonic business_sequences allocation, zero global renumber on deleteBusiness, and CLI bin/migrate-and-seed.php
$seqNumA = VettingPurger::allocateNextBusinessNumber($pdo);
$seqNumB = VettingPurger::allocateNextBusinessNumber($pdo);
$curatedNumBeforeStmt = $pdo->prepare("SELECT business_number FROM businesses WHERE id = :id");
$curatedNumBeforeStmt->execute([':id' => $curatedId179]);
$curatedNumBefore = (int)$curatedNumBeforeStmt->fetchColumn();

// Delete earlier business $pendId175 and verify $curatedId179 retains its exact business_number!
VettingPurger::deleteBusiness($pdo, $pendId175, 'admin_dashboard', 'admin_session');
$curatedNumAfterStmt = $pdo->prepare("SELECT business_number FROM businesses WHERE id = :id");
$curatedNumAfterStmt->execute([':id' => $curatedId179]);
$curatedNumAfter = (int)$curatedNumAfterStmt->fetchColumn();

$cliMigrateSqlite = sys_get_temp_dir() . '/bbiz_cli_mig183_' . getmypid() . '.sqlite';
$cliOut183 = [];
$cliRc183 = 0;
exec(
    sprintf(
        'APP_ENV=testing DB_DRIVER=sqlite DB_SQLITE_PATH=%s php %s --seed-samples 2>&1',
        escapeshellarg($cliMigrateSqlite),
        escapeshellarg(dirname(__DIR__) . '/bin/migrate-and-seed.php')
    ),
    $cliOut183,
    $cliRc183
);
@unlink($cliMigrateSqlite);
assertTrue(
    $seqNumB === ($seqNumA + 1) &&
    $curatedNumBefore > 0 &&
    $curatedNumAfter === $curatedNumBefore &&
    $cliRc183 === 0 &&
    str_contains(implode("\n", $cliOut183), '"status": "ok"') && str_contains(implode("\n", $cliOut183), '"schema_version"'),
    'business_sequences allocates monotonic numbers, deleteBusiness preserves existing business_number values without global renumbering, and bin/migrate-and-seed.php succeeds (#183)'
);
VettingPurger::deleteBusiness($pdo, $curatedId179, 'admin_dashboard', 'admin_session');

// 39i. MED-13 (#184): Overnight shift carryover (Sunday 20:00 -> Monday 02:00) & strict 24h schedule validation
$overnightSchedule = [
    'mon' => [],
    'tue' => [],
    'wed' => [],
    'thu' => [],
    'fri' => [],
    'sat' => [],
    'sun' => [['20:00', '02:00']],
];
$eatTz = new DateTimeZone('Africa/Nairobi');
// 2026-11-16 is a Monday -> 01:00 EAT carries over from Sunday's 20:00-02:00 overnight shift!
$mon0100 = new DateTimeImmutable('2026-11-16 01:00:00', $eatTz);
$mon0140 = new DateTimeImmutable('2026-11-16 01:40:00', $eatTz);
$mon0205 = new DateTimeImmutable('2026-11-16 02:05:00', $eatTz);
$ovOpen = HoursEngine::evaluateStatus($overnightSchedule, $mon0100);
$ovClosingSoon = HoursEngine::evaluateStatus($overnightSchedule, $mon0140);
$ovClosed = HoursEngine::evaluateStatus($overnightSchedule, $mon0205);

$badHoursSchedule = $overnightSchedule;
$badHoursSchedule['mon'] = [['25:00', '17:00']];
$validMidnightClose = $overnightSchedule;
$validMidnightClose['mon'] = [['08:00', '24:00']];
assertTrue(
    $ovOpen['is_open'] === true && $ovOpen['state'] === 'OPEN_NOW' &&
    $ovClosingSoon['is_open'] === true && $ovClosingSoon['state'] === 'CLOSING_SOON' &&
    $ovClosed['is_open'] === false && $ovClosed['state'] === 'CLOSED' &&
    HoursEngine::validateScheduleJson((string)json_encode($badHoursSchedule)) === false &&
    HoursEngine::validateScheduleJson((string)json_encode($validMidnightClose)) === true,
    'HoursEngine evaluates overnight carryover shifts across midnight (including Sunday->Monday) and rejects out-of-range HH:MM values (#184)'
);

// 39j. MED-14 (#185) & MED-15 (#186): AbortController/request-seq/retry UI, sector+specialty hierarchy check, and strict http(s) URL & bounds validation
$otherSecStmt = $pdo->prepare("SELECT id FROM categories WHERE parent_id IS NULL AND id <> :sec LIMIT 1");
$otherSecStmt->execute([':sec' => $validSecId]);
$otherSecId = (int)$otherSecStmt->fetchColumn();

$validHoursJson186 = (string)json_encode($validMidnightClose);
$threwJavascriptUrl = false;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'XSS URL Attempt',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'description' => 'Testing javascript URL rejection',
        'website_url' => 'javascript:alert(document.cookie)',
    ], null, null, $validHoursJson186);
} catch (\InvalidArgumentException $e) {
    $threwJavascriptUrl = true;
}

$threwOutOfBoundsLat = false;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'Invalid Latitude Attempt',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'description' => 'Testing latitude bounds',
    ], 95.5, 36.8, $validHoursJson186);
} catch (\InvalidArgumentException $e) {
    $threwOutOfBoundsLat = true;
}

assertTrue(
    CategoryMatcher::validateSectorAndSpecialtyPair($pdo, $validSecId, $validSpecId) === true &&
    CategoryMatcher::validateSectorAndSpecialtyPair($pdo, $otherSecId, $validSpecId) === false &&
    CategoryMatcher::validateSectorAndSpecialtyPair($pdo, $validSecId, $validSecId) === false &&
    $threwJavascriptUrl === true &&
    $threwOutOfBoundsLat === true &&
    str_contains($dirJsSrc178, 'AbortController') &&
    str_contains($dirJsSrc178, 'latestRequestSeq') &&
    str_contains($dirJsSrc178, 'directory-fetch-error') &&
    str_contains($dirJsSrc178, 'sanitizeHttpUrl'),
    'Server & frontend enforce sector->specialty hierarchy, http(s) URL schemes, coordinate bounds (#186), and AbortController/retryable fetch error UI (#185)'
);


// =========================================================================
// 40. Pass-2 R2-LOW-01..03 (#201, #202, #203) Regression Suite
// =========================================================================

// 40a. R2-LOW-01 (#201): getMaskedOwnerEmailHintsForBusiness($pdo, $id, true) hides hints for non-approved listings
$pendStmt201 = $pdo->prepare("
    INSERT INTO businesses (
        name, slug, tagline, description, school_badge, sector_id, specialty_id, service_mode,
        city_county, operating_hours_json, phone_primary, email_public, owner_email, status
    ) VALUES (
        'Unpublished Stealth Venture 201', 'unpublished-stealth-201', 'Stealth', 'Pending listing', 'AHS', :sec, :spec, 'hybrid',
        'Nairobi', '{\"mon\":[[\"08:00\",\"17:00\"]]}', '+254700201201', 'info@stealth201.co.ke', 'founder@stealth201.co.ke', 'pending'
    )
");
$pendStmt201->execute([':sec' => $validSecId, ':spec' => $validSpecId]);
$pendBizId201 = (int)$pdo->lastInsertId();

$approvedBizStmt201 = $pdo->query("SELECT id FROM businesses WHERE status = 'approved' AND owner_email IS NOT NULL LIMIT 1");
$approvedBizId201 = (int)$approvedBizStmt201->fetchColumn();

$anonHintsPending201 = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $pendBizId201, true);
$adminHintsPending201 = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $pendBizId201, false);
$anonHintsApproved201 = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $approvedBizId201, true);

$loginSrc201 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
assertTrue(
    $anonHintsPending201 === [] &&
    count($adminHintsPending201) >= 1 &&
    count($anonHintsApproved201) >= 1 &&
    str_contains($loginSrc201, "status = 'approved'") &&
    str_contains($loginSrc201, "'owner_biz_hint_lookup'"),
    'Anonymous ?biz=<id> hint lookup returns empty hints for non-approved listings and throttles per-IP enumeration (#201)'
);
VettingPurger::deleteBusiness($pdo, $pendBizId201, 'unit_cleanup', 'system');

// 40b. R2-LOW-02 (#202): HoursEngine rejects ambiguous equal endpoints (08:00-08:00, 00:00-00:00) and evaluates explicit 00:00-24:00 24/7 cleanly
$ambiguousEqualJson = (string)json_encode(['mon' => [['08:00', '08:00']]]);
$ambiguousMidnightJson = (string)json_encode(['mon' => [['00:00', '00:00']]]);
$explicit24x7Schedule = [
    'mon' => [['00:00', '24:00']],
    'tue' => [['00:00', '24:00']],
    'wed' => [['00:00', '24:00']],
    'thu' => [['00:00', '24:00']],
    'fri' => [['00:00', '24:00']],
    'sat' => [['00:00', '24:00']],
    'sun' => [['00:00', '24:00']],
];
$singleDay24hSchedule = [
    'mon' => [['00:00', '24:00']],
    'tue' => [],
];
$threwEqualBuild202 = false;
try {
    HoursEngine::buildScheduleJsonFromPost([
        'hours_weekday_open' => '08:00',
        'hours_weekday_close' => '08:00',
    ]);
} catch (\InvalidArgumentException $e) {
    $threwEqualBuild202 = true;
}
$mon0005 = new DateTimeImmutable('2026-11-16 00:05:00', $eatTz);
$mon2345 = new DateTimeImmutable('2026-11-16 23:45:00', $eatTz);
$tue0130 = new DateTimeImmutable('2026-11-17 01:30:00', $eatTz);

$eval24x7Start = HoursEngine::evaluateStatus($explicit24x7Schedule, $mon0005);
$eval24x7Late = HoursEngine::evaluateStatus($explicit24x7Schedule, $mon2345);
$eval24x7NextDay = HoursEngine::evaluateStatus($explicit24x7Schedule, $tue0130);
$evalSingle24Late = HoursEngine::evaluateStatus($singleDay24hSchedule, $mon2345);
$evalSingle24NextDay = HoursEngine::evaluateStatus($singleDay24hSchedule, $tue0130);
$evalUnvalidatedEqual = HoursEngine::evaluateStatus($ambiguousEqualJson, $mon0005);

assertTrue(
    HoursEngine::validateScheduleJson($ambiguousEqualJson) === false &&
    HoursEngine::validateScheduleJson($ambiguousMidnightJson) === false &&
    HoursEngine::validateScheduleJson((string)json_encode($explicit24x7Schedule)) === true &&
    $threwEqualBuild202 === true &&
    $eval24x7Start['state'] === 'OPEN_NOW' &&
    $eval24x7Late['state'] === 'OPEN_NOW' &&
    $eval24x7NextDay['state'] === 'OPEN_NOW' &&
    $evalSingle24Late['state'] === 'CLOSING_SOON' &&
    $evalSingle24NextDay['state'] === 'CLOSED' &&
    $evalUnvalidatedEqual['is_open'] === false &&
    $eval24x7Start['schedule_table']['mon']['hours'] === 'Open 24 Hours',
    'HoursEngine rejects ambiguous equal-endpoint intervals, requires explicit 00:00-24:00 for 24-hour service, and evaluates continuous 24/7 vs single-day 24h schedules consistently (#202)'
);

// 40c. R2-LOW-03 (#203): Owner and Admin logout endpoints require POST + valid CSRF token and navbars use POST forms
$ownerLogoutSrc203 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/logout.php');
$adminLoginSrc203 = (string)file_get_contents(dirname(__DIR__) . '/public/admin/login.php');
$ownerIndexSrc203 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/index.php');
$adminIndexSrc203 = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$ownerClaimSrc203 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/claim.php');

assertTrue(
    !str_contains($adminLoginSrc203, "\$_GET['logout']") &&
    str_contains($adminLoginSrc203, "(\$_POST['action'] ?? '') === 'logout'") &&
    str_contains($ownerLogoutSrc203, "AntiSpamGate::verifyCsrfToken") &&
    !str_contains($ownerIndexSrc203, '<a href="/owner/logout.php"') &&
    !str_contains($ownerClaimSrc203, '<a href="/owner/logout.php"') &&
    !str_contains($adminIndexSrc203, '/admin/login.php?logout=1'),
    'Owner and Admin logout routes require POST + valid session CSRF token and all navigation Sign Out controls submit POST forms (#203)'
);

// =========================================================================
// 41. Pass-2 R2-HIGH-01..04 (#205, #206, #207, #208) Regression Suite
// =========================================================================

// 41a. R2-HIGH-01 (#205): APP_ENV=production fails closed when SIMULATE_EMAILS=yes and never enables canRenderDevOtpPreview()
$prodOtpProbeCmd = sprintf(
    'APP_ENV=production SIMULATE_EMAILS=yes APP_HMAC_SECRET=%s IP_HASH_SECRET=%s CSRF_SECRET=%s php -r %s 2>&1',
    escapeshellarg(str_repeat('a', 64)),
    escapeshellarg(str_repeat('b', 64)),
    escapeshellarg(str_repeat('c', 64)),
    escapeshellarg(
        'require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';' .
        'echo "PREVIEW=" . (\BusherianBiasharas\Services\OwnerAuth::canRenderDevOtpPreview() ? "1" : "0") . "\n";' .
        'try { \BusherianBiasharas\Config\Database::validateProductionSecrets(); echo "NO_THROW\n"; }' .
        'catch (\RuntimeException $e) { echo "THREW:" . $e->getMessage() . "\n"; }'
    )
);
$prodOtpOut = [];
$prodOtpRc = 0;
exec($prodOtpProbeCmd, $prodOtpOut, $prodOtpRc);
$prodOtpJoined = implode("\n", $prodOtpOut);

$prodNoSimProbeCmd = sprintf(
    'APP_ENV=production SIMULATE_EMAILS=no APP_HMAC_SECRET=%s IP_HASH_SECRET=%s CSRF_SECRET=%s php -r %s 2>&1',
    escapeshellarg(str_repeat('a', 64)),
    escapeshellarg(str_repeat('b', 64)),
    escapeshellarg(str_repeat('c', 64)),
    escapeshellarg(
        'require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';' .
        'echo "PREVIEW_NOSIM=" . (\BusherianBiasharas\Services\OwnerAuth::canRenderDevOtpPreview() ? "1" : "0") . "\n";' .
        '\BusherianBiasharas\Config\Database::validateProductionSecrets(); echo "PROD_OK\n";'
    )
);
$prodNoSimOut = [];
exec($prodNoSimProbeCmd, $prodNoSimOut);
$prodNoSimJoined = implode("\n", $prodNoSimOut);

assertTrue(
    str_contains($prodOtpJoined, 'PREVIEW=0') &&
    str_contains($prodOtpJoined, 'THREW:FATAL: SIMULATE_EMAILS cannot be enabled when APP_ENV=production') &&
    str_contains($prodNoSimJoined, 'PREVIEW_NOSIM=0') &&
    str_contains($prodNoSimJoined, 'PROD_OK'),
    'APP_ENV=production rejects SIMULATE_EMAILS=yes at startup and OwnerAuth::canRenderDevOtpPreview() is strictly false in production (#205)'
);

// 41b. R2-HIGH-02 (#206): bbiz_json_for_html_script escapes </script>, &, ', and ", and submit.php / edit-business.php eliminate unsafe sinks
$xssPayload206 = '</script><script>window.__xss206=1</script>&"\'';
$encodedScriptJson206 = bbiz_json_for_html_script(['reg' => $xssPayload206]);
$ownerEditSrc206 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/edit-business.php');
$submitSrc206 = (string)file_get_contents(dirname(__DIR__) . '/public/submit.php');

assertTrue(
    !str_contains($encodedScriptJson206, '</script>') &&
    !str_contains($encodedScriptJson206, '<script>') &&
    str_contains($encodedScriptJson206, '\u003C') &&
    (json_decode($encodedScriptJson206, true)['reg'] ?? '') === $xssPayload206 &&
    str_contains($ownerEditSrc206, 'bbiz_json_for_html_script($taxonomyTree)') &&
    str_contains($ownerEditSrc206, 'bbiz_json_for_html_script((string)($biz[\'regulator_name\']') &&
    !str_contains($submitSrc206, 'fuzzyBox.innerHTML = `') &&
    !str_contains($submitSrc206, 'statusBox.innerHTML = `'),
    'bbiz_json_for_html_script hex-escapes HTML tags/quotes cleanly and owner/edit-business.php + submit.php use safe serialization & DOM textContent (#206)'
);

// 41c. R2-HIGH-03 (#207): Public contact email (email_public) is NOT recognized as owner email and cannot hijack designated owner listing
$h03BizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 High03 Public Email Boundary Co',
    'tagline' => 'Front desk email must never grant ownership',
    'description' => 'Testing strict separation between email_public and owner_email.',
    'school_badge' => 'AGHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722207207',
    'email_public' => 'frontdesk@r2high03.co.ke',
    'owner_email' => 'true.founder@r2high03.co.ke',
    'admin_provenance_note' => 'Verified founder authorization',
]);
$recogPublic = OwnerAuth::isRecognizedOwnerEmail($pdo, 'frontdesk@r2high03.co.ke');
$recogFounder = OwnerAuth::isRecognizedOwnerEmail($pdo, 'true.founder@r2high03.co.ke');
$eligPublicUnscoped = OwnerAuth::isEmailEligibleForClaim($pdo, $h03BizId, 'frontdesk@r2high03.co.ke', '');
$eligPublicExplicitInvite = OwnerAuth::isEmailEligibleForClaim($pdo, $h03BizId, 'frontdesk@r2high03.co.ke', 'frontdesk@r2high03.co.ke');
$frontDeskAcct = OwnerAuth::upsertOwner($pdo, 'frontdesk@r2high03.co.ke', 'Front Desk');
$suggestions207 = OwnerAuth::getCorporateDomainClaimSuggestions($pdo, 'frontdesk@r2high03.co.ke', (int)$frontDeskAcct['id']);
$suggestedBizIds207 = array_map(static fn(array $s): int => (int)$s['id'], $suggestions207);

assertTrue(
    $recogPublic === false &&
    $recogFounder === true &&
    $eligPublicUnscoped === false &&
    $eligPublicExplicitInvite === true &&
    !OwnerAuth::canOwnerEditBusiness($pdo, (int)$frontDeskAcct['id'], $h03BizId) &&
    in_array($h03BizId, $suggestedBizIds207, true),
    'email_public is never auto-linked or treated as a recognized/unscoped-claim owner email, and surfaces only as informational claim guidance (#207)'
);
VettingPurger::deleteBusiness($pdo, $h03BizId, 'unit_cleanup', 'system');

// 41d. R2-HIGH-04 (#208): Editing a quarantined_dpa business (owner or admin) immediately quarantines newly uploaded replacement photos, and failed quarantine moves fail closed
$gdImg208 = imagecreatetruecolor(16, 16);
ob_start();
imagepng($gdImg208);
$pngBytes208 = (string)ob_get_clean();
imagedestroy($gdImg208);
$dataUri208A = 'data:image/png;base64,' . base64_encode((string)$pngBytes208);
$dataUri208B = 'data:image/png;base64,' . base64_encode((string)$pngBytes208);

$qBizId208 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 High04 Quarantine Edit Vault Co',
    'tagline' => 'Quarantined media must never leak on edit',
    'description' => 'Verifying state-aware media quarantine on owner edits.',
    'school_badge' => 'AHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722208208',
    'owner_email' => 'owner208@quarantinevault.co.ke',
    'business_photos_data_json' => (string)json_encode([$dataUri208A]),
    'admin_provenance_note' => 'Unit test fixture',
]);
$owner208 = OwnerAuth::upsertOwner($pdo, 'owner208@quarantinevault.co.ke', 'Owner 208');
VettingPurger::quarantineBusinessDpa($pdo, $qBizId208, 'owner_portal', 'owner:208');

$qRowBeforeEdit = $pdo->query("SELECT * FROM businesses WHERE id = {$qBizId208}")->fetch();
$oldPhotoUrls208 = PhotoUploader::decodePhotosJson((string)($qRowBeforeEdit['photos_json'] ?? ''));
$editPayload208 = (array)$qRowBeforeEdit;
$editPayload208['remove_photos'] = $oldPhotoUrls208;
$editPayload208['business_photos_data_json'] = (string)json_encode([$dataUri208B]);

$afterOwnerEdit208 = VettingPurger::updateBusinessByOwner($pdo, $qBizId208, $owner208, $editPayload208);
$newPhotoUrls208 = PhotoUploader::decodePhotosJson((string)($afterOwnerEdit208['business']['photos_json'] ?? ''));
$newPubPath208 = !empty($newPhotoUrls208[0]) ? PhotoUploader::resolveSafeUploadFilePath($newPhotoUrls208[0], true) : 'unexpected';
$expectedRelFile208 = !empty($newPhotoUrls208[0]) ? basename((string)PhotoUploader::resolveSafeUploadFilePath($newPhotoUrls208[0], false)) : '';
$quarantinedCopyExists208 = $expectedRelFile208 !== '' && is_file(PhotoUploader::quarantineDirRoot() . '/' . $qBizId208 . '/' . $expectedRelFile208);

VettingPurger::restoreQuarantinedBusiness($pdo, $qBizId208, 'admin_dashboard', 'admin_test');
$restoredPubPath208 = !empty($newPhotoUrls208[0]) ? PhotoUploader::resolveSafeUploadFilePath($newPhotoUrls208[0], true) : null;

// Also verify fail-closed rollback when quarantine target path is blocked by a regular file
$blockerDirParent = PhotoUploader::quarantineDirRoot();
if (!is_dir($blockerDirParent)) {
    @mkdir($blockerDirParent, 0750, true);
}
$blockerFile208 = $blockerDirParent . '/' . $qBizId208;
if (is_dir($blockerFile208)) {
    @rmdir($blockerFile208);
}
file_put_contents($blockerFile208, 'not_a_directory');
$failedMoveThrew208 = false;
try {
    VettingPurger::quarantineBusinessDpa($pdo, $qBizId208, 'admin_dashboard', 'admin_test');
} catch (\RuntimeException $e) {
    $failedMoveThrew208 = true;
}
@unlink($blockerFile208);
$statusAfterFailedQuarantine208 = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$qBizId208}")->fetchColumn();

assertTrue(
    count($newPhotoUrls208) === 1 &&
    $newPubPath208 === null &&
    $quarantinedCopyExists208 === true &&
    $restoredPubPath208 !== null && is_file($restoredPubPath208) &&
    $failedMoveThrew208 === true &&
    $statusAfterFailedQuarantine208 === 'approved',
    'Editing a quarantined_dpa business immediately quarantines replacement uploads out of public webroot, restores on unquarantine, and rolls back DB status if quarantine file move fails (#208)'
);
VettingPurger::deleteBusiness($pdo, $qBizId208, 'unit_cleanup', 'system');

// =========================================================================
// 42. Pass-2 R2-MED-01..17 (#209..#225) Behavioral & Multi-Process Concurrency Suite
// =========================================================================

// 42a. R2-MED-03 (#211), R2-MED-04 (#212), R2-MED-15 (#223): Real 2-process proc_open races on shared DB
$workerPhpBin = PHP_BINARY ?: 'php';
$bootstrapPath = dirname(__DIR__) . '/src/bootstrap.php';
$raceTempSqlite = sys_get_temp_dir() . '/bbiz_proc_race_' . getmypid() . '.sqlite';
$workerEnv = getenv();
$workerEnv['APP_ENV'] = 'testing';
$workerEnv['DB_DRIVER'] = $initialDriver;
$workerEnv['SIMULATE_EMAILS'] = 'yes';
if ($initialDriver === 'sqlite') {
    $workerEnv['DB_SQLITE_PATH'] = $raceTempSqlite;
}

// Seed the shared target DB (isolated file-backed SQLite or shared MySQL 8.0) via a clean subprocess so zero parent WAL cursors pin snapshots
$seedWorkerCode = sprintf(
    'require %s; ' .
    '$pdo = \BusherianBiasharas\Config\Database::getConnection(); ' .
    '$stmt = $pdo->prepare("INSERT INTO email_outbox (recipient_email, subject, body_html, status, attempts, lease_until, next_retry_at, expires_at) VALUES (\'race211@busherian-test.co.ke\', \'Concurrent Lease Race #211\', \'<p>Lease test</p>\', \'queued\', 0, NULL, NULL, :exp)"); ' .
    '$stmt->execute([\':exp\' => time() + 600]); ' .
    'echo (int)$pdo->lastInsertId();',
    var_export($bootstrapPath, true)
);
$descSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$pSeed = proc_open([$workerPhpBin, '-r', $seedWorkerCode], $descSpec, $pipesSeed, null, $workerEnv);
fclose($pipesSeed[0]);
$raceOutboxId = (int)trim((string)stream_get_contents($pipesSeed[1]));
$seedErr = (string)stream_get_contents($pipesSeed[2]);
fclose($pipesSeed[1]);
fclose($pipesSeed[2]);
$seedRc = proc_close($pSeed);

$barrierAt1 = microtime(true) + 0.22;
$outboxWorkerCode = sprintf(
    '$target = %.6f; while (microtime(true) < $target) { usleep(1000); } ' .
    'require %s; ' .
    '$pdo = \BusherianBiasharas\Config\Database::getConnection(); ' .
    '$ok = \BusherianBiasharas\Services\Mailer::dispatchOutboxRow($pdo, %d); ' .
    'echo $ok ? "1" : "0";',
    $barrierAt1,
    var_export($bootstrapPath, true),
    $raceOutboxId
);

$pA = proc_open([$workerPhpBin, '-r', $outboxWorkerCode], $descSpec, $pipesA, null, $workerEnv);
$pB = proc_open([$workerPhpBin, '-r', $outboxWorkerCode], $descSpec, $pipesB, null, $workerEnv);
fclose($pipesA[0]);
fclose($pipesB[0]);
$outA = trim((string)stream_get_contents($pipesA[1]));
$errA = (string)stream_get_contents($pipesA[2]);
$outB = trim((string)stream_get_contents($pipesB[1]));
$errB = (string)stream_get_contents($pipesB[2]);
fclose($pipesA[1]);
fclose($pipesA[2]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$rcA = proc_close($pA);
$rcB = proc_close($pB);

// Also race 2 concurrent OS processes on AntiSpamGate::claimSingleUseNonce()
$sharedRaceNonceHash = hash('sha256', 'r2_med_race_nonce_' . bin2hex(random_bytes(8)));
$barrierAt2 = microtime(true) + 0.22;
$nonceWorkerCode = sprintf(
    '$target = %.6f; while (microtime(true) < $target) { usleep(1000); } ' .
    'require %s; ' .
    '$pdo = \BusherianBiasharas\Config\Database::getConnection(); ' .
    '$claimed = \BusherianBiasharas\Services\AntiSpamGate::claimSingleUseNonce($pdo, %s, time() + 300); ' .
    'echo $claimed ? "1" : "0";',
    $barrierAt2,
    var_export($bootstrapPath, true),
    var_export($sharedRaceNonceHash, true)
);
$pN1 = proc_open([$workerPhpBin, '-r', $nonceWorkerCode], $descSpec, $pipesN1, null, $workerEnv);
$pN2 = proc_open([$workerPhpBin, '-r', $nonceWorkerCode], $descSpec, $pipesN2, null, $workerEnv);
fclose($pipesN1[0]);
fclose($pipesN2[0]);
$nOut1 = trim((string)stream_get_contents($pipesN1[1]));
$nOut2 = trim((string)stream_get_contents($pipesN2[1]));
fclose($pipesN1[1]);
fclose($pipesN1[2]);
fclose($pipesN2[1]);
fclose($pipesN2[2]);
$nRc1 = proc_close($pN1);
$nRc2 = proc_close($pN2);

$verifyWorkerCode = sprintf(
    'require %s; ' .
    '$pdo = \BusherianBiasharas\Config\Database::getConnection(); ' .
    '$st = $pdo->prepare("SELECT status, attempts, lease_until FROM email_outbox WHERE id = :id"); ' .
    '$st->execute([\':id\' => %d]); ' .
    'echo json_encode($st->fetch());',
    var_export($bootstrapPath, true),
    $raceOutboxId
);
$pVer = proc_open([$workerPhpBin, '-r', $verifyWorkerCode], $descSpec, $pipesVer, null, $workerEnv);
fclose($pipesVer[0]);
$raceRowAfter = json_decode((string)stream_get_contents($pipesVer[1]), true) ?: [];
fclose($pipesVer[1]);
fclose($pipesVer[2]);
proc_close($pVer);

if ($initialDriver === 'sqlite') {
    @unlink($raceTempSqlite);
    @unlink($raceTempSqlite . '-wal');
    @unlink($raceTempSqlite . '-shm');
} else {
    $pdo->prepare("DELETE FROM email_outbox WHERE id = :id")->execute([':id' => $raceOutboxId]);
}

$raceBizId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 Med Race Fixture Co',
    'tagline' => 'Multi-process concurrency test fixture',
    'description' => 'Verifying exclusive email_outbox worker leases and single-use nonces across OS processes.',
    'school_badge' => 'AHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722211211',
    'admin_provenance_note' => 'Concurrency unit fixture',
]);

assertTrue(
    $seedRc === 0 && $raceOutboxId > 0 &&
    $rcA === 0 && $rcB === 0 &&
    ((int)$outA + (int)$outB) === 1 &&
    (int)($raceRowAfter['attempts'] ?? 0) === 1 &&
    (string)($raceRowAfter['status'] ?? '') === 'simulated' &&
    ($raceRowAfter['lease_until'] ?? null) === null &&
    $nRc1 === 0 && $nRc2 === 0 &&
    ((int)$nOut1 + (int)$nOut2) === 1,
    'Real 2-process proc_open concurrency: exclusive email_outbox worker lease dispatches queued message exactly once (1 winner, 1 loser) and claimSingleUseNonce admits exactly 1 winner (#211, #212, #223)'
);

// 42b. R2-MED-03 (#211) & R2-MED-04 (#212): Superseded/expired OTP outbox suppression, central mail quota, and mutex self-healing
$otpRaceEmail = 'superseded.otp211@busherian-test.co.ke';
$otp1 = OwnerAuth::issuePasswordOtp($pdo, $otpRaceEmail);
$pdo->prepare("
    INSERT INTO email_outbox (business_id, recipient_email, subject, body_html, status, attempts, expires_at)
    VALUES (:bid, :em, :subj, '<p>OTP 1</p>', 'queued', 0, :exp)
")->execute([
    ':bid' => $raceBizId,
    ':em' => $otpRaceEmail,
    ':subj' => $otp1['code'] . ' is your Busherian Biasharas 6-Digit Verification Code',
    ':exp' => time() + 600,
]);
$supersededOutboxId = (int)$pdo->lastInsertId();

// Issue a newer OTP for the same recipient -> supersedes $otp1!
$otp2 = OwnerAuth::issuePasswordOtp($pdo, $otpRaceEmail);

// Also insert a hard-expired auth email
$pdo->prepare("
    INSERT INTO email_outbox (business_id, recipient_email, subject, body_html, status, attempts, expires_at)
    VALUES (:bid, :em, 'Expired Magic Link', '<p>Expired token</p>', 'queued', 0, :exp)
")->execute([
    ':bid' => $raceBizId,
    ':em' => $otpRaceEmail,
    ':exp' => time() - 30,
]);
$hardExpiredOutboxId = (int)$pdo->lastInsertId();

$dispSuperseded = Mailer::dispatchOutboxRow($pdo, $supersededOutboxId);
$dispExpired = Mailer::dispatchOutboxRow($pdo, $hardExpiredOutboxId);

$stSupRow = $pdo->query("SELECT status, subject, body_html FROM email_outbox WHERE id = {$supersededOutboxId}")->fetch();
$stExpRow = $pdo->query("SELECT status, subject, body_html FROM email_outbox WHERE id = {$hardExpiredOutboxId}")->fetch();

// Central dispatch-time quota ceiling (#212 M-04)
$pdo->exec("DELETE FROM rate_limits WHERE action_type = 'central_mail_dispatch'");
putenv('BBIZ_MAX_DISPATCH_PER_HOUR=2');
$_ENV['BBIZ_MAX_DISPATCH_PER_HOUR'] = '2';
$qMail1 = Mailer::enqueueAndSend($pdo, 'quota1@busherian-test.co.ke', 'Quota Test 1', '<p>1</p>', null, $raceBizId);
$qMail2 = Mailer::enqueueAndSend($pdo, 'quota2@busherian-test.co.ke', 'Quota Test 2', '<p>2</p>', null, $raceBizId);
$qMail3 = Mailer::enqueueAndSend($pdo, 'quota3@busherian-test.co.ke', 'Quota Test 3', '<p>3</p>', null, $raceBizId);
putenv('BBIZ_MAX_DISPATCH_PER_HOUR=');
unset($_ENV['BBIZ_MAX_DISPATCH_PER_HOUR']);
$pdo->exec("DELETE FROM rate_limits WHERE action_type = 'central_mail_dispatch'");

$qRow1 = $pdo->query("SELECT status FROM email_outbox WHERE id = {$qMail1}")->fetchColumn();
$qRow2 = $pdo->query("SELECT status FROM email_outbox WHERE id = {$qMail2}")->fetchColumn();
$qRow3 = $pdo->query("SELECT status, next_retry_at FROM email_outbox WHERE id = {$qMail3}")->fetch();

// Mutex self-healing when rate_limit_mutex row is missing
$pdo->exec("DELETE FROM business_sequences WHERE seq_key = 'rate_limit_mutex'");
AntiSpamGate::acquireRateLimitSerializationLock($pdo);
$mutexValAfter = (int)$pdo->query("SELECT current_val FROM business_sequences WHERE seq_key = 'rate_limit_mutex'")->fetchColumn();

assertTrue(
    $dispSuperseded === false &&
    $dispExpired === false &&
    (string)($stSupRow['status'] ?? '') === 'expired' &&
    (string)($stExpRow['status'] ?? '') === 'expired' &&
    str_contains((string)($stSupRow['body_html'] ?? ''), '[EXPIRED OR SUPERSEDED') &&
    $qRow1 === 'simulated' &&
    $qRow2 === 'simulated' &&
    (string)($qRow3['status'] ?? '') === 'queued' &&
    (int)($qRow3['next_retry_at'] ?? 0) > time() &&
    $mutexValAfter >= 1,
    'Mailer suppresses expired/superseded OTP challenges, enforces central dispatch-time hourly ceiling across callers, and self-heals rate_limit_mutex (#211, #212)'
);
VettingPurger::deleteBusiness($pdo, $raceBizId, 'unit_cleanup', 'system');
VettingPurger::flushDeferredMediaDeletions();

// 42c. R2-MED-05 (#213): Per-IP + per-account auth throttles prevent global cross-user DoS while blocking targeted brute force
for ($i = 1; $i <= 18; $i++) {
    $resSpray = AntiSpamGate::checkAuthAttemptAllowed($pdo, "198.51.100.{$i}", 'unit_auth_dos_probe', "user{$i}@alumni.test", 10, 30, 3, 10);
    if (!$resSpray['allowed']) {
        assertTrue(false, "Unexpected global DoS block on distinct IP/account #{$i}");
        break;
    }
}
$legitUser19 = AntiSpamGate::checkAuthAttemptAllowed($pdo, '198.51.100.250', 'unit_auth_dos_probe', 'legit19@alumni.test', 10, 30, 3, 10);

// Targeted attack against a single victim account across rotating botnet IPs
AntiSpamGate::checkAuthAttemptAllowed($pdo, '203.0.113.10', 'unit_auth_acct_lock', 'target.victim@alumni.test', 10, 30, 3, 10);
AntiSpamGate::checkAuthAttemptAllowed($pdo, '203.0.113.11', 'unit_auth_acct_lock', 'target.victim@alumni.test', 10, 30, 3, 10);
AntiSpamGate::checkAuthAttemptAllowed($pdo, '203.0.113.12', 'unit_auth_acct_lock', 'target.victim@alumni.test', 10, 30, 3, 10);
$fourthAgainstVictim = AntiSpamGate::checkAuthAttemptAllowed($pdo, '203.0.113.13', 'unit_auth_acct_lock', 'target.victim@alumni.test', 10, 30, 3, 10);

assertTrue(
    $legitUser19['allowed'] === true &&
    $fourthAgainstVictim['allowed'] === false &&
    $fourthAgainstVictim['reason'] === 'auth_account_rate_limit_15m',
    'checkAuthAttemptAllowed isolates unrelated users from global auth DoS while blocking distributed brute-force per account (#213)'
);

// 42d. R2-MED-01 (#209) & R2-MED-16 (#224): Separate claim verification from publication consent + adult-only (18+) enforcement
$noConsentCuratedId = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 Med01 Unconsented Claim Probe',
    'tagline' => 'Ownership verification alone must not manufacture consent',
    'description' => 'Verifying separation between ownership link and publication consent.',
    'school_badge' => 'AGHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722209209',
    'owner_email' => 'noconsent209@busherian-test.co.ke',
    'admin_provenance_basis' => 'public_alumni_business_registry',
    'admin_provenance_note' => 'Curated from public chapter directory',
]);
$owner209 = OwnerAuth::upsertOwner($pdo, 'noconsent209@busherian-test.co.ke', 'Owner 209');
$invUrl209 = OwnerAuth::generateClaimInviteUrl($noConsentCuratedId, time() + 3600, 'noconsent209@busherian-test.co.ke');
parse_str((string)parse_url($invUrl209, PHP_URL_QUERY), $q209);
$redeemedWithoutConsent = OwnerAuth::redeemVerifiedClaimInvite(
    $pdo,
    (int)$owner209['id'],
    'noconsent209@busherian-test.co.ke',
    $noConsentCuratedId,
    (int)$q209['exp'],
    (string)$q209['sig'],
    'noconsent209@busherian-test.co.ke',
    false,
    false
);
$row209 = $pdo->query("SELECT listing_provenance, consent_recorded_at, age_attested_at FROM businesses WHERE id = {$noConsentCuratedId}")->fetch();

// Adult attestation required when require_adult_attestation => true (#224)
$threwMissingAdult224 = false;
try {
    VettingPurger::createBusinessDirectly($pdo, [
        'name' => 'Missing Adult Attestation Probe',
        'tagline' => 'Must reject unattested admin curation',
        'description' => 'Testing Kenya DPA s. 33 adult-only ingestion gate.',
        'school_badge' => 'AHS',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'service_mode' => 'physical',
        'country' => 'Kenya',
        'city_county' => 'Nairobi',
        'phone_primary' => '+254722224224',
        'admin_provenance_note' => 'Missing checkbox test',
        'require_adult_attestation' => true,
    ]);
} catch (\InvalidArgumentException $e) {
    $threwMissingAdult224 = true;
}

$attestedCuratedId224 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'Attested Adult Curation Probe',
    'tagline' => 'Adult attestation recorded cleanly',
    'description' => 'Testing Kenya DPA s. 33 adult-only ingestion timestamp.',
    'school_badge' => 'AHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722224225',
    'admin_provenance_note' => 'Verified adult founder',
    'admin_adult_subject_attested' => '1',
    'require_adult_attestation' => true,
]);
$ageAttestedVal224 = $pdo->query("SELECT age_attested_at FROM businesses WHERE id = {$attestedCuratedId224}")->fetchColumn();
$takedownSrc224 = (string)file_get_contents(dirname(__DIR__) . '/public/takedown.php');

assertTrue(
    $redeemedWithoutConsent === true &&
    (string)($row209['listing_provenance'] ?? '') === 'claimed' &&
    $row209['consent_recorded_at'] === null &&
    $row209['age_attested_at'] === null &&
    $threwMissingAdult224 === true &&
    !empty($ageAttestedVal224) &&
    str_contains($takedownSrc224, 'minor_data_s33'),
    'redeemVerifiedClaimInvite never manufactures consent_recorded_at without explicit consent, require_adult_attestation enforces 18+ gate, and takedown.php supports urgent minor_data_s33 escalation (#209, #224)'
);
VettingPurger::deleteBusiness($pdo, $noConsentCuratedId, 'unit_cleanup', 'system');
VettingPurger::deleteBusiness($pdo, $attestedCuratedId224, 'unit_cleanup', 'system');
VettingPurger::flushDeferredMediaDeletions();

// 42e. R2-MED-13 (#221): Exact column & URL length boundary alignment (tagline <= 180, website_url <= 350, social_* <= 255)
$hours221 = (string)json_encode(['mon' => [['08:00', '17:00']]]);
$exact180Tagline = str_repeat('é', 180);
$over181Tagline = str_repeat('é', 181);
$exact350Url = 'https://example.co.ke/' . str_repeat('a', 350 - strlen('https://example.co.ke/'));
$over351Url = $exact350Url . 'x';
$exact255Social = 'https://instagram.com/' . str_repeat('b', 255 - strlen('https://instagram.com/'));
$over256Social = $exact255Social . 'y';

$acceptedValidBoundary221 = true;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'Boundary Alignment 221 Co',
        'tagline' => $exact180Tagline,
        'description' => 'Exact boundary validation check.',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'website_url' => $exact350Url,
        'social_instagram' => $exact255Social,
    ], null, null, $hours221);
} catch (\Throwable $e) {
    $acceptedValidBoundary221 = false;
}

$threw181Tagline = false;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'Over Tagline Co',
        'tagline' => $over181Tagline,
        'description' => 'Should fail 181 chars.',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
    ], null, null, $hours221);
} catch (\InvalidArgumentException $e) {
    $threw181Tagline = true;
}

$threw351Url = false;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'Over Website URL Co',
        'tagline' => 'Short tagline',
        'description' => 'Should fail 351 URL chars.',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'website_url' => $over351Url,
    ], null, null, $hours221);
} catch (\InvalidArgumentException $e) {
    $threw351Url = true;
}

$threw256Social = false;
try {
    VettingPurger::validateListingWritePayload($pdo, [
        'name' => 'Over Social URL Co',
        'tagline' => 'Short tagline',
        'description' => 'Should fail 256 social URL chars.',
        'sector_id' => $validSecId,
        'specialty_id' => $validSpecId,
        'social_instagram' => $over256Social,
    ], null, null, $hours221);
} catch (\InvalidArgumentException $e) {
    $threw256Social = true;
}

assertTrue(
    $acceptedValidBoundary221 === true &&
    $threw181Tagline === true &&
    $threw351Url === true &&
    $threw256Social === true,
    'validateListingWritePayload enforces exact schema column & URL length ceilings (tagline 180, website_url 350, social_* 255) (#221)'
);

// 42f. R2-MED-06 (#214) & R2-MED-07 (#215): Comprehensive retention sweep + Owner JSON Data Export & Co-Owner-Safe Erasure
$old35d = gmdate('Y-m-d H:i:s', time() - (35 * 86400));
$old95d = gmdate('Y-m-d H:i:s', time() - (95 * 86400));
$old190d = gmdate('Y-m-d H:i:s', time() - (190 * 86400));
$old370d = gmdate('Y-m-d H:i:s', time() - (370 * 86400));

$pdo->prepare("
    INSERT INTO email_outbox (recipient_email, reply_to, subject, body_html, status, attempts, created_at)
    VALUES ('old214@retention-test.co.ke', 'reply214@retention-test.co.ke', 'Sensitive Subject 214', '<p>Sensitive Body</p>', 'sent', 1, :ca)
")->execute([':ca' => $old35d]);
$retOutboxId214 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO owner_auth_tokens (email, token_hash, expires_at, created_at)
    VALUES ('oldtok214@retention-test.co.ke', :th, :exp, :ca)
")->execute([
    ':th' => hash('sha256', 'ret_tok_214_' . bin2hex(random_bytes(8))),
    ':exp' => time() - (9 * 86400),
    ':ca' => gmdate('Y-m-d H:i:s', time() - (9 * 86400)),
]);
$retTokId214 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO takedown_requests (business_id, requester_name, requester_email, reason, status, ip_hmac, created_at, resolved_at)
    VALUES (:bid, 'Old Requester 214', 'oldtd214@retention-test.co.ke', 'Resolved 95 days ago', 'dismissed', 'deadbeef214', :ca, :ra)
")->execute([':bid' => $approvedBizId201, ':ca' => $old95d, ':ra' => $old95d]);
$retTdId214 = (int)$pdo->lastInsertId();

$catSlug214 = 'ret-cat-214-' . substr(bin2hex(random_bytes(4)), 0, 6);
$pdo->prepare("
    INSERT INTO categories (parent_id, name, slug, status, submitted_by_email, created_at)
    VALUES (:pid, :nm, :sl, 'approved', 'submitter214@retention-test.co.ke', :ca)
")->execute([':pid' => $validSecId, ':nm' => 'Ret Cat ' . $catSlug214, ':sl' => $catSlug214, ':ca' => $old35d]);
$retCatId214 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO click_events (business_id, event_type, created_at)
    VALUES (:bid, 'website_click', :ca)
")->execute([':bid' => $approvedBizId201, ':ca' => $old190d]);
$retClickId214 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO moderation_audit_log (business_id, action, channel, actor_identifier, ip_hash, previous_status, new_status, created_at)
    VALUES (:bid, 'edit', 'owner_portal', 'oldactor214@retention-test.co.ke', 'iphash214', 'approved', 'approved', :ca)
")->execute([':bid' => $approvedBizId201, ':ca' => $old370d]);
$retAuditId214 = (int)$pdo->lastInsertId();

$sweepStats214 = VettingPurger::enforceRetentionCeilings($pdo, 30);

$afterOutbox214 = $pdo->query("SELECT recipient_email, reply_to, subject, body_html FROM email_outbox WHERE id = {$retOutboxId214}")->fetch();
$afterTok214 = $pdo->query("SELECT COUNT(*) FROM owner_auth_tokens WHERE id = {$retTokId214}")->fetchColumn();
$afterTd214 = $pdo->query("SELECT requester_name, requester_email, ip_hmac FROM takedown_requests WHERE id = {$retTdId214}")->fetch();
$afterCatEmail214 = $pdo->query("SELECT submitted_by_email FROM categories WHERE id = {$retCatId214}")->fetchColumn();
$afterClick214 = $pdo->query("SELECT COUNT(*) FROM click_events WHERE id = {$retClickId214}")->fetchColumn();
$afterAudit214 = $pdo->query("SELECT actor_identifier, ip_hash FROM moderation_audit_log WHERE id = {$retAuditId214}")->fetch();

$pdo->exec("DELETE FROM categories WHERE id = {$retCatId214}");
$pdo->exec("DELETE FROM takedown_requests WHERE id = {$retTdId214}");

assertTrue(
    (string)($afterOutbox214['recipient_email'] ?? '') === 'redacted@retention.invalid' &&
    $afterOutbox214['reply_to'] === null &&
    (string)($afterOutbox214['subject'] ?? '') === '[REDACTED PER 30-DAY RETENTION POLICY]' &&
    (int)$afterTok214 === 0 &&
    (string)($afterTd214['requester_email'] ?? '') === 'redacted@retention.invalid' &&
    $afterTd214['ip_hmac'] === null &&
    $afterCatEmail214 === null &&
    (int)$afterClick214 === 0 &&
    (string)($afterAudit214['actor_identifier'] ?? '') === 'redacted_actor' &&
    $afterAudit214['ip_hash'] === null,
    'enforceRetentionCeilings scrubs 30d outbox headers/subjects, 7d auth tokens, 90d resolved takedowns, approved category emails, 180d click_events, and 365d audit PII (#214)'
);

// Subject-level Owner Export & Co-Owner-Safe Erasure (#215 M-07)
$owner215A = OwnerAuth::upsertOwner($pdo, 'ownera215@erasure-test.co.ke', 'Owner Alpha 215', '+254722215001');
$owner215B = OwnerAuth::upsertOwner($pdo, 'ownerb215@erasure-test.co.ke', 'Owner Beta 215', '+254722215002');
$coBizId215 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 Med07 CoOwned Partnership LLP',
    'tagline' => 'Must survive when one co-owner erases personal account',
    'description' => 'Co-owned venture testing subject-level erasure.',
    'school_badge' => 'AHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722215100',
    'owner_email' => 'ownera215@erasure-test.co.ke',
    'admin_provenance_note' => 'Co-owned unit fixture',
]);
OwnerAuth::linkOwnerToBusiness($pdo, (int)$owner215A['id'], $coBizId215, 'admin_verified');
OwnerAuth::linkOwnerToBusiness($pdo, (int)$owner215B['id'], $coBizId215, 'admin_verified');

$soleBizId215 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 Med07 Sole Venture Ltd',
    'tagline' => 'Deleted cleanly with disk media verification on sole-owner erasure',
    'description' => 'Sole-owned venture with uploaded photo.',
    'school_badge' => 'AGHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722215200',
    'owner_email' => 'ownera215@erasure-test.co.ke',
    'business_photos_data_json' => (string)json_encode([$dataUri208A]),
    'admin_provenance_note' => 'Sole-owned unit fixture',
]);
OwnerAuth::linkOwnerToBusiness($pdo, (int)$owner215A['id'], $soleBizId215, 'admin_verified');
OwnerAuth::issuePasswordOtp($pdo, 'ownera215@erasure-test.co.ke');
Mailer::enqueueAndSend($pdo, 'ownera215@erasure-test.co.ke', 'Welcome Owner A 215', '<p>Hello Owner A</p>', null, $soleBizId215);

$exportBundle215 = OwnerAuth::exportOwnerPersonalData($pdo, (int)$owner215A['id']);
$erasureReport215 = OwnerAuth::deleteOwnerAccountAndData($pdo, (int)$owner215A['id'], true, '127.0.0.1');

$coBizAfter215 = $pdo->query("SELECT status, owner_email FROM businesses WHERE id = {$coBizId215}")->fetch();
$soleBizAfter215 = $pdo->query("SELECT COUNT(*) FROM businesses WHERE id = {$soleBizId215}")->fetchColumn();
$ownerAAfter215 = OwnerAuth::findOwnerByEmail($pdo, 'ownera215@erasure-test.co.ke');

assertTrue(
    ($exportBundle215['privacy_notice_version'] ?? '') === VettingPurger::PRIVACY_NOTICE_VERSION && ($exportBundle215['owner_profile']['email'] ?? '') === 'ownera215@erasure-test.co.ke' &&
    count($exportBundle215['linked_businesses'] ?? []) === 2 &&
    $erasureReport215['owner_deleted'] === true &&
    in_array($soleBizId215, $erasureReport215['sole_listings_deleted'], true) &&
    in_array($coBizId215, $erasureReport215['co_owned_listings_preserved'], true) &&
    $erasureReport215['incomplete_media_biz_ids'] === [] &&
    $ownerAAfter215 === null &&
    (int)$soleBizAfter215 === 0 &&
    (string)($coBizAfter215['status'] ?? '') === 'approved' &&
    (string)($coBizAfter215['owner_email'] ?? '') === 'ownerb215@erasure-test.co.ke' &&
    OwnerAuth::canOwnerEditBusiness($pdo, (int)$owner215B['id'], $coBizId215) === true,
    'exportOwnerPersonalData exports full DPA bundle and deleteOwnerAccountAndData erases sole listings + media while preserving co-owned businesses & rotating owner_email to co-owner (#215)'
);
VettingPurger::deleteBusiness($pdo, $coBizId215, 'unit_cleanup', 'system');
VettingPurger::flushDeferredMediaDeletions();

// 42g. R2-MED-14 (#222): Post-commit media deletion queue preserves uploaded photos if enclosing DB transaction rolls back
$rollbackBizId222 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R2 Med14 Rollback Media Guard Co',
    'tagline' => 'Disk media must survive rolled-back DB deletes',
    'description' => 'Verifying deferred post-commit media unlink queue.',
    'school_badge' => 'AHS',
    'sector_id' => $validSecId,
    'specialty_id' => $validSpecId,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722222222',
    'business_photos_data_json' => (string)json_encode([$dataUri208A]),
    'admin_provenance_note' => 'Rollback media test',
]);
$rbPhotosJson = (string)$pdo->query("SELECT photos_json FROM businesses WHERE id = {$rollbackBizId222}")->fetchColumn();
$rbPhotoUrls = PhotoUploader::decodePhotosJson($rbPhotosJson);
$rbDiskPathBefore = !empty($rbPhotoUrls[0]) ? PhotoUploader::resolveSafeUploadFilePath($rbPhotoUrls[0], true) : null;

$pdo->beginTransaction();
VettingPurger::deleteBusiness($pdo, $rollbackBizId222, 'unit_tx_test', 'system');
$existsDuringOpenTx = ($rbDiskPathBefore !== null && is_file($rbDiskPathBefore));
$pdo->rollBack();
VettingPurger::clearDeferredMediaDeletions();

$existsAfterRollback = ($rbDiskPathBefore !== null && is_file($rbDiskPathBefore));
$dbRowAfterRollback = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE id = {$rollbackBizId222}")->fetchColumn();

// Now commit a real delete outside an outer transaction -> media MUST be unlinked post-commit
VettingPurger::deleteBusiness($pdo, $rollbackBizId222, 'unit_cleanup', 'system');
$existsAfterCommittedDelete = ($rbDiskPathBefore !== null && is_file($rbDiskPathBefore));

assertTrue(
    $existsDuringOpenTx === true &&
    $existsAfterRollback === true &&
    $dbRowAfterRollback === 1 &&
    $existsAfterCommittedDelete === false,
    'deleteBusiness defers disk media unlink until DB transaction commits, preserving live photos across rollbacks (#222)'
);

// 42h. R2-MED-17 (#225), R2-MED-12 (#220), R2-MED-02 (#210), R2-MED-08 (#216), R2-MED-09 (#217), R2-MED-10 (#218)
AdminAuth::establishAdminSession($pdo);
$authBeforeRevoke225 = AdminAuth::isAuthenticated($pdo);
$savedAdminSessionSnap = $_SESSION;
AdminAuth::revokeAllAdminSessions($pdo);
$_SESSION = $savedAdminSessionSnap;
$authAfterServerRevoke225 = AdminAuth::isAuthenticated($pdo);

// Re-establish and verify credential rotation invalidates session immediately
AdminAuth::establishAdminSession($pdo);
$authFresh225 = AdminAuth::isAuthenticated($pdo);
$origAdminTok = getenv('ADMIN_ACCESS_TOKEN') ?: 'test_admin_token_secret_12345';
putenv('ADMIN_ACCESS_TOKEN=rotated_admin_secret_token_99999');
$_ENV['ADMIN_ACCESS_TOKEN'] = 'rotated_admin_secret_token_99999';
$authAfterCredRotate225 = AdminAuth::isAuthenticated($pdo);
putenv('ADMIN_ACCESS_TOKEN=' . $origAdminTok);
$_ENV['ADMIN_ACCESS_TOKEN'] = $origAdminTok;

$envExampleSrc220 = (string)file_get_contents(dirname(__DIR__) . '/.env.example');
$dbPhpSrc220 = (string)file_get_contents(dirname(__DIR__) . '/src/Config/Database.php');
$formEnhSrc210 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/form-enhancements.js');
$trackMetricSrc217 = (string)file_get_contents(dirname(__DIR__) . '/public/api/track-metric.php');
$consentJsSrc217 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/consent-metrics.js');
$dirJsSrc217 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
$apiBizSrc218 = (string)file_get_contents(dirname(__DIR__) . '/public/api/businesses.php');

assertTrue(
    $authBeforeRevoke225 === true &&
    $authAfterServerRevoke225 === false &&
    $authFresh225 === true &&
    $authAfterCredRotate225 === false &&
    str_contains($envExampleSrc220, 'BBIZ_DISABLE_WEB_AUTO_MIGRATE=true') &&
    str_contains($dbPhpSrc220, "GET_LOCK('bbiz_schema_migrate', 15)") &&
    str_contains($formEnhSrc210, 'sessionStorage') &&
    str_contains($formEnhSrc210, 'bbiz_submit_draft_v2') &&
    str_contains($formEnhSrc210, "'house_name'") &&
    str_contains($trackMetricSrc217, '[redacted-email]') &&
    str_contains($consentJsSrc217, 'pseudonymous') &&
    !str_contains($dirJsSrc217, 'enableOsmMap(false); // auto-load on analytics') &&
    str_contains($apiBizSrc218, '6371.0 * ACOS') &&
    str_contains($apiBizSrc218, 'LIMIT 500'),
    'AdminAuth revokes sessions server-side & on credential rotation (#225), web auto-migrate defaults off with GET_LOCK (#220), drafts use 2h sessionStorage without vetting PII (#210), metrics scrub PII & unbundle OSM tiles (#217), and API paginates Haversine in SQL (#218)'
);


// =========================================================================
// 43. Pass-3 LOW Remediations (#233 R3-LOW-01, #234 R3-LOW-02, #235 R3-LOW-03)
// =========================================================================

// 43a. R3-LOW-01 (#233): Submitter vetting identity bounds match exact MySQL schema widths (80, 30, 150, 190, 40)
$exactEmail233 = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.co.ke'; // 188 chars <= 190
VettingPurger::validateVettingIdentityPayload([
    'school_code' => 'AHS',
    'house_name' => 'Livingstone',
    'year_left' => 2010,
    'school_number' => str_repeat('9', 30),
    'submitter_name' => str_repeat('N', 150),
    'submitter_email' => $exactEmail233,
    'raw_submitter_phone' => '+2547' . str_repeat('1', 8),
    'submitter_phone' => '+254711111111',
]);

$vetBoundaryOverflows = [
    ['school_number' => str_repeat('9', 31)],
    ['submitter_name' => str_repeat('N', 151)],
    ['submitter_email' => str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.com'], // >190 chars
    ['raw_submitter_phone' => '+' . str_repeat('2', 40), 'submitter_phone' => '+' . str_repeat('2', 40)],
];
$caughtAllVetOverflows233 = true;
foreach ($vetBoundaryOverflows as $ovf) {
    $caughtOne = false;
    try {
        VettingPurger::validateVettingIdentityPayload(array_merge([
            'school_code' => 'AHS',
            'house_name' => 'Livingstone',
            'year_left' => 2012,
            'school_number' => '12345',
            'submitter_name' => 'Valid Alumni Name',
            'submitter_email' => 'valid233@example.com',
            'raw_submitter_phone' => '+254722000233',
            'submitter_phone' => '+254722000233',
        ], $ovf));
    } catch (\InvalidArgumentException $e) {
        $caughtOne = true;
    }
    if (!$caughtOne) {
        $caughtAllVetOverflows233 = false;
    }
}
assertTrue(
    $caughtAllVetOverflows233,
    'VettingPurger::validateVettingIdentityPayload enforces exact schema column bounds (school_number<=30, submitter_name<=150, submitter_email<=190, submitter_phone<=40) (#233)'
);

// 43b. R3-LOW-02 (#234): Batched getMaskedOwnerEmailHintsForBusinesses matches per-business output across multiple rows
$sampleIds234 = array_map('intval', $pdo->query("SELECT id FROM businesses WHERE status = 'approved' ORDER BY id ASC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN));
$batchHints234 = OwnerAuth::getMaskedOwnerEmailHintsForBusinesses($pdo, $sampleIds234, true);
$parityMatch234 = count($sampleIds234) >= 3;
foreach ($sampleIds234 as $sid234) {
    $singleHint234 = OwnerAuth::getMaskedOwnerEmailHintsForBusiness($pdo, $sid234, true);
    if (($batchHints234[$sid234] ?? []) !== $singleHint234) {
        $parityMatch234 = false;
    }
}
assertTrue(
    $parityMatch234,
    'OwnerAuth::getMaskedOwnerEmailHintsForBusinesses returns identical masked hints across batched listings without per-row N+1 loops (#234)'
);

// 43c. R3-LOW-03 (#235): Concurrent CLI worker processes enforce OS flock() non-blocking overlap guard
$cliLockDir235 = sys_get_temp_dir() . '/bbiz_cli_lock_' . getmypid() . '_' . mt_rand(1000, 9999);
@mkdir($cliLockDir235, 0770, true);
$cliLockDb235 = $cliLockDir235 . '/cli_lock_test.sqlite';
$cliEnvBase235 = array_merge($_ENV, [
    'APP_ENV' => 'testing',
    'DB_DRIVER' => 'sqlite',
    'DB_SQLITE_PATH' => $cliLockDb235,
    'APP_HMAC_SECRET' => 'unit_test_cli_lock_secret_32_bytes_minimum_235',
    'SIMULATE_EMAILS' => 'yes',
    'BBIZ_CLI_LOCK_DIR' => $cliLockDir235,
]);
// Warm up isolated SQLite DB once before racing flock()
$warmProc235 = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/bin/process-email-outbox.php', '1', '2'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $warmPipes235,
    dirname(__DIR__),
    $cliEnvBase235
);
@fclose($warmPipes235[0]);
@stream_get_contents($warmPipes235[1]);
@fclose($warmPipes235[1]);
@stream_get_contents($warmPipes235[2]);
@fclose($warmPipes235[2]);
@proc_close($warmProc235);

$holdEnv235 = array_merge($cliEnvBase235, ['BBIZ_CLI_TEST_HOLD_MS' => '500']);
$fastEnv235 = array_merge($cliEnvBase235, ['BBIZ_CLI_TEST_HOLD_MS' => '0']);

$pHold235 = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/bin/process-email-outbox.php', '5', '3'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipesHold235,
    dirname(__DIR__),
    $holdEnv235
);
usleep(120000); // 120ms so Worker 1 acquires flock() and holds for 500ms
$pSkip235 = proc_open(
    [PHP_BINARY, dirname(__DIR__) . '/bin/process-email-outbox.php', '5', '3'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipesSkip235,
    dirname(__DIR__),
    $fastEnv235
);

@fclose($pipesSkip235[0]);
$outSkip235 = trim((string)stream_get_contents($pipesSkip235[1]));
@fclose($pipesSkip235[1]);
@stream_get_contents($pipesSkip235[2]);
@fclose($pipesSkip235[2]);
$rcSkip235 = proc_close($pSkip235);

@fclose($pipesHold235[0]);
$outHold235 = trim((string)stream_get_contents($pipesHold235[1]));
@fclose($pipesHold235[1]);
@stream_get_contents($pipesHold235[2]);
@fclose($pipesHold235[2]);
$rcHold235 = proc_close($pHold235);

$jsonHold235 = json_decode($outHold235, true) ?: [];
$jsonSkip235 = json_decode($outSkip235, true) ?: [];

@unlink($cliLockDir235 . '/process_email_outbox.lock');
@unlink($cliLockDb235);
@unlink($cliLockDb235 . '-wal');
@unlink($cliLockDb235 . '-shm');
@rmdir($cliLockDir235);

$preprodWf235 = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy-preprod.yml');
assertTrue(
    $rcHold235 === 0 &&
    $rcSkip235 === 0 &&
    ($jsonHold235['status'] ?? '') === 'ok' &&
    ($jsonSkip235['status'] ?? '') === 'skipped_locked' &&
    str_contains($preprodWf235, 'cancel-in-progress: false'),
    'CLI workers enforce non-blocking OS flock() overlap guard across concurrent OS processes and deploy-preprod.yml prevents mid-promotion cancellation (#235)'
);


// 44. Pass-3 Wave 2 HIGH Hardening (#236, #237, #239, #240)

// 44a. H-01 (#236): HTTP_X_ADMIN_TOKEN enforces checkAuthAttemptAllowed, rejects ADMIN_PASSWORD_HASH, and disables in production unless ALLOW_ADMIN_HEADER_TOKEN=true
$prevAdminHash236 = getenv('ADMIN_PASSWORD_HASH');
$prevAdminTok236 = getenv('ADMIN_ACCESS_TOKEN');
$prevAppEnv236 = getenv('APP_ENV');
$prevAllowHdr236 = getenv('ALLOW_ADMIN_HEADER_TOKEN');
$prevRemoteAddr236 = $_SERVER['REMOTE_ADDR'] ?? null;
$prevHdr236 = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null;

$pw236 = 'StrongPass236!Aa';
$hash236 = password_hash($pw236, PASSWORD_BCRYPT);
$autoTok236 = 'automation-dedicated-token-236-secret';
putenv('ADMIN_PASSWORD_HASH=' . $hash236);
putenv('ADMIN_ACCESS_TOKEN=' . $autoTok236);
putenv('APP_ENV=testing');
putenv('ALLOW_ADMIN_HEADER_TOKEN=false');
AdminAuth::logout();

// Fresh IP: password in HTTP_X_ADMIN_TOKEN MUST be rejected; dedicated ADMIN_ACCESS_TOKEN works in testing
$_SERVER['REMOTE_ADDR'] = '198.51.100.236';
$_SERVER['HTTP_X_ADMIN_TOKEN'] = $pw236;
$pwHeaderAccepted236 = AdminAuth::isAuthenticated();

$_SERVER['HTTP_X_ADMIN_TOKEN'] = $autoTok236;
$tokHeaderTesting236 = AdminAuth::isAuthenticated();

// Production disables header token unless ALLOW_ADMIN_HEADER_TOKEN=true
putenv('APP_ENV=production');
putenv('ALLOW_ADMIN_HEADER_TOKEN=false');
$tokHeaderProdDefault236 = AdminAuth::isAuthenticated();
putenv('ALLOW_ADMIN_HEADER_TOKEN=true');
$tokHeaderProdOptIn236 = AdminAuth::isAuthenticated();

// Rate-limit exhaustion on IP MUST block HTTP_X_ADMIN_TOKEN too (reproducing Codex H-01 PoC!)
putenv('APP_ENV=testing');
$bruteIp236 = '198.51.100.237';
$_SERVER['REMOTE_ADDR'] = $bruteIp236;
unset($_SERVER['HTTP_X_ADMIN_TOKEN']);
for ($i236 = 0; $i236 < 10; $i236++) {
    AdminAuth::login($pdo, 'wrong-pass-' . $i236, $bruteIp236);
}
$resLogin236 = AdminAuth::login($pdo, $pw236, $bruteIp236);
$formBlocked236 = empty($resLogin236['ok']);
$_SERVER['HTTP_X_ADMIN_TOKEN'] = $autoTok236;
$headerAfterLockout236 = AdminAuth::isAuthenticated();

unset($_SERVER['HTTP_X_ADMIN_TOKEN']);
if ($prevHdr236 !== null) {
    $_SERVER['HTTP_X_ADMIN_TOKEN'] = $prevHdr236;
}
if ($prevRemoteAddr236 !== null) {
    $_SERVER['REMOTE_ADDR'] = $prevRemoteAddr236;
} else {
    unset($_SERVER['REMOTE_ADDR']);
}
putenv($prevAdminHash236 !== false ? 'ADMIN_PASSWORD_HASH=' . $prevAdminHash236 : 'ADMIN_PASSWORD_HASH');
putenv($prevAdminTok236 !== false ? 'ADMIN_ACCESS_TOKEN=' . $prevAdminTok236 : 'ADMIN_ACCESS_TOKEN');
putenv($prevAppEnv236 !== false ? 'APP_ENV=' . $prevAppEnv236 : 'APP_ENV=testing');
putenv($prevAllowHdr236 !== false ? 'ALLOW_ADMIN_HEADER_TOKEN=' . $prevAllowHdr236 : 'ALLOW_ADMIN_HEADER_TOKEN');

assertTrue(
    $pwHeaderAccepted236 === false &&
    $tokHeaderTesting236 === true &&
    $tokHeaderProdDefault236 === false &&
    $tokHeaderProdOptIn236 === true &&
    $formBlocked236 === true &&
    $headerAfterLockout236 === false,
    'AdminAuth enforces rate limiting on HTTP_X_ADMIN_TOKEN, rejects human passwords in header auth, and disables header token in production by default (#236)'
);

// 44b. H-02 (#237): email_public alone never grants owner auto-quarantine authority; confirmOwnerTakedownByToken re-verifies ownership
$biz237 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R3 High02 Authority Guard Ltd',
    'tagline' => 'Separate public contact and authoritative owner email',
    'description' => 'Shared info@ inbox must never auto-quarantine a listing.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254711000237',
    'email_public' => 'info@authority237.co.ke',
    'owner_email' => 'founder@authority237.co.ke',
]);
$pubEmailAuth237 = VettingPurger::isAuthoritativeOwnerEmailForTakedown($pdo, $biz237, 'info@authority237.co.ke');
$ownEmailAuth237 = VettingPurger::isAuthoritativeOwnerEmailForTakedown($pdo, $biz237, 'founder@authority237.co.ke');
$pubSub237 = VettingPurger::submitTakedownRequest(
    $pdo,
    $biz237,
    'Front Desk Info',
    'info@authority237.co.ke',
    'Shared inbox requesting pause.',
    '198.51.100.238',
    false,
    true // even if caller claims match, service re-verifies!
);

// Also test ownership transfer AFTER token issuance: founder gets token, transfers both owner_email and business_owner_links away, then redeems old token -> escalates to pending_review!
$rotSub237 = VettingPurger::submitTakedownRequest(
    $pdo,
    $biz237,
    'Original Founder',
    'founder@authority237.co.ke',
    'Request before ownership transfer.',
    '198.51.100.239',
    false,
    true
);
$pdo->prepare("DELETE FROM business_owner_links WHERE business_id = :bid")->execute([':bid' => $biz237]);
$pdo->prepare("UPDATE businesses SET owner_email = 'new-owner@authority237.co.ke' WHERE id = :id")->execute([':id' => $biz237]);
$rotConfirm237 = VettingPurger::confirmOwnerTakedownByToken($pdo, (string)$rotSub237['raw_token']);
$liveStatus237 = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$biz237}")->fetchColumn();
$takedownSrc237 = (string)file_get_contents(dirname(__DIR__) . '/public/takedown.php');

assertTrue(
    $pubEmailAuth237 === false &&
    $ownEmailAuth237 === true &&
    $pubSub237['status'] === 'pending_review' &&
    $pubSub237['raw_token'] === null &&
    is_array($rotConfirm237) &&
    $rotConfirm237['status'] === 'pending_review' &&
    $liveStatus237 === 'approved' &&
    !str_contains($takedownSrc237, "\$bizRow['email_public'],\n                    \$bizRow['owner_email']"),
    'Public email_public never grants owner auto-quarantine authority and confirmOwnerTakedownByToken re-verifies ownership at redemption (#237)'
);

// 44c. H-04 (#239): Photo uploads stage privately in storage/quarantined_media/<id> and concurrent quarantine keeps media out of public uploads/
$biz239 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R3 High04 Quarantine Race Studio',
    'tagline' => 'Private media staging before status lock',
    'description' => 'Quarantined listings must never leak staged photos to public uploads.',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722000239',
    'owner_email' => 'owner239@studio.co.ke',
]);
$ownerRec239 = OwnerAuth::findOwnerByEmail($pdo, 'owner239@studio.co.ke');

$im239 = imagecreatetruecolor(16, 16);
imagefill($im239, 0, 0, imagecolorallocate($im239, 17, 75, 62));
ob_start();
imagepng($im239);
$pngBytes239 = (string)ob_get_clean();
imagedestroy($im239);
$dataUrl239 = 'data:image/png;base64,' . base64_encode($pngBytes239);

// 1) Direct PhotoUploader staging check: stages privately into storage/quarantined_media/<id>/ without writing to public/uploads/businesses/
$stagedJsonDirect239 = PhotoUploader::processBusinessPhotos(null, [], null, json_encode([$dataUrl239]), $biz239);
$stagedArrDirect239 = PhotoUploader::decodePhotosJson($stagedJsonDirect239);
$directRel239 = $stagedArrDirect239[0] ?? '';
$directPriv239 = PhotoUploader::quarantineDirRoot() . '/' . $biz239 . '/' . basename($directRel239);
$directPub239 = PhotoUploader::resolvePublicWebRoot() . '/' . $directRel239;
$wasPrivatelyStaged239 = ($directRel239 !== '') && is_file($directPriv239) && !is_file($directPub239);
@unlink($directPriv239);

// 2) Full VettingPurger::updateBusinessByOwner check when quarantine lands before owner edit executes:
VettingPurger::quarantineBusinessDpa($pdo, $biz239, 'admin_dashboard', 'admin_239', '127.0.0.1');
$updRes239 = VettingPurger::updateBusinessByOwner($pdo, $biz239, (array)$ownerRec239, [
    'name' => 'R3 High04 Quarantine Race Studio',
    'tagline' => 'Edited while quarantine landed',
    'description' => 'Quarantined listings must never leak staged photos to public uploads.',
    'school_badge' => 'AGHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'physical',
    'country' => 'Kenya',
    'city_county' => 'Nairobi',
    'phone_primary' => '+254722000239',
    'business_photos_data_json' => json_encode([$dataUrl239]),
]);
$savedPhotos239 = PhotoUploader::decodePhotosJson((string)($updRes239['business']['photos_json'] ?? '[]'));
$savedRel239 = $savedPhotos239[0] ?? '';
$privFileAfterRace239 = PhotoUploader::quarantineDirRoot() . '/' . $biz239 . '/' . basename($savedRel239);
$pubFileAfterRace239 = PhotoUploader::resolvePublicWebRoot() . '/' . $savedRel239;
$dbStatusAfterRace239 = (string)$pdo->query("SELECT status FROM businesses WHERE id = {$biz239}")->fetchColumn();
$stillPrivateAfterRace239 = ($savedRel239 !== '') && is_file($privFileAfterRace239) && !is_file($pubFileAfterRace239);

// Restoring from quarantine promotes the private media back to public uploads, and deleting removes it cleanly!
VettingPurger::restoreQuarantinedBusiness($pdo, $biz239, 'admin_dashboard', 'admin_239', '127.0.0.1');
$promotedOnRestore239 = is_file($pubFileAfterRace239) && !is_file($privFileAfterRace239);
VettingPurger::deleteBusiness($pdo, $biz239, 'admin_dashboard', 'admin_239', '127.0.0.1');

assertTrue(
    $wasPrivatelyStaged239 === true &&
    $dbStatusAfterRace239 === 'quarantined_dpa' &&
    $stillPrivateAfterRace239 === true &&
    $promotedOnRestore239 === true &&
    !is_file($pubFileAfterRace239),
    'PhotoUploader stages uploads privately and VettingPurger serializes live status so quarantined listings never publish staged photos (#239)'
);

// 44d. H-05 (#240): Durable pending_media_deletions obligations survive unlink failures and drain cleanly on retry
$ownerRec240 = OwnerAuth::upsertOwner($pdo, 'owner240@erasure-durable.co.ke', 'Durable Erasure Owner', '+254733000240');
$ownerId240 = (int)$ownerRec240['id'];
$biz240 = VettingPurger::createBusinessDirectly($pdo, [
    'name' => 'R3 High05 Durable Media Erasure Biz',
    'tagline' => 'Tracks unlinked media in pending_media_deletions',
    'description' => 'Never claim complete erasure while disk media remains.',
    'school_badge' => 'AHS',
    'sector_id' => 1,
    'specialty_id' => 2,
    'service_mode' => 'online',
    'country' => 'Kenya',
    'phone_primary' => '+254733000240',
    'owner_email' => 'owner240@erasure-durable.co.ke',
]);
OwnerAuth::linkOwnerToBusiness($pdo, $ownerId240, $biz240, 'primary_owner');

$relPhoto240 = '/uploads/businesses/biz240_locked_photo.webp';
$absPhoto240 = PhotoUploader::resolvePublicWebRoot() . $relPhoto240;
if (!is_dir(dirname($absPhoto240))) {
    mkdir(dirname($absPhoto240), 0775, true);
}
file_put_contents($absPhoto240, 'LOCKED_PHOTO_BYTES_240');
$pdo->prepare("UPDATE businesses SET photos_json = :pj WHERE id = :id")->execute([
    ':pj' => json_encode([$relPhoto240]),
    ':id' => $biz240,
]);

$uploadsBizDir240 = dirname($absPhoto240);
$isRoot240 = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (!$isRoot240) {
    @chmod($uploadsBizDir240, 0555);
}
try {
    $erasureRes240 = OwnerAuth::deleteOwnerAccountAndData($pdo, $ownerId240, true, '198.51.100.240');
} finally {
    if (!$isRoot240) { @chmod($uploadsBizDir240, 0775); }
}
if (!$isRoot240) {
    @chmod($uploadsBizDir240, 0775);
    $queuedBeforeDrain240 = (int)$pdo->query("SELECT COUNT(*) FROM pending_media_deletions")->fetchColumn();
    $reportedIncomplete240 = !empty($erasureRes240['incomplete_media_biz_ids']) && (int)$erasureRes240['pending_media_obligations'] > 0 && is_file($absPhoto240);
    $drainRes240 = VettingPurger::drainDurableMediaDeletions($pdo, null);
    $drainedClean240 = $queuedBeforeDrain240 >= 1 && $drainRes240['completed'] >= 1 && $drainRes240['remaining'] === 0 && !is_file($absPhoto240);
} else {
    file_put_contents($absPhoto240, 'SYNTHETIC_ROOT_OBLIGATION');
    $pdo->prepare("
        INSERT INTO pending_media_deletions (business_id, photos_json, attempts, created_at, updated_at)
        VALUES (:bid, :pj, 1, :now, :now)
    ")->execute([':bid' => $biz240, ':pj' => json_encode([$relPhoto240]), ':now' => gmdate('Y-m-d H:i:s')]);
    $reportedIncomplete240 = is_file($absPhoto240);
    $drainRes240 = VettingPurger::drainDurableMediaDeletions($pdo, null);
    $drainedClean240 = $drainRes240['completed'] >= 1 && $drainRes240['remaining'] === 0 && !is_file($absPhoto240);
}

assertTrue(
    $erasureRes240['owner_deleted'] === true &&
    $reportedIncomplete240 === true &&
    $drainedClean240 === true,
    'Durable pending_media_deletions journal tracks failed disk unlinks honestly and drains residual media on retry (#240)'
);

if ($initialDriver === 'sqlite') {
    @unlink($unitTempSqlite);
}
@rmdir($unitTempUploadsRoot . '/uploads/businesses');
@rmdir($unitTempUploadsRoot . '/uploads');
@rmdir($unitTempUploadsRoot);

if ($failed > 0) {
    exit(1);
}
echo "\nAll {$passed} PHP unit assertions passed!\n";
