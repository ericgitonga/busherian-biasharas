<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\OwnerAuth;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);
OwnerAuth::startSecureSession();

$pdo = Database::getConnection();

$businessId = (int)($_REQUEST['id'] ?? 0);
$action     = strtolower(trim((string)($_REQUEST['action'] ?? '')));
$exp        = (int)($_REQUEST['exp'] ?? 0);
$mergeId    = (int)($_REQUEST['merge'] ?? 0);
$jti        = trim((string)($_REQUEST['jti'] ?? ''));
$sig        = trim((string)($_REQUEST['sig'] ?? ''));
$wantsJson  = (!empty($_REQUEST['format']) && $_REQUEST['format'] === 'json');
$clientIp   = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

$sendJsonOrHtmlError = static function (int $statusCode, string $code, string $message) use ($wantsJson): never {
    http_response_code($statusCode);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status' => 'error', 'code' => $code, 'message' => $message]);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Admin Review Link Notice — Busherian Biasharas</title>
      <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body style="padding:2rem;">
      <div data-testid="review-action-error" style="max-width:600px;margin:2rem auto;background:#FEF2F2;border:2px solid #DC2626;border-radius:14px;padding:1.75rem;">
        <h2 style="margin-top:0;color:#991B1B;">⚠️ Review Action Not Executed</h2>
        <p style="color:#7F1D1D;font-weight:600;"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <div style="display:flex;gap:0.75rem;margin-top:1.25rem;">
          <a href="/admin/index.php" class="btn btn-crimson">Open Admin Review Console</a>
          <a href="/" class="btn btn-outline">Public Directory</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
};

if ($businessId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $sendJsonOrHtmlError(400, 'invalid_parameters', 'Invalid review action parameters.');
}

if (!Mailer::verifySignedAdminAction($businessId, $action, $exp, $mergeId, $sig, $jti)) {
    $sendJsonOrHtmlError(403, 'invalid_signature', 'Invalid or expired cryptographic signature on admin action link.');
}

$nonceHash = Mailer::adminActionNonceHash($businessId, $action, $exp, $mergeId, $jti);

