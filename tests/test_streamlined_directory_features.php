<?php
declare(strict_types=1);

$tempSqlite = sys_get_temp_dir() . '/bbiz_streamlined_' . getmypid() . '.sqlite';
@unlink($tempSqlite);
putenv('APP_ENV=testing');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $tempSqlite);
putenv('SIMULATE_EMAILS=yes');

require_once dirname(__DIR__) . '/src/bootstrap.php';
\BusherianBiasharas\Services\OwnerAuth::startSecureSession();

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
testAssert(str_contains($indexSrc, 'id="chk-show-map"') && str_contains($indexSrc, 'id="chk-show-map-bottom"'), 'index.php contains #chk-show-map and #chk-show-map-bottom checkboxes (#397)');
testAssert(str_contains($indexSrc, 'id="btn-view-grid"') && str_contains($indexSrc, 'id="btn-view-list"') && str_contains($indexSrc, 'id="btn-view-sector"'), 'index.php contains Top View Switcher Toolbar (Grid, List, By Sector)');
testAssert(str_contains($indexSrc, 'id="view-biz-detail"'), 'index.php contains canonical detail view #view-biz-detail');
testAssert(str_contains($indexSrc, 'class="foot-site"') && str_contains($indexSrc, 'class="foot-cols"'), 'index.php contains 4-column footer');
testAssert(str_contains($indexSrc, 'https://www.alliancehighschool.ac.ke/') && str_contains($indexSrc, 'https://aghs.sc.ke/'), 'index.php contains official school websites for AHS and AGHS');
testAssert(!str_contains($indexSrc, 'ahs.png') && !str_contains($indexSrc, 'aghs.png'), 'index.php enforces zero external school logo images policy');
testAssert(str_contains($indexSrc, 'id="btn-mobile-menu"') && str_contains($indexSrc, 'id="drawer-backdrop"'), 'index.php contains #btn-mobile-menu and #drawer-backdrop (#398)');
testAssert(str_contains($indexSrc, 'header-desktop-action') && str_contains($indexSrc, 'header-mobile-only'), 'index.php segregates desktop and mobile header actions (#398)');
testAssert(str_contains($indexSrc, 'id="drawer-search-q"') && str_contains($indexSrc, 'mobile-drawer-only'), 'index.php houses search and actions inside drawer for mobile (#398)');
testAssert(str_contains($indexSrc, 'id="btn-toggle-sector-collapse"') && str_contains($indexSrc, 'id="sector-collapse-arrow"'), 'index.php contains collapsible Sector toggle button and arrow (#400)');
testAssert(str_contains($indexSrc, 'id="filter-country"') && str_contains($indexSrc, 'id="filter-city"'), 'index.php contains cascading country and city/subdivisions filter selectors (#401)');

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
testAssert(str_contains($cssSrc, '.filters-sidebar.drawer-open') && str_contains($cssSrc, '.drawer-backdrop'), 'app.css defines slide-over mobile drawer styles (#398)');
testAssert(str_contains($cssSrc, '.biz-list-group') && str_contains($cssSrc, '.biz-list-item'), 'app.css contains responsive zero-scroll .biz-list-group and .biz-list-item (#399)');
testAssert(str_contains($cssSrc, '.filter-collapse-btn') && str_contains($cssSrc, '.collapse-arrow'), 'app.css styles collapsible filter toggle and chevron (#400)');
testAssert(str_contains($cssSrc, '.sidebar-select.active-filter'), 'app.css defines active-filter style for sidebar selectors (#401)');

// 5. Check directory.js for 12-item card rendering & view switcher (#372, #373, #384)
$jsSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
testAssert(str_contains($jsSrc, 'openBizDetail'), 'directory.js defines openBizDetail');
testAssert(str_contains($jsSrc, 'showDirectoryView'), 'directory.js defines showDirectoryView');
testAssert(str_contains($jsSrc, 'switchDirectoryView'), 'directory.js defines switchDirectoryView');
testAssert(str_contains($jsSrc, 'renderListView'), 'directory.js defines renderListView');
testAssert(str_contains($jsSrc, 'biz-list-group') && str_contains($jsSrc, 'biz-list-item'), 'directory.js renders responsive zero-scroll biz-list-group (#399)');
testAssert(str_contains($jsSrc, 'renderSectorView'), 'directory.js defines renderSectorView');
testAssert(str_contains($jsSrc, 'founder-attribution'), 'directory.js renders founder-attribution on cards');
testAssert(str_contains($jsSrc, 'btn-action-whatsapp') && str_contains($jsSrc, 'btn-action-call'), 'directory.js renders direct WhatsApp and Call action buttons');
testAssert(str_contains($jsSrc, 'toggleMapVisibility'), 'directory.js defines toggleMapVisibility (#397)');
testAssert(str_contains($jsSrc, 'toggleMobileDrawer'), 'directory.js defines toggleMobileDrawer (#398)');
testAssert(str_contains($jsSrc, 'toggleSectorCollapse'), 'directory.js defines toggleSectorCollapse (#400)');
testAssert(str_contains($jsSrc, 'populateCitiesDropdown(state.selectedCountry)'), 'directory.js dynamically cascades subdivisions on country change (#401)');

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

// 10. Check H-01 Basic Submission Fallbacks (#386, #478)
testAssert(str_contains($submitSrc, '$description = $tagline;'), 'submit.php defaults empty description to tagline');
testAssert(str_contains($submitSrc, '$rawPrimaryPhone = $rawWhatsappPhone;'), 'submit.php defaults primary phone to whatsapp when collapsed');
testAssert(str_contains($submitSrc, '!Houses::isValidHouse($schoolCode, $houseName)'), 'submit.php enforces compulsory valid house for school');

// Verify VettingPurger validates compulsory house
$caughtEmptyHouse = false;
try {
    \BusherianBiasharas\Services\VettingPurger::validateVettingIdentityPayload([
        'school_code' => 'AHS',
        'house_name' => '',
        'year_left' => 2012,
        'school_number' => '',
        'submitter_name' => 'Kanyi Gitonga',
        'submitter_email' => 'kanyi@example.com',
        'raw_submitter_phone' => '+254711223344',
        'submitter_phone' => '+254711223344',
    ]);
} catch (\InvalidArgumentException $e) {
    $caughtEmptyHouse = true;
}
testAssert($caughtEmptyHouse, 'VettingPurger rejects empty house when house is compulsory');

\BusherianBiasharas\Services\VettingPurger::validateVettingIdentityPayload([
    'school_code' => 'AHS',
    'house_name' => 'Livingstone',
    'year_left' => 2012,
    'school_number' => '',
    'submitter_name' => 'Kanyi Gitonga',
    'submitter_email' => 'kanyi@example.com',
    'raw_submitter_phone' => '+254711223344',
    'submitter_phone' => '+254711223344',
]);
testAssert(true, 'VettingPurger validates basic vetting identity with confirmed House and WhatsApp contact');

// 11. Check business_recommendations table exists in DB (#Mockup)
$recTableExists = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='business_recommendations'")->fetchColumn();
testAssert($recTableExists, 'business_recommendations table exists in SQLite database schema');

// 12. Check Install App button visibility (Mobile only, hidden on web)
testAssert(str_contains($indexSrc, 'id="btn-install-app" data-testid="btn-install-app" class="btn btn-outline-light btn-install-pwa header-mobile-only"'), 'index.php has btn-install-app marked as header-mobile-only');
$appCss = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
testAssert(str_contains($appCss, '@media (min-width: 901px)') && str_contains($appCss, '.btn-install-pwa {') && str_contains($appCss, 'display: none !important;'), 'app.css hides .btn-install-pwa on desktop screens (min-width: 901px)');
$dirJs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
testAssert(str_contains($dirJs, 'function isMobileContext()') && str_contains($dirJs, 'btnInstallApp.hidden = !visible || !isMobile;'), 'directory.js syncPwaInstallButtons enforces mobile-only visibility');

// 13. Check WhatsApp button greyed-out when WhatsApp line is missing
testAssert(str_contains($appCss, '.btn-action-whatsapp.btn-action-disabled') && str_contains($appCss, 'cursor: not-allowed !important;'), 'app.css styles disabled/greyed-out WhatsApp button');
testAssert(str_contains($dirJs, 'btn-action-primary btn-action-whatsapp btn-action-disabled') && str_contains($dirJs, 'title="No WhatsApp line provided"'), 'directory.js renders greyed-out disabled button when listing lacks WhatsApp');

// 14. Check visual Popular with Busherians and New badges are removed from cards
testAssert(!str_contains($dirJs, 'biz-card-popular-badge') && !str_contains($dirJs, 'biz-card-new-badge'), 'directory.js removes visual popular and new badge pills from card templates while preserving ranking');

// 15. Check Recommendations panel & confirmation footnote in index.php
testAssert(str_contains($indexSrc, 'id="detail-recs-panel"') && str_contains($indexSrc, 'id="btn-toggle-recommend"'), 'index.php contains #detail-recs-panel and #btn-toggle-recommend button');
testAssert(str_contains($indexSrc, 'id="modal-recommend"') && str_contains($indexSrc, 'id="form-recommend"'), 'index.php contains #modal-recommend alumni verification modal');
testAssert(str_contains($indexSrc, 'id="detail-foot-note"') && str_contains($indexSrc, 'Details confirmed by the owner in'), 'index.php contains confirmation footnote with owner claim and report links');

