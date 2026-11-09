<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\Mailer;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();

// Fetch pending businesses alongside their ephemeral one-time vetting records
$pendingStmt = $pdo->query("
    SELECT
        b.id, b.name, b.tagline, b.school_badge, b.status, b.website_url, b.phone_primary,
        sec.name AS sector_name,
        spec.id AS specialty_id, spec.name AS specialty_name, spec.status AS specialty_status,
        v.school_code, v.house_name, v.year_left, v.school_number,
        v.submitter_name, v.submitter_email, v.submitter_phone
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    LEFT JOIN business_vetting_queue v ON v.business_id = b.id
    WHERE b.status IN ('pending', 'quarantined_dpa')
    ORDER BY b.id DESC
");
$pendingRows = $pendingStmt->fetchAll();

// Verify that ZERO approved/rejected businesses still have rows in business_vetting_queue
$leakedVettingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM business_vetting_queue v
    INNER JOIN businesses b ON b.id = v.business_id
    WHERE b.status IN ('approved', 'rejected')
")->fetchColumn();

$approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Vetting &amp; Category Triage — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Admin Triage &amp; One-Time Vetting Queue</h1>
          <p class="brand-subtitle">Notifications routed to admin@busherianbiasharas.com</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/submit.php" class="btn btn-gold">+ Register / Auto-Fill Business via URL</a>
        <a href="/" class="btn btn-outline-light">&larr; Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:1200px;margin:1.75rem auto;padding:0 1.25rem;">
    <div class="vetting-privacy-banner" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
      <div>
        <strong style="color:#114B3E;">🔒 Vetting Data Disposal Audit Status:</strong>
        <span>Approved Businesses: <strong><?= $approvedCount ?></strong></span> •
        <span>Retained Vetting Rows for Reviewed Businesses: <strong><?= $leakedVettingCount ?> (Must always be 0)</strong></span>
      </div>
      <span class="badge-verified">DPA Storage Limitation Enforced</span>
    </div>

    <h2 style="color:#7B1113;margin-bottom:0.75rem;">Pending Business Submissions (<?= count($pendingRows) ?>)</h2>

    <?php if (empty($pendingRows)): ?>
      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:2rem;text-align:center;color:#6B7280;">
        No pending submissions in the vetting queue right now.
      </div>
    <?php else: ?>
      <div style="display:grid;gap:1rem;">
        <?php foreach ($pendingRows as $row):
            $bizId = (int)$row['id'];
            $approveUrl = Mailer::generateSignedAdminActionUrl($bizId, 'approve');
            $rejectUrl  = Mailer::generateSignedAdminActionUrl($bizId, 'reject');
        ?>
          <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
              <div>
                <h3 style="margin:0 0 0.25rem 0;"><?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                <div style="font-size:0.84rem;color:#7B1113;font-weight:700;">
                  <?= htmlspecialchars((string)$row['sector_name'], ENT_QUOTES, 'UTF-8') ?> &rarr;
                  <?= htmlspecialchars((string)$row['specialty_name'], ENT_QUOTES, 'UTF-8') ?>
                  <?php if ($row['specialty_status'] === 'pending_approval'): ?>
                    <span class="badge-verified" style="margin-left:0.4rem;">Custom Specialty (Pending Approval)</span>
                  <?php endif; ?>
                </div>
                <p style="margin:0.4rem 0;font-size:0.88rem;color:#4B5563;"><?= htmlspecialchars((string)$row['tagline'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <div style="display:flex;gap:0.5rem;">
                <a href="<?= htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-green">✓ Approve &amp; Purge Vetting Data</a>
                <a href="<?= htmlspecialchars($rejectUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-crimson">✕ Reject &amp; Purge Vetting Data</a>
              </div>
            </div>

            <div style="margin-top:0.85rem;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:0.75rem;font-size:0.83rem;">
              <strong style="color:#114B3E;">Temporary Vetting Credentials (Will be permanently deleted on Approve/Reject):</strong><br>
              School: <strong><?= htmlspecialchars((string)($row['school_code'] ?? $row['school_badge']), ENT_QUOTES, 'UTF-8') ?> — Bush</strong> |
              House: <strong><?= htmlspecialchars((string)($row['house_name'] ?? 'Purged'), ENT_QUOTES, 'UTF-8') ?></strong> |
              Year Left: <strong><?= htmlspecialchars((string)($row['year_left'] ?? 'Purged'), ENT_QUOTES, 'UTF-8') ?></strong> |
              School Number: <strong><?= htmlspecialchars((string)($row['school_number'] ?: 'Optional / Not provided'), ENT_QUOTES, 'UTF-8') ?></strong> |
              Submitter: <?= htmlspecialchars((string)($row['submitter_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)($row['submitter_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
