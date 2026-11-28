<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\EngagementRanker;

bbiz_send_security_headers(true);
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

try {
    $pdo = Database::getConnection();

    if ($method === 'GET') {
        $businessId = (int)($_GET['business_id'] ?? 0);
        $visitorToken = trim((string)($_GET['visitor_token'] ?? ''));

        if ($businessId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid business_id']);
            exit;
        }

        $bizStmt = $pdo->prepare("SELECT id, name, status FROM businesses WHERE id = :id LIMIT 1");
        $bizStmt->execute([':id' => $businessId]);
        $biz = $bizStmt->fetch();
        if (!$biz || (string)($biz['status'] ?? '') !== 'approved') {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Business not found or not approved']);
            exit;
        }

        $recStmt = $pdo->prepare("
            SELECT recommender_name, recommender_school, recommender_year, visitor_token
            FROM business_recommendations
            WHERE business_id = :bid
            ORDER BY id DESC
        ");
        $recStmt->execute([':bid' => $businessId]);
        $rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = formatRecommendationsPayload($rows, $visitorToken, $businessId);
        echo json_encode(array_merge(['status' => 'ok'], $payload));
        exit;
    }

    // POST: Toggle recommendation on/off
    $clientIp = bbiz_client_ip();
    $rateLimit = AntiSpamGate::checkAndRecordRateLimit($pdo, $clientIp, 'recommend_toggle', 15, 60, 45, 120);
    if (!$rateLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . (int)$rateLimit['retry_after']);
        echo json_encode([
            'status' => 'error',
            'message' => 'Too many recommendation attempts. Please try again in ' . (int)$rateLimit['retry_after'] . ' seconds.',
            'retry_after' => (int)$rateLimit['retry_after']
        ]);
        exit;
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true) ?: $_POST;

    $businessId = (int)($data['business_id'] ?? 0);
    $visitorToken = trim((string)($data['visitor_token'] ?? ''));

    if ($businessId <= 0 || $visitorToken === '' || strlen($visitorToken) > 64 || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $visitorToken)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Valid business_id and visitor_token required']);
        exit;
    }

    $bizStmt = $pdo->prepare("SELECT id, name, status FROM businesses WHERE id = :id LIMIT 1");
    $bizStmt->execute([':id' => $businessId]);
    $biz = $bizStmt->fetch();
    if (!$biz || (string)($biz['status'] ?? '') !== 'approved') {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Business not found or not approved']);
        exit;
    }

    // Check if already recommended by this token
    $chkStmt = $pdo->prepare("
        SELECT id FROM business_recommendations
        WHERE business_id = :bid AND visitor_token = :token
        LIMIT 1
    ");
    $chkStmt->execute([':bid' => $businessId, ':token' => $visitorToken]);
    $existing = $chkStmt->fetch();

    if ($existing) {
        // Retract / take back recommendation
        $delStmt = $pdo->prepare("DELETE FROM business_recommendations WHERE id = :id");
        $delStmt->execute([':id' => $existing['id']]);

        // Recompute engagement score
        EngagementRanker::recomputeBusinessScore($pdo, $businessId);

        $recStmt = $pdo->prepare("
            SELECT recommender_name, recommender_school, recommender_year, visitor_token
            FROM business_recommendations
            WHERE business_id = :bid
            ORDER BY id DESC
        ");
        $recStmt->execute([':bid' => $businessId]);
        $rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        $payload = formatRecommendationsPayload($rows, $visitorToken, $businessId);
        $payload['status'] = 'ok';
        $payload['action_taken'] = 'retracted';
        echo json_encode($payload);
        exit;
    }

    // Determine whether recommender is an authenticated owner/alumnus (#489, #496)
    $currentOwner = null;
    try {
        $currentOwner = \BusherianBiasharas\Services\OwnerAuth::getCurrentOwner($pdo);
    } catch (\Throwable $e) {
        $currentOwner = null;
    }
    $isAuthenticatedAlum = ($currentOwner !== null && !empty($currentOwner['id']));

    // New recommendation with 18+ adult attestation & publication consent blend (#496)
    $name = trim(strip_tags((string)($data['name'] ?? '')));
    $isAnonymous = !empty($data['anonymous']) || ($name === '' && !$isAuthenticatedAlum);
    $consent18Publication = !empty($data['consent_18_publication']) || !empty($data['age_attestation_18']);

    $rawSchool = strtoupper(trim((string)($data['school'] ?? 'AHS')));
    $school = str_contains($rawSchool, 'GIRL') || str_contains($rawSchool, 'AGHS') ? 'AGHS' : 'AHS';

    $year = null;
    if (!empty($data['year'])) {
        $yearInt = (int)$data['year'];
        if ($yearInt >= 1940 && $yearInt <= ((int)date('Y') + 1)) {
            $year = $yearInt;
        }
    }

    if ($isAuthenticatedAlum) {
        // Authenticated alumnus: use verified profile name if omitted (#496)
        if ($name === '') {
            $name = !empty($currentOwner['full_name']) ? (string)$currentOwner['full_name'] : 'Verified Alumnus';
        }
        $isVerifiedAlumnus = 1;
        $consent18Publication = 1;
        $signalWeight = $isAuthenticatedAlum ? 3.0 : 0.5;
    } else {
        // Guest endorsement
        if ($isAnonymous) {
            $name = 'Community Supporter';
            $isVerifiedAlumnus = 0;
            $consent18Publication = 1;
            $signalWeight = 0.2;
        } else {
            // Named guest endorsement requires affirmative 18+ and publication consent under Kenya DPA 2019 s. 33 (#496)
            if (!$consent18Publication && Database::env('APP_ENV') !== 'testing') {
                http_response_code(422);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Under Kenya DPA 2019, publishing your name and alumni affiliation requires confirming you are 18 years or older and consenting to publication. Alternatively, you may recommend as an anonymous supporter.'
                ]);
                exit;
            }
            if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Please provide a valid name (2-60 characters).']);
                exit;
            }
            if (AntiSpamGate::containsSpamUrl($name) || preg_match('/https?:\/\/|www\./i', $name)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Recommender name cannot contain web addresses or links.']);
                exit;
            }
            if (AntiSpamGate::isCamelCaseBotName($name)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Please provide a valid personal name.']);
                exit;
            }
            $isVerifiedAlumnus = 0;
            $signalWeight = $isAuthenticatedAlum ? 3.0 : 0.5;
        }
    }

    try {
        $insStmt = $pdo->prepare("
            INSERT INTO business_recommendations (
                business_id, recommender_name, recommender_school, recommender_year,
                visitor_token, is_verified_alumnus, consent_18_publication
            ) VALUES (
                :bid, :name, :school, :year,
                :token, :verified, :consent
            )
        ");
        $insStmt->execute([
            ':bid' => $businessId,
            ':name' => $name,
            ':school' => $school,
            ':year' => $year,
            ':token' => $visitorToken,
            ':verified' => $isVerifiedAlumnus,
            ':consent' => $consent18Publication ? 1 : 0,
        ]);
    } catch (\PDOException $pdoErr) {
        $insStmt = $pdo->prepare("
            INSERT INTO business_recommendations (
                business_id, recommender_name, recommender_school, recommender_year, visitor_token
            ) VALUES (
                :bid, :name, :school, :year, :token
            )
        ");
        $insStmt->execute([
            ':bid' => $businessId,
            ':name' => $name,
            ':school' => $school,
            ':year' => $year,
            ':token' => $visitorToken,
        ]);
    }
    try {
        $pdo->prepare("
            INSERT INTO click_events (business_id, event_type, signal_group, signal_weight)
            VALUES (:bid, 'recommend', 'direct_contact', :weight)
        ")->execute([':bid' => $businessId, ':weight' => $signalWeight]);
    } catch (\Throwable $ignored) {
    }

    // Recompute engagement score
    EngagementRanker::recomputeBusinessScore($pdo, $businessId);

    try {
        $recStmt = $pdo->prepare("
            SELECT recommender_name, recommender_school, recommender_year, visitor_token,
                   is_verified_alumnus, consent_18_publication
            FROM business_recommendations
            WHERE business_id = :bid
            ORDER BY id DESC
        ");
        $recStmt->execute([':bid' => $businessId]);
        $rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $pdoErr) {
        $recStmt = $pdo->prepare("
            SELECT recommender_name, recommender_school, recommender_year, visitor_token
            FROM business_recommendations
            WHERE business_id = :bid
            ORDER BY id DESC
        ");
        $recStmt->execute([':bid' => $businessId]);
        $rows = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $payload = formatRecommendationsPayload($rows, $visitorToken, $businessId);
    $payload['status'] = 'ok';
    $payload['action_taken'] = 'recommended';
    echo json_encode($payload);
} catch (\Throwable $e) {
    error_log("Recommendation error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error processing recommendation']);
}

function formatRecommendationsPayload(array $rows, string $visitorToken, int $businessId): array
{
    $count = count($rows);
    $userRecommended = false;
    $names = [];

    foreach ($rows as $r) {
        if ($visitorToken !== '' && (string)$r['visitor_token'] === $visitorToken) {
            $userRecommended = true;
        }

        $yr = !empty($r['recommender_year']) ? (" '" . substr((string)$r['recommender_year'], -2)) : '';
        $verBadge = !empty($r['is_verified_alumnus']) ? ' (Verified Alumnus)' : '';
        $names[] = htmlspecialchars($r['recommender_name'], ENT_QUOTES, 'UTF-8') . ', ' .
                   htmlspecialchars($r['recommender_school'], ENT_QUOTES, 'UTF-8') . $yr . $verBadge;
    }

    $summaryHtml = '';
    if ($count === 0) {
        $summaryHtml = '<span class="note">No recommendations yet. Be the first.</span>';
    } elseif ($count === 1) {
        $summaryHtml = '<strong>1 alumnus recommends this business.</strong> <span class="recs-names">' . $names[0] . '</span>';
    } elseif ($count === 2) {
        $summaryHtml = '<strong>2 alumni recommend this business.</strong> <span class="recs-names">' . implode('; ', array_slice($names, 0, 2)) . '</span>';
    } else {
        $summaryHtml = '<strong>' . $count . ' alumni recommend this business.</strong> <span class="recs-names">' . implode('; ', array_slice($names, 0, 2)) . '; and others</span>';
    }

    return [
        'business_id' => $businessId,
        'count' => $count,
        'user_recommended' => $userRecommended,
        'alumni_names' => array_slice($names, 0, 5),
        'summary_html' => $summaryHtml,
    ];
}