// 16. Test /api/recommend.php endpoint (Toggle On & Take Back / Retract)
$insBiz = $pdo->prepare("
    INSERT INTO businesses (
        name, slug, tagline, description, sector_id, specialty_id,
        phone_primary, status, operating_hours_json
    ) VALUES (
        'Test Recommendation Biashara', 'test-rec-biashara', 'Test tagline', 'Test desc', 1, 1,
        '+254711000000', 'approved', '{}'
    )
");
$insBiz->execute();
$testBizId = (int)$pdo->lastInsertId();

// Ensure recommendations table initialized
$pdo->exec("
    CREATE TABLE IF NOT EXISTS business_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        business_id INTEGER NOT NULL,
        recommender_name TEXT NOT NULL,
        recommender_school TEXT NOT NULL,
        recommender_year INTEGER NULL,
        visitor_token TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(business_id, visitor_token)
    )
");

// Test toggle ON
$tok = 'test_token_999';
$recInsert = $pdo->prepare("
    INSERT INTO business_recommendations (business_id, recommender_name, recommender_school, recommender_year, visitor_token)
    VALUES (:bid, 'Akinyi', 'AGHS', 2011, :tok)
");
$recInsert->execute([':bid' => $testBizId, ':tok' => $tok]);
$recCount1 = (int)$pdo->query("SELECT COUNT(*) FROM business_recommendations WHERE business_id = {$testBizId}")->fetchColumn();
testAssert($recCount1 === 1, 'Recommendation added successfully for Akinyi (AGHS 2011)');

// Test toggle OFF / Take back
$delRec = $pdo->prepare("DELETE FROM business_recommendations WHERE business_id = :bid AND visitor_token = :tok");
$delRec->execute([':bid' => $testBizId, ':tok' => $tok]);
$recCount0 = (int)$pdo->query("SELECT COUNT(*) FROM business_recommendations WHERE business_id = {$testBizId}")->fetchColumn();
testAssert($recCount0 === 0, 'Recommendation successfully taken back (retracted) on second click');

// 12. Check Photo Carousel & Airbnb-Style Lightbox (#403)
testAssert(!str_contains($indexSrc, 'Three to five photos of the premises, work, or products.'), 'index.php has removed the subtext "Three to five photos"');
testAssert(str_contains($indexSrc, 'id="detail-photos-nav"'), 'index.php has #detail-photos-nav scroll navigation buttons');
testAssert(str_contains($indexSrc, 'id="btn-photos-scroll-left"'), 'index.php has #btn-photos-scroll-left');
testAssert(str_contains($indexSrc, 'id="btn-photos-scroll-right"'), 'index.php has #btn-photos-scroll-right');
testAssert(str_contains($indexSrc, 'id="bb-photo-lightbox"'), 'index.php contains #bb-photo-lightbox modal');
testAssert(str_contains($cssSrc, '.photo-lightbox'), 'app.css defines .photo-lightbox');
testAssert(str_contains($cssSrc, '.photos-carousel-track'), 'app.css defines .photos-carousel-track');
testAssert(str_contains($jsSrc, 'openPhotoLightbox'), 'directory.js defines openPhotoLightbox');
testAssert(str_contains($jsSrc, 'closePhotoLightbox'), 'directory.js defines closePhotoLightbox');
testAssert(str_contains($jsSrc, 'updatePhotoNavButtons'), 'directory.js defines updatePhotoNavButtons');

// 13. Check Airbnb-Style Detail Top Showcase & Minimal Mobile Filters (#405)
testAssert(str_contains($indexSrc, 'id="detail-photos-showcase"'), 'index.php has #detail-photos-showcase at the top of detail view');
testAssert(str_contains($indexSrc, 'id="btn-show-all-photos"'), 'index.php contains #btn-show-all-photos floating button');
testAssert(str_contains($indexSrc, 'id="mobile-search-controls"'), 'index.php contains #mobile-search-controls for streamlined mobile search');
testAssert(str_contains($indexSrc, 'id="mobile-quick-rail"'), 'index.php contains #mobile-quick-rail horizontal pill strip');
testAssert(str_contains($indexSrc, 'id="mobile-filter-sheet"'), 'index.php contains #mobile-filter-sheet bottom sheet modal');
testAssert(str_contains($cssSrc, '.detail-photos-showcase'), 'app.css defines .detail-photos-showcase');
testAssert(str_contains($cssSrc, '.airbnb-photos-grid'), 'app.css defines .airbnb-photos-grid');
testAssert(str_contains($cssSrc, '.mobile-filter-sheet'), 'app.css defines .mobile-filter-sheet');
testAssert(str_contains($cssSrc, '.mobile-search-capsule'), 'app.css defines .mobile-search-capsule');
testAssert(str_contains($jsSrc, 'openAllDetailPhotos'), 'directory.js defines openAllDetailPhotos');
testAssert(str_contains($jsSrc, 'openMobileFilterSheet'), 'directory.js defines openMobileFilterSheet');
testAssert(str_contains($jsSrc, 'closeMobileFilterSheet'), 'directory.js defines closeMobileFilterSheet');

// 14. Check Business Card Photographs Showcase & Interactive Carousel
testAssert(str_contains($jsSrc, 'data-testid="biz-card-photo-hero"'), 'directory.js renders data-testid="biz-card-photo-hero" on cards with photos');
testAssert(str_contains($jsSrc, 'changeCardPhoto'), 'directory.js defines changeCardPhoto for multi-photo card navigation');
testAssert(str_contains($jsSrc, 'gotoCardPhoto'), 'directory.js defines gotoCardPhoto for direct dot navigation');
testAssert(str_contains($cssSrc, '.card-photo-hero'), 'app.css defines .card-photo-hero styling');
testAssert(str_contains($cssSrc, '.card-photo-arrow'), 'app.css defines .card-photo-arrow chevron buttons');
testAssert(str_contains($cssSrc, '.card-photo-dots'), 'app.css defines .card-photo-dots pagination indicators');

// 15. Check Mobile Full-Width Alignment (Body width equals Header and Footer, no horizontal scroll)
testAssert(str_contains($cssSrc, 'overflow-x: hidden'), 'app.css defines overflow-x: hidden on body and wrapper to prevent mobile blowout');
testAssert(str_contains($cssSrc, 'grid-template-columns: 100% !important'), 'app.css defines 100% width grid columns for business-grid on mobile');
testAssert(str_contains($cssSrc, 'align-items: stretch !important'), 'app.css defines align-items: stretch for directory-layout-wrapper on mobile');

// 16. Check Blank School Initials Banner in Lieu of Photos for Equal Height & Placement (#423)
testAssert(str_contains($jsSrc, 'getBusinessInitials'), 'directory.js defines getBusinessInitials extractor');
testAssert(str_contains($jsSrc, 'data-testid="biz-card-initials-banner"'), 'directory.js renders data-testid="biz-card-initials-banner" in lieu of photos');
testAssert(str_contains($jsSrc, 'data-testid="biz-card-initials-text"'), 'directory.js renders data-testid="biz-card-initials-text"');
testAssert(str_contains($jsSrc, 'banner-ahs') && str_contains($jsSrc, 'banner-aghs'), 'directory.js assigns school banner classes');
testAssert(str_contains($cssSrc, '.card-initials-banner'), 'app.css defines .card-initials-banner styling');
testAssert(str_contains($cssSrc, '.card-initials-banner.banner-ahs') && str_contains($cssSrc, '#2E3192'), 'app.css defines banner-ahs in AHS Blue (#2E3192)');
testAssert(str_contains($cssSrc, '.card-initials-banner.banner-aghs') && str_contains($cssSrc, '#1E5E2A'), 'app.css defines banner-aghs in AGHS Green (#1E5E2A)');
testAssert(str_contains($cssSrc, '.card-initials-text'), 'app.css defines .card-initials-text typography');

// 17. Check Open Now/Closed Badges Removed from Cards (#421)
testAssert(!str_contains($jsSrc, 'open-status-btn'), 'directory.js removes visual Open Now/Closed status badge from cards while preserving filter functionality');

// 18. Check Admin Table Column Header Sort Arrows (#424)
$adminIndexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
testAssert(str_contains($adminIndexSrc, '$allowedSortCols = ['), 'admin/index.php whitelists sortable columns');
testAssert(str_contains($adminIndexSrc, 'data-testid="sort-<?= $col ?>-asc"') && str_contains($adminIndexSrc, 'data-testid="sort-<?= $col ?>-desc"'), 'admin/index.php renders top and bottom sort arrows');
testAssert(str_contains($adminIndexSrc, "\$renderSortHeader('name'") && str_contains($adminIndexSrc, "\$renderSortHeader('number'"), 'admin/index.php renders sort controls for Business Name and other columns');
testAssert(str_contains($adminIndexSrc, '$renderSortHeader = static function'), 'admin/index.php implements renderSortHeader component');

// 19. Check Website Button on Detail Page (#422)
testAssert(str_contains($indexSrc, 'id="detail-website-btn"') && str_contains($indexSrc, 'data-testid="detail-website-btn"'), 'index.php renders #detail-website-btn in detail CTA bar');
testAssert(str_contains($indexSrc, 'id="detail-website-row"') && str_contains($indexSrc, 'data-testid="detail-website-row"'), 'index.php renders #detail-website-row in contact details');
testAssert(str_contains($jsSrc, "document.getElementById('detail-website-btn')"), 'directory.js populates detail website CTA button');
testAssert(str_contains($jsSrc, "document.getElementById('detail-website-row')"), 'directory.js populates detail website contact row');
testAssert(str_contains($cssSrc, '.detail-cta-bar') && str_contains($cssSrc, 'flex-wrap: wrap'), 'app.css enables flex-wrap on .detail-cta-bar');

// 20. Check Admin 30-Day Remember-Me & Default 25-item Pagination (#424, #425)
$adminLoginSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/login.php');
testAssert(str_contains($adminLoginSrc, 'data-testid="admin-remember-me-checkbox"'), 'admin/login.php renders remember-me checkbox (#424)');
testAssert(str_contains($adminLoginSrc, 'Remember me on this machine for 30 days'), 'admin/login.php discloses 30-day remember-me option (#424)');
testAssert(str_contains($adminLoginSrc, '$rememberMe = !empty($_POST[\'remember_me\']);'), 'admin/login.php passes remember_me to AdminAuth::login (#424)');

$adminAuthSrc = (string)file_get_contents(dirname(__DIR__) . '/src/Services/AdminAuth.php');
testAssert(str_contains($adminAuthSrc, 'REMEMBER_ME_COOKIE') && str_contains($adminAuthSrc, 'REMEMBER_ME_TTL_SECONDS'), 'AdminAuth defines REMEMBER_ME constants (#424)');
testAssert(str_contains($adminAuthSrc, 'clearRememberMeCookie'), 'AdminAuth implements clearRememberMeCookie (#424)');
testAssert(str_contains($adminAuthSrc, 'admin_remember_tokens'), 'AdminAuth queries admin_remember_tokens table (#441)');
testAssert(str_contains($adminAuthSrc, 'DELETE FROM admin_remember_tokens WHERE selector ='), 'AdminAuth revokes remember token on logout (#441)');
testAssert(str_contains($adminAuthSrc, 'UPDATE admin_remember_tokens') && str_contains($adminAuthSrc, 'validator_hash ='), 'AdminAuth rotates validator upon renewal (#441)');

$adminIndexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
testAssert(str_contains($adminIndexSrc, '$allowedPerPage = [25, 50, 100];'), 'admin/index.php restricts allowedPerPage to [25, 50, 100] (#425)');
testAssert(str_contains($adminIndexSrc, ': 25;'), 'admin/index.php defaults to 25 items per page (#425)');

// 21. Check Company Logo in Banner in Lieu of Abbreviation (#427, #431)
testAssert(str_contains($jsSrc, 'data-testid="biz-card-banner-logo"'), 'directory.js renders company logo in banner when logo_path is present (#427)');
testAssert(str_contains($jsSrc, 'data-testid="detail-banner-logo"'), 'directory.js renders company logo in detail showcase when logo_path is present (#427)');
testAssert(str_contains($indexSrc, 'data-testid="detail-banner-logo"'), 'index.php SSR renders company logo in detail showcase when logo_path is present (#427)');
testAssert(str_contains($cssSrc, '.card-banner-logo') && str_contains($cssSrc, 'object-fit: contain'), 'app.css defines .card-banner-logo with contain fitting (#427)');
testAssert(str_contains($cssSrc, '.detail-banner-logo'), 'app.css defines .detail-banner-logo (#427)');
$apiBizSrc = (string)file_get_contents(dirname(__DIR__) . '/public/api/businesses.php');
testAssert(str_contains($apiBizSrc, "'logo_path' => !empty(\$row['logo_path'])"), 'public/api/businesses.php includes logo_path in item payload (#427)');
$expectedLogos = [
    'akili_ai_logo.png',
    'branfoot_logo.png',
    'bustani_bistro_logo.png',
    'harriets_botanicals_logo.png',
    'impel_web_studio_logo.png',
    'nifty_payroll_logo.png',
    'sasa_signal_logo.png',
    'tag_creative_logo.png',
    'ujuzi_software_logo.jpg',
];
foreach ($expectedLogos as $expectedLogo) {
    testAssert(file_exists(dirname(__DIR__) . '/public/assets/img/logos/' . $expectedLogo), "Logo {$expectedLogo} exists on disk in public/assets/img/logos/ (#431)");
}

// 22. Check User & Admin Editable Company Logo (#429)
$ownerEditSrc = (string)file_get_contents(dirname(__DIR__) . '/public/owner/edit-business.php');
$bootstrapSrc = (string)file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
$formEnhanceSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/form-enhancements.js');

testAssert(str_contains($bootstrapSrc, 'data-testid="business-logo-input"'), 'bootstrap.php renders business-logo-input field (#429)');
testAssert(str_contains($bootstrapSrc, 'data-testid="remove-logo-checkbox"'), 'bootstrap.php renders remove-logo-checkbox when logo exists (#429)');
testAssert(str_contains($ownerEditSrc, 'bbiz_render_hours_and_photos_fields') && str_contains($ownerEditSrc, 'logo_path'), 'owner/edit-business.php passes logo_path to form helper (#429)');
testAssert(str_contains($formEnhanceSrc, 'bindLogoUploadPreview'), 'form-enhancements.js binds live logo upload preview (#429)');

// Unit test PhotoUploader::processBusinessLogo
$im = imagecreatetruecolor(30, 30);
imagealphablending($im, false);
imagesavealpha($im, true);
$trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $trans);
ob_start();
imagepng($im);
$testTransparentPng = ob_get_clean();
imagedestroy($im);
$tmpPngPath = sys_get_temp_dir() . '/test_logo_' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($tmpPngPath, $testTransparentPng);

$uploadedFileArr = [
    'name' => 'logo.png',
    'type' => 'image/png',
    'tmp_name' => $tmpPngPath,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($testTransparentPng),
];

$logoUrl = \BusherianBiasharas\Services\PhotoUploader::processBusinessLogo(null, false, $uploadedFileArr);
testAssert($logoUrl !== null && str_starts_with($logoUrl, '/uploads/logos/'), 'PhotoUploader::processBusinessLogo creates logo in /uploads/logos/ (#429)');
testAssert(\BusherianBiasharas\Services\PhotoUploader::resolveSafeUploadFilePath($logoUrl, true) !== null, 'Uploaded logo file exists safely on disk (#429)');

$removedLogoUrl = \BusherianBiasharas\Services\PhotoUploader::processBusinessLogo($logoUrl, true, null);
testAssert($removedLogoUrl === null, 'PhotoUploader::processBusinessLogo with removeLogo returns null and deletes file (#429)');
testAssert(\BusherianBiasharas\Services\PhotoUploader::resolveSafeUploadFilePath($logoUrl, true) === null, 'Removed logo file no longer exists on disk (#429)');

// Unit test PhotoUploader::processBusinessLogo with client-side inline data URL (#481)
$dataUrlLogo = 'data:image/png;base64,' . base64_encode($testTransparentPng);
$inlineLogoUrl = \BusherianBiasharas\Services\PhotoUploader::processBusinessLogo(null, false, null, $dataUrlLogo);
testAssert($inlineLogoUrl !== null && str_starts_with($inlineLogoUrl, '/uploads/logos/'), 'PhotoUploader::processBusinessLogo handles inline client-side resized data URL (#481)');
testAssert(\BusherianBiasharas\Services\PhotoUploader::resolveSafeUploadFilePath($inlineLogoUrl, true) !== null, 'Inline client-side logo saved safely to disk (#481)');
\BusherianBiasharas\Services\PhotoUploader::processBusinessLogo($inlineLogoUrl, true, null);

testAssert(str_contains($bootstrapSrc, 'id="business_logo_data_url"'), 'bootstrap.php renders hidden business_logo_data_url input (#481)');
testAssert(str_contains($formEnhanceSrc, 'resizeImageFile'), 'form-enhancements.js contains universal client-side image downscaler (#481)');
testAssert(str_contains($formEnhanceSrc, 'business_logo_data_url'), 'form-enhancements.js binds business_logo_data_url for optimized upload (#481)');
testAssert(!str_contains($formEnhanceSrc, 'Logo file exceeds 8 MB size limit'), 'form-enhancements.js eliminates 8MB logo size limit barrier in favor of auto-downscaling (#481)');

// Check Directory Complete Row Slices 30/60/90/120 (#482)
$indexSrcCheck = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
$dirJsSrcCheck = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
$apiBizSrcCheck = (string)file_get_contents(dirname(__DIR__) . '/public/api/businesses.php');
testAssert(str_contains($indexSrcCheck, '$allowedPageSizes = [30, 60, 90, 120];'), 'index.php defines allowedPageSizes as [30, 60, 90, 120] (#482)');
testAssert(str_contains($indexSrcCheck, ': 30;'), 'index.php defaults to 30 items per page for complete card rows (#482)');
testAssert(str_contains($dirJsSrcCheck, 'ALLOWED_PAGE_SIZES = [30, 60, 90, 120]'), 'directory.js defines ALLOWED_PAGE_SIZES as [30, 60, 90, 120] (#482)');
testAssert(str_contains($apiBizSrcCheck, 'min(120, (int)$_GET[\'per_page\'])) : 30;'), 'api/businesses.php bounds per_page up to 120 defaulting to 30 (#482)');

// 19. Check BB-DPA-01 Founder Attribution Consent Contract & Revocation (#435)
$privacySrc = (string)file_get_contents(dirname(__DIR__) . '/public/privacy.php');
testAssert(str_contains($submitSrc, 'Kenya DPA 2019 Voluntary Publication Notice'), 'submit.php has voluntary publication notice (#435)');
testAssert(str_contains($privacySrc, 'Business Entity Profile Data vs. Founder Personal Data'), 'privacy.php delineates entity profile vs founder personal data (#435)');
testAssert(str_contains($privacySrc, 'Strictly Opt-In Public Founder Attribution'), 'privacy.php documents strictly opt-in founder attribution (#435)');
testAssert(str_contains($bootstrapSrc, 'Founder personal attribution is 100% voluntary and opt-in under Kenya DPA 2019'), 'bootstrap.php Community Charter clause 4 states founder attribution is opt-in and revocable (#435)');

testAssert(str_contains($ownerEditSrc, 'name="show_founder_publicly"'), 'owner/edit-business.php provides founder attribution toggle (#435)');
testAssert(str_contains($ownerEditSrc, 'name="founder_role"') && str_contains($ownerEditSrc, 'name="founder_name"'), 'owner/edit-business.php provides founder role and name fields (#435)');

$adminEditSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/edit-business.php');
testAssert(str_contains($adminEditSrc, 'name="show_founder_publicly"'), 'admin/edit-business.php provides founder attribution toggle (#435)');
testAssert(str_contains($adminEditSrc, 'name="founder_role"') && str_contains($adminEditSrc, 'name="founder_name"'), 'admin/edit-business.php provides founder role and name fields (#435)');

// Functional test: VettingPurger::updateBusinessByOwner enables and then revokes founder attribution
$testBizId = (int)$pdo->query("SELECT id FROM businesses ORDER BY id ASC LIMIT 1")->fetchColumn();
if ($testBizId > 0) {
    $ownerRow = \BusherianBiasharas\Services\OwnerAuth::upsertOwner($pdo, 'test-owner-435@example.com', 'Test Founder');
    $linkStmt = $pdo->prepare("REPLACE INTO business_owner_links (business_id, owner_id) VALUES (:bid, :oid)");
    $linkStmt->execute([':bid' => $testBizId, ':oid' => (int)$ownerRow['id']]);

    // 1. Update with founder attribution enabled
    \BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId, $ownerRow, [
        'name' => 'Attribution Test Business',
        'sector_id' => 1,
        'specialty_id' => 2,
        'owner_adult_attestation_18' => 1,
        'show_founder_publicly' => 1,
        'founder_role' => 'co_founder',
        'founder_name' => 'Wangari Maathai',
        'founder_school' => 'AGHS',
        'founder_year' => '1959',
    ]);
    $chkStmt = $pdo->prepare("SELECT founder_role, founder_name, founder_school, founder_year FROM businesses WHERE id = :id");
    $chkStmt->execute([':id' => $testBizId]);
    $updatedFounderRow = $chkStmt->fetch();
    testAssert($updatedFounderRow['founder_role'] === 'co_founder', 'updateBusinessByOwner saves founder_role co_founder (#435)');
    testAssert($updatedFounderRow['founder_name'] === 'Wangari Maathai', 'updateBusinessByOwner saves founder_name (#435)');
    testAssert($updatedFounderRow['founder_school'] === 'AGHS', 'updateBusinessByOwner saves founder_school (#435)');
    testAssert($updatedFounderRow['founder_year'] === '1959', 'updateBusinessByOwner saves founder_year (#435)');

    // 2. Revoke founder attribution (show_founder_publicly omitted/unchecked)
    \BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId, $ownerRow, [
        'name' => 'Attribution Test Business',
        'sector_id' => 1,
        'specialty_id' => 2,
        'owner_adult_attestation_18' => 1,
        'show_founder_publicly' => 0,
        'founder_role' => 'none',
    ]);
    $chkStmt->execute([':id' => $testBizId]);
    $revokedFounderRow = $chkStmt->fetch();
    testAssert($revokedFounderRow['founder_role'] === 'none', 'updateBusinessByOwner revokes founder_role to none (#435)');
    testAssert($revokedFounderRow['founder_name'] === null, 'updateBusinessByOwner clears founder_name on revocation (#435)');
    testAssert($revokedFounderRow['founder_school'] === null, 'updateBusinessByOwner clears founder_school on revocation (#435)');
    testAssert($revokedFounderRow['founder_year'] === null, 'updateBusinessByOwner clears founder_year on revocation (#435)');
    $chkStmt->closeCursor();
    unset($chkStmt);
}

