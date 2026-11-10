<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

$pdo = Database::getConnection();
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)$_GET['days'] : 30;
$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));

// Headline KPIs (Consented Usage Metrics)
$kpiStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS page_views,
        COUNT(DISTINCT visitor_hash) AS unique_visitors,
        SUM(CASE WHEN event_type = 'modal_open' THEN 1 ELSE 0 END) AS profile_opens,
        SUM(CASE WHEN event_type = 'route_request' THEN 1 ELSE 0 END) AS route_requests,
        SUM(CASE WHEN event_type = 'search' THEN 1 ELSE 0 END) AS searches
    FROM usage_metrics
    WHERE created_at >= :cut
");
$kpiStmt->execute([':cut' => $cutoff]);
$kpis = $kpiStmt->fetch() ?: [];

$clicksStmt = $pdo->prepare("SELECT COUNT(*) FROM click_events WHERE created_at >= :cut");
$clicksStmt->execute([':cut' => $cutoff]);
$totalOutboundClicks = (int)$clicksStmt->fetchColumn();

// Per-Business Engagement & Outbound Leads Leaderboard
$bizStmt = $pdo->prepare("
    SELECT
        b.business_number,
        b.business_id,
        b.name,
        b.school_badge,
        COALESCE(m.profile_opens, 0) AS profile_opens,
        COALESCE(m.route_requests, 0) AS route_requests,
        COALESCE(c.website_clicks, 0) AS website_clicks,
        COALESCE(c.whatsapp_clicks, 0) AS whatsapp_clicks,
        COALESCE(c.call_clicks, 0) AS call_clicks,
        COALESCE(c.email_clicks, 0) AS email_clicks,
        COALESCE(c.total_clicks, 0) AS total_clicks
    FROM businesses b
    LEFT JOIN (
        SELECT business_id,
               SUM(CASE WHEN event_type = 'modal_open' THEN 1 ELSE 0 END) AS profile_opens,
               SUM(CASE WHEN event_type = 'route_request' THEN 1 ELSE 0 END) AS route_requests
        FROM usage_metrics
        WHERE created_at >= :mcut AND business_id IS NOT NULL
        GROUP BY business_id
    ) m ON m.business_id = b.id
    LEFT JOIN (
        SELECT business_id,
               SUM(CASE WHEN event_type = 'website' THEN 1 ELSE 0 END) AS website_clicks,
               SUM(CASE WHEN event_type = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapp_clicks,
               SUM(CASE WHEN event_type = 'call' THEN 1 ELSE 0 END) AS call_clicks,
               SUM(CASE WHEN event_type = 'email' THEN 1 ELSE 0 END) AS email_clicks,
               COUNT(*) AS total_clicks
        FROM click_events
        WHERE created_at >= :ccut
        GROUP BY business_id
    ) c ON c.business_id = b.id
    WHERE b.status = 'approved'
    ORDER BY (COALESCE(c.total_clicks, 0) + COALESCE(m.profile_opens, 0)) DESC, b.business_number ASC
");
$bizStmt->execute([':mcut' => $cutoff, ':ccut' => $cutoff]);
$bizStats = $bizStmt->fetchAll();

// Top Search Terms & Filter Selections
$searchStmt = $pdo->prepare("
    SELECT event_type, meta_label, COUNT(*) AS cnt
    FROM usage_metrics
    WHERE created_at >= :cut
      AND event_type IN ('search', 'filter')
      AND meta_label IS NOT NULL
      AND meta_label != ''
    GROUP BY event_type, meta_label
    ORDER BY cnt DESC
    LIMIT 15
");
$searchStmt->execute([':cut' => $cutoff]);
$topSearches = $searchStmt->fetchAll();

// Daily Trend (Last 14 Days)
$dailyCutoff = gmdate('Y-m-d H:i:s', time() - (14 * 86400));
$dailyStmt = $pdo->prepare("
    SELECT SUBSTR(created_at, 1, 10) AS day_utc,
           SUM(CASE WHEN event_type = 'page_view' THEN 1 ELSE 0 END) AS views,
           COUNT(DISTINCT visitor_hash) AS visitors,
           SUM(CASE WHEN event_type IN ('modal_open', 'route_request', 'search') THEN 1 ELSE 0 END) AS actions
    FROM usage_metrics
    WHERE created_at >= :dcut
    GROUP BY SUBSTR(created_at, 1, 10)
    ORDER BY day_utc DESC
");
$dailyStmt->execute([':dcut' => $dailyCutoff]);
$dailyRows = $dailyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usage Metrics &amp; Analytics — Busherian Biasharas Admin</title>
<?= bbiz_head_meta_tags('Usage Metrics & Analytics — Busherian Biasharas Admin', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/admin/metrics.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/admin/index.php" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Consented Usage Metrics &amp; Outbound Leads</h1>
          <p class="brand-subtitle">Kenya DPA 2019 Compliant First-Party Analytics (Strictly Opt-In Visitors)</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/admin/index.php" class="btn btn-outline-light">&larr; Admin Console</a>
        <a href="/" class="btn btn-outline-light">Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:1320px;margin:1.75rem auto;padding:0 1.25rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
      <div>
        <strong style="color:#114B3E;font-size:1rem;">📊 Reporting Window: Last <?= $days ?> Days</strong>
        <span style="font-size:0.82rem;color:#6B7280;margin-left:0.5rem;">(Zero tracking for visitors who selected "Allow only Essential" or have not consented)</span>
      </div>
      <div style="display:flex;gap:0.45rem;">
        <?php foreach ([7 => 'Last 7 Days', 30 => 'Last 30 Days', 90 => 'Last 90 Days'] as $d => $lbl): ?>
          <a href="/admin/metrics.php?days=<?= $d ?>" class="btn <?= $days === $d ? 'btn-crimson' : 'btn-outline' ?>" style="padding:0.4rem 0.85rem;font-size:0.82rem;">
            <?= $lbl ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Headline KPI Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:2rem;">
      <?php
      $cards = [
          ['Consented Page Views', (int)($kpis['page_views'] ?? 0), '#7B1113'],
          ['Unique Consented Visitors', (int)($kpis['unique_visitors'] ?? 0), '#114B3E'],
          ['Business Profile Opens', (int)($kpis['profile_opens'] ?? 0), '#1D4ED8'],
          ['Directions Requested', (int)($kpis['route_requests'] ?? 0), '#B45309'],
          ['Searches Performed', (int)($kpis['searches'] ?? 0), '#6D28D9'],
          ['Outbound Business Clicks', $totalOutboundClicks, '#047857'],
      ];
      foreach ($cards as [$label, $val, $color]): ?>
        <div style="background:#fff;border:1px solid #E5E7EB;border-top:4px solid <?= $color ?>;border-radius:12px;padding:1.1rem 1.25rem;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
          <div style="font-size:0.78rem;font-weight:700;color:#6B7280;text-transform:uppercase;"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:1.85rem;font-weight:900;color:<?= $color ?>;margin-top:0.25rem;"><?= number_format($val) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Per-Business Engagement Leaderboard -->
    <section style="margin-bottom:2.25rem;">
      <h2 style="color:#114B3E;margin:0 0 0.75rem 0;font-size:1.15rem;">1. Business Engagement &amp; Outbound Leads Leaderboard</h2>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
          <thead>
            <tr style="background:#F9FAFB;border-bottom:1px solid #E5E7EB;text-align:left;">
              <th style="padding:0.75rem 1rem;">No.</th>
              <th style="padding:0.75rem 1rem;">Business</th>
              <th style="padding:0.75rem 1rem;">School</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Profile Views</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Directions</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Website</th>
              <th style="padding:0.75rem 1rem;text-align:right;">WhatsApp</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Calls / Email</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Total Leads</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bizStats as $bs): ?>
              <tr style="border-bottom:1px solid #F3F4F6;">
                <td style="padding:0.7rem 1rem;font-weight:800;color:#114B3E;">#<?= (int)$bs['business_number'] ?></td>
                <td style="padding:0.7rem 1rem;font-weight:700;"><?= htmlspecialchars((string)$bs['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:0.7rem 1rem;"><?= htmlspecialchars((string)$bs['school_badge'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;"><?= (int)$bs['profile_opens'] ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;"><?= (int)$bs['route_requests'] ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;"><?= (int)$bs['website_clicks'] ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;"><?= (int)$bs['whatsapp_clicks'] ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;"><?= (int)$bs['call_clicks'] + (int)$bs['email_clicks'] ?></td>
                <td style="padding:0.7rem 1rem;text-align:right;font-weight:800;color:#7B1113;"><?= (int)$bs['total_clicks'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:1.5rem;">
      <!-- Top Search Queries & Filters -->
      <section>
        <h2 style="color:#7B1113;margin:0 0 0.75rem 0;font-size:1.1rem;">2. Top Searches &amp; Active Filters</h2>
        <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem;">
          <?php if (empty($topSearches)): ?>
            <p style="margin:0;color:#6B7280;font-size:0.86rem;">No consented search/filter events recorded in this period yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
              <?php foreach ($topSearches as $ts): ?>
                <tr style="border-bottom:1px solid #F3F4F6;">
                  <td style="padding:0.5rem 0.4rem;"><span class="badge-verified"><?= htmlspecialchars(strtoupper((string)$ts['event_type']), ENT_QUOTES, 'UTF-8') ?></span></td>
                  <td style="padding:0.5rem 0.4rem;font-weight:700;"><?= htmlspecialchars((string)$ts['meta_label'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td style="padding:0.5rem 0.4rem;text-align:right;font-weight:800;"><?= (int)$ts['cnt'] ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      </section>

      <!-- 14-Day Daily Activity -->
      <section>
        <h2 style="color:#114B3E;margin:0 0 0.75rem 0;font-size:1.1rem;">3. Daily Consented Activity (Last 14 Days)</h2>
        <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem;">
          <?php if (empty($dailyRows)): ?>
            <p style="margin:0;color:#6B7280;font-size:0.86rem;">No consented daily page views recorded yet.</p>
          <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
              <thead>
                <tr style="border-bottom:1px solid #E5E7EB;text-align:left;color:#6B7280;">
                  <th style="padding:0.45rem;">Date (UTC)</th>
                  <th style="padding:0.45rem;text-align:right;">Page Views</th>
                  <th style="padding:0.45rem;text-align:right;">Unique Visitors</th>
                  <th style="padding:0.45rem;text-align:right;">Interactions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($dailyRows as $dr): ?>
                  <tr style="border-bottom:1px solid #F3F4F6;">
                    <td style="padding:0.45rem;font-weight:600;"><?= htmlspecialchars((string)$dr['day_utc'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="padding:0.45rem;text-align:right;"><?= (int)$dr['views'] ?></td>
                    <td style="padding:0.45rem;text-align:right;"><?= (int)$dr['visitors'] ?></td>
                    <td style="padding:0.45rem;text-align:right;"><?= (int)$dr['actions'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </main>
</body>
</html>
