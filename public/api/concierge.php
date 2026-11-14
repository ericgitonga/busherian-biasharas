<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use BusherianBiasharas\Config\Database;
use BusherianBiasharas\Services\AntiSpamGate;
use BusherianBiasharas\Services\ConciergeRetriever;
use BusherianBiasharas\Services\ConciergeService;
use BusherianBiasharas\Services\QueryScrubber;

// Anonymous JSON endpoint: zero Set-Cookie headers, zero visitor tracking profiles (Kenya DPA 2019)
bbiz_send_security_headers(true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method Not Allowed. Concierge queries must use POST.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$decoded = json_decode($raw, true);
$payload = is_array($decoded) ? $decoded : $_POST;

// 1. Consent Verification (Kenya DPA 2019 ss. 25, 32)
$hasConsent = !empty($payload['consent'])
    || (isset($_SERVER['HTTP_X_BBIZ_CONSENT']) && $_SERVER['HTTP_X_BBIZ_CONSENT'] === '1');

if (!$hasConsent) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 'consent_required',
        'message' => 'DPA consent is required to use Biashara Concierge.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. Input Validation & Visitor PII Redaction (QueryScrubber)
$rawQuery = trim((string)($payload['query'] ?? ''));
if ($rawQuery === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'code' => 'empty_query',
        'message' => 'Please provide a search inquiry for the Concierge.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$scrubbed = QueryScrubber::scrub($rawQuery);
$cleanQuery = $scrubbed['clean_query'];

if ($cleanQuery === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'error',
        'code' => 'invalid_query',
        'message' => 'Query contains only invalid or redacted characters.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

try {
    $pdo = Database::getConnection();

    // 3. Abuse & Cost Circuit-Breaker Rate Limiting (AntiSpamGate)
    // Limits: <= 10 / 15m per IP, <= 50 / 24h per IP, <= 100 / 1h globally
    $rateLimit = AntiSpamGate::checkAndRecordRateLimit(
        $pdo,
        $clientIp,
        'concierge_query',
        10,
        50,
        100
    );

    if (!$rateLimit['allowed']) {
        http_response_code(429);
        echo json_encode([
            'status' => 'rate_limited',
            'code' => 'too_many_requests',
            'message' => 'Concierge query limit reached. Please wait a few moments before submitting another question.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 4. Intent Cache Check (15-Minute Cache)
    $b2bOnly = !empty($payload['b2b_only']);
    $openNowOnly = !empty($payload['open_now_only']);
    $userLat = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
    $userLng = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
    $town = isset($payload['town']) ? trim((string)$payload['town']) : null;

    $cacheHash = hash('sha256', strtolower($cleanQuery) . '|' . ($b2bOnly ? '1' : '0') . '|' . ($openNowOnly ? '1' : '0'));
    $cacheKey = 'qcache_' . substr($cacheHash, 0, 48);

    $cachedResponse = Database::getSetting($pdo, $cacheKey, '');
    if ($cachedResponse !== '') {
        $cachedData = json_decode($cachedResponse, true);
        if (is_array($cachedData)) {
            $cachedData['cached'] = true;
            echo json_encode($cachedData, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // 5. Candidate Retrieval (Layer 2)
    $retrieverContext = [
        'query' => $cleanQuery,
        'latitude' => $userLat,
        'longitude' => $userLng,
        'town' => $town,
        'b2b_only' => $b2bOnly,
        'open_now_only' => $openNowOnly,
        'user_seed' => hash('sha256', $clientIp . date('Y-m-d H')),
    ];

    $candidates = ConciergeRetriever::retrieveCandidates($pdo, $retrieverContext);

    // 6. Grounded Synthesis & Local Smart Mode Fallback (Layer 3)
    $synthesis = ConciergeService::synthesizeRecommendations($pdo, $candidates, $cleanQuery);

    $response = [
        'status' => 'ok',
        'mode' => $synthesis['mode'],
        'conversational_response' => $synthesis['conversational_response'],
        'direct_recommendations' => $synthesis['direct_recommendations'],
        'ecosystem_recommendations' => $synthesis['ecosystem_recommendations'],
        'unmet_ecosystem_specialties' => $synthesis['unmet_ecosystem_specialties'],
        'unmet_needs_commentary' => $synthesis['unmet_needs_commentary'],
        'query_intent' => $synthesis['query_intent'],
        'scrubbed_query' => $cleanQuery,
        'pii_was_redacted' => $scrubbed['pii_detected'],
        'candidates_evaluated' => $synthesis['candidates_evaluated'],
        'cached' => false,
    ];

    // Store in 15-minute response cache (transient in app_settings)
    Database::setSetting($pdo, $cacheKey, (string)json_encode($response));

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while processing your concierge query.',
    ], JSON_UNESCAPED_SLASHES);
}
