<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use BusherianBiasharas\Config\Database;
use PDO;

/**
 * ConciergeService — LLM Grounding & Conversational Synthesis Engine
 *
 * Implements Layer 3 of the Intelligent Biashara Concierge:
 * 1. Outbound HTTPS REST connection to Google Gemini API (gemini-2.5-flash) with 4-second timeout.
 * 2. Strict Candidate Grounding: validates every suggested business against the retriever candidate list;
 *    silently drops any hallucinated or unapproved business ID and hydrates authoritative card data from MySQL.
 * 3. Graceful Deterministic Fallback (Local Smart Mode): provides warm, structured recommendations
 *    powered by NeedGraph & ConciergeRetriever whenever API keys are missing, rate-limited, or timed out.
 */
final class ConciergeService
{
    public const DEFAULT_MODEL = 'gemini-2.5-flash';
    public const TIMEOUT_SECONDS = 4;

    /**
     * Synthesize grounded recommendations from retriever candidates.
     *
     * @param PDO $pdo Authoritative database connection
     * @param array{
     *     direct_matches: array<int, array<string, mixed>>,
     *     ecosystem_matches: array<int, array<string, mixed>>,
     *     unmet_ecosystem_specialties: array<int, array<string, mixed>>,
     *     query_intent: array<string, mixed>,
     *     total_candidates: int
     * } $retrieverResult
     * @param string $scrubbedQuery Clean PII-redacted user query
     * @param array{
     *     force_local?: bool,
     *     model?: string,
     *     mock_response?: array<string, mixed>
     * } $options
     * @return array{
     *     mode: string,
     *     conversational_response: string,
     *     direct_recommendations: array<int, array<string, mixed>>,
     *     ecosystem_recommendations: array<int, array<string, mixed>>,
     *     unmet_ecosystem_specialties: array<int, array<string, mixed>>,
     *     unmet_needs_commentary: string|null,
     *     query_intent: array<string, mixed>,
     *     scrubbed_query: string,
     *     candidates_evaluated: int
     * }
     */
    public static function synthesizeRecommendations(
        PDO $pdo,
        array $retrieverResult,
        string $scrubbedQuery,
        array $options = []
    ): array {
        // Mock injection for offline deterministic testing
        if (isset($options['mock_response']) && is_array($options['mock_response'])) {
            $grounded = self::verifyAndHydrateGrounding($options['mock_response'], $retrieverResult, $scrubbedQuery);
            if ($grounded !== null) {
                return $grounded;
            }
        }

        $forceLocal = !empty($options['force_local']);
        $apiKey = $forceLocal ? '' : (Database::getSetting($pdo, 'GEMINI_API_KEY') ?: Database::getSetting($pdo, 'BBIZ_AI_API_KEY'));
        $model = (string)($options['model'] ?? Database::getSetting($pdo, 'BBIZ_AI_MODEL', self::DEFAULT_MODEL));

        // If no API key or forced local, use Local Smart Mode
        if ($apiKey === '' || $forceLocal) {
            return self::synthesizeLocalSmartMode($retrieverResult, $scrubbedQuery);
        }

        // Prepare compact candidate list for inference
        $candidatesPayload = self::buildCandidatesPayload($retrieverResult);

        // Perform external LLM inference
        $llmResult = self::callGeminiInference($candidatesPayload, $scrubbedQuery, $apiKey, $model);

        if ($llmResult !== null) {
            $grounded = self::verifyAndHydrateGrounding($llmResult, $retrieverResult, $scrubbedQuery);
            if ($grounded !== null) {
                return $grounded;
            }
        }

        // Fallback to Local Smart Mode on error, timeout, or grounding invalidation
        return self::synthesizeLocalSmartMode($retrieverResult, $scrubbedQuery);
    }

