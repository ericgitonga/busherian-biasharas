<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\Mailer;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(false);

$pdo = Database::getConnection();

$businessId = (int)($_REQUEST['id'] ?? 0);
$action     = strtolower(trim((string)($_REQUEST['action'] ?? '')));
$exp        = (int)($_REQUEST['exp'] ?? 0);
$mergeId    = (int)($_REQUEST['merge'] ?? 0);
$sig        = trim((string)($_REQUEST['sig'] ?? ''));

if ($businessId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo 'Invalid review action parameters.';
    exit;
}

if (!Mailer::verifySignedAdminAction($businessId, $action, $exp, $mergeId, $sig)) {
    http_response_code(403);
    echo 'Invalid or expired cryptographic signature on admin action link.';
    exit;
}

$result = ($action === 'approve')
    ? VettingPurger::approveBusiness($pdo, $businessId, $mergeId > 0 ? $mergeId : null)
    : VettingPurger::rejectBusiness($pdo, $businessId);

if (!empty($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => 'ok', 'result' => $result]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Review Completed — Busherian Biasharas</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body style="padding:2rem;">
  <div style="max-width:600px;margin:2rem auto;background:#fff;border:2px solid #114B3E;border-radius:14px;padding:1.75rem;">
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