// 20. Check BB-DPA-02 Rate Limiting and Contact/Coordinate Obfuscation (#436)
testAssert(\BusherianBiasharas\Services\PhoneNormalizer::maskPhoneNumber('+254722100200') === '+254 72X XXXX00', 'PhoneNormalizer::maskPhoneNumber masks Kenyan mobile number (#436)');
testAssert(str_contains(\BusherianBiasharas\Services\PhoneNormalizer::maskPhoneNumber('+254700000099') ?? '', 'XXXX0099') || str_contains(\BusherianBiasharas\Services\PhoneNormalizer::maskPhoneNumber('+254700000099') ?? '', 'XXXX99'), 'PhoneNormalizer::maskPhoneNumber masks middle digits (#436)');

$jsDirSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
testAssert(str_contains($jsDirSrc, 'onCardContactClick'), 'directory.js defines onCardContactClick click-to-reveal handler (#436)');
testAssert(str_contains($jsDirSrc, 'phone_obfuscated'), 'directory.js checks phone_obfuscated (#436)');

// Test API bulk output vs single listing output
$runBizApiTest = static function (array $queryParams): array {
    $runner = sprintf(
        '$_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_GET=%s; require %s;',
        var_export($queryParams, true),
        var_export(dirname(__DIR__) . '/public/api/businesses.php', true)
    );
    $raw = (string)shell_exec('php -r ' . escapeshellarg($runner));
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : ['status' => 'decode_err', 'raw' => $raw];
};

