<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

$pdo = Database::getConnection();
VettingPurger::enforceRetentionCeilings($pdo, 30);

$flashMsg = '';
$flashErr = '';
if (isset($_GET['added']) && (int)$_GET['added'] > 0) {
    $addedId = (int)$_GET['added'];
    $flashMsg = "Business #{$addedId} was added directly and published live to the directory (no verification queue required).";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $adminAction = trim((string)($_POST['admin_action'] ?? ''));
    $targetId = (int)($_POST['business_id'] ?? 0);

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $flashErr = 'Invalid security token (CSRF). Please try again.';
    } elseif ($targetId > 0) {
        if ($adminAction === 'delete') {
            $bizNameStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id");
            $bizNameStmt->execute([':id' => $targetId]);
            $deletedName = (string)($bizNameStmt->fetchColumn() ?: "#{$targetId}");
            VettingPurger::deleteBusiness($pdo, $targetId);
            $flashMsg = "Permanently deleted business record '{$deletedName}' (#{$targetId}).";
        } elseif ($adminAction === 'unpublish') {
            VettingPurger::quarantineBusinessDpa($pdo, $targetId);
            $flashMsg = "Business #{$targetId} has been unpublished (moved to quarantined/taken-down state).";
        } elseif ($adminAction === 'approve') {
            VettingPurger::approveBusiness($pdo, $targetId);
            $flashMsg = "Business #{$targetId} approved and published (vetting credentials purged).";
        } elseif ($adminAction === 'reject') {
            VettingPurger::rejectBusiness($pdo, $targetId);
            $flashMsg = "Business #{$targetId} rejected (vetting credentials purged).";
        }
    }
}

$tokens = AntiSpamGate::generateFormTokens();

// Pending businesses with temporary vetting records
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
    WHERE b.status = 'pending'
    ORDER BY b.id DESC
");
$pendingRows = $pendingStmt->fetchAll();

