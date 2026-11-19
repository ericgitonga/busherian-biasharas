<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\WebsiteEnricher;

$pdo = Database::getConnection();

function testAssert(bool $condition, string $message): void {
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
    echo "PASS: {$message}\n";
}

echo "=== Running Streamlined Directory & Profile Feature Tests ===\n";

// 1. Check Database Schema Columns (#383)
$driver = Database::getActiveDriver();
if ($driver === 'sqlite') {
    $cols = [];
    $stmt = $pdo->query("PRAGMA table_info(businesses)");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[strtolower($row['name'])] = true;
    }
    testAssert(isset($cols['founder_role']), 'businesses table has founder_role column');
    testAssert(isset($cols['founder_name']), 'businesses table has founder_name column');
    testAssert(isset($cols['founder_school']), 'businesses table has founder_school column');
    testAssert(isset($cols['founder_year']), 'businesses table has founder_year column');
}

// 2. Check public/.htaccess for /biz/{slug} rewrite (#376)
$htaccess = (string)file_get_contents(dirname(__DIR__) . '/public/.htaccess');
testAssert(str_contains($htaccess, 'RewriteRule ^biz/([a-zA-Z0-9_-]+)/?$ index.php?biz=$1'), '.htaccess has canonical profile /biz/{slug} rewrite rule');

// 3. Check public/index.php for layout, sidebar, view switcher, detail view, and footer (#370-#381)
$indexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
testAssert(str_contains($indexSrc, 'id="filters-sidebar"'), 'index.php contains #filters-sidebar');
testAssert(str_contains($indexSrc, 'id="sidebar-sector-nav"'), 'index.php contains #sidebar-sector-nav with sector counts');
testAssert(str_contains($indexSrc, 'id="sidebar-school-nav"'), 'index.php contains #sidebar-school-nav with zero logo images');
testAssert(str_contains($indexSrc, 'id="chk-open-now"') && str_contains($indexSrc, 'id="chk-offers-perk"'), 'index.php contains Show Only checkboxes (Open now & Offers an alumni perk)');
testAssert(str_contains($indexSrc, 'id="btn-view-grid"') && str_contains($indexSrc, 'id="btn-view-list"') && str_contains($indexSrc, 'id="btn-view-sector"'), 'index.php contains Top View Switcher Toolbar (Grid, List, By Sector)');
testAssert(str_contains($indexSrc, 'id="view-biz-detail"'), 'index.php contains canonical detail view #view-biz-detail');
testAssert(str_contains($indexSrc, 'class="foot-site"') && str_contains($indexSrc, 'class="foot-cols"'), 'index.php contains 4-column footer');
testAssert(str_contains($indexSrc, 'https://www.alliancehighschool.ac.ke/') && str_contains($indexSrc, 'https://aghs.sc.ke/'), 'index.php contains official school websites for AHS and AGHS');
testAssert(!str_contains($indexSrc, 'ahs.png') && !str_contains($indexSrc, 'aghs.png'), 'index.php enforces zero external school logo images policy');

// 4. Check CSS styling in public/assets/css/app.css (#374)
$cssSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
testAssert(str_contains($cssSrc, 'background: var(--bg-ivory);'), 'app.css styles body in Ivory');
testAssert(str_contains($cssSrc, '.site-header') && str_contains($cssSrc, 'var(--bush-green)') && str_contains($cssSrc, 'var(--bush-crimson)'), 'app.css styles site-header in jungle green / maroon blend');
testAssert(str_contains($cssSrc, '.foot-site') && str_contains($cssSrc, 'var(--bush-green)') && str_contains($cssSrc, 'var(--bush-crimson)'), 'app.css styles 4-column footer in jungle green / maroon blend');
testAssert(str_contains($cssSrc, '.directory-layout-wrapper'), 'app.css contains .directory-layout-wrapper');
testAssert(str_contains($cssSrc, '.filters-sidebar'), 'app.css contains .filters-sidebar');
testAssert(str_contains($cssSrc, '.view-switcher-bar'), 'app.css contains .view-switcher-bar');
testAssert(str_contains($cssSrc, '.founder-attribution'), 'app.css contains .founder-attribution');
testAssert(str_contains($cssSrc, '.biz-detail-view'), 'app.css contains .biz-detail-view');

// 5. Check directory.js for 12-item card rendering & view switcher (#372, #373, #384)
$jsSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
testAssert(str_contains($jsSrc, 'openBizDetail'), 'directory.js defines openBizDetail');
testAssert(str_contains($jsSrc, 'showDirectoryView'), 'directory.js defines showDirectoryView');
testAssert(str_contains($jsSrc, 'switchDirectoryView'), 'directory.js defines switchDirectoryView');
testAssert(str_contains($jsSrc, 'renderListView'), 'directory.js defines renderListView');
testAssert(str_contains($jsSrc, 'renderSectorView'), 'directory.js defines renderSectorView');
testAssert(str_contains($jsSrc, 'founder-attribution'), 'directory.js renders founder-attribution on cards');
testAssert(str_contains($jsSrc, 'btn-action-whatsapp') && str_contains($jsSrc, 'btn-action-call'), 'directory.js renders direct WhatsApp and Call action buttons');

