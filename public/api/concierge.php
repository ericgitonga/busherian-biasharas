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

// Early Request Size Bound (M-09): reject bodies > 4KB before decoding
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4096) {
    http_response_code(413);
    echo json_encode([
        'status' => 'error',
        'code' => 'payload_too_large',
        'message' => 'Query payload exceeds maximum allowed size (4KB).',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 4096) {
    http_response_code(413);
    echo json_encode([
        'status' => 'error',
        'code' => 'payload_too_large',
        'message' => 'Query payload exceeds maximum allowed size (4KB).',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$decoded = json_decode($raw, true);
$payload = is_array($decoded) ? $decoded : $_POST;

// 1. Consent Verification (Kenya DPA 2019 ss. 25, 32, 33)
$consentVal = $payload['consent'] ?? null;
$hasConsent = false;
$adultAttested = false;

if (is_array($consentVal)) {
    $hasConsent = !empty($consentVal['granted']);
    $adultAttested = !empty($consentVal['adult_attested']);
} elseif (!empty($consentVal)) {
    $hasConsent = true;
    $adultAttested = true; // Scalar consent (1 or 'granted') includes adult attestation from UI modal
} elseif (isset($_SERVER['HTTP_X_BBIZ_CONSENT']) && $_SERVER['HTTP_X_BBIZ_CONSENT'] === '1') {
    $hasConsent = true;
    $adultAttested = true;
}

if (!$hasConsent || !$adultAttested) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'code' => 'consent_required',
        'message' => 'Kenya DPA consent and adult (18+) attestation are required to use Biashara Concierge.',
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
$isSensitive = !empty($scrubbed['is_sensitive']) || QueryScrubber::containsSensitiveNarrative($rawQuery);

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

    // 4. Intent Cache Check (15-Minute Cache with Decoupled Hydration, H-01, M-01)
    $b2bOnly = !empty($payload['b2b_only']);
    $openNowOnly = !empty($payload['open_now_only']);
    $userLat = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
    $userLng = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
    $town = isset($payload['town']) ? trim((string)$payload['town']) : null;

    $cacheHash = hash('sha256', strtolower($cleanQuery) . '|' . ($b2bOnly ? '1' : '0') . '|' . ($openNowOnly ? '1' : '0'));
    $cacheKey = 'concierge_cache_' . substr($cacheHash, 0, 48);

    if (!$isSensitive) {
        $cachedJson = Database::getSetting($pdo, $cacheKey, '');
        if ($cachedJson !== '') {
            $cachedEntry = json_decode($cachedJson, true);
            if (is_array($cachedEntry) && !empty($cachedEntry['cached_at']) && (time() - (int)$cachedEntry['cached_at'] < 900)) {
                // Re-hydrate strictly from live approved database records! (H-01, M-01)
                $directCards = ConciergeService::hydrateApprovedBusinesses($pdo, $cachedEntry['direct_meta'] ?? [], $userLat, $userLng);
                $ecoCards = ConciergeService::hydrateApprovedBusinesses($pdo, $cachedEntry['ecosystem_meta'] ?? [], $userLat, $userLng);

                $cachedResponse = [
                    'status' => 'ok',
                    'mode' => $cachedEntry['mode'] ?? 'local_smart',
                    'conversational_response' => $cachedEntry['conversational_response'] ?? '',
                    'direct_recommendations' => $directCards,
                    'ecosystem_recommendations' => $ecoCards,
                    'unmet_ecosystem_specialties' => $cachedEntry['unmet_ecosystem_specialties'] ?? [],
                    'unmet_needs_commentary' => $cachedEntry['unmet_needs_commentary'] ?? null,
                    'query_intent' => $cachedEntry['query_intent'] ?? [],
                    'scrubbed_query' => $cleanQuery,
                    'pii_was_redacted' => $scrubbed['pii_detected'],
                    'candidates_evaluated' => count($directCards) + count($ecoCards),
                    'cached' => true,
                ];
                echo json_encode($cachedResponse, JSON_UNESCAPED_SLASHES);
                exit;
            }
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
    // Sensitive queries are strictly forced to local smart mode (H-02)
    $synthesis = ConciergeService::synthesizeRecommendations(
        $pdo,
        $candidates,
        $cleanQuery,
        ['force_local' => $isSensitive]
    );

    // Hydrate strictly approved cards
    $directMeta = array_map(fn($d) => [
        'id' => $d['id'],
        'business_id' => $d['business_id'],
        'match_tier' => 'direct',
        'tailored_reason' => $d['tailored_reason'] ?? $d['match_rationale'] ?? '',
    ], $synthesis['direct_recommendations']);

    $ecoMeta = array_map(fn($e) => [
        'id' => $e['id'],
        'business_id' => $e['business_id'],
        'match_tier' => 'ecosystem',
        'underlying_need_label' => $e['underlying_need_label'] ?? null,
        'tailored_reason' => $e['tailored_reason'] ?? $e['match_rationale'] ?? '',
    ], $synthesis['ecosystem_recommendations']);

    $directCards = ConciergeService::hydrateApprovedBusinesses($pdo, $directMeta, $userLat, $userLng);
    $ecoCards = ConciergeService::hydrateApprovedBusinesses($pdo, $ecoMeta, $userLat, $userLng);

    $response = [
        'status' => 'ok',
        'mode' => $synthesis['mode'],
        'conversational_response' => $synthesis['conversational_response'],
        'direct_recommendations' => $directCards,
        'ecosystem_recommendations' => $ecoCards,
        'unmet_ecosystem_specialties' => $synthesis['unmet_ecosystem_specialties'],
        'unmet_needs_commentary' => $synthesis['unmet_needs_commentary'],
        'query_intent' => $synthesis['query_intent'],
        'scrubbed_query' => $cleanQuery,
        'pii_was_redacted' => $scrubbed['pii_detected'],
        'candidates_evaluated' => count($directCards) + count($ecoCards),
        'cached' => false,
    ];

    // Store lightweight cache in app_settings (excluding raw query and static cards, H-01/H-02)
    if (!$isSensitive) {
        $cachePayload = [
            'cached_at' => time(),
            'mode' => $synthesis['mode'],
            'conversational_response' => $synthesis['conversational_response'],
            'direct_meta' => $directMeta,
            'ecosystem_meta' => $ecoMeta,
            'unmet_ecosystem_specialties' => $synthesis['unmet_ecosystem_specialties'],
            'unmet_needs_commentary' => $synthesis['unmet_needs_commentary'],
            'query_intent' => $synthesis['query_intent'],
        ];
        Database::setSetting($pdo, $cacheKey, (string)json_encode($cachePayload));
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'An error occurred while processing your concierge query.',
    ], JSON_UNESCAPED_SLASHES);
}