// All existing businesses (for Edit / Unpublish / Delete management)
$searchQ = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$allSql = "
    SELECT
        b.id, b.name, b.slug, b.tagline, b.school_badge, b.status,
        b.city_county, b.area_neighborhood, b.phone_primary, b.website_url,
        sec.name AS sector_name, spec.name AS specialty_name, b.updated_at
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    WHERE 1=1
";
$allParams = [];
if ($searchQ !== '') {
    $allSql .= " AND (b.name LIKE :q OR b.website_url LIKE :q OR b.city_county LIKE :q OR spec.name LIKE :q)";
    $allParams[':q'] = '%' . $searchQ . '%';
}
if ($statusFilter !== '' && in_array($statusFilter, ['approved', 'pending', 'quarantined_dpa', 'rejected'], true)) {
    $allSql .= " AND b.status = :st";
    $allParams[':st'] = $statusFilter;
}
$allSql .= " ORDER BY b.id DESC";
$allStmt = $pdo->prepare($allSql);
$allStmt->execute($allParams);
$allBusinesses = $allStmt->fetchAll();

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
  <title>Admin Business Management &amp; Vetting Triage — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — Admin Management Console</h1>
          <p class="brand-subtitle">Edit, Unpublish, or Delete Listings &amp; Triage One-Time Vetting Queue</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/admin/add-business.php" class="btn btn-gold">+ Direct Add Business</a>
        <a href="/admin/login.php?logout=1" class="btn btn-outline-light">Sign Out</a>
        <a href="/" class="btn btn-outline-light">&larr; Public Directory</a>
      </nav>
    </div>
  </header>

  <main style="max-width:1320px;margin:1.75rem auto;padding:0 1.25rem;">

    <?php if ($flashMsg !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($flashErr !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <div class="vetting-privacy-banner" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
      <div>
        <strong style="color:#114B3E;">🔒 Vetting Data Disposal Audit Status:</strong>
        <span>Published Businesses: <strong><?= $approvedCount ?></strong></span> •
        <span>Retained Vetting Rows for Reviewed Businesses: <strong><?= $leakedVettingCount ?> (Always 0)</strong></span>
      </div>
      <span class="badge-verified">DPA Storage Limitation Enforced</span>
    </div>

    <!-- QUICK DIRECT-ADD KNOWN BUSHERIAN BUSINESS FROM URL (BYPASSES VETTING QUEUE) -->
    <section class="autofill-box" style="margin-bottom:2rem;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.5rem;">
        <div>
          <h2 style="margin:0;font-size:1.08rem;color:#7B1113;">⚡ Direct-Add Known Busherian Business (Skip Verification Process)</h2>
          <p style="margin:0.2rem 0 0 0;font-size:0.83rem;color:#4B5563;">
            Already know the business is Busherian? Paste its website URL below to auto-extract its details and publish straight to the directory, or add manually if it has no website.
          </p>
        </div>
        <a href="/admin/add-business.php" class="btn btn-outline" style="font-size:0.82rem;">+ Add Manually (No Website)</a>
      </div>
      <form method="GET" action="/admin/add-business.php" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;">
        <input type="url" name="url" class="input-control" style="flex:1;min-width:240px;" placeholder="https://www.knownbusherianbusiness.co.ke" required>
        <select name="school" class="select-control" style="width:auto;">
          <option value="AHS">AHS</option>
          <option value="AGHS">AGHS</option>
          <option value="BOTH">AHS &amp; AGHS</option>
        </select>
        <button type="submit" class="btn btn-crimson">✨ Fetch from URL &amp; Review</button>
      </form>
    </section>

    <!-- SECTION 1: PENDING VETTING QUEUE -->
    <section style="margin-bottom:2.25rem;">
      <h2 style="color:#7B1113;margin-bottom:0.75rem;">1. Pending Vetting Queue (<?= count($pendingRows) ?>)</h2>
      <?php if (empty($pendingRows)): ?>
        <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;color:#6B7280;font-size:0.9rem;">
          No pending submissions waiting in the vetting queue.
        </div>
      <?php else: ?>
        <div style="display:grid;gap:1rem;">
          <?php foreach ($pendingRows as $row):
              $bizId = (int)$row['id'];
          ?>
            <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                <div>
                  <h3 style="margin:0 0 0.25rem 0;"><?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <div style="font-size:0.84rem;color:#7B1113;font-weight:700;">
                    <?= htmlspecialchars((string)$row['sector_name'], ENT_QUOTES, 'UTF-8') ?> &rarr;
                    <?= htmlspecialchars((string)$row['specialty_name'], ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <p style="margin:0.4rem 0;font-size:0.88rem;color:#4B5563;"><?= htmlspecialchars((string)$row['tagline'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                  <a href="/admin/edit-business.php?id=<?= $bizId ?>" class="btn btn-outline">✏️ Edit</a>
                  <form method="POST" action="/admin/index.php" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="business_id" value="<?= $bizId ?>">
                    <input type="hidden" name="admin_action" value="approve">
                    <button type="submit" class="btn btn-green">✓ Approve &amp; Purge Vetting</button>
                  </form>
                  <form method="POST" action="/admin/index.php" style="display:inline;" onsubmit="return confirm('Reject this submission and purge its vetting record?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="business_id" value="<?= $bizId ?>">
                    <input type="hidden" name="admin_action" value="reject">
                    <button type="submit" class="btn btn-crimson">✕ Reject &amp; Purge</button>
                  </form>
                </div>
              </div>

              <div style="margin-top:0.85rem;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:0.75rem;font-size:0.83rem;">
                <strong style="color:#114B3E;">Temporary Vetting Credentials (Permanently deleted on Approve/Reject):</strong><br>
                School: <strong><?= htmlspecialchars((string)($row['school_code'] ?? $row['school_badge']), ENT_QUOTES, 'UTF-8') ?></strong> |
                House: <strong><?= htmlspecialchars((string)($row['house_name'] ?? 'Purged'), ENT_QUOTES, 'UTF-8') ?></strong> |
                Year Left: <strong><?= htmlspecialchars((string)($row['year_left'] ?? 'Purged'), ENT_QUOTES, 'UTF-8') ?></strong> |
                School Number: <strong><?= htmlspecialchars((string)($row['school_number'] ?: 'Optional / Not provided'), ENT_QUOTES, 'UTF-8') ?></strong>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- SECTION 2: MANAGE EXISTING / PUBLISHED BUSINESSES (EDIT, UNPUBLISH, DELETE) -->
    <section>
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:0.85rem;">
        <h2 style="color:#114B3E;margin:0;">2. Manage Existing Business Records (<?= count($allBusinesses) ?>)</h2>
        <form method="GET" action="/admin/index.php" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
          <input type="search" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" class="input-control" style="width:240px;" placeholder="Filter by name, URL, or city...">
          <select name="status" class="select-control" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved (Live)</option>
            <option value="quarantined_dpa" <?= $statusFilter === 'quarantined_dpa' ? 'selected' : '' ?>>Unpublished / Quarantined</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          </select>
          <button type="submit" class="btn btn-outline">Filter</button>
        </form>
      </div>

      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
        <table style="width:100%;border-collapse:collapse;font-size:0.86rem;">
          <thead>
            <tr style="background:#F9FAFB;border-bottom:1px solid #E5E7EB;text-align:left;">
              <th style="padding:0.75rem 1rem;">ID</th>
              <th style="padding:0.75rem 1rem;">Business Name &amp; Website</th>
              <th style="padding:0.75rem 1rem;">Category (Level 1 &rarr; Level 2)</th>
              <th style="padding:0.75rem 1rem;">Location</th>
              <th style="padding:0.75rem 1rem;">Status</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Actions (Edit / Takedown / Delete)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allBusinesses as $b):
                $bid = (int)$b['id'];
                $st = (string)$b['status'];
            ?>
              <tr style="border-bottom:1px solid #F3F4F6;">
                <td style="padding:0.75rem 1rem;font-weight:700;color:#6B7280;">#<?= $bid ?></td>
                <td style="padding:0.75rem 1rem;">
                  <div style="font-weight:800;color:#111827;"><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php if (!empty($b['website_url'])): ?>
                    <a href="<?= htmlspecialchars((string)$b['website_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="font-size:0.78rem;color:#7B1113;">
                      <?= htmlspecialchars((string)$b['website_url'], ENT_QUOTES, 'UTF-8') ?> &nearr;
                    </a>
                  <?php endif; ?>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <div style="font-weight:600;color:#114B3E;"><?= htmlspecialchars((string)$b['sector_name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div style="font-size:0.78rem;color:#6B7280;"><?= htmlspecialchars((string)$b['specialty_name'], ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <?= htmlspecialchars(trim($b['area_neighborhood'] . ', ' . $b['city_county'], ', '), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <span class="badge-verified" style="background:<?= $st === 'approved' ? '#ECFDF5' : '#FEF2F2' ?>;color:<?= $st === 'approved' ? '#059669' : '#DC2626' ?>;border-color:currentColor;">
                    <?= htmlspecialchars(strtoupper($st), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </td>
                <td style="padding:0.75rem 1rem;text-align:right;white-space:nowrap;">
                  <a href="/admin/edit-business.php?id=<?= $bid ?>" class="btn btn-outline" style="padding:0.35rem 0.65rem;font-size:0.78rem;">
                    ✏️ Edit
                  </a>
                  <?php if ($st === 'approved'): ?>
                    <form method="POST" action="/admin/index.php" style="display:inline;" onsubmit="return confirm('Unpublish / take down this business from the public directory?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="business_id" value="<?= $bid ?>">
                      <input type="hidden" name="admin_action" value="unpublish">
                      <button type="submit" class="btn btn-outline" style="padding:0.35rem 0.65rem;font-size:0.78rem;color:#D97706;border-color:#F59E0B;">
                        ⏸️ Take Down
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="POST" action="/admin/index.php" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                      <input type="hidden" name="business_id" value="<?= $bid ?>">
                      <input type="hidden" name="admin_action" value="approve">
                      <button type="submit" class="btn btn-green" style="padding:0.35rem 0.65rem;font-size:0.78rem;">
                        ✓ Republish
                      </button>
                    </form>
                  <?php endif; ?>

                  <form method="POST" action="/admin/index.php" style="display:inline;" onsubmit="return confirm('Permanently DELETE this business record from the database? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="business_id" value="<?= $bid ?>">
                    <input type="hidden" name="admin_action" value="delete">
                    <button type="submit" class="btn btn-crimson" style="padding:0.35rem 0.65rem;font-size:0.78rem;">
                      🗑️ Delete
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>
