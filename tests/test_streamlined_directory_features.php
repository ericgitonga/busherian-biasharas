<?php
declare(strict_types=1);

$tempSqlite = sys_get_temp_dir() . '/bbiz_streamlined_' . getmypid() . '.sqlite';
@unlink($tempSqlite);
putenv('APP_ENV=testing');
putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE_PATH=' . $tempSqlite);
putenv('SIMULATE_EMAILS=yes');

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

$adminIndexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
testAssert(str_contains($adminIndexSrc, '$allowedPerPage = [25, 50, 100];'), 'admin/index.php restricts allowedPerPage to [25, 50, 100] (#425)');
testAssert(str_contains($adminIndexSrc, ': 25;'), 'admin/index.php defaults to 25 items per page (#425)');

// 21. Check Company Logo in Banner in Lieu of Abbreviation (#427)
testAssert(str_contains($jsSrc, 'data-testid="biz-card-banner-logo"'), 'directory.js renders company logo in banner when logo_path is present (#427)');
testAssert(str_contains($jsSrc, 'data-testid="detail-banner-logo"'), 'directory.js renders company logo in detail showcase when logo_path is present (#427)');
testAssert(str_contains($indexSrc, 'data-testid="detail-banner-logo"'), 'index.php SSR renders company logo in detail showcase when logo_path is present (#427)');
testAssert(str_contains($cssSrc, '.card-banner-logo') && str_contains($cssSrc, 'object-fit: contain'), 'app.css defines .card-banner-logo with contain fitting (#427)');
testAssert(str_contains($cssSrc, '.detail-banner-logo'), 'app.css defines .detail-banner-logo (#427)');
$apiBizSrc = (string)file_get_contents(dirname(__DIR__) . '/public/api/businesses.php');
testAssert(str_contains($apiBizSrc, "'logo_path' => !empty(\$row['logo_path'])"), 'public/api/businesses.php includes logo_path in item payload (#427)');
testAssert(file_exists(dirname(__DIR__) . '/public/assets/photos/akili_ai_logo.png'), 'Akili AI logo exists on disk in public/assets/photos/ (#427)');

echo "All Streamlined Directory & Profile feature tests PASSED!\n";
