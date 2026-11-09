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
assertTrue(count($tree) === 14, 'Taxonomy has 14 Level 1 Industry Sectors');
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

@unlink(getenv('DB_SQLITE_PATH'));



if ($failed > 0) {
    exit(1);
}
echo "
All {$passed} PHP unit assertions passed!
";
