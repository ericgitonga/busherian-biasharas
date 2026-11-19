<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
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
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true) ?: $_POST;

    $businessId = (int)($data['business_id'] ?? 0);
    $visitorToken = trim((string)($data['visitor_token'] ?? ''));

    if ($businessId <= 0 || $visitorToken === '' || strlen($visitorToken) > 64) {
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

    // New recommendation
    $name = trim(strip_tags((string)($data['name'] ?? '')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Please provide a valid name (2-60 characters).']);
        exit;
    }

    $rawSchool = strtoupper(trim((string)($data['school'] ?? 'AHS')));
    $school = str_contains($rawSchool, 'GIRL') || str_contains($rawSchool, 'AGHS') ? 'AGHS' : 'AHS';

    $year = null;
    if (!empty($data['year'])) {
        $yearInt = (int)$data['year'];
        if ($yearInt >= 1940 && $yearInt <= ((int)date('Y') + 1)) {
            $year = $yearInt;
        }
    }

    $insStmt = $pdo->prepare("
        INSERT INTO business_recommendations (business_id, recommender_name, recommender_school, recommender_year, visitor_token)
        VALUES (:bid, :name, :school, :year, :token)
    ");
    $insStmt->execute([
        ':bid' => $businessId,
        ':name' => $name,
        ':school' => $school,
        ':year' => $year,
        ':token' => $visitorToken,
    ]);

    // Record engagement click event
    try {
        $pdo->prepare("
            INSERT INTO click_events (business_id, event_type, signal_group, signal_weight)
            VALUES (:bid, 'recommend', 'direct_contact', 3.0)
        ")->execute([':bid' => $businessId]);
    } catch (\Throwable $ignored) {
    }

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
    $payload['action_taken'] = 'recommended';
    echo json_encode($payload);
} catch (\Throwable $e) {
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
        $names[] = htmlspecialchars($r['recommender_name'], ENT_QUOTES, 'UTF-8') . ', ' .
                   htmlspecialchars($r['recommender_school'], ENT_QUOTES, 'UTF-8') . $yr;
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