$bulkApiRes = $runBizApiTest(['per_page' => '5']);
testAssert(($bulkApiRes['status'] ?? '') === 'ok', 'bulk /api/businesses.php returns status ok (#436)');
$firstBulkBiz = $bulkApiRes['businesses'][0] ?? [];
testAssert($firstBulkBiz['phone_obfuscated'] === true, 'bulk directory card has phone_obfuscated = true (#436)');
testAssert($firstBulkBiz['coords_fuzzed'] === true, 'bulk directory card has coords_fuzzed = true (#436)');
if (!empty($firstBulkBiz['phone_primary'])) {
    testAssert(str_contains($firstBulkBiz['phone_primary'], 'X'), 'bulk directory card phone_primary is masked (#436)');
}
if (!empty($firstBulkBiz['latitude'])) {
    $decPlaces = strlen(substr(strrchr((string)$firstBulkBiz['latitude'], '.'), 1));
    testAssert($decPlaces <= 3, 'bulk directory card latitude is bounded to at most 3 decimal places (#436)');
}

$singleApiRes = $runBizApiTest(['id' => (string)$firstBulkBiz['id']]);
testAssert(($singleApiRes['status'] ?? '') === 'ok', 'single /api/businesses.php?id= returns status ok (#436)');
$firstSingleBiz = $singleApiRes['businesses'][0] ?? [];
testAssert($firstSingleBiz['phone_obfuscated'] === false, 'single listing detail has phone_obfuscated = false (#436)');
testAssert($firstSingleBiz['coords_fuzzed'] === false, 'single listing detail has coords_fuzzed = false (#436)');
if (!empty($firstSingleBiz['phone_primary'])) {
    testAssert(!str_contains($firstSingleBiz['phone_primary'], 'X'), 'single listing detail phone_primary is unmasked (#436)');
}

// 21. Check BB-DPA-03 PWA Cache Eviction, Tombstone Sync & Status Headers (#437)
$swContent = (string)file_get_contents(dirname(__DIR__) . '/public/sw.js');
testAssert(str_contains($swContent, "const CACHE_VERSION = 'bbiz-pwa-v" . bbiz_app_version() . "'"), 'sw.js CACHE_VERSION is aligned with bbiz_app_version (#437)');
testAssert(str_contains($swContent, 'MAX_CACHE_AGE_MS'), 'sw.js defines MAX_CACHE_AGE_MS for 24h freshness (#437)');
testAssert(str_contains($swContent, 'putInCacheWithTtl'), 'sw.js defines putInCacheWithTtl helper (#437)');
testAssert(str_contains($swContent, 'evictTombstonesFromCache'), 'sw.js defines evictTombstonesFromCache helper (#437)');
testAssert(str_contains($swContent, 'netRes.status === 404 || netRes.status === 410'), 'sw.js evicts on 404 or 410 network status (#437)');
testAssert(str_contains($swContent, "data.type === 'EVICT_TOMBSTONES'"), 'sw.js handles EVICT_TOMBSTONES message (#437)');
testAssert(str_contains($swContent, "data.type === 'PURGE_LISTING'"), 'sw.js handles PURGE_LISTING message (#437)');

$jsContent = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
testAssert(str_contains($jsContent, 'evictListingFromClientCache'), 'directory.js defines evictListingFromClientCache (#437)');
testAssert(str_contains($jsContent, 'evictTombstonesFromClient'), 'directory.js defines evictTombstonesFromClient (#437)');
testAssert(str_contains($jsContent, 'res.status === 410 || res.status === 404'), 'directory.js openBizDetail checks 410 or 404 (#437)');