    /**
     * Build a compact, prompt-injection-safe JSON payload of candidate listings for the LLM.
     *
     * @param array<string, mixed> $retrieverResult
     * @return array<string, mixed>
     */
    public static function buildCandidatesPayload(array $retrieverResult): array
    {
        $direct = [];
        foreach (array_slice($retrieverResult['direct_matches'] ?? [], 0, 15) as $d) {
            $direct[] = [
                'business_id' => (string)($d['business_id'] ?? ''),
                'name' => AiListingIndexer::sanitizePromptInjection((string)($d['name'] ?? '')),
                'specialty' => AiListingIndexer::sanitizePromptInjection((string)($d['category_name'] ?? '')),
                'secondary_specialty' => !empty($d['secondary_category_name']) ? AiListingIndexer::sanitizePromptInjection((string)$d['secondary_category_name']) : null,
                'location' => AiListingIndexer::sanitizePromptInjection(($d['town'] ? $d['town'] . ', ' : '') . ($d['country'] ?? 'Kenya')),
                'accepts_lpo' => !empty($d['accepts_lpo']),
                'open_status' => $d['open_status'] ?? 'UNKNOWN',
                'distance_km' => $d['distance_km'] ?? null,
                'capability_summary' => AiListingIndexer::sanitizePromptInjection((string)($d['tagline'] ?? $d['description'] ?? '')),
                'match_rationale' => AiListingIndexer::sanitizePromptInjection((string)($d['match_rationale'] ?? '')),
            ];
        }

        $ecosystem = [];
        foreach (array_slice($retrieverResult['ecosystem_matches'] ?? [], 0, 10) as $e) {
            $ecosystem[] = [
                'business_id' => (string)($e['business_id'] ?? ''),
                'name' => AiListingIndexer::sanitizePromptInjection((string)($e['name'] ?? '')),
                'specialty' => AiListingIndexer::sanitizePromptInjection((string)($e['category_name'] ?? '')),
                'underlying_need_label' => AiListingIndexer::sanitizePromptInjection((string)($e['underlying_need_label'] ?? 'Companion Partner')),
                'relationship_type' => $e['relationship_type'] ?? 'companion',
                'location' => AiListingIndexer::sanitizePromptInjection(($e['town'] ? $e['town'] . ', ' : '') . ($e['country'] ?? 'Kenya')),
                'accepts_lpo' => !empty($e['accepts_lpo']),
                'open_status' => $e['open_status'] ?? 'UNKNOWN',
                'distance_km' => $e['distance_km'] ?? null,
                'companion_rationale' => AiListingIndexer::sanitizePromptInjection((string)($e['match_rationale'] ?? '')),
            ];
        }

        $unmet = [];
        foreach ($retrieverResult['unmet_ecosystem_specialties'] ?? [] as $u) {
            $unmet[] = [
                'specialty_name' => $u['specialty_name'],
                'underlying_need_label' => $u['underlying_need_label'],
                'invite_prompt' => $u['invite_prompt'],
            ];
        }

        return [
            'direct_candidates' => $direct,
            'ecosystem_candidates' => $ecosystem,
            'unmet_specialties' => $unmet,
        ];
    }

