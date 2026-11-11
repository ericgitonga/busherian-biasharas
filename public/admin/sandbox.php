<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AdminAuth;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\PreprodSandbox;

bbiz_send_security_headers(false);
AdminAuth::requireAdmin();

// If visited on Live Prod, never expose DB reset/dummy-seeding on production — hand off cleanly to /admin/preprod!
if (!PreprodSandbox::isPreprod()) {
    $handoffUrl = PreprodSandbox::generateProdToPreprodHandoffUrl('/admin/sandbox.php');
    if (!headers_sent()) {
        header('Location: ' . $handoffUrl);
    }
    exit;
}

$pdo = Database::getConnection();
$flashSuccess = '';
$flashError = '';
$selfTestResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sandbox_action'])) {
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if (!AntiSpamGate::verifyCsrfToken($csrf)) {
        $flashError = 'Invalid or expired security token (CSRF). Please try again.';
    } else {
        $act = trim((string)$_POST['sandbox_action']);
        try {
            if ($act === 'seed_dummies') {
                $res = PreprodSandbox::seedDummyListings($pdo);
                $flashSuccess = sprintf(
                    'Seeded dummy test listings into Pre-Prod SQLite: +%d approved, +%d pending in vetting queue (%d already existed).',
                    $res['approved_added'],
                    $res['pending_added'],
                    $res['skipped']
                );
            } elseif ($act === 'reset_clean_seed') {
                $res = PreprodSandbox::resetDatabaseToCleanSeed();
                $pdo = Database::getConnection();
                $flashSuccess = sprintf(
                    'Reset Pre-Prod SQLite database to clean baseline (%d approved seed businesses, %d dummy photo upload(s) cleared). Ready for E2E test runs!',
                    $res['approved_count'],
                    $res['removed_uploads']
                );
            } elseif ($act === 'clone_prod_snapshot') {
                $res = PreprodSandbox::cloneApprovedFromProductionMysql($pdo);
                $flashSuccess = sprintf(
                    'Cloned read-only snapshot from Live Production MySQL into Pre-Prod SQLite (%d categories, %d approved listings). Live Production was NOT modified.',
                    $res['categories_cloned'],
                    $res['businesses_cloned']
                );
            } elseif ($act === 'clear_outbox') {
                $pdo->exec('DELETE FROM email_outbox');
                $flashSuccess = 'Cleared simulated email outbox in Pre-Prod.';
            } elseif ($act === 'run_selftests') {
                $selfTestResults = PreprodSandbox::runSelfTests($pdo);
                $passedCount = count(array_filter($selfTestResults, static fn(array $r): bool => $r['passed']));
                $flashSuccess = sprintf('Pre-Prod Self-Test Suite completed: %d / %d checks passed.', $passedCount, count($selfTestResults));
            }
        } catch (Throwable $e) {
            $flashError = 'Sandbox action error: ' . $e->getMessage();
        }
    }
}

if ($selfTestResults === null && !empty($_GET['autotest'])) {
    $selfTestResults = PreprodSandbox::runSelfTests($pdo);
}

$tokens = AntiSpamGate::generateFormTokens();
$approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();
$pendingCount  = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'pending'")->fetchColumn();
$dummyCount    = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE name LIKE '[DUMMY%'")->fetchColumn();
$ownersCount   = (int)$pdo->query("SELECT COUNT(*) FROM business_owners")->fetchColumn();

