<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();
$owner = OwnerAuth::requireOwner($pdo);

$profileSaved = '';
$profileError = '';
if (isset($_GET['deleted'])) {
    $profileSaved = 'The business listing has been permanently deleted from the directory.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_business') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    $delBizId = (int)($_POST['business_id'] ?? 0);
    $typedConfirm = trim((string)($_POST['confirm_business_name'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $profileError = 'Your page session expired. Please reload and try again.';
    } elseif ($delBizId <= 0 || !OwnerAuth::canOwnerEditBusiness($pdo, (int)$owner['id'], $delBizId)) {
        $profileError = 'You are not authorized to delete that listing.';
    } else {
        $nmStmt = $pdo->prepare("SELECT name FROM businesses WHERE id = :id LIMIT 1");
        $nmStmt->execute([':id' => $delBizId]);
        $actualName = trim((string)($nmStmt->fetchColumn() ?: ''));
        if ($actualName === '' || $typedConfirm !== $actualName) {
            $profileError = 'Deletion cancelled: You must type the exact business name to confirm permanent deletion.';
        } else {
            VettingPurger::deleteBusiness($pdo, $delBizId);
            $profileSaved = "Permanently deleted '{$actualName}' from the directory.";
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $profileError = 'Your page session expired. Please reload and try again.';
    } else {
        $newPw = (string)($_POST['new_password'] ?? '');
        if ($newPw !== '' && mb_strlen($newPw) < 8) {
            $profileError = 'Your new password must be at least 8 characters long.';
        } else {
            $owner = OwnerAuth::upsertOwner(
                $pdo,
                (string)$owner['email'],
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['phone'] ?? ''))
            );
            if ($newPw !== '') {
                // setOwnerPassword bumps auth_version and revokes all prior persistent sessions across devices (#165);
                // re-establish a fresh session for the current browser so this active owner stays signed in.
                OwnerAuth::setOwnerPassword($pdo, (int)$owner['id'], $newPw);
                $freshOwner = OwnerAuth::getOwnerById($pdo, (int)$owner['id']) ?? $owner;
                OwnerAuth::establishOwnerSession($freshOwner, true, $pdo);
                $owner = $freshOwner;
                $profileSaved = 'Your owner profile and password have been saved. All other signed-in devices have been signed out for your security.';
            } else {
                $profileSaved = 'Your owner profile details have been updated.';
            }
        }
    }
}