// 6. Check submit.php for smart website prefill & founder role radio buttons (#383, #386, #387)
$submitSrc = (string)file_get_contents(dirname(__DIR__) . '/public/submit.php');
testAssert(str_contains($submitSrc, 'founder_role'), 'submit.php captures founder_role in POST and form');
testAssert(str_contains($submitSrc, 'founder_name') && str_contains($submitSrc, 'founder_school') && str_contains($submitSrc, 'founder_year'), 'submit.php captures founder details');
testAssert(str_contains($submitSrc, 'enrich-box') || str_contains($submitSrc, 'autofill-box'), 'submit.php provides smart website pre-fill');

// 7. Check enrich-website.php & WebsiteEnricher SSRF blocking (#387)
$enrichPhp = (string)file_get_contents(dirname(__DIR__) . '/public/api/enrich-website.php');
testAssert(str_contains($enrichPhp, 'WebsiteEnricher::fetchAndExtract'), 'enrich-website.php uses WebsiteEnricher::fetchAndExtract');
$enricherSrc = (string)file_get_contents(dirname(__DIR__) . '/src/Services/WebsiteEnricher.php');
testAssert(str_contains($enricherSrc, 'validateSafeUrlAndResolveIp'), 'WebsiteEnricher implements validateSafeUrlAndResolveIp SSRF guard');

// 8. Check that public/index.php renders fully without fatal type errors (#371)
ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__) . '/public/index.php';
$renderedIndexHtml = (string)ob_get_clean();
testAssert(str_contains($renderedIndexHtml, 'id="cards-grid"'), 'index.php renders id="cards-grid"');
testAssert(str_contains($renderedIndexHtml, '</html>'), 'index.php renders complete HTML document with closing </html>');
testAssert(str_contains($renderedIndexHtml, 'All locations'), 'index.php renders location dropdown options');

// 9. Check simplified submit.php form fields, advanced toggle, and updated privacy terms (#386)
testAssert(str_contains($submitSrc, "id=\"toggle-advanced-details\""), "submit.php has #toggle-advanced-details checkbox");
testAssert(str_contains($submitSrc, "id=\"advanced-fields-container\""), "submit.php has #advanced-fields-container");
testAssert(str_contains($submitSrc, "name=\"area_town\""), "submit.php captures area_town on simple form");
testAssert(str_contains($submitSrc, "name=\"show_founder_publicly\""), "submit.php has show_founder_publicly checkbox");
testAssert(str_contains($submitSrc, "name=\"assist_requested\""), "submit.php has assist_requested checkbox");
testAssert(str_contains($submitSrc, "School Number is used strictly once for alumni verification and is permanently deleted immediately upon review"), "submit.php terms explicitly state School Number permanent deletion upon review");
testAssert(str_contains($submitSrc, "Name and Year of Departure are retained and publicly displayed to show the world only if I choose founder attribution"), "submit.php terms explicitly state Name and Departure Year retention for founder attribution");
testAssert(str_contains($submitSrc, "id=\"founder-role-choice-wrap\""), "submit.php has #founder-role-choice-wrap container");
testAssert(str_contains($submitSrc, "name=\"simple_founder_role\""), "submit.php has simple_founder_role radio inputs for solo vs co-founder");
testAssert(str_contains($submitSrc, "Founder (Solo)"), "submit.php allows selecting Founder (Solo)");
testAssert(str_contains($submitSrc, "Co-Founder (Joint with others)"), "submit.php allows selecting Co-Founder (Joint with others)");

// 10. Check H-01 Basic Submission Fallbacks (#386, H-01)
testAssert(str_contains($submitSrc, '$description = $tagline;'), 'submit.php defaults empty description to tagline');
testAssert(str_contains($submitSrc, '$rawPrimaryPhone = $rawWhatsappPhone;'), 'submit.php defaults primary phone to whatsapp when collapsed');
testAssert(str_contains($submitSrc, "\$houseName = 'Unspecified';"), 'submit.php defaults omitted house to Unspecified');
testAssert(str_contains($submitSrc, "\$houseName !== 'Unspecified'"), 'submit.php allows Unspecified house in validation');

// Verify VettingPurger accepts Unspecified house and basic payload
\BusherianBiasharas\Services\VettingPurger::validateVettingIdentityPayload([
    'school_code' => 'AHS',
    'house_name' => 'Unspecified',
    'year_left' => 2012,
    'school_number' => '',
    'submitter_name' => 'Kanyi Gitonga',
    'submitter_email' => 'kanyi@example.com',
    'raw_submitter_phone' => '+254711223344',
    'submitter_phone' => '+254711223344',
]);
testAssert(true, 'VettingPurger validates basic vetting identity with Unspecified house and WhatsApp contact');

echo "All Streamlined Directory & Profile feature tests PASSED!\n";
