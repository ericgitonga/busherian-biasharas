<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\VettingPurger;

bbiz_send_security_headers(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true) ?: $_POST;
$decision = strtolower(trim((string)($data['decision'] ?? '')));
if (!in_array($decision, ['accepted', 'rejected', 'revoked'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid consent decision']);
    exit;
}

$visitorId = trim((string)($_COOKIE['bbiz_vid'] ?? ($data['visitor_id'] ?? '')));
if ($visitorId !== '' && !preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $visitorId)) {
    $visitorId = '';
}

try {
    $pdo = Database::getConnection();
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rate = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'analytics_consent', 30, 120, 1500);
    if (!$rate['allowed']) {
        http_response_code(429);
        echo json_encode(['status' => 'rate_limited']);
        exit;
    }

    $salt = Database::env('IP_HASH_SECRET', 'bbiz_default_metric_salt');
    $activePolicyVersion = VettingPurger::PRIVACY_NOTICE_VERSION;
    $nowTs = time();
    $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);

    if ($decision === 'accepted') {
        if ($visitorId === '' || strlen($visitorId) < 12) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing visitor identifier']);
            exit;
        }
        $visitorHash = hash_hmac('sha256', $visitorId, $salt);

        // Non-rolling 12-month consent window (#245 M-05): preserve original granted_at/expires_at if active under current notice
        $curStmt = $pdo->prepare("
            SELECT policy_version, consent_status, granted_at, expires_at
            FROM analytics_consents
            WHERE visitor_hash = :vh
            LIMIT 1
        ");
        $curStmt->execute([':vh' => $visitorHash]);
        $existing = $curStmt->fetch();

        if (
            $existing
            && (string)$existing['consent_status'] === 'accepted'
            && (string)$existing['policy_version'] === $activePolicyVersion
            && (string)$existing['expires_at'] > $nowUtc
        ) {
            $expiresUtc = (string)$existing['expires_at'];
            $grantedUtc = (string)$existing['granted_at'];
        } else {
            $grantedUtc = $nowUtc;
            $expiresUtc = gmdate('Y-m-d H:i:s', $nowTs + 31536000);
            $driver = strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
            if ($driver === 'sqlite') {
                $pdo->prepare("
                    INSERT INTO analytics_consents (visitor_hash, policy_version, consent_status, granted_at, expires_at, updated_at)
                    VALUES (:vh, :pv, 'accepted', :ga, :ea, :ua)
                    ON CONFLICT(visitor_hash) DO UPDATE SET
                        policy_version = excluded.policy_version,
                        consent_status = 'accepted',
                        granted_at = excluded.granted_at,
                        expires_at = excluded.expires_at,
                        updated_at = excluded.updated_at
                ")->execute([
                    ':vh' => $visitorHash,
                    ':pv' => $activePolicyVersion,
                    ':ga' => $grantedUtc,
                    ':ea' => $expiresUtc,
                    ':ua' => $nowUtc,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO analytics_consents (visitor_hash, policy_version, consent_status, granted_at, expires_at, updated_at)
                    VALUES (:vh, :pv, 'accepted', :ga, :ea, :ua)
                    ON DUPLICATE KEY UPDATE
                        policy_version = VALUES(policy_version),
                        consent_status = 'accepted',
                        granted_at = VALUES(granted_at),
                        expires_at = VALUES(expires_at),
                        updated_at = VALUES(updated_at)
                ")->execute([
                    ':vh' => $visitorHash,
                    ':pv' => $activePolicyVersion,
                    ':ga' => $grantedUtc,
                    ':ea' => $expiresUtc,
                    ':ua' => $nowUtc,
                ]);
            }
        }

        $expTs = strtotime($expiresUtc . ' UTC') ?: ($nowTs + 31536000);
        echo json_encode([
            'status' => 'ok',
            'decision' => 'accepted',
            'policy_version' => $activePolicyVersion,
            'granted_at_utc' => $grantedUtc,
            'expires_at_utc' => $expiresUtc,
            'expires_at_unix' => $expTs,
        ]);
        exit;
    }

    // Revocation / Rejection (#245 M-05): purge server-side consent record & any retained visitor metrics
    if ($visitorId !== '') {
        $visitorHash = hash_hmac('sha256', $visitorId, $salt);
        $pdo->prepare("DELETE FROM analytics_consents WHERE visitor_hash = :vh")->execute([':vh' => $visitorHash]);
        $pdo->prepare("DELETE FROM usage_metrics WHERE visitor_hash = :vh")->execute([':vh' => $visitorHash]);
    }

    echo json_encode([
        'status' => 'ok',
        'decision' => $decision,
        'policy_version' => $activePolicyVersion,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error']);
}