$myBusinesses = OwnerAuth::getBusinessesForOwner($pdo, (int)$owner['id']);
$domainClaimSuggestions = OwnerAuth::getCorporateDomainClaimSuggestions($pdo, (string)$owner['email'], (int)$owner['id']);
$tokens = AntiSpamGate::generateFormTokens();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Businesses — Busherian Biasharas Owner Portal</title>
<?= bbiz_head_meta_tags('My Businesses — Busherian Biasharas Owner Portal', 'Manage and update your verified Busherian Biashara listings.', '/owner/index.php') ?>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Busherian Biasharas — My Businesses</h1>
          <p class="brand-subtitle" data-testid="owner-signed-in-email">Signed in as <?= htmlspecialchars((string)$owner['email'], ENT_QUOTES, 'UTF-8') ?> (You stay signed in for 30 days)</p>
        </div>
      </a>
      <nav class="header-actions" style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
        <a href="/submit.php" class="btn btn-gold">+ Register Another Biashara</a>
        <a href="/" class="btn btn-outline-light">Public Directory</a>
        <a href="/owner/logout.php" data-testid="owner-logout-link" class="btn btn-outline-light">Sign Out</a>
        <form method="POST" action="/owner/logout.php" style="margin:0;display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="scope" value="all">
          <button type="submit" data-testid="owner-logout-all-btn" class="btn btn-outline-light" style="cursor:pointer;" title="Immediately sign out this browser and every other phone or computer signed into your Owner Account">
            Sign Out All Devices
          </button>
        </form>
      </nav>
    </div>
  </header>

  <main style="max-width:1060px;margin:1.75rem auto;padding:0 1.25rem;">
    <?php if ($profileSaved !== ''): ?>
      <div data-testid="owner-flash-success" style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:0.9rem 1.2rem;margin-bottom:1.25rem;color:#065F46;font-weight:600;">
        ✓ <?= htmlspecialchars($profileSaved, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>
    <?php if ($profileError !== ''): ?>
      <div data-testid="owner-flash-error" style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:0.9rem 1.2rem;margin-bottom:1.25rem;color:#991B1B;font-weight:600;">
        ⚠️ <?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Section 1: Linked Businesses -->
    <section style="margin-bottom:2rem;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.85rem;">
        <h2 data-testid="owner-linked-count" style="margin:0;color:#114B3E;font-size:1.2rem;">Your Linked Business Listings (<?= count($myBusinesses) ?>)</h2>
        <a href="/submit.php" class="btn btn-crimson" style="font-size:0.82rem;">+ List Another Business</a>
      </div>

      <?php if (empty($myBusinesses)): ?>
        <div data-testid="owner-no-linked-businesses" style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.5rem;color:#374151;">
          <h3 style="margin:0 0 0.4rem 0;color:#7B1113;">No Business Linked to <?= htmlspecialchars((string)$owner['email'], ENT_QUOTES, 'UTF-8') ?> Yet</h3>
          <p style="margin:0 0 0.9rem 0;font-size:0.88rem;color:#4B5563;">
            For security, listings only link automatically when you sign in using the exact owner or public business email address on file, or when you open a single-use Owner Claim Link shared with you by the directory team.
          </p>
          <a href="/submit.php" class="btn btn-green">+ Register Your Busherian Business</a>
        </div>
      <?php else: ?>
        <div style="display:grid;gap:1rem;">
          <?php foreach ($myBusinesses as $biz):
              $bid = (int)$biz['id'];
          ?>
            <div data-testid="owner-business-card" data-business-id="<?= $bid ?>" style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;box-shadow:0 2px 8px rgba(0,0,0,0.04);display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
              <div>
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                  <span style="font-weight:800;color:#114B3E;font-size:0.85rem;">#<?= (int)$biz['business_number'] ?></span>
                  <h3 style="margin:0;font-size:1.1rem;color:#111827;"><?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                  <span class="badge-verified"><?= htmlspecialchars((string)$biz['school_badge'], ENT_QUOTES, 'UTF-8') ?></span>
                  <span style="font-size:0.74rem;background:#F3F4F6;color:#374151;padding:0.15rem 0.5rem;border-radius:999px;font-weight:600;">
                    Status: <?= htmlspecialchars(strtoupper((string)$biz['status']), ENT_QUOTES, 'UTF-8') ?>
                  </span>
                </div>
                <div style="font-size:0.83rem;color:#7B1113;font-weight:700;margin-top:0.25rem;">
                  <?= htmlspecialchars((string)$biz['sector_name'], ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars((string)$biz['specialty_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <p style="margin:0.35rem 0 0 0;font-size:0.86rem;color:#4B5563;"><?= htmlspecialchars((string)$biz['tagline'], ENT_QUOTES, 'UTF-8') ?></p>
              </div>
              <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="/owner/edit-business.php?id=<?= $bid ?>" data-testid="owner-edit-business-link" class="btn btn-green">✏️ Edit Business Details</a>
                <button type="button" class="btn btn-outline" style="color:#991B1B;border-color:#FCA5A5;"
                  data-open-delete-modal="1"
                  data-delete-biz-id="<?= $bid ?>"
                  data-delete-biz-name="<?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?>"
                  data-delete-action-url="/owner/index.php">
                  🗑️ Delete Listing
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($domainClaimSuggestions)): ?>
        <div data-testid="owner-domain-claim-suggestions" style="margin-top:1rem;background:#FFFBEB;border:1px solid #F59E0B;border-radius:12px;padding:1.1rem 1.25rem;color:#78350F;">
          <div style="font-weight:800;font-size:0.9rem;margin-bottom:0.35rem;">
            🔒 Unclaimed Directory Listings Sharing Your Organization Domain
          </div>
          <p style="margin:0 0 0.65rem 0;font-size:0.83rem;line-height:1.5;">
            To protect alumni businesses from unauthorized edits by non-owner staff mailboxes, sharing a company email domain does not automatically grant edit access. To manage a listing below, sign in with its exact listed business email or request a verified Owner Claim Link from the directory team:
          </p>
          <ul style="margin:0;padding-left:1.25rem;font-size:0.84rem;">
            <?php foreach ($domainClaimSuggestions as $sug): ?>
              <li style="margin-bottom:0.25rem;">
                <strong>#<?= (int)$sug['business_number'] ?> <?= htmlspecialchars((string)$sug['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                (<?= htmlspecialchars((string)$sug['school_badge'], ENT_QUOTES, 'UTF-8') ?> — domain <?= htmlspecialchars((string)$sug['matched_domain'], ENT_QUOTES, 'UTF-8') ?>)
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

    <!-- Section 2: Persistent Owner Contact Profile -->
    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.35rem;">
      <h3 style="margin:0 0 0.3rem 0;color:#7B1113;font-size:1rem;">Persistent Owner Contact Details &amp; Security</h3>
      <p style="margin:0 0 0.9rem 0;font-size:0.82rem;color:#6B7280;">
        Saved to your Owner Account so you don't have to re-type your details when registering additional businesses. Updating your password automatically signs out any other browsers or phones. (For your privacy, your House, Year Left, and School Number are never stored here.)
      </p>
      <form method="POST" action="/owner/index.php" data-testid="owner-profile-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.85rem;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="update_profile">
        <div>
          <label style="font-weight:700;font-size:0.8rem;display:block;margin-bottom:0.25rem;">Signed-In Email Address</label>
          <input type="email" class="input-control" value="<?= htmlspecialchars((string)$owner['email'], ENT_QUOTES, 'UTF-8') ?>" disabled style="background:#F3F4F6;">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.8rem;display:block;margin-bottom:0.25rem;">Your Full Name</label>
          <input type="text" name="full_name" class="input-control" value="<?= htmlspecialchars((string)($owner['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Your Name">
        </div>
        <div>
          <label style="font-weight:700;font-size:0.8rem;display:block;margin-bottom:0.25rem;">Your Direct Phone</label>
          <input type="text" name="phone" class="input-control" value="<?= htmlspecialchars((string)($owner['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="+2547...">
        </div>
        <div>
          <label for="new_password" style="font-weight:700;font-size:0.8rem;display:block;margin-bottom:0.25rem;">
            Set / Update Password <?= !empty($owner['password_hash']) ? '<span style="color:#059669;">(✓ Password Set)</span>' : '<span style="color:#6B7280;">(Optional — for other devices)</span>' ?>
          </label>
          <input type="password" id="new_password" name="new_password" data-testid="owner-new-password" class="input-control" minlength="8" autocomplete="new-password" placeholder="At least 8 characters...">
        </div>
        <div>
          <button type="submit" data-testid="owner-save-profile-btn" class="btn btn-outline" style="width:100%;justify-content:center;">💾 Save Owner Profile &amp; Password</button>
        </div>
      </form>
    </section>
    <?= bbiz_render_github_delete_modal($tokens['csrf_token'], '/owner/index.php', 0, '', 'action', 'delete_business') ?>
  </main>
</body>
</html>