$bizStmt = $pdo->prepare("SELECT id, business_id, business_number, name, school_badge, status FROM businesses WHERE id = :id LIMIT 1");
$bizStmt->execute([':id' => $businessId]);
$biz = $bizStmt->fetch();
if (!$biz) {
    $sendJsonOrHtmlError(404, 'not_found', "Business #{$businessId} was not found.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Security (#175): GET requests are strictly read-only so mail scanners / link prefetchers never mutate state!
    if (AntiSpamGate::isNonceUsed($pdo, $nonceHash)) {
        $sendJsonOrHtmlError(409, 'nonce_replayed', 'This single-use admin review link has already been used.');
    }
    if ((string)$biz['status'] !== 'pending') {
        $sendJsonOrHtmlError(409, 'not_pending', "Business #{$businessId} is already '{$biz['status']}' and is no longer awaiting review.");
    }
    $csrfToken = AntiSpamGate::generateCsrfToken();
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'confirmation_required',
            'method_required' => 'POST',
            'business_id' => $businessId,
            'business_name' => (string)$biz['name'],
            'action' => $action,
            'csrf_token' => $csrfToken,
        ]);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Confirm Admin <?= htmlspecialchars(ucfirst($action), ENT_QUOTES, 'UTF-8') ?> — Busherian Biasharas</title>
      <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body style="padding:2rem;">
      <div style="max-width:600px;margin:2rem auto;background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.75rem;">
        <div style="font-size:0.78rem;font-weight:800;text-transform:uppercase;color:#114B3E;letter-spacing:0.04em;">🔒 Single-Use Signed Moderation Confirmation</div>
        <h2 style="margin:0.35rem 0 0.65rem 0;color:#7B1113;">
          Confirm <?= htmlspecialchars(ucfirst($action), ENT_QUOTES, 'UTF-8') ?> for <?= htmlspecialchars((string)$biz['name'], ENT_QUOTES, 'UTF-8') ?> (#<?= (int)($biz['business_number'] ?: $biz['id']) ?>)
        </h2>
        <p style="color:#374151;font-size:0.92rem;line-height:1.55;">
          To protect pending submissions from automated email link scanners, review decisions require explicit confirmation below. Confirming this action will <strong><?= $action === 'approve' ? 'publish the listing live' : 'reject the submission' ?></strong> and permanently purge its one-time alumni vetting record.
        </p>
        <form method="POST" action="/admin/review-action.php<?= $wantsJson ? '?format=json' : '' ?>" data-testid="review-action-confirm-form" style="margin-top:1.25rem;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="id" value="<?= $businessId ?>">
          <input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="exp" value="<?= $exp ?>">
          <input type="hidden" name="merge" value="<?= $mergeId ?>">
          <input type="hidden" name="jti" value="<?= htmlspecialchars($jti, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="sig" value="<?= htmlspecialchars($sig, ENT_QUOTES, 'UTF-8') ?>">
          <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button type="submit" data-testid="confirm-review-action-btn" class="btn <?= $action === 'approve' ? 'btn-green' : 'btn-crimson' ?>">
              <?= $action === 'approve' ? '✓ Confirm Approve & Purge Vetting' : '✕ Confirm Reject & Purge Vetting' ?>
            </button>
            <a href="/admin/index.php" class="btn btn-outline">Inspect in Admin Console First</a>
          </div>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (!AntiSpamGate::verifyCsrfToken($csrf)) {
    $sendJsonOrHtmlError(403, 'invalid_csrf', 'Invalid or expired security token (CSRF). Please reload the confirmation page and try again.');
}

$pdo->beginTransaction();
try {
    if (!AntiSpamGate::claimSingleUseNonce($pdo, $nonceHash, max($exp, time() + 3600))) {
        $pdo->rollBack();
        $sendJsonOrHtmlError(409, 'nonce_replayed', 'This single-use admin review link has already been used.');
    }

    $actorId = 'signed_email_jti:' . substr($jti, 0, 12);
    $result = ($action === 'approve')
        ? VettingPurger::approveBusiness($pdo, $businessId, $mergeId > 0 ? $mergeId : null, 'signed_email_link', $actorId, $clientIp)
        : VettingPurger::rejectBusiness($pdo, $businessId, 'signed_email_link', $actorId, $clientIp);

    $pdo->commit();
    Mailer::flushPendingOutbox($pdo);
} catch (\RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Mailer::clearPendingOutbox();
    $sendJsonOrHtmlError(409, 'not_pending', $e->getMessage());
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Mailer::clearPendingOutbox();
    error_log('[review-action.php] Moderation failed: ' . $e->getMessage());
    $sendJsonOrHtmlError(500, 'server_error', 'Could not complete review action due to a temporary server error.');
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'ok', 'result' => $result]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Review Completed — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="padding:2rem;">
  <div data-testid="review-action-completed" style="max-width:600px;margin:2rem auto;background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.75rem;">
    <h2 style="margin-top:0;color:#114B3E;">✓ Business #<?= (int)$result['business_id'] ?> <?= htmlspecialchars(ucfirst($result['new_status']), ENT_QUOTES, 'UTF-8') ?></h2>
    <p>
      <strong>Cryptographic Vetting Disposal Completed:</strong>
      <code><?= (int)$result['vetting_rows_purged'] ?></code> vetting record(s) containing House, Year Left, and School Number were permanently deleted from <code>business_vetting_queue</code>.
    </p>
    <div style="display:flex;gap:0.75rem;margin-top:1.25rem;">
      <a href="/admin/index.php" class="btn btn-green">Back to Admin Review Queue</a>
      <a href="/" class="btn btn-outline">View Public Directory</a>
    </div>
  </div>
</body>
</html>