    /**
     * Dispatch HTTPS request to Google Gemini API with timeout and structured JSON response schema.
     */
    public static function callGeminiInference(
        array $candidatesPayload,
        string $scrubbedQuery,
        string $apiKey,
        string $model = self::DEFAULT_MODEL
    ): ?array {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $systemInstruction = "You are the Biashara Concierge for Busherian Biasharas, the verified business directory for Alliance High School (AHS) and Alliance Girls High School (AGHS) alumni.\n"
            . "Your job is to recommend the best verified Busherian biasharas from the provided CANDIDATE LIST based on the visitor's question.\n\n"
            . "NON-NEGOTIABLE GROUNDING RULES:\n"
            . "1. You MAY ONLY recommend businesses that are explicitly listed in the direct_candidates or ecosystem_candidates arrays.\n"
            . "2. You are STRICTLY FORBIDDEN from inventing or hallucinating businesses, names, phone numbers, WhatsApp links, or addresses.\n"
            . "3. For each recommended business, you MUST provide its exact business_id and a 1-2 sentence tailored_reason explaining why it fits the user's inquiry.\n"
            . "4. Divide recommendations into direct_recommendations (addressing the primary ask) and ecosystem_recommendations (complementary lifecycle partners).\n"
            . "5. Maintain a warm, respectful alumni tone (Kiswahili greetings like 'Karibu' or 'Hongera' are welcome where natural). Keep conversational_response under 3 sentences.";

        $userPrompt = "Visitor Query: " . json_encode($scrubbedQuery) . "\n\n"
            . "Available Candidates:\n" . json_encode($candidatesPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $requestBody = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'response_mime_type' => 'application/json',
                'response_schema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'conversational_response' => [
                            'type' => 'STRING',
                            'description' => 'Warm, concise 1-3 sentence alumni summary of recommendations.',
                        ],
                        'direct_recommendations' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'business_id' => ['type' => 'STRING'],
                                    'tailored_reason' => ['type' => 'STRING'],
                                ],
                                'required' => ['business_id', 'tailored_reason'],
                            ],
                        ],
                        'ecosystem_recommendations' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'business_id' => ['type' => 'STRING'],
                                    'underlying_need_label' => ['type' => 'STRING'],
                                    'tailored_reason' => ['type' => 'STRING'],
                                ],
                                'required' => ['business_id', 'underlying_need_label', 'tailored_reason'],
                            ],
                        ],
                        'unmet_needs_commentary' => [
                            'type' => 'STRING',
                            'description' => 'Optional brief note if any complementary need has zero directory listings.',
                        ],
                    ],
                    'required' => ['conversational_response', 'direct_recommendations', 'ecosystem_recommendations'],
                ],
            ],
        ];

        $ch = curl_init($endpoint);
        if (!$ch) {
            return null;
        }

        $payloadJson = json_encode($requestBody);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !is_string($response) || $response === '') {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        $candidateText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($candidateText) || trim($candidateText) === '') {
            return null;
        }

        $parsed = json_decode($candidateText, true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Strict anti-phishing prose grounding:
     * Neutralizes hallucinated phone numbers, emails, URLs, M-Pesa/Till payment directives,
     * or off-platform payment lures from generated LLM text.
     */
    public static function sanitizeGroundedProse(string $text): string
    {
        $sanitized = trim($text);
        if ($sanitized === '') {
            return '';
        }

        // 1. Strip URLs and web links
        $sanitized = (string)preg_replace('/(?:https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/i', '[link omitted]', $sanitized);

        // 2. Strip email addresses
        $sanitized = (string)preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i', '[email omitted]', $sanitized);

        // 3. Strip phone numbers (Kenyan or international)
        $sanitized = (string)preg_replace('/(?:\+?254|0)[17]\d{2}[-\s]?\d{3}[-\s]?\d{3}|\+?[1-9]\d{0,3}[-\s]?(?:\(\d{1,4}\)[-\s]?)?\d{2,4}[-\s]?\d{2,4}[-\s]?\d{2,9}/', '[phone omitted]', $sanitized);

        // 4. Strip fraudulent payment handles / Till / Paybill / Account commands
        $sanitized = (string)preg_replace('/\b(?:paybill|till\s*(?:no|number)?|send\s*(?:money|kes|funds)|deposit\s*to|mpesa|m-pesa|account\s*no)\s*[:#.-]?\s*\d+\b/i', '[payment details omitted]', $sanitized);

        return $sanitized;
    }

    /**
     * Authoritative hydration of business cards from database records.
     * Enforces that only status = 'approved' records are returned, filtering out
     * any business that was quarantined, deleted, or unapproved since cache write.
     * Dynamically calculates distance_km if visitor coordinates are supplied.
     *
     * @param PDO $pdo
     * @param array<int, array<string, mixed>> $metaList List containing at least 'id' or 'business_id', plus recommendation meta
     * @param float|null $userLat
     * @param float|null $userLng
     * @return array<int, array<string, mixed>>
     */
    public static function hydrateApprovedBusinesses(
        PDO $pdo,
        array $metaList,
        ?float $userLat = null,
        ?float $userLng = null
    ): array {
        if (empty($metaList)) {
            return [];
        }

        $idMap = [];
        $bidMap = [];
        foreach ($metaList as $meta) {
            if (isset($meta['id']) && (int)$meta['id'] > 0) {
                $idMap[(int)$meta['id']] = $meta;
            }
            if (!empty($meta['business_id'])) {
                $bidMap[(string)$meta['business_id']] = $meta;
            }
        }

        $clauses = [];
        $params = [];
        if (!empty($idMap)) {
            $ph = implode(',', array_fill(0, count($idMap), '?'));
            $clauses[] = "b.id IN ({$ph})";
            foreach (array_keys($idMap) as $id) {
                $params[] = $id;
            }
        }
        if (!empty($bidMap)) {
            $ph = implode(',', array_fill(0, count($bidMap), '?'));
            $clauses[] = "b.business_id IN ({$ph})";
            foreach (array_keys($bidMap) as $bid) {
                $params[] = $bid;
            }
        }

        if (empty($clauses)) {
            return [];
        }

        $idOrBidSql = implode(' OR ', $clauses);
        $sql = "
            SELECT b.*,
                   sec.name AS sector_name, spec.name AS specialty_name,
                   sec2.name AS secondary_sector_name, spec2.name AS secondary_specialty_name
            FROM businesses b
            LEFT JOIN categories sec ON sec.id = b.sector_id
            LEFT JOIN categories spec ON spec.id = b.specialty_id
            LEFT JOIN categories sec2 ON sec2.id = b.secondary_sector_id
            LEFT JOIN categories spec2 ON spec2.id = b.secondary_specialty_id
            WHERE ({$idOrBidSql}) AND b.status = 'approved'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rowById = [];
        $rowByBid = [];
        foreach ($rows as $row) {
            $rowById[(int)$row['id']] = $row;
            if (!empty($row['business_id'])) {
                $rowByBid[(string)$row['business_id']] = $row;
            }
        }

        $hydrated = [];
        foreach ($metaList as $meta) {
            $row = null;
            if (isset($meta['id']) && isset($rowById[(int)$meta['id']])) {
                $row = $rowById[(int)$meta['id']];
            } elseif (!empty($meta['business_id']) && isset($rowByBid[(string)$meta['business_id']])) {
                $row = $rowByBid[(string)$meta['business_id']];
            }

            if ($row === null) {
                // Listing was quarantined, deleted, or unapproved -> exclude immediately!
                continue;
            }

            // Calculate dynamic distance if coordinates available
            $distKm = null;
            if ($userLat !== null && $userLng !== null && $row['latitude'] !== null && $row['longitude'] !== null) {
                $distKm = round(LocationEngine::haversineDistance($userLat, $userLng, (float)$row['latitude'], (float)$row['longitude']), 1);
            }

            $bizTz = LocationEngine::getTimezoneForCountry($row['country'] ?? null, $row['city_county'] ?? null);
            $hoursEval = !empty($row['operating_hours_json'])
                ? HoursEngine::evaluateStatus((string)$row['operating_hours_json'], null, $bizTz)
                : ['state' => 'HOURS_NOT_SET', 'label' => 'Hours not listed'];
            $photos = PhotoUploader::decodePhotosJson(isset($row['photos_json']) ? (string)$row['photos_json'] : null);

            // Construct full directory card payload conforming to directory.js contract
            $card = [
                'id' => (int)$row['id'],
                'business_id' => (string)$row['business_id'],
                'business_number' => (int)$row['business_number'],
                'name' => (string)$row['name'],
                'slug' => (string)$row['slug'],
                'tagline' => (string)$row['tagline'],
                'description' => (string)$row['description'],
                'sector_name' => (string)($row['sector_name'] ?? ''),
                'specialty_name' => (string)($row['specialty_name'] ?? ''),
                'category_name' => (string)($row['specialty_name'] ?? ''),
                'secondary_sector_name' => $row['secondary_sector_name'] ?? null,
                'secondary_specialty_name' => $row['secondary_specialty_name'] ?? null,
                'school_badge' => (string)$row['school_badge'],
                'phone_primary' => (string)$row['phone_primary'],
                'whatsapp_number' => $row['whatsapp_number'] ?? null,
                'email_public' => $row['email_public'] ?? null,
                'website_url' => $row['website_url'] ?? null,
                'city_county' => (string)$row['city_county'],
                'area_neighborhood' => (string)($row['area_neighborhood'] ?? ''),
                'country' => (string)($row['country'] ?? 'Kenya'),
                'latitude' => $row['latitude'] !== null ? (float)$row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float)$row['longitude'] : null,
                'hours_status' => [
                    'state' => $hoursEval['state'] ?? 'CLOSED',
                    'badge_text' => $hoursEval['badge_text'] ?? 'Closed',
                ],
                'is_bookable' => !empty($row['booking_enabled']),
                'booking_url' => $row['booking_url'] ?? null,
                'has_perk' => !empty($row['alumni_perk']),
                'perk_description' => $row['alumni_perk'] ?? null,
                'b2b_ready' => !empty($row['b2b_ready']) || !empty($row['accepts_lpo']),
                'accepts_lpo' => !empty($row['accepts_lpo']),
                'logo_path' => $row['logo_path'] ?? null,
                'photos' => $photos,
                'distance_km' => $distKm,
                // Match metadata
                'match_tier' => $meta['match_tier'] ?? 'direct',
                'tailored_reason' => $meta['tailored_reason'] ?? $meta['rationale'] ?? '',
                'underlying_need_label' => $meta['underlying_need_label'] ?? null,
                'is_popular' => ((float)($row['engagement_score'] ?? 0) >= 10.0),
                'is_new' => EngagementRanker::isWithinNewWindow($row['verified_at'] ?? null, $row['created_at'] ?? null, time()),
                'momentum_badge' => EngagementRanker::isWithinNewWindow($row['verified_at'] ?? null, $row['created_at'] ?? null, time()) ? 'new' : (((float)($row['engagement_score'] ?? 0) >= 10.0) ? 'popular' : null),
                // Compatibility aliases
                'phone' => (string)$row['phone_primary'],
                'whatsapp' => $row['whatsapp_number'] ?? null,
                'website' => $row['website_url'] ?? null,
                'town' => (string)$row['city_county'],
                'open_status' => $hoursEval['state'] ?? 'CLOSED',
            ];

            $hydrated[] = $card;
        }

        return $hydrated;
    }

    /**
     * Strict Grounding Verifier:
     * - Validates every recommended business_id against the candidate pool.
     * - Silently drops any unknown or hallucinated ID.
     * - Hydrates authoritative card data straight from DB candidate objects.
     */
    public static function verifyAndHydrateGrounding(
        array $llmJson,
        array $retrieverResult,
        string $scrubbedQuery
    ): ?array {
        $candidateLookup = [];
        foreach ($retrieverResult['direct_matches'] as $d) {
            $candidateLookup[(string)$d['business_id']] = $d;
            $candidateLookup[(string)$d['id']] = $d;
        }
        foreach ($retrieverResult['ecosystem_matches'] as $e) {
            $candidateLookup[(string)$e['business_id']] = $e;
            $candidateLookup[(string)$e['id']] = $e;
        }

        $verifiedDirect = [];
        foreach ($llmJson['direct_recommendations'] ?? [] as $rec) {
            $bid = (string)($rec['business_id'] ?? '');
            if ($bid !== '' && isset($candidateLookup[$bid])) {
                $item = $candidateLookup[$bid];
                $item['match_tier'] = 'direct';
                $reason = trim((string)($rec['tailored_reason'] ?? $item['match_rationale'] ?? ''));
                $item['tailored_reason'] = self::sanitizeGroundedProse($reason);
                $verifiedDirect[] = $item;
            }
        }

        $verifiedEcosystem = [];
        foreach ($llmJson['ecosystem_recommendations'] ?? [] as $rec) {
            $bid = (string)($rec['business_id'] ?? '');
            if ($bid !== '' && isset($candidateLookup[$bid])) {
                $item = $candidateLookup[$bid];
                $item['match_tier'] = 'ecosystem';
                $item['underlying_need_label'] = trim((string)($rec['underlying_need_label'] ?? $item['underlying_need_label'] ?? 'Companion Partner'));
                $reason = trim((string)($rec['tailored_reason'] ?? $item['match_rationale'] ?? ''));
                $item['tailored_reason'] = self::sanitizeGroundedProse($reason);
                $verifiedEcosystem[] = $item;
            }
        }

        // If the LLM returned zero valid grounded recommendations when candidates existed, fallback
        $totalCandidates = count($retrieverResult['direct_matches']) + count($retrieverResult['ecosystem_matches']);
        if ($totalCandidates > 0 && empty($verifiedDirect) && empty($verifiedEcosystem)) {
            return null;
        }

        $conversational = self::sanitizeGroundedProse(trim((string)($llmJson['conversational_response'] ?? '')));
        if ($conversational === '') {
            $conversational = "Here are verified Busherian biasharas tailored to your inquiry.";
        }

        return [
            'mode' => 'gemini',
            'conversational_response' => $conversational,
            'direct_recommendations' => $verifiedDirect,
            'ecosystem_recommendations' => $verifiedEcosystem,
            'unmet_ecosystem_specialties' => $retrieverResult['unmet_ecosystem_specialties'] ?? [],
            'unmet_needs_commentary' => isset($llmJson['unmet_needs_commentary']) && trim((string)$llmJson['unmet_needs_commentary']) !== ''
                ? self::sanitizeGroundedProse(trim((string)$llmJson['unmet_needs_commentary']))
                : null,
            'query_intent' => $retrieverResult['query_intent'] ?? [],
            'scrubbed_query' => $scrubbedQuery,
            'candidates_evaluated' => $retrieverResult['total_candidates'] ?? 0,
        ];
    }

    /**
     * Deterministic, dignified local fallback synthesis (Local Smart Mode).
     */
    public static function synthesizeLocalSmartMode(
        array $retrieverResult,
        string $scrubbedQuery
    ): array {
        $directMatches = $retrieverResult['direct_matches'] ?? [];
        $ecosystemMatches = $retrieverResult['ecosystem_matches'] ?? [];
        $unmetDemand = $retrieverResult['unmet_ecosystem_specialties'] ?? [];

        $directCount = count($directMatches);
        $ecoCount = count($ecosystemMatches);

        if ($directCount > 0 && $ecoCount > 0) {
            $conversational = "Found {$directCount} verified Busherian biashara" . ($directCount > 1 ? 's' : '') . " matching your request, alongside {$ecoCount} companion service" . ($ecoCount > 1 ? 's' : '') . " from fellow alumni to support your project.";
        } elseif ($directCount > 0) {
            $conversational = "Found {$directCount} verified Busherian biashara" . ($directCount > 1 ? 's' : '') . " matching your request.";
        } elseif ($ecoCount > 0) {
            $conversational = "We don't have a direct listing matching that exact specialty yet, but here are {$ecoCount} verified companion partner" . ($ecoCount > 1 ? 's' : '') . " from our alumni network that can help.";
        } else {
            $conversational = "We currently don't have an approved listing matching that exact inquiry in the directory. If you know a fellow Busherian who offers this service, invite them to list their biashara!";
        }

        $directRecs = [];
        foreach (array_slice($directMatches, 0, 4) as $d) {
            $d['match_tier'] = 'direct';
            $d['tailored_reason'] = $d['match_rationale'] ?? "Verified Busherian provider in {$d['category_name']}.";
            $directRecs[] = $d;
        }

        $ecosystemRecs = [];
        foreach (array_slice($ecosystemMatches, 0, 4) as $e) {
            $e['match_tier'] = 'ecosystem';
            $e['tailored_reason'] = $e['match_rationale'] ?? "Recommended companion partner in {$e['category_name']}.";
            $ecosystemRecs[] = $e;
        }

        return [
            'mode' => 'local_smart',
            'conversational_response' => $conversational,
            'direct_recommendations' => $directRecs,
            'ecosystem_recommendations' => $ecosystemRecs,
            'unmet_ecosystem_specialties' => $unmetDemand,
            'unmet_needs_commentary' => !empty($unmetDemand)
                ? "Our community currently has open opportunities for alumni in " . implode(', ', array_column($unmetDemand, 'specialty_name')) . "."
                : null,
            'query_intent' => $retrieverResult['query_intent'] ?? [],
            'scrubbed_query' => $scrubbedQuery,
            'candidates_evaluated' => $retrieverResult['total_candidates'] ?? 0,
        ];
    }
}