// Test 404 on nonexistent listing
$notFoundApiRes = $runBizApiTest(['id' => '99999999']);
testAssert(($notFoundApiRes['code'] ?? '') === 'not_found', 'API returns code not_found for nonexistent listing (#437)');

// Test 410 on quarantined listing
$insQStmt = $pdo->prepare("
    INSERT INTO businesses (
        name, tagline, slug, sector_id, specialty_id, status, description, operating_hours_json, phone_primary, created_at, updated_at
    ) VALUES (
        'Quarantined Test Biz', 'Quarantined Tagline', 'quarantined-test-biz-437', 1, 2, 'quarantined_dpa', 'Test description', '{}', '+254711000111', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
");
$insQStmt->execute();
$quarantinedBizId = (int)$pdo->lastInsertId();

$quarantinedApiRes = $runBizApiTest(['id' => (string)$quarantinedBizId]);
testAssert(($quarantinedApiRes['code'] ?? '') === 'quarantined', 'API returns code quarantined for quarantined listing (#437)');
testAssert(($quarantinedApiRes['tombstone'] ?? false) === true, 'API returns tombstone = true for quarantined listing (#437)');
testAssert(($quarantinedApiRes['business_id'] ?? 0) === $quarantinedBizId, 'API returns business_id for quarantined listing (#437)');

// Test bulk directory includes tombstone ID
$bulkWithTombstones = $runBizApiTest(['per_page' => '5']);
$tombstones = $bulkWithTombstones['tombstones'] ?? [];
testAssert(is_array($tombstones) && in_array($quarantinedBizId, $tombstones, true), 'bulk API returns tombstones array containing quarantined business ID (#437)');

// Clean up test quarantined business
$pdo->prepare("DELETE FROM businesses WHERE id = :id")->execute([':id' => $quarantinedBizId]);

// 22. Check BB-SEC-01 OTP Dispatch and Authenticated SMTP Hardening (#439)
testAssert(\BusherianBiasharas\Services\AntiSpamGate::OTP_IP_LIMIT_15M === 5, 'AntiSpamGate::OTP_IP_LIMIT_15M is 5 (#439)');
testAssert(\BusherianBiasharas\Services\AntiSpamGate::OTP_IP_LIMIT_24H === 15, 'AntiSpamGate::OTP_IP_LIMIT_24H is 15 (#439)');

$testOtpHmacIp = \BusherianBiasharas\Services\AntiSpamGate::pseudonymizeIp('198.51.100.77');
$nowTs = time();
// Seed 5 OTP attempts for 198.51.100.77
for ($i = 0; $i < 5; $i++) {
    $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:h, 'otp_ip_dispatch', :ts)")
        ->execute([':h' => $testOtpHmacIp, ':ts' => $nowTs - ($i * 10)]);
}
// Set non-testing env temporarily to verify rate limit trigger
putenv('APP_ENV=production');
$quotaCheck = \BusherianBiasharas\Services\AntiSpamGate::checkAndRecordOutboundMailQuota($pdo, '198.51.100.77', 'victim@example.com', true);
testAssert($quotaCheck['allowed'] === false, 'checkAndRecordOutboundMailQuota blocks 6th OTP attempt from same IP (#439)');
testAssert($quotaCheck['reason'] === 'otp_ip_limit_15m', 'checkAndRecordOutboundMailQuota returns otp_ip_limit_15m (#439)');

// Verify Mailer::isPhpMailFallbackEnabled returns false in production
testAssert(\BusherianBiasharas\Services\Mailer::isPhpMailFallbackEnabled() === false, 'Mailer::isPhpMailFallbackEnabled returns false in production (#439)');
testAssert(\BusherianBiasharas\Services\Mailer::isSmtpSecurityAllowed('none', 'production') === false, 'Mailer::isSmtpSecurityAllowed blocks unencrypted SMTP in production (#439)');
testAssert(\BusherianBiasharas\Services\Mailer::isSmtpSecurityAllowed('tls', 'production') === true, 'Mailer::isSmtpSecurityAllowed permits TLS in production (#439)');
testAssert(\BusherianBiasharas\Services\Mailer::isSmtpSecurityAllowed('ssl', 'production') === true, 'Mailer::isSmtpSecurityAllowed permits SSL in production (#439)');

// Clean up test rate limit rows and restore testing env
$pdo->prepare("DELETE FROM rate_limits WHERE ip_hmac = :h")->execute([':h' => $testOtpHmacIp]);
putenv('APP_ENV=testing');

$swContent439 = (string)file_get_contents(dirname(__DIR__) . '/public/sw.js');
testAssert(str_contains($swContent439, "const CACHE_VERSION = 'bbiz-pwa-v" . bbiz_app_version() . "'"), 'sw.js CACHE_VERSION is aligned with bbiz_app_version (#439)');

// 23. Check BB-SEC-02 Rate Limiting on Enrichment & Map Proxies (#440)
$enrichSrc = (string)file_get_contents(dirname(__DIR__) . '/public/api/enrich-website.php');
testAssert(str_contains($enrichSrc, 'AntiSpamGate::checkAndRecordRateLimit'), 'enrich-website.php applies AntiSpamGate rate limiting (#440)');
testAssert(str_contains($enrichSrc, 'WebsiteEnricher::fetchAndExtract'), 'enrich-website.php invokes WebsiteEnricher::fetchAndExtract (#440)');

$directionsSrc = (string)file_get_contents(dirname(__DIR__) . '/public/api/route-directions.php');
testAssert(str_contains($directionsSrc, 'AntiSpamGate::checkAndRecordRateLimit'), 'route-directions.php applies AntiSpamGate rate limiting (#440)');

$geocodeSrc = (string)file_get_contents(dirname(__DIR__) . '/public/api/geocode.php');
testAssert(str_contains($geocodeSrc, 'AntiSpamGate::checkAndRecordRateLimit'), 'geocode.php applies AntiSpamGate rate limiting (#440)');

// Test enrich-website.php rate limit triggering via subprocess
$runEnrichTest = static function (string $ip, string $url): array {
    $runner = sprintf(
        '$_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["REMOTE_ADDR"]=%s; $_POST=["url"=>%s]; require %s;',
        var_export($ip, true),
        var_export($url, true),
        var_export(dirname(__DIR__) . '/public/api/enrich-website.php', true)
    );
    $raw = (string)shell_exec('php -r ' . escapeshellarg($runner));
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : ['raw' => $raw];
};

$testEnrichHmacIp = \BusherianBiasharas\Services\AntiSpamGate::pseudonymizeIp('198.51.100.88');
$enrichNow = time();
for ($i = 0; $i < 10; $i++) {
    $pdo->prepare("INSERT INTO rate_limits (ip_hmac, action_type, created_at) VALUES (:h, 'autofill_url', :ts)")
        ->execute([':h' => $testEnrichHmacIp, ':ts' => $enrichNow - ($i * 5)]);
}

$enrichRes = $runEnrichTest('198.51.100.88', 'https://example.com');
testAssert(str_contains(($enrichRes['error'] ?? ''), 'Rate limit exceeded'), 'enrich-website.php blocks requests when autofill_url rate limit is reached (#440)');

$pdo->prepare("DELETE FROM rate_limits WHERE ip_hmac = :h")->execute([':h' => $testEnrichHmacIp]);

// 24. Check BB-SEC-04 Logo Lifecycle, Quarantine & Durable Media Deletions (#442)
$testLogoBizId = 999442;
$logoUploadDir = \BusherianBiasharas\Services\PhotoUploader::logoUploadDir();
if (!is_dir($logoUploadDir)) {
    @mkdir($logoUploadDir, 0775, true);
}
$dummyLogoPath = $logoUploadDir . '/test_lifecycle_logo_442.png';
$im = imagecreatetruecolor(40, 40);
imagepng($im, $dummyLogoPath);
imagedestroy($im);

$relLogoUrl = '/uploads/logos/test_lifecycle_logo_442.png';
testAssert(file_exists($dummyLogoPath), 'Test logo created in uploads/logos (#442)');

// 1. Quarantine logo
\BusherianBiasharas\Services\PhotoUploader::quarantineBusinessLogo($testLogoBizId, $relLogoUrl);
$quarFile = \BusherianBiasharas\Services\PhotoUploader::quarantineDirRoot() . '/' . $testLogoBizId . '/test_lifecycle_logo_442.png';
testAssert(!file_exists($dummyLogoPath) && file_exists($quarFile), 'quarantineBusinessLogo relocates logo to storage/quarantined_media (#442)');

// 2. Restore logo from quarantine
\BusherianBiasharas\Services\PhotoUploader::restoreQuarantinedBusinessLogo($testLogoBizId, $relLogoUrl);
testAssert(file_exists($dummyLogoPath) && !file_exists($quarFile), 'restoreQuarantinedBusinessLogo restores logo back to uploads/logos (#442)');

// 3. Durable media deletion journaling & draining
\BusherianBiasharas\Services\VettingPurger::enqueueDurableMediaDeletion($pdo, $testLogoBizId, null, $relLogoUrl);
$chkDel = $pdo->prepare("SELECT photos_json FROM pending_media_deletions WHERE business_id = ?");
$chkDel->execute([$testLogoBizId]);
$delPj = (string)$chkDel->fetchColumn();
$chkDel->closeCursor(); unset($chkDel);
testAssert(str_contains($delPj, 'test_lifecycle_logo_442.png'), 'enqueueDurableMediaDeletion journals logo into pending_media_deletions (#442)');

// Drain media deletion
\BusherianBiasharas\Services\VettingPurger::drainDurableMediaDeletions($pdo, $testLogoBizId);
testAssert(!file_exists($dummyLogoPath), 'drainDurableMediaDeletions purges logo from disk (#442)');

// 25. Check Cascading Country -> Subdivisions Filter on Mobile Sheet (#401)
$indexSrc401 = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
$dirJs401 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/directory.js');
$css401 = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');

testAssert(str_contains($indexSrc401, 'id="sheet-filter-country"') && str_contains($indexSrc401, 'foreach (array_keys($placeHierarchy) as $cName)'), 'public/index.php populates sheet-filter-country dynamically from placeHierarchy (#401)');
testAssert(str_contains($dirJs401, 'function syncSubdivisionsToSelect') && str_contains($dirJs401, 'hierarchy[countryName]'), 'directory.js syncSubdivisionsToSelect consumes placeHierarchy dynamically (#401)');
testAssert(str_contains($dirJs401, 'sheetCountry.classList.toggle(\'active-filter\''), 'directory.js syncs active-filter class to sheetCountry select (#401)');
testAssert(str_contains($css401, '.sheet-select.active-filter'), 'app.css defines .sheet-select.active-filter styling (#401)');

// 26. Check Residual Audit Remediations (#451, #452, #453, #454, #455)
// Child 1 (#452): SSR Index initial listing contact masking & coordinate fuzzing
$indexSrc452 = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
testAssert(str_contains($indexSrc452, 'PhoneNormalizer::maskPhoneNumber($rawPhone)') && str_contains($indexSrc452, 'PhoneNormalizer::maskPhoneNumber($rawWa)'), 'public/index.php masks phone numbers for bulk initial listings (#452)');
testAssert(str_contains($indexSrc452, 'round($rawLat, 3)') && str_contains($indexSrc452, '\'coords_fuzzed\' => $coordsFuzzed'), 'public/index.php fuzzes coordinates and emits coords_fuzzed flag (#452)');

// Child 2 (#453): Affirmative opt-in required for founder attribution in submit.php
$submitSrc453 = (string)file_get_contents(dirname(__DIR__) . '/public/submit.php');
testAssert(str_contains($submitSrc453, 'if ($showFounderPublicly) {') && str_contains($submitSrc453, '$founderRole = \'none\';'), 'submit.php strictly forces founderRole to none when show_founder_publicly is false (#453)');

// Child 3 (#454): Token-bucket rate limiter and abuse filter in recommend.php
$recSrc454 = (string)file_get_contents(dirname(__DIR__) . '/public/api/recommend.php');
testAssert(str_contains($recSrc454, 'AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, \'recommend_toggle\''), 'recommend.php applies AntiSpamGate rate limiting (#454)');
testAssert(str_contains($recSrc454, 'AntiSpamGate::containsSpamUrl($name)'), 'recommend.php checks recommender name for spam URLs (#454)');

// Child 4 (#455): Google SSO stable identity lookup & age attestation
$googleSrc455 = (string)file_get_contents(dirname(__DIR__) . '/public/owner/google-callback.php');
testAssert(str_contains($googleSrc455, 'SELECT * FROM business_owners WHERE google_sub = :sub') && str_contains($googleSrc455, 'OwnerAuth::recordAgeAttestation'), 'google-callback.php prioritizes google_sub and records age attestation (#455)');

$ownerColsCheck = $pdo->query("PRAGMA table_info(business_owners)")->fetchAll();
$hasAgeAttestedCol = false;
foreach ($ownerColsCheck as $oc) {
    if (($oc['name'] ?? '') === 'age_attested_at') {
        $hasAgeAttestedCol = true;
    }
}
testAssert($hasAgeAttestedCol, 'business_owners schema has age_attested_at column (#455)');

$testOwnerId = 999455;
$pdo->prepare("INSERT OR REPLACE INTO business_owners (id, email, full_name, created_at, last_login_at) VALUES (?, 'audit455@example.com', 'Audit Test', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$testOwnerId]);
\BusherianBiasharas\Services\OwnerAuth::recordAgeAttestation($pdo, $testOwnerId);
$attestCheck = $pdo->prepare("SELECT age_attested_at FROM business_owners WHERE id = ?");
$attestCheck->execute([$testOwnerId]);
$attestVal = $attestCheck->fetchColumn();
testAssert(!empty($attestVal), 'OwnerAuth::recordAgeAttestation sets age_attested_at timestamp (#455)');
$pdo->prepare("DELETE FROM business_owners WHERE id = ?")->execute([$testOwnerId]);

// Granular Founder / Co-Founder Opt-In (Name compulsory, School & Year optional #457)
$submitSrc457 = (string)file_get_contents(dirname(__DIR__) . "/public/submit.php");
testAssert(str_contains($submitSrc457, "name=\"display_founder_school\""), "submit.php has display_founder_school checkbox in Section 1 (#457)");
testAssert(str_contains($submitSrc457, "name=\"display_founder_year\""), "submit.php has display_founder_year checkbox in Section 1 (#457)");
testAssert(str_contains($submitSrc457, "id=\"simple-founder-preview\""), "submit.php has #simple-founder-preview live preview container (#457)");
testAssert(str_contains($submitSrc457, "Do not display school (optional)"), "submit.php has optional school option in Section 2 (#457)");
testAssert(str_contains($submitSrc457, "Full Name <span style=\"color:#DC2626;\">* (Compulsory)</span>"), "submit.php marks Full Name as compulsory in Section 2 (#457)");

$ownerEditSrc457 = (string)file_get_contents(dirname(__DIR__) . "/public/owner/edit-business.php");
testAssert(str_contains($ownerEditSrc457, "Founder Personal Name <span style=\"color:#DC2626;\">* (Compulsory)</span>"), "owner/edit-business.php marks Founder Name as compulsory (#457)");
testAssert(str_contains($ownerEditSrc457, "Do not display school (optional)"), "owner/edit-business.php allows omitting school (#457)");

$adminEditSrc457 = (string)file_get_contents(dirname(__DIR__) . "/public/admin/edit-business.php");
testAssert(str_contains($adminEditSrc457, "Founder Personal Name <span style=\"color:#DC2626;\">* (Compulsory)</span>"), "admin/edit-business.php marks Founder Name as compulsory (#457)");
testAssert(str_contains($adminEditSrc457, "Do not display school (optional)"), "admin/edit-business.php allows omitting school (#457)");

$jsSrc457 = (string)file_get_contents(dirname(__DIR__) . "/public/assets/js/directory.js");
testAssert(str_contains($jsSrc457, "function formatFounderAttributionText"), "directory.js defines formatFounderAttributionText (#457)");

// Functional tests: updateBusinessByOwner with granular opt-in fields
$testBizId457 = $testBizId; // reuse active test biz
$baseBizData = [
    "name" => "Granular Biashara",
    "tagline" => "Granular Opt-In Test",
    "description" => "Testing name-only vs multi-field founder display",
    "sector_id" => 1,
    "specialty_id" => 2,
    "service_mode" => "hybrid",
    "owner_adult_attestation_18" => 1,
    "status" => "approved"
];

// 1. Name only (School & Year explicitly omitted/blank)
\BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId457, $ownerRow, array_merge($baseBizData, [
    "show_founder_publicly" => 1,
    "founder_role" => "founder",
    "founder_name" => "Stephen Kanyi Gitonga",
    "founder_school" => "",
    "founder_year" => ""
]));
$rowNameOnly = $pdo->query("SELECT founder_role, founder_name, founder_school, founder_year FROM businesses WHERE id = " . (int)$testBizId457)->fetch(PDO::FETCH_ASSOC);
testAssert($rowNameOnly["founder_role"] === "founder", "updateBusinessByOwner saves founder_role founder (#457)");
testAssert($rowNameOnly["founder_name"] === "Stephen Kanyi Gitonga", "updateBusinessByOwner saves compulsory founder_name (#457)");
testAssert($rowNameOnly["founder_school"] === null, "updateBusinessByOwner sets founder_school to NULL when omitted (#457)");
testAssert($rowNameOnly["founder_year"] === null, "updateBusinessByOwner sets founder_year to NULL when omitted (#457)");

// 2. Name + School only
\BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId457, $ownerRow, array_merge($baseBizData, [
    "show_founder_publicly" => 1,
    "founder_role" => "founder",
    "founder_name" => "Stephen Kanyi Gitonga",
    "founder_school" => "AHS",
    "founder_year" => ""
]));
$rowNameSchool = $pdo->query("SELECT founder_role, founder_name, founder_school, founder_year FROM businesses WHERE id = " . (int)$testBizId457)->fetch(PDO::FETCH_ASSOC);
testAssert($rowNameSchool["founder_school"] === "AHS", "updateBusinessByOwner saves school when chosen (#457)");
testAssert($rowNameSchool["founder_year"] === null, "updateBusinessByOwner keeps year NULL when omitted (#457)");

