<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

$pdo = Database::getConnection();
VettingPurger::enforceRetentionCeilings($pdo, 30);

$flashMsg = '';
$flashErr = '';
if (isset($_GET['added']) && (int)$_GET['added'] > 0) {
    $addedId = (int)$_GET['added'];
    $addedRowStmt = $pdo->prepare("SELECT business_number, business_id, name FROM businesses WHERE id = :id");
    $addedRowStmt->execute([':id' => $addedId]);
    $addedRow = $addedRowStmt->fetch();
    if ($addedRow) {
        $flashMsg = "Added '{$addedRow['name']}' as Business #{$addedRow['business_number']} (ID: {$addedRow['business_id']}) and published live.";
    } else {
        $flashMsg = "Business was added directly and published live to the directory.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $adminAction = trim((string)($_POST['admin_action'] ?? ''));
    $targetId = (int)($_POST['business_id'] ?? 0);

    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $flashErr = 'Invalid security token (CSRF). Please try again.';
    } elseif ($adminAction === 'save_google_sso') {
        $gClientId = trim((string)($_POST['google_client_id'] ?? ''));
        $gClientSecret = trim((string)($_POST['google_client_secret'] ?? ''));
        Database::setSetting($pdo, 'GOOGLE_CLIENT_ID', $gClientId);
        Database::setSetting($pdo, 'GOOGLE_CLIENT_SECRET', $gClientSecret);
        $flashMsg = $gClientId !== ''
            ? 'Google SSO (OAuth 2.0) credentials saved! The "Continue with Google (SSO)" button is now active on /owner/login.php.'
            : 'Google SSO credentials cleared.';
    } elseif ($targetId > 0) {
        if ($adminAction === 'delete') {
            $bizNameStmt = $pdo->prepare("SELECT name, business_number, business_id FROM businesses WHERE id = :id");
            $bizNameStmt->execute([':id' => $targetId]);
            $delInfo = $bizNameStmt->fetch();
            $typedConfirm = trim((string)($_POST['confirm_business_name'] ?? ''));
            if (!$delInfo || $typedConfirm !== trim((string)$delInfo['name'])) {
                $flashErr = 'Deletion cancelled: You must type the exact business name to confirm permanent deletion.';
            } else {
                VettingPurger::deleteBusiness($pdo, $targetId);
                $totalNow = (int)$pdo->query("SELECT COUNT(*) FROM businesses")->fetchColumn();
                $flashMsg = "Permanently deleted '{$delInfo['name']}' (was #{$delInfo['business_number']}, ID: {$delInfo['business_id']}). Remaining {$totalNow} businesses renumbered sequentially (1–{$totalNow}).";
            }
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
        b.whatsapp_number, b.email_public, b.owner_email,
        sec.name AS sector_name,
        spec.id AS specialty_id, spec.name AS specialty_name, spec.status AS specialty_status,
        v.school_code, v.house_name, v.year_left, v.school_number,
        v.submitter_name, v.submitter_email, v.submitter_phone, v.created_at AS submitted_at
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
        b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.school_badge, b.status,
        b.city_county, b.area_neighborhood, b.phone_primary, b.whatsapp_number, b.email_public, b.owner_email, b.website_url,
        sec.name AS sector_name, spec.name AS specialty_name, b.updated_at
    FROM businesses b
    INNER JOIN categories sec ON sec.id = b.sector_id
    INNER JOIN categories spec ON spec.id = b.specialty_id
    WHERE 1=1
";
$allParams = [];
if ($searchQ !== '') {
    $allSql .= " AND (b.name LIKE :q1 OR b.website_url LIKE :q2 OR b.city_county LIKE :q3 OR spec.name LIKE :q4 OR b.business_id LIKE :q5)";
    $qVal = '%' . $searchQ . '%';
    $allParams[':q1'] = $qVal;
    $allParams[':q2'] = $qVal;
    $allParams[':q3'] = $qVal;
    $allParams[':q4'] = $qVal;
    $allParams[':q5'] = $qVal;
}
if ($statusFilter !== '' && in_array($statusFilter, ['approved', 'pending', 'quarantined_dpa', 'rejected'], true)) {
    $allSql .= " AND b.status = :st";
    $allParams[':st'] = $statusFilter;
}
$allSql .= " ORDER BY b.business_number ASC, b.id ASC";
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
<?= bbiz_head_meta_tags('Admin Console — Busherian Biasharas', 'Busherian Biasharas — Verified Alumni Business Directory of the Alliances (AHS & AGHS).', '/admin/index.php') ?>
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
        <?php if (\BusherianBiasharas\Services\PreprodSandbox::isPreprod()): ?>
          <a href="/admin/sandbox.php" class="btn btn-outline-light">🛠️ Sandbox &amp; Outbox</a>
        <?php else: ?>
          <a href="<?= htmlspecialchars(\BusherianBiasharas\Services\PreprodSandbox::generateProdToPreprodHandoffUrl('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light">🧪 Pre-Prod Preview</a>
        <?php endif; ?>
        <a href="/admin/metrics.php" class="btn btn-outline-light">📊 Usage Metrics</a>
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

    <!-- GOOGLE SSO CONFIGURATION BOX -->
    <?php
      $savedGoogleClientId = OwnerAuth::getGoogleClientId($pdo);
      $savedGoogleSecret = OwnerAuth::getGoogleClientSecret($pdo);
      $callbackUrl = rtrim(Database::env('APP_URL', 'https://www.busherianbiasharas.com'), '/') . '/owner/google-callback.php';
    ?>
    <section id="google-sso-config" style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
      <details <?= $savedGoogleClientId === '' ? '' : '' ?>>
        <summary style="cursor:pointer;font-weight:800;color:#114B3E;font-size:0.92rem;">
          🌐 Owner Google SSO Configuration <?= $savedGoogleClientId !== '' ? '<span class="badge-verified" style="margin-left:0.5rem;">✓ Active</span>' : '<span style="font-size:0.78rem;color:#6B7280;font-weight:600;margin-left:0.5rem;">(Optional — Click to configure Google OAuth Client ID &amp; Secret)</span>' ?>
        </summary>
        <div style="margin-top:0.85rem;font-size:0.82rem;color:#4B5563;">
          <p style="margin:0 0 0.6rem 0;">
            To enable the <strong>Continue with Google (SSO)</strong> button on <code>/owner/login.php</code>, create a Web Application OAuth Client in <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" style="color:#7B1113;font-weight:700;">Google Cloud Console &rarr; Credentials</a> with Authorized Redirect URI:
            <code style="background:#F3F4F6;padding:0.15rem 0.4rem;border-radius:4px;user-select:all;"><?= htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8') ?></code>
          </p>
          <form method="POST" action="/admin/index.php" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.65rem;align-items:end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="admin_action" value="save_google_sso">
            <div>
              <label style="font-weight:700;display:block;margin-bottom:0.2rem;">Google Client ID</label>
              <input type="text" name="google_client_id" class="input-control" value="<?= htmlspecialchars($savedGoogleClientId, ENT_QUOTES, 'UTF-8') ?>" placeholder="xxxx.apps.googleusercontent.com">
            </div>
            <div>
              <label style="font-weight:700;display:block;margin-bottom:0.2rem;">Google Client Secret</label>
              <input type="password" name="google_client_secret" class="input-control" value="<?= htmlspecialchars($savedGoogleSecret, ENT_QUOTES, 'UTF-8') ?>" placeholder="GOCSPX-...">
            </div>
            <div>
              <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;">💾 Save Google SSO Settings</button>
            </div>
          </form>
        </div>
      </details>
    </section>

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

              <?php
                $subName = trim((string)($row['submitter_name'] ?? ''));
                $subEmail = trim((string)($row['submitter_email'] ?? '')) ?: trim((string)($row['owner_email'] ?? '')) ?: trim((string)($row['email_public'] ?? ''));
                $subPhone = trim((string)($row['submitter_phone'] ?? '')) ?: trim((string)($row['phone_primary'] ?? '')) ?: trim((string)($row['whatsapp_number'] ?? ''));
                $waDigits = preg_replace('/\D+/', '', $subPhone);
                if (str_starts_with($waDigits, '0') && strlen($waDigits) === 10) {
                    $waDigits = '254' . substr($waDigits, 1);
                }
              ?>
              <div style="margin-top:0.85rem;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px;padding:0.8rem 0.95rem;font-size:0.85rem;">
                <div style="font-weight:800;color:#92400E;margin-bottom:0.35rem;">
                  👤 Person Requesting Review (Submitter Contact Details):
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:1.1rem;align-items:center;color:#1F2937;">
                  <span>
                    Name: <strong><?= htmlspecialchars($subName !== '' ? $subName : 'Not provided', ENT_QUOTES, 'UTF-8') ?></strong>
                  </span>
                  <span>
                    Email:
                    <?php if ($subEmail !== ''): ?>
                      <a href="mailto:<?= htmlspecialchars($subEmail, ENT_QUOTES, 'UTF-8') ?>" style="color:#7B1113;font-weight:700;text-decoration:underline;">
                        ✉️ <?= htmlspecialchars($subEmail, ENT_QUOTES, 'UTF-8') ?>
                      </a>
                    <?php else: ?>
                      <strong>Not provided</strong>
                    <?php endif; ?>
                  </span>
                  <span>
                    Phone:
                    <?php if ($subPhone !== ''): ?>
                      <a href="tel:<?= htmlspecialchars($subPhone, ENT_QUOTES, 'UTF-8') ?>" style="color:#114B3E;font-weight:700;text-decoration:underline;">
                        📞 <?= htmlspecialchars($subPhone, ENT_QUOTES, 'UTF-8') ?>
                      </a>
                      <?php if ($waDigits !== ''): ?>
                        &nbsp;<a href="https://wa.me/<?= htmlspecialchars($waDigits, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#DCFCE7;color:#065F46;border:1px solid #86EFAC;border-radius:6px;padding:0.1rem 0.45rem;font-size:0.76rem;font-weight:700;text-decoration:none;">💬 WhatsApp</a>
                      <?php endif; ?>
                    <?php else: ?>
                      <strong>Not provided</strong>
                    <?php endif; ?>
                  </span>
                </div>
              </div>

              <div style="margin-top:0.6rem;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:0.75rem 0.95rem;font-size:0.83rem;">
                <strong style="color:#114B3E;">🎓 Temporary Vetting Credentials (Permanently deleted on Approve/Reject):</strong><br>
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
    <?php
      $allowedPerPage = [10, 25, 50, 100];
      $perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowedPerPage, true)
          ? (int)$_GET['per_page']
          : 10;
      $totalMatchingBusinesses = count($allBusinesses);
      $totalPages = max(1, (int)ceil($totalMatchingBusinesses / $perPage));
      $currentPage = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
      $pageOffset = ($currentPage - 1) * $perPage;
      $pagedBusinesses = array_slice($allBusinesses, $pageOffset, $perPage);
      $showingFrom = $totalMatchingBusinesses === 0 ? 0 : ($pageOffset + 1);
      $showingTo = min($totalMatchingBusinesses, $pageOffset + count($pagedBusinesses));

      $buildAdminPageUrl = static function (int $targetPage) use ($searchQ, $statusFilter, $perPage): string {
          $qp = ['per_page' => $perPage, 'page' => $targetPage];
          if ($searchQ !== '') {
              $qp['q'] = $searchQ;
          }
          if ($statusFilter !== '') {
              $qp['status'] = $statusFilter;
          }
          return '/admin/index.php?' . http_build_query($qp) . '#manage-businesses';
      };
    ?>
    <section id="manage-businesses">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:0.85rem;">
        <div>
          <h2 style="color:#114B3E;margin:0;">2. Manage Existing Business Records (<?= $totalMatchingBusinesses ?>)</h2>
          <div style="font-size:0.8rem;color:#6B7280;margin-top:0.2rem;">
            Showing <strong><?= $showingFrom ?>&ndash;<?= $showingTo ?></strong> of <strong><?= $totalMatchingBusinesses ?></strong> business <?= $totalMatchingBusinesses === 1 ? 'record' : 'records' ?>
          </div>
        </div>
        <form method="GET" action="/admin/index.php#manage-businesses" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
          <input type="search" name="q" value="<?= htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8') ?>" class="input-control" style="width:230px;" placeholder="Filter by name, URL, or city...">
          <select name="status" class="select-control" style="width:auto;">
            <option value="">All Statuses</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved (Live)</option>
            <option value="quarantined_dpa" <?= $statusFilter === 'quarantined_dpa' ? 'selected' : '' ?>>Unpublished / Quarantined</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          </select>
          <label for="admin-per-page" style="font-size:0.8rem;font-weight:700;color:#374151;">Show:</label>
          <select id="admin-per-page" name="per_page" class="select-control" style="width:auto;" aria-label="Records per page" onchange="this.form.submit()">
            <?php foreach ($allowedPerPage as $sizeOpt): ?>
              <option value="<?= $sizeOpt ?>" <?= $perPage === $sizeOpt ? 'selected' : '' ?>><?= $sizeOpt ?> per page</option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-outline">Filter</button>
        </form>
      </div>

      <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;overflow-x:auto;box-shadow:0 2px 6px rgba(0,0,0,0.04);">
        <table style="width:100%;border-collapse:collapse;font-size:0.86rem;">
          <thead>
            <tr style="background:#F9FAFB;border-bottom:1px solid #E5E7EB;text-align:left;">
              <th style="padding:0.75rem 1rem;">No. / Unique ID</th>
              <th style="padding:0.75rem 1rem;">Business Name &amp; Website</th>
              <th style="padding:0.75rem 1rem;">Category (Level 1 &rarr; Level 2)</th>
              <th style="padding:0.75rem 1rem;">Location</th>
              <th style="padding:0.75rem 1rem;">Status</th>
              <th style="padding:0.75rem 1rem;text-align:right;">Actions (Edit / Takedown / Delete)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pagedBusinesses)): ?>
              <tr>
                <td colspan="6" style="padding:1.5rem;text-align:center;color:#6B7280;">No matching business records found.</td>
              </tr>
            <?php endif; ?>
            <?php foreach ($pagedBusinesses as $b):
                $bid = (int)$b['id'];
                $st = (string)$b['status'];
            ?>
              <tr style="border-bottom:1px solid #F3F4F6;">
                <td style="padding:0.75rem 1rem;">
                  <div style="font-weight:800;color:#114B3E;font-size:0.92rem;">#<?= (int)$b['business_number'] ?></div>
                  <div style="font-family:monospace;font-size:0.72rem;color:#6B7280;" title="Immutable unique business_id"><?= htmlspecialchars((string)($b['business_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                </td>
                <td style="padding:0.75rem 1rem;">
                  <div style="font-weight:800;color:#111827;"><?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?></div>
                  <?php if (!empty($b['website_url'])): ?>
                    <a href="<?= htmlspecialchars((string)$b['website_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" style="font-size:0.78rem;color:#7B1113;">
                      <?= htmlspecialchars((string)$b['website_url'], ENT_QUOTES, 'UTF-8') ?> &nearr;
                    </a>
                  <?php endif; ?>
                  <?php
                    $linkedOwners = OwnerAuth::getOwnersForBusiness($pdo, $bid);
                    $claimUrl = OwnerAuth::generateClaimInviteUrl($bid);
                  ?>
                  <div style="margin-top:0.25rem;font-size:0.74rem;color:#065F46;">
                    <?php if (!empty($linkedOwners)): ?>
                      👤 Linked Owner: <strong><?= htmlspecialchars(implode(', ', array_column($linkedOwners, 'email')), ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php elseif (!empty($b['owner_email'])): ?>
                      👤 Pre-Assigned Owner: <strong><?= htmlspecialchars((string)$b['owner_email'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php else: ?>
                      <span style="color:#6B7280;">👤 Auto-links on domain/email login or via DM Claim Link</span>
                    <?php endif; ?>
                  </div>
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
                  <?php
                    $waPhoneDigits = preg_replace('/\D+/', '', (string)($b['whatsapp_number'] ?: ($b['phone_primary'] ?? ''))) ?? '';
                    $waAccessMsg = "Hi! Use this verified Owner Access Link to set your password and edit {$b['name']} on Busherian Biasharas anytime: {$claimUrl}";
                    $waShareHref = $waPhoneDigits !== ''
                        ? ('https://wa.me/' . $waPhoneDigits . '?text=' . rawurlencode($waAccessMsg))
                        : ('https://wa.me/?text=' . rawurlencode($waAccessMsg));
                  ?>
                  <a href="<?= htmlspecialchars($waShareHref, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline" style="padding:0.35rem 0.6rem;font-size:0.75rem;color:#065F46;border-color:#059669;background:#ECFDF5;" title="Send 1-Click Set-Password &amp; Edit Link via WhatsApp">
                    💬 WhatsApp Link
                  </a>
                  <button type="button" data-copy-claim="<?= htmlspecialchars($claimUrl, ENT_QUOTES, 'UTF-8') ?>" data-biz-name="<?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline" style="padding:0.35rem 0.65rem;font-size:0.75rem;color:#114B3E;border-color:#114B3E;" title="Copy 1-Click Owner Claim &amp; Password Setup Link">
                    🔗 Claim Link
                  </button>
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

                  <button type="button" class="btn btn-crimson" style="padding:0.35rem 0.65rem;font-size:0.78rem;"
                    data-open-delete-modal="1"
                    data-delete-biz-id="<?= $bid ?>"
                    data-delete-biz-name="<?= htmlspecialchars((string)$b['name'], ENT_QUOTES, 'UTF-8') ?>"
                    data-delete-action-url="/admin/index.php">
                    🗑️ Delete Listing
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <nav id="admin-pagination" aria-label="Admin Business Records Pagination" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-top:0.95rem;background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:0.65rem 1rem;">
        <div style="font-size:0.82rem;color:#4B5563;">
          Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong>
          <span style="color:#9CA3AF;">•</span>
          Showing <strong><?= $showingFrom ?>&ndash;<?= $showingTo ?></strong> of <strong><?= $totalMatchingBusinesses ?></strong>
        </div>
        <div style="display:flex;gap:0.35rem;align-items:center;flex-wrap:wrap;">
          <?php if ($currentPage > 1): ?>
            <a href="<?= htmlspecialchars($buildAdminPageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline" style="padding:0.3rem 0.65rem;font-size:0.8rem;">&larr; Prev</a>
          <?php else: ?>
            <span class="btn btn-outline" style="padding:0.3rem 0.65rem;font-size:0.8rem;opacity:0.45;pointer-events:none;">&larr; Prev</span>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++):
              if ($totalPages > 7 && $p !== 1 && $p !== $totalPages && abs($p - $currentPage) > 2) {
                  if ($p === 2 || $p === $totalPages - 1) {
                      echo '<span style="padding:0 0.25rem;color:#9CA3AF;">&hellip;</span>';
                  }
                  continue;
              }
          ?>
            <?php if ($p === $currentPage): ?>
              <span aria-current="page" class="btn btn-green" style="padding:0.3rem 0.65rem;font-size:0.8rem;"><?= $p ?></span>
            <?php else: ?>
              <a href="<?= htmlspecialchars($buildAdminPageUrl($p), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline" style="padding:0.3rem 0.65rem;font-size:0.8rem;"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($currentPage < $totalPages): ?>
            <a href="<?= htmlspecialchars($buildAdminPageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline" style="padding:0.3rem 0.65rem;font-size:0.8rem;">Next &rarr;</a>
          <?php else: ?>
            <span class="btn btn-outline" style="padding:0.3rem 0.65rem;font-size:0.8rem;opacity:0.45;pointer-events:none;">Next &rarr;</span>
          <?php endif; ?>
        </div>
      </nav>
    </section>
    <?= bbiz_render_github_delete_modal($tokens['csrf_token'], '/admin/index.php', 0, '', 'admin_action', 'delete') ?>
  </main>
  <script>
    document.querySelectorAll('[data-copy-claim]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const url = btn.getAttribute('data-copy-claim');
        const name = btn.getAttribute('data-biz-name') || 'Business';
        try {
          await navigator.clipboard.writeText(url);
          const orig = btn.textContent;
          btn.textContent = '✓ Copied Link!';
          setTimeout(() => { btn.textContent = orig; }, 2200);
        } catch (_) {
          window.prompt('Copy 1-Click Owner Claim Link for ' + name + ':', url);
        }
      });
    });
  </script>
</body>
</html>