$outboxRows = $pdo->query("SELECT * FROM email_outbox ORDER BY id DESC LIMIT 25")->fetchAll();
$versionFile = dirname(__DIR__, 2) . '/VERSION';
$currentVersion = is_file($versionFile) ? trim((string)file_get_contents($versionFile)) : 'dev';
$sqlitePath = Database::env('DB_SQLITE_PATH', 'storage/preprod.sqlite');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <title>Pre-Prod Sandbox &amp; Test Harness — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="brand-group">
        <div class="brand-crest">BB</div>
        <div>
          <h1 class="brand-title">Pre-Prod Sandbox Control Panel (v<?= htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8') ?>)</h1>
          <p class="brand-subtitle">Dummy Listings · Read-Only Prod Snapshot · Clean DB Reset · Simulated Email Outbox · Self-Tests</p>
        </div>
      </a>
      <nav class="header-actions">
        <a href="/admin/index.php" class="btn btn-outline-light">&larr; Pre-Prod Admin Queue</a>
        <a href="/submit.php" class="btn btn-gold">+ Test Submit Form</a>
        <a href="/" class="btn btn-outline-light">Pre-Prod Directory</a>
        <a href="/admin/sandbox.php?preprod_lock=1" class="btn btn-outline-light">Lock Preview</a>
      </nav>
    </div>
  </header>

  <main style="max-width:1280px;margin:1.75rem auto;padding:0 1.25rem;">
    <?php if ($flashSuccess !== ''): ?>
      <div style="background:#ECFDF5;border:2px solid #059669;border-radius:12px;padding:0.95rem 1.2rem;margin-bottom:1.2rem;color:#065F46;font-weight:700;">
        ✓ <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
      <div style="background:#FEF2F2;border:2px solid #DC2626;border-radius:12px;padding:0.95rem 1.2rem;margin-bottom:1.2rem;color:#991B1B;font-weight:700;">
        ⚠️ <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- KPI Environment Status Strip -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;margin-bottom:1.5rem;">
      <div style="background:#fff;border:1px solid #E5E7EB;border-left:5px solid #D97706;border-radius:12px;padding:1rem;">
        <div style="font-size:0.75rem;color:#6B7280;text-transform:uppercase;font-weight:700;">Environment &amp; Version</div>
        <div style="font-size:1.25rem;font-weight:800;color:#92400E;margin-top:0.2rem;">PRE-PROD · v<?= htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="font-size:0.76rem;color:#4B5563;margin-top:0.2rem;">Driver: <strong><?= htmlspecialchars(strtoupper(Database::getActiveDriver()), ENT_QUOTES, 'UTF-8') ?></strong> (Isolated)</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-left:5px solid #114B3E;border-radius:12px;padding:1rem;">
        <div style="font-size:0.75rem;color:#6B7280;text-transform:uppercase;font-weight:700;">Pre-Prod Listings</div>
        <div style="font-size:1.25rem;font-weight:800;color:#114B3E;margin-top:0.2rem;"><?= $approvedCount ?> Approved · <?= $pendingCount ?> Pending</div>
        <div style="font-size:0.76rem;color:#4B5563;margin-top:0.2rem;"><?= $dummyCount ?> dummy · <?= $ownersCount ?> test owner account(s)</div>
      </div>
      <div style="background:#fff;border:1px solid #E5E7EB;border-left:5px solid #7B1113;border-radius:12px;padding:1rem;">
        <div style="font-size:0.75rem;color:#6B7280;text-transform:uppercase;font-weight:700;">Outbound SMTP Safety</div>
        <div style="font-size:1.25rem;font-weight:800;color:#7B1113;margin-top:0.2rem;">100% SIMULATED</div>
        <div style="font-size:0.76rem;color:#4B5563;margin-top:0.2rem;"><?= count($outboxRows) ?> intercepted email(s) below</div>
      </div>
    </div>

    <!-- 1-Click Sandbox Actions -->
    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.35rem;margin-bottom:1.5rem;">
      <h2 style="margin:0 0 0.35rem 0;color:#114B3E;font-size:1.15rem;">1. Pre-Prod Database &amp; Test Actions</h2>
      <p style="margin:0 0 1rem 0;font-size:0.86rem;color:#4B5563;">
        All actions below operate strictly on Pre-Prod's isolated SQLite file (<code><?= htmlspecialchars(basename($sqlitePath), ENT_QUOTES, 'UTF-8') ?></code>) and never modify Live Production.
      </p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.9rem;">
        <form method="POST" action="/admin/sandbox.php" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:1rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="sandbox_action" value="seed_dummies">
          <strong style="color:#065F46;display:block;margin-bottom:0.3rem;">➕ Seed Dummy Test Listings</strong>
          <p style="margin:0 0 0.75rem 0;font-size:0.8rem;color:#374151;">Adds 3 approved + 2 pending AHS/AGHS dummy listings (with Maps pin URLs, Plus Codes, regulated LSK badge, diaspora state, and vetting queue records).</p>
          <button type="submit" class="btn btn-green" style="width:100%;justify-content:center;">Seed 5 Dummy Listings</button>
        </form>

        <form method="POST" action="/admin/sandbox.php" style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:1rem;" onsubmit="return confirm('Replace Pre-Prod SQLite listings with a fresh read-only snapshot of Live Production approved listings?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="sandbox_action" value="clone_prod_snapshot">
          <strong style="color:#1E40AF;display:block;margin-bottom:0.3rem;">🔄 Clone Live Prod Snapshot</strong>
          <p style="margin:0 0 0.75rem 0;font-size:0.8rem;color:#374151;">Copies current approved categories &amp; listings from Live MySQL (read-only) into Pre-Prod SQLite so you can test new UI/pagination against real listings.</p>
          <button type="submit" class="btn btn-outline" style="width:100%;justify-content:center;border-color:#2563EB;color:#1D4ED8;">Clone Prod Approved Listings</button>
        </form>

        <form method="POST" action="/admin/sandbox.php" style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:1rem;" onsubmit="return confirm('Wipe all dummy listings/uploads in Pre-Prod and restore the clean 12-listing seed database?');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="sandbox_action" value="reset_clean_seed">
          <strong style="color:#991B1B;display:block;margin-bottom:0.3rem;">🧹 Reset Pre-Prod DB to Clean Seed</strong>
          <p style="margin:0 0 0.75rem 0;font-size:0.8rem;color:#374151;">Wipes dummy records, owners, outbox &amp; test uploads, restoring the deterministic 12-listing baseline expected by automated E2E tests.</p>
          <button type="submit" class="btn btn-crimson" style="width:100%;justify-content:center;">Reset Pre-Prod DB</button>
        </form>

        <form method="POST" action="/admin/sandbox.php" style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;padding:1rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="sandbox_action" value="run_selftests">
          <strong style="color:#92400E;display:block;margin-bottom:0.3rem;">✅ Run Built-In Verification Suite</strong>
          <p style="margin:0 0 0.75rem 0;font-size:0.8rem;color:#374151;">Executes 8 live health &amp; security assertions (DB isolation, SMTP sandbox, DPA schema, anti-spam, SSRF, Maps pin inference, HoursEngine).</p>
          <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;">▶ Run Self-Tests Now</button>
        </form>
      </div>
    </section>

    <?php if (is_array($selfTestResults)): ?>
      <section style="background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.35rem;margin-bottom:1.5rem;">
        <h2 style="margin:0 0 0.75rem 0;color:#114B3E;font-size:1.1rem;">Self-Test Verification Results</h2>
        <div style="display:grid;gap:0.5rem;">
          <?php foreach ($selfTestResults as $row): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:0.6rem 0.85rem;border-radius:8px;background:<?= $row['passed'] ? '#ECFDF5' : '#FEF2F2' ?>;border:1px solid <?= $row['passed'] ? '#A7F3D0' : '#FECACA' ?>;">
              <div>
                <strong style="color:<?= $row['passed'] ? '#065F46' : '#991B1B' ?>;"><?= $row['passed'] ? 'PASS' : 'FAIL' ?> — <?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="font-size:0.8rem;color:#374151;margin-top:0.15rem;"><?= htmlspecialchars($row['detail'], ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <span style="font-weight:800;color:<?= $row['passed'] ? '#059669' : '#DC2626' ?>;"><?= $row['passed'] ? '✓' : '✗' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- Workflow & CLI E2E Instructions -->
    <section style="background:#F9FAFB;border:1px solid #D1D5DB;border-radius:14px;padding:1.2rem 1.35rem;margin-bottom:1.5rem;">
      <h2 style="margin:0 0 0.45rem 0;color:#114B3E;font-size:1.05rem;">2. How to Push Pre-Prod Code &amp; Run Remote E2E Tests</h2>
      <ol style="margin:0;padding-left:1.25rem;font-size:0.85rem;color:#374151;line-height:1.65;">
        <li><strong>Deploy code to Pre-Prod without touching Live Prod:</strong> Push any branch to <code>origin/preprod</code> (<code>git push origin HEAD:preprod --force</code>) — GitHub Actions (<code>deploy-preprod.yml</code>) lints PHP and deploys strictly to <code>/admin/preprod</code>.</li>
        <li><strong>Run full automated E2E suite against Pre-Prod:</strong> Click <em>Reset Pre-Prod DB</em> above, then run locally:<br>
          <code style="background:#E5E7EB;padding:0.18rem 0.45rem;border-radius:4px;">BASE_URL=https://www.busherianbiasharas.com/admin/preprod PREPROD_ACCESS_TOKEN="&lt;your-admin-token&gt;" python e2e/run.py</code>
        </li>
        <li><strong>Promote to Production:</strong> Once satisfied on Pre-Prod, merge your PR into <code>main</code> — <code>deploy-ftp.yml</code> deploys the verified code to <code>busherianbiasharas.com/</code>.</li>
      </ol>
    </section>

    <!-- Simulated Outbound Email Outbox -->
    <section style="background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1.35rem;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-bottom:0.85rem;">
        <div>
          <h2 style="margin:0;color:#114B3E;font-size:1.15rem;">3. Simulated Outbound Email Outbox (<?= count($outboxRows) ?>)</h2>
          <p style="margin:0.2rem 0 0 0;font-size:0.82rem;color:#6B7280;">Every Owner Magic Sign-In Link, Claim Invite, and Admin Review Email generated inside Pre-Prod is captured here instead of sending live email.</p>
        </div>
        <?php if (!empty($outboxRows)): ?>
          <form method="POST" action="/admin/sandbox.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($tokens['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="sandbox_action" value="clear_outbox">
            <button type="submit" class="btn btn-outline" style="font-size:0.8rem;">Clear Outbox</button>
          </form>
        <?php endif; ?>
      </div>

      <?php if (empty($outboxRows)): ?>
        <div style="padding:1.5rem;text-align:center;color:#6B7280;background:#F9FAFB;border-radius:10px;border:1px dashed #D1D5DB;font-size:0.88rem;">
          No simulated emails yet. Try clicking <strong>Seed 5 Dummy Listings</strong> above, submitting a test listing on <code>/submit.php</code>, or requesting an Owner Sign-In link on <code>/owner/login.php</code>!
        </div>
      <?php else: ?>
        <div style="display:grid;gap:0.85rem;">
          <?php foreach ($outboxRows as $mail): ?>
            <details style="border:1px solid #E5E7EB;border-radius:10px;padding:0.85rem 1rem;background:#FAFAFA;">
              <summary style="cursor:pointer;font-weight:700;color:#111827;font-size:0.88rem;">
                <span style="background:#FEF3C7;color:#92400E;padding:0.12rem 0.45rem;border-radius:4px;font-size:0.72rem;margin-right:0.4rem;">SIMULATED</span>
                To: <strong><?= htmlspecialchars((string)$mail['recipient_email'], ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= htmlspecialchars((string)$mail['subject'], ENT_QUOTES, 'UTF-8') ?>
                <span style="color:#6B7280;font-weight:400;font-size:0.78rem;margin-left:0.5rem;">(<?= htmlspecialchars((string)$mail['sent_at'], ENT_QUOTES, 'UTF-8') ?>)</span>
              </summary>
              <div style="margin-top:0.85rem;padding:1rem;background:#fff;border:1px solid #E5E7EB;border-radius:8px;">
                <?= (string)$mail['body_html'] ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