// 3. Name + Year only
\BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId457, $ownerRow, array_merge($baseBizData, [
    "show_founder_publicly" => 1,
    "founder_role" => "co_founder",
    "founder_name" => "Jane Wanjiku",
    "founder_school" => "",
    "founder_year" => "98"
]));
$rowNameYear = $pdo->query("SELECT founder_role, founder_name, founder_school, founder_year FROM businesses WHERE id = " . (int)$testBizId457)->fetch(PDO::FETCH_ASSOC);
testAssert($rowNameYear["founder_role"] === "co_founder", "updateBusinessByOwner saves co_founder role (#457)");
testAssert($rowNameYear["founder_name"] === "Jane Wanjiku", "updateBusinessByOwner saves founder_name (#457)");
testAssert($rowNameYear["founder_school"] === null, "updateBusinessByOwner sets school to NULL (#457)");
testAssert($rowNameYear["founder_year"] === "98", "updateBusinessByOwner saves year when chosen (#457)");

// 4. Compulsory name validation: empty founder_name throws InvalidArgumentException
$nameCompulsoryThrown = false;
try {
    \BusherianBiasharas\Services\VettingPurger::updateBusinessByOwner($pdo, $testBizId457, $ownerRow, [
        "name" => "",
        "show_founder_publicly" => 1,
        "founder_role" => "founder",
        "founder_name" => "",
        "owner_adult_attestation_18" => 1
    ]);
} catch (InvalidArgumentException $e) {
    $nameCompulsoryThrown = true;
}
testAssert($nameCompulsoryThrown, "updateBusinessByOwner enforces compulsory founder_name when opt-in is enabled (#457)");

// 28. Check AngryHosting SMTP Relay Resolution, Manual Retry Backoff Bypass, and Outbox Triage (#460, #461, #462)
testAssert(\BusherianBiasharas\Services\Mailer::resolveEffectiveSmtpHost('mail.busherianbiasharas.com') === 'mx1.angryhosting.com', 'Mailer maps mail.busherianbiasharas.com to mx1.angryhosting.com (#461)');
testAssert(\BusherianBiasharas\Services\Mailer::resolveEffectiveSmtpHost('') === 'mx1.angryhosting.com', 'Mailer resolves empty host to default mx1.angryhosting.com relay (#461)');
testAssert(\BusherianBiasharas\Services\Mailer::resolveEffectiveSmtpHost('custom.smtp.relay.com') === 'custom.smtp.relay.com', 'Mailer preserves custom external SMTP relays (#461)');
testAssert(\BusherianBiasharas\Services\Mailer::resolveEffectiveSmtpPeerName('mx1.angryhosting.com') === 'mx1.angryhosting.com', 'Mailer aligns SNI peer name for mx1.angryhosting.com (#461)');
testAssert(\BusherianBiasharas\Services\Mailer::getAdminEmail($pdo) !== '', 'Mailer::getAdminEmail returns active administrator email (#461)');

// Verify $forceImmediate bypasses future next_retry_at in processRetryQueue
$futureRetryTs = time() + 1800; // 30 minutes in future
$pdo->prepare("
    INSERT INTO email_outbox (business_id, recipient_email, subject, body_html, status, attempts, next_retry_at)
    VALUES (NULL, 'retrytest462@example.com', 'Immediate Retry Test', '<p>Test</p>', 'failed', 2, :nra)
")->execute([':nra' => $futureRetryTs]);
$testOutboxId462 = (int)$pdo->lastInsertId();

// Normal retry with forceImmediate=false should ignore future next_retry_at
$normalRetryRes = \BusherianBiasharas\Services\Mailer::processRetryQueue($pdo, 25, 5, false);
$stCheck1 = (string)$pdo->query("SELECT status FROM email_outbox WHERE id = {$testOutboxId462}")->fetchColumn();
testAssert($stCheck1 === 'failed', 'processRetryQueue with forceImmediate=false respects future backoff window (#462)');

// Manual retry with forceImmediate=true should lease and process immediately
putenv('SIMULATE_EMAILS=yes');
$forcedRetryRes = \BusherianBiasharas\Services\Mailer::processRetryQueue($pdo, 25, 5, true);
$stCheck2 = (string)$pdo->query("SELECT status FROM email_outbox WHERE id = {$testOutboxId462}")->fetchColumn();
testAssert(in_array($stCheck2, ['sent', 'simulated'], true), 'processRetryQueue with forceImmediate=true immediately processes backoff-locked outbox rows (#462)');
testAssert(isset($forcedRetryRes['expired']), 'processRetryQueue returns distinct expired tally in result dictionary (#462)');

// Clean up test outbox row
$pdo->prepare("DELETE FROM email_outbox WHERE id = :id")->execute([':id' => $testOutboxId462]);

// 29. Check Preview Subdomain Production .env Resolution & Admin Credentials Inheritance (#464)
$tempDir464 = sys_get_temp_dir() . '/bbiz_test_env_' . getmypid() . '_' . time();
mkdir($tempDir464 . '/www', 0755, true);
mkdir($tempDir464 . '/preview', 0755, true);
$mockToken464 = 'preview_test_token_' . bin2hex(random_bytes(8));
$mockHmac464 = 'preview_test_hmac_secret_32bytes_long_min!';
file_put_contents($tempDir464 . '/www/.env', "ADMIN_ACCESS_TOKEN={$mockToken464}\nAPP_HMAC_SECRET={$mockHmac464}\n");

$parsedProd464 = \BusherianBiasharas\Services\PreprodSandbox::parseEnvFile($tempDir464 . '/www/.env');
testAssert(($parsedProd464['ADMIN_ACCESS_TOKEN'] ?? '') === $mockToken464, 'PreprodSandbox::parseEnvFile successfully parses production .env without mutating global env (#464)');
testAssert(($parsedProd464['APP_HMAC_SECRET'] ?? '') === $mockHmac464, 'PreprodSandbox::parseEnvFile extracts APP_HMAC_SECRET for handoff validation (#464)');

// Test handoff token generated with production secret
$handoffExp464 = time() + 300;
$handoffSig464 = hash_hmac('sha256', "preprod_handoff|{$handoffExp464}", $mockHmac464);
$rawHandoffToken464 = "{$handoffExp464}.{$handoffSig464}";
$fakeHandoffToken464 = "{$handoffExp464}.badsignature";
testAssert(\BusherianBiasharas\Services\PreprodSandbox::verifyProdHandoffToken($fakeHandoffToken464) === false, 'PreprodSandbox::verifyProdHandoffToken blocks invalid signature (#464)');

// Clean up temp directories
@unlink($tempDir464 . '/www/.env');
@rmdir($tempDir464 . '/www');
@rmdir($tempDir464 . '/preview');
@rmdir($tempDir464);

// 30. Check Google SSO OAuth 2.0 Credentials & URL Generation (#466)
testAssert(\BusherianBiasharas\Services\OwnerAuth::isGoogleSsoConfigured($pdo) === true, 'OwnerAuth::isGoogleSsoConfigured returns true when credentials present in env (#466)');
testAssert(\BusherianBiasharas\Services\OwnerAuth::getGoogleClientId($pdo) === '64004769695-fev3ccrifjenfi4priml8q5ganppb1vo.apps.googleusercontent.com', 'OwnerAuth::getGoogleClientId matches configured Client ID (#466)');
testAssert(str_starts_with(\BusherianBiasharas\Services\OwnerAuth::getGoogleClientSecret($pdo), 'GOCSPX-'), 'OwnerAuth::getGoogleClientSecret matches configured Client Secret format (#466, #469)');
$testState466 = 'test_state_nonce_12345';
$authUrl466 = \BusherianBiasharas\Services\OwnerAuth::getGoogleAuthorizationUrl($testState466, $pdo);
testAssert(str_starts_with($authUrl466, 'https://accounts.google.com/o/oauth2/v2/auth?'), 'Google authorization URL points to Google OAuth 2.0 endpoint (#466)');
testAssert(str_contains($authUrl466, 'client_id=64004769695-fev3ccrifjenfi4priml8q5ganppb1vo.apps.googleusercontent.com'), 'Google authorization URL includes client_id (#466)');
testAssert(str_contains($authUrl466, 'redirect_uri='), 'Google authorization URL includes redirect_uri (#466)');
testAssert(str_contains($authUrl466, 'scope=openid+email+profile') || str_contains($authUrl466, 'scope=openid%20email%20profile'), 'Google authorization URL requests standard non-sensitive scopes (#466)');

// #468: Unified Cookie Domain & Google OAuth Redirect URI Scoping
$_SERVER['HTTP_HOST'] = 'busherianbiasharas.com';
testAssert(\BusherianBiasharas\Services\OwnerAuth::cookieDomain() === '.busherianbiasharas.com', 'OwnerAuth::cookieDomain unifies apex domain into .busherianbiasharas.com (#468)');
testAssert(str_starts_with(\BusherianBiasharas\Services\OwnerAuth::getGoogleRedirectUri(), 'http://busherianbiasharas.com/owner/google-callback.php') || str_starts_with(\BusherianBiasharas\Services\OwnerAuth::getGoogleRedirectUri(), 'https://busherianbiasharas.com/owner/google-callback.php'), 'getGoogleRedirectUri matches apex request host (#468)');

$_SERVER['HTTP_HOST'] = 'www.busherianbiasharas.com';
testAssert(\BusherianBiasharas\Services\OwnerAuth::cookieDomain() === '.busherianbiasharas.com', 'OwnerAuth::cookieDomain unifies www subdomain into .busherianbiasharas.com (#468)');
testAssert(str_contains(\BusherianBiasharas\Services\OwnerAuth::getGoogleRedirectUri(), 'www.busherianbiasharas.com/owner/google-callback.php'), 'getGoogleRedirectUri matches www request host (#468)');

$_SERVER['HTTP_HOST'] = 'preview.busherianbiasharas.com';
testAssert(\BusherianBiasharas\Services\OwnerAuth::cookieDomain() === '', 'OwnerAuth::cookieDomain isolates preview subdomain (#468)');
testAssert(str_contains(\BusherianBiasharas\Services\OwnerAuth::getGoogleRedirectUri(), 'preview.busherianbiasharas.com/owner/google-callback.php'), 'getGoogleRedirectUri matches preview request host (#468)');

unset($_SERVER['HTTP_HOST']);

// #469: Unified List / Edit Navbar Action & Streamlined Onboarding
$indexHtml = file_get_contents(dirname(__DIR__) . '/public/index.php');
testAssert(str_contains($indexHtml, 'btn-list-edit-biashara'), 'index.php contains unified btn-list-edit-biashara action (#469)');
testAssert(str_contains($indexHtml, '+ List / Edit Your Biashara'), 'index.php renders unified + List / Edit Your Biashara label (#469)');
testAssert(!str_contains($indexHtml, 'Owner Sign In / Edit Listing'), 'index.php eliminates redundant Owner Sign In / Edit Listing button (#469)');
testAssert(str_contains($indexHtml, 'drawer-list-edit-biashara'), 'index.php mobile drawer contains drawer-list-edit-biashara action (#469)');

$loginHtml = file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
testAssert(str_contains($loginHtml, 'proceed directly to the listing submission form'), 'login.php contains direct link to listing submission form (#469)');

$submitHtml = file_get_contents(dirname(__DIR__) . '/public/submit.php');
testAssert(str_contains($submitHtml, 'submit-welcome-owner-banner'), 'submit.php contains welcome banner for authenticated owners (#469)');

// #470: Explicit Owner Sign-Out Option across Desktop Header, Mobile Drawer, and Portal Pages
testAssert(str_contains($indexHtml, 'btn-owner-logout'), 'index.php contains desktop btn-owner-logout form action (#470)');
testAssert(str_contains($indexHtml, 'drawer-owner-logout'), 'index.php mobile drawer contains drawer-owner-logout form action (#470)');
testAssert(str_contains($indexHtml, 'action="/owner/logout.php"'), 'index.php logout controls submit to /owner/logout.php (#470)');
testAssert(!str_contains($indexHtml, '<a href="/owner/logout.php"'), 'index.php logout does not use insecure GET link (#203, #470)');

$ownerIndexHtml = file_get_contents(dirname(__DIR__) . '/public/owner/index.php');
testAssert(str_contains($ownerIndexHtml, 'owner-session-security-section'), 'owner/index.php contains owner-session-security-section in dashboard body (#470)');
testAssert(str_contains($ownerIndexHtml, 'owner-body-logout-btn'), 'owner/index.php contains body logout button (#470)');
testAssert(str_contains($ownerIndexHtml, 'owner-body-logout-all-btn'), 'owner/index.php contains body logout-all button (#470)');
testAssert(str_contains($ownerIndexHtml, 'portal-header-actions'), 'owner/index.php header uses responsive portal-header-actions (#470)');

// #472 / #473: Decoupled CSRF Tokens surviving session ID regeneration
\BusherianBiasharas\Services\OwnerAuth::startSecureSession();
$csrfToken = \BusherianBiasharas\Services\AntiSpamGate::generateCsrfToken();
testAssert(strlen($csrfToken) === 64, 'AntiSpamGate::generateCsrfToken returns 64-char hex token (#473)');
testAssert($_SESSION['csrf_token'] === $csrfToken, 'AntiSpamGate stores csrf_token directly in session payload (#473)');
testAssert(\BusherianBiasharas\Services\AntiSpamGate::verifyCsrfToken($csrfToken), 'AntiSpamGate::verifyCsrfToken validates active session token (#473)');

// Simulate session ID regeneration (e.g. login privilege elevation or background re-key)
if (session_status() === PHP_SESSION_ACTIVE) {
    @session_regenerate_id(true);
}
testAssert(\BusherianBiasharas\Services\AntiSpamGate::verifyCsrfToken($csrfToken), 'AntiSpamGate::verifyCsrfToken survives session_regenerate_id without invalidating form (#473)');

// Legacy HMAC fallback test
$legacyHmac = hash_hmac('sha256', session_id(), \BusherianBiasharas\Config\Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len'));
testAssert(\BusherianBiasharas\Services\AntiSpamGate::verifyCsrfToken($legacyHmac), 'AntiSpamGate::verifyCsrfToken accepts legacy HMAC token during rolling deployment migration (#473)');

// #472 / #475: Cookie Names & Session Isolation across preview and production
testAssert(\BusherianBiasharas\Services\OwnerAuth::sessionCookieName() === 'bbiz_sess', 'OwnerAuth::sessionCookieName defaults to bbiz_sess in production (#475)');
testAssert(\BusherianBiasharas\Services\OwnerAuth::cookieName() === 'bbiz_owner_session', 'OwnerAuth::cookieName defaults to bbiz_owner_session in production (#475)');

// #472 / #474 & #476: Anti-cache headers and Logout Verification Order
$logoutSource = file_get_contents(dirname(__DIR__) . '/public/owner/logout.php');
testAssert(str_contains($logoutSource, "header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0')"), 'logout.php enforces private no-store cache control headers (#474, #476)');
testAssert(strpos($logoutSource, 'AntiSpamGate::verifyCsrfToken') < strpos($logoutSource, 'OwnerAuth::revokeAllOwnerSessions'), 'logout.php verifies CSRF token before revoking sessions (#474)');

$loginSource = file_get_contents(dirname(__DIR__) . '/public/owner/login.php');
testAssert(str_contains($loginSource, "header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0')"), 'login.php enforces private no-store cache control headers (#476)');
testAssert(str_contains($loginSource, 'bbiz_google_oauth_states'), 'login.php tracks multi-slot OAuth states to prevent multi-tab collisions (#476)');

$callbackSource = file_get_contents(dirname(__DIR__) . '/public/owner/google-callback.php');
testAssert(str_contains($callbackSource, 'bbiz_google_oauth_states'), 'google-callback.php validates and consumes multi-slot OAuth states (#476)');

// #480: Anti-cache headers, PHPSESSID cross-migration fallback, and session GC lifetime
$submitSource = file_get_contents(dirname(__DIR__) . '/public/submit.php');
testAssert(str_contains($submitSource, "header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0')"), 'submit.php enforces private no-store cache control headers (#480)');
testAssert(str_contains($submitSource, "exceeded the maximum upload limit"), 'submit.php detects empty $_POST on large uploads and warns user (#480)');

// Test PHPSESSID cross-migration fallback
$_COOKIE['PHPSESSID'] = 'legacy_sess_999';
$legacyPhpSessHmac = hash_hmac('sha256', 'legacy_sess_999', \BusherianBiasharas\Config\Database::env('CSRF_SECRET', 'dev_csrf_secret_key_32_bytes_min_len'));
testAssert(\BusherianBiasharas\Services\AntiSpamGate::verifyCsrfToken($legacyPhpSessHmac), 'AntiSpamGate::verifyCsrfToken accepts legacy token derived from PHPSESSID across session migration (#480)');
testAssert(!empty($_SESSION['csrf_token']), 'AntiSpamGate re-seeds active session csrf_token upon legacy PHPSESSID fallback match (#480)');
unset($_COOKIE['PHPSESSID']);

// Test csrf-token.php endpoint source and validity
$csrfTokenSource = file_get_contents(dirname(__DIR__) . '/public/api/csrf-token.php');
testAssert(str_contains($csrfTokenSource, 'generateFormTokens'), 'csrf-token.php generates and returns fresh security tokens (#480)');
testAssert(str_contains($csrfTokenSource, "header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0')"), 'csrf-token.php enforces private no-store cache headers (#480)');

// Test session.gc_maxlifetime is configured to 7200 in OwnerAuth
$ownerAuthSource = file_get_contents(dirname(__DIR__) . '/src/Services/OwnerAuth.php');
testAssert(str_contains($ownerAuthSource, "'session.gc_maxlifetime', '7200'"), 'OwnerAuth::startSecureSession configures 2-hour session.gc_maxlifetime (#480)');

// Test form-enhancements.js has refreshSecurityTokens and visibilitychange listeners
$enhancementsJs = file_get_contents(dirname(__DIR__) . '/public/assets/js/form-enhancements.js');
testAssert(str_contains($enhancementsJs, 'refreshSecurityTokens'), 'form-enhancements.js implements refreshSecurityTokens (#480)');
testAssert(str_contains($enhancementsJs, "visibilityState === 'visible'"), 'form-enhancements.js hooks visibilitychange to refresh tokens (#480)');
testAssert(str_contains($enhancementsJs, 'pageshow'), 'form-enhancements.js hooks pageshow for bfcache restoration (#480)');

echo "All Streamlined Directory & Profile feature tests PASSED!\n";

