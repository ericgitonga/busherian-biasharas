<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use DateTimeImmutable;
use PDO;

/**
 * ConciergeRetriever — Hybrid Candidate Retrieval Engine
 *
 * Implements Layer 2 candidate retrieval for the Intelligent Biashara Concierge:
 * 1. Hybrid intent parsing (specialties, semantic keywords, geographic, temporal, B2B/LPO).
 * 2. Strict approved-public boundary: queries only status = 'approved' businesses with active business_ai_index rows.
 * 3. Two-tier candidate partitioning: Direct Matches vs. Adjacent Ecosystem Suggestions (via NeedGraph).
 * 4. Multi-factor operational scoring: relevance, Haversine distance, local open-now status, B2B boost, engagement momentum.
 * 5. Seeded peer-fairness rotation: 2-hour slotted deterministic permutation for near-tie candidate bands.
 * 6. Unmet ecosystem demand detection: flags adjacent specialties with 0 approved listings for community growth prompts.
 */
final class ConciergeRetriever
{
    public const DEFAULT_DIRECT_LIMIT = 10;
    public const DEFAULT_ECOSYSTEM_LIMIT = 6;
    public const NEAR_TIE_SCORE_BAND = 1.5;

    /**
     * B2B & Corporate intent indicator keywords.
     */
    private const B2B_CUE_WORDS = [
        'lpo', 'corporate', 'tender', 'b2b', 'procurement', 'invoice', 'bulk',
        'enterprise', 'company', 'institution', 'contractor', 'vendor', 'supplier',
    ];

    /**
     * Temporal / Open Now intent indicator keywords.
     */
    private const TEMPORAL_CUE_WORDS = [
        'now', 'open', 'today', 'tonight', 'urgent', 'emergency', 'asap', 'currently',
    ];

    /**
     * Retrieve structured candidate recommendations for a user query.
     *
     * @param PDO $pdo Authoritative database connection
     * @param array{
     *     query?: string,
     *     specialties?: array<int|string>,
     *     sector_id?: int|null,
     *     latitude?: float|null,
     *     longitude?: float|null,
     *     town?: string|null,
     *     open_now_only?: bool,
     *     b2b_only?: bool,
     *     timestamp?: int|null,
     *     user_seed?: string|null,
     *     limit_direct?: int,
     *     limit_ecosystem?: int
     * } $queryContext
     * @param array<string, mixed> $options
     * @return array{
     *     direct_matches: array<int, array<string, mixed>>,
     *     ecosystem_matches: array<int, array<string, mixed>>,
     *     unmet_ecosystem_specialties: array<int, array<string, mixed>>,
     *     query_intent: array<string, mixed>,
     *     total_candidates: int
     * }
     */
    public static function retrieveCandidates(PDO $pdo, array $queryContext, array $options = []): array
    {
        $rawQuery = trim((string)($queryContext['query'] ?? ''));
        $nowTs = $queryContext['timestamp'] ?? time();
        $userLat = isset($queryContext['latitude']) ? (float)$queryContext['latitude'] : null;
        $userLng = isset($queryContext['longitude']) ? (float)$queryContext['longitude'] : null;
        $openNowOnly = !empty($queryContext['open_now_only']);
        $b2bOnly = !empty($queryContext['b2b_only']);
        $limitDirect = max(1, (int)($queryContext['limit_direct'] ?? self::DEFAULT_DIRECT_LIMIT));
        $limitEcosystem = max(1, (int)($queryContext['limit_ecosystem'] ?? self::DEFAULT_ECOSYSTEM_LIMIT));

        // 1. Parse Query Intent
        $intent = self::parseQueryIntent($pdo, $rawQuery, $queryContext);

        // 2. Identify Direct Specialties & Adjacent Companion Specialties
        $directSpecialties = $intent['matched_specialties'];
        $directSpecialtyIds = array_column($directSpecialties, 'id');
        $directSpecialtyNames = array_column($directSpecialties, 'name');

        $adjacentMap = [];
        $unmetDemandCandidates = [];
        foreach ($directSpecialtyNames as $sourceName) {
            $adjacents = NeedGraph::getAdjacentSpecialties($sourceName, null, $pdo);
            foreach ($adjacents as $adj) {
                $target = $adj['target_specialty'];
                if (!in_array($target, $directSpecialtyNames, true) && !isset($adjacentMap[$target])) {
                    $adjacentMap[$target] = $adj;
                }
            }
        }

        // Resolve IDs for adjacent specialties
        $adjacentSpecialtyIds = [];
        if (!empty($adjacentMap)) {
            $placeholders = implode(',', array_fill(0, count($adjacentMap), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE name IN ({$placeholders}) AND status = 'approved'");
            $stmt->execute(array_keys($adjacentMap));
            $foundAdjRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($foundAdjRows as $arow) {
                $adjacentSpecialtyIds[(int)$arow['id']] = (string)$arow['name'];
            }
        }

        // 3. Fetch Candidate Pool from DB
        // Strict boundary: businesses.status = 'approved' and active in business_ai_index
        $pool = self::fetchCandidatePool($pdo, $directSpecialtyIds, array_keys($adjacentSpecialtyIds), $intent['tokens']);

        if (empty($pool)) {
            return [
                'direct_matches' => [],
                'ecosystem_matches' => [],
                'unmet_ecosystem_specialties' => [],
                'query_intent' => $intent,
                'total_candidates' => 0,
            ];
        }

        // 4. Score and Partition Candidates
        $scoredDirect = [];
        $scoredEcosystem = [];
        $approvedSpecialtyCounts = [];

        foreach ($pool as $biz) {
            $bizId = (int)$biz['id'];
            $catId = (int)$biz['specialty_id'];
            $secCatId = $biz['secondary_specialty_id'] !== null ? (int)$biz['secondary_specialty_id'] : null;

            // Track specialty counts for unmet demand
            $approvedSpecialtyCounts[$catId] = ($approvedSpecialtyCounts[$catId] ?? 0) + 1;
            if ($secCatId !== null) {
                $approvedSpecialtyCounts[$secCatId] = ($approvedSpecialtyCounts[$secCatId] ?? 0) + 1;
            }

            // Resolve business timezone & evaluate Operating Hours in business timezone (#328)
            $bizTz = LocationEngine::getTimezoneForCountry($biz['country'] ?? null, $biz['city_county'] ?? null);
            $hoursEval = HoursEngine::evaluateStatus(
                $biz['operating_hours_json'] ?? '',
                new DateTimeImmutable("@{$nowTs}"),
                $bizTz
            );
            $hoursState = $hoursEval['state'] ?? 'UNKNOWN';

            if ($openNowOnly && $hoursState === 'CLOSED') {
                continue;
            }

            // B2B Only Filter
            $isB2b = !empty($biz['accepts_lpo']) || !empty($biz['b2b_ready']) || str_contains(strtolower((string)$biz['capability_digest']), 'b2b') || str_contains(strtolower((string)$biz['capability_digest']), 'corporate');
            if ($b2bOnly && !$isB2b) {
                continue;
            }

            // Determine if Direct vs Ecosystem Candidate
            $isDirectMatch = in_array($catId, $directSpecialtyIds, true)
                || ($secCatId !== null && in_array($secCatId, $directSpecialtyIds, true));

            if (!$isDirectMatch && empty($directSpecialtyIds)) {
                $isDirectMatch = self::matchesDirectKeywordThreshold($biz, $intent['tokens']);
            }

            // Calculate Base Scores
            $scoreData = self::calculateRelevanceScore(
                $biz,
                $intent,
                $isDirectMatch,
                $hoursEval,
                $userLat,
                $userLng,
                $adjacentMap
            );

            $bizPayload = [
                'id' => $bizId,
                'business_id' => $biz['business_id'] ?? (string)$bizId,
                'business_number' => (int)$biz['business_number'],
                'name' => (string)$biz['name'],
                'slug' => (string)$biz['slug'],
                'tagline' => (string)$biz['tagline'],
                'description' => (string)$biz['description'],
                'category_name' => (string)$biz['category_name'],
                'secondary_category_name' => $biz['secondary_category_name'] ?? null,
                'school_badge' => (string)$biz['school_badge'],
                'phone' => (string)$biz['phone_primary'],
                'whatsapp' => $biz['whatsapp_number'] ?? null,
                'email_public' => $biz['email_public'] ?? null,
                'website' => $biz['website_url'] ?? null,
                'town' => $biz['city_county'] ?? null,
                'estate' => $biz['area_neighborhood'] ?? null,
                'country' => $biz['country'] ?? 'Kenya',
                'accepts_lpo' => !empty($biz['accepts_lpo']),
                'open_status' => $hoursState,
                'open_status_label' => $hoursEval['badge_text'] ?? '',
                'distance_km' => $scoreData['distance_km'],
                'engagement_score' => (float)($biz['engagement_score'] ?? 0),
                'relevance_score' => $scoreData['total_score'],
                'score_breakdown' => $scoreData['breakdown'],
                'capability_digest' => $biz['capability_digest'] ?? null,
            ];

            if ($isDirectMatch) {
                $bizPayload['match_tier'] = 'direct';
                $bizPayload['match_rationale'] = $scoreData['direct_rationale'];
                $scoredDirect[] = $bizPayload;
            } elseif (isset($scoreData['adjacent_meta'])) {
                $bizPayload['match_tier'] = 'ecosystem';
                $bizPayload['underlying_need_label'] = $scoreData['adjacent_meta']['underlying_need_label'];
                $bizPayload['relationship_type'] = $scoreData['adjacent_meta']['relationship_type'];
                $bizPayload['match_rationale'] = $scoreData['adjacent_meta']['rationale'];
                $scoredEcosystem[] = $bizPayload;
            }
        }

        // 5. Apply Seeded Peer Fairness Rotation (2-Hour Near-Tie Bands)
        $rankedDirect = self::applyPeerFairnessRotation($scoredDirect, $nowTs, $queryContext['user_seed'] ?? null);
        $rankedEcosystem = self::applyPeerFairnessRotation($scoredEcosystem, $nowTs, $queryContext['user_seed'] ?? null);

        // Truncate to limits
        $finalDirect = array_slice($rankedDirect, 0, $limitDirect);
        $finalEcosystem = array_slice($rankedEcosystem, 0, $limitEcosystem);

        // 6. Detect Unmet Demand in Ecosystem Specialties
        $unmetDemand = [];
        foreach ($adjacentMap as $targetSpecialty => $adjMeta) {
            $targetId = array_search($targetSpecialty, $adjacentSpecialtyIds, true);
            $hasListings = $targetId !== false && isset($approvedSpecialtyCounts[$targetId]) && $approvedSpecialtyCounts[$targetId] > 0;
            if (!$hasListings) {
                // Verify whether any approved listing exists in the DB for this specialty
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE (specialty_id = ? OR secondary_specialty_id = ?) AND status = 'approved'");
                $stmtCheck->execute([$targetId ?: 0, $targetId ?: 0]);
                $cnt = (int)$stmtCheck->fetchColumn();
                if ($cnt === 0) {
                    $unmetDemand[] = [
                        'specialty_name' => $targetSpecialty,
                        'underlying_need_label' => $adjMeta['underlying_need_label'],
                        'rationale' => $adjMeta['rationale'],
                        'invite_prompt' => "Know a fellow Busherian in {$targetSpecialty}? Invite them to list their biashara!",
                    ];
                }
            }
        }

        return [
            'direct_matches' => $finalDirect,
            'ecosystem_matches' => $finalEcosystem,
            'unmet_ecosystem_specialties' => $unmetDemand,
            'query_intent' => $intent,
            'total_candidates' => count($finalDirect) + count($finalEcosystem),
        ];
    }

    /**
     * Parse raw user search query into structured intent terms.
     *
     * @param PDO $pdo
     * @param string $rawQuery
     * @param array<string, mixed> $extraContext
     * @return array{
     *     raw_query: string,
     *     tokens: string[],
     *     matched_specialties: array<int, array{id: int, name: string, score: float}>,
     *     detected_cluster: array{cluster_key: string, label: string, description: string, score: int}|null,
     *     has_b2b_intent: bool,
     *     has_open_now_intent: bool,
     *     has_location_filter: bool
     * }
     */
    public static function parseQueryIntent(PDO $pdo, string $rawQuery, array $extraContext = []): array
    {
        $clean = strtolower(trim($rawQuery));
        $tokens = CategoryMatcher::extractMeaningfulTokens($clean);

        // Check if caller supplied explicit specialty IDs or names
        $matchedSpecialties = [];
        if (!empty($extraContext['specialties'])) {
            foreach ($extraContext['specialties'] as $sp) {
                if (is_numeric($sp)) {
                    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE id = ? AND status = 'approved'");
                    $stmt->execute([(int)$sp]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $matchedSpecialties[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'score' => 1.0];
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE name = ? AND status = 'approved'");
                    $stmt->execute([(string)$sp]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $matchedSpecialties[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'score' => 1.0];
                    }
                }
            }
        }

        // If no explicit specialties provided, detect via CategoryMatcher search
        if (empty($matchedSpecialties) && $clean !== '') {
            $searchResults = CategoryMatcher::searchSpecialties($pdo, $clean, 5);
            foreach ($searchResults as $res) {
                $specId = (int)($res['specialty_id'] ?? $res['id'] ?? 0);
                $specName = (string)($res['specialty_name'] ?? $res['name'] ?? '');
                if ($specId > 0 && $specName !== '') {
                    $matchedSpecialties[] = [
                        'id' => $specId,
                        'name' => $specName,
                        'score' => (float)($res['score'] ?? 1.0),
                    ];
                }
            }
        }

        // B2B & Open Now intent cues
        $hasB2b = !empty($extraContext['b2b_only']);
        $hasOpenNow = !empty($extraContext['open_now_only']);

        foreach ($tokens as $token) {
            if (in_array($token, self::B2B_CUE_WORDS, true)) {
                $hasB2b = true;
            }
            if (in_array($token, self::TEMPORAL_CUE_WORDS, true)) {
                $hasOpenNow = true;
            }
        }

        // Detect Ecosystem Cluster
        $specialtyNames = array_column($matchedSpecialties, 'name');
        $cluster = NeedGraph::getClusterForSpecialties(array_merge($specialtyNames, $tokens));

        return [
            'raw_query' => $rawQuery,
            'tokens' => $tokens,
            'matched_specialties' => $matchedSpecialties,
            'detected_cluster' => $cluster,
            'has_b2b_intent' => $hasB2b,
            'has_open_now_intent' => $hasOpenNow,
            'has_location_filter' => isset($extraContext['latitude']) || !empty($extraContext['town']),
        ];
    }

    /**
     * Query candidate listings from businesses table joined with business_ai_index.
     * Enforces status = 'approved' and active row in business_ai_index.
     */
    private static function fetchCandidatePool(PDO $pdo, array $directIds, array $adjacentIds, array $tokens): array
    {
        $allSpecialtyIds = array_values(array_unique(array_merge($directIds, $adjacentIds)));

        $clauses = ["b.status = 'approved'"];
        $params = [];

        $specOrKeywordClauses = [];

        if (!empty($allSpecialtyIds)) {
            $ph = implode(',', array_fill(0, count($allSpecialtyIds), '?'));
            $specOrKeywordClauses[] = "(b.specialty_id IN ({$ph}) OR b.secondary_specialty_id IN ({$ph}))";
            foreach ($allSpecialtyIds as $sid) {
                $params[] = $sid;
            }
            foreach ($allSpecialtyIds as $sid) {
                $params[] = $sid;
            }
        }

        // Keyword matches in AI keywords_norm or capability_digest
        if (!empty($tokens)) {
            $kwClauses = [];
            foreach (array_slice($tokens, 0, 4) as $tok) {
                if (strlen($tok) >= 3) {
                    $kwClauses[] = "(ai.keywords_norm LIKE ? OR ai.capability_digest LIKE ? OR b.name LIKE ?)";
                    $likeTok = '%' . $tok . '%';
                    $params[] = $likeTok;
                    $params[] = $likeTok;
                    $params[] = $likeTok;
                }
            }
            if (!empty($kwClauses)) {
                $specOrKeywordClauses[] = '(' . implode(' OR ', $kwClauses) . ')';
            }
        }

        if (!empty($specOrKeywordClauses)) {
            $clauses[] = '(' . implode(' OR ', $specOrKeywordClauses) . ')';
        }

        $whereSql = implode(' AND ', $clauses);

        $sql = "SELECT b.id, b.business_id, b.business_number, b.name, b.slug, b.tagline, b.description,
                       b.school_badge, b.specialty_id, b.secondary_specialty_id, b.phone_primary, b.whatsapp_number,
                       b.email_public, b.website_url, b.operating_hours_json, b.latitude, b.longitude,
                       b.city_county, b.area_neighborhood, b.country, b.accepts_lpo, b.b2b_ready, b.engagement_score,
                       c1.name AS category_name, c2.name AS secondary_category_name,
                       ai.capability_digest, ai.keywords_norm
                FROM businesses b
                INNER JOIN business_ai_index ai ON b.id = ai.business_id
                LEFT JOIN categories c1 ON b.specialty_id = c1.id
                LEFT JOIN categories c2 ON b.secondary_specialty_id = c2.id
                WHERE {$whereSql}
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a listing has sufficient direct keyword matches to be evaluated as direct.
     */
    private static function matchesDirectKeywordThreshold(array $biz, array $tokens): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $haystack = strtolower(($biz['name'] ?? '') . ' ' . ($biz['tagline'] ?? '') . ' ' . ($biz['keywords_norm'] ?? ''));
        $matched = 0;
        foreach ($tokens as $token) {
            if (strlen($token) >= 3 && str_contains($haystack, $token)) {
                $matched++;
            }
        }

        return $matched >= 2;
    }

    /**
     * Compute multi-factor relevance score.
     */
    private static function calculateRelevanceScore(
        array $biz,
        array $intent,
        bool $isDirectMatch,
        array $hoursEval,
        ?float $userLat,
        ?float $userLng,
        array $adjacentMap
    ): array {
        $score = 0.0;
        $breakdown = [];
        $directSpecialtyIds = array_column($intent['matched_specialties'], 'id');
        $catId = (int)$biz['specialty_id'];
        $secCatId = $biz['secondary_specialty_id'] !== null ? (int)$biz['secondary_specialty_id'] : null;

        $adjacentMeta = null;
        $directRationale = null;

        if ($isDirectMatch) {
            // Category Match
            if (in_array($catId, $directSpecialtyIds, true)) {
                $score += 10.0;
                $breakdown['category_match'] = 10.0;
                $directRationale = "Matches primary specialty ({$biz['category_name']})";
            } elseif ($secCatId !== null && in_array($secCatId, $directSpecialtyIds, true)) {
                $score += 6.0;
                $breakdown['secondary_category_match'] = 6.0;
                $directRationale = "Matches secondary specialty ({$biz['secondary_category_name']})";
            } else {
                $score += 4.0;
                $breakdown['keyword_match_direct'] = 4.0;
                $directRationale = "Matches requested service capabilities";
            }
        } else {
            // Ecosystem Match
            $catName = (string)$biz['category_name'];
            $secCatName = (string)($biz['secondary_category_name'] ?? '');

            if (isset($adjacentMap[$catName])) {
                $adjacentMeta = $adjacentMap[$catName];
            } elseif ($secCatName !== '' && isset($adjacentMap[$secCatName])) {
                $adjacentMeta = $adjacentMap[$secCatName];
            } else {
                // Check canonical match
                $canCat = NeedGraph::matchCanonicalSpecialtyKey($catName);
                if ($canCat !== null && isset($adjacentMap[$canCat])) {
                    $adjacentMeta = $adjacentMap[$canCat];
                }
            }

            if ($adjacentMeta !== null) {
                $adjWeight = (float)($adjacentMeta['weight'] ?? 0.8);
                $adjScore = round(7.0 * $adjWeight, 2);
                $score += $adjScore;
                $breakdown['ecosystem_adjacency'] = $adjScore;
            }
        }

        // Keyword Overlap
        $keywords = strtolower((string)($biz['keywords_norm'] ?? ''));
        $digest = strtolower((string)($biz['capability_digest'] ?? ''));
        $tokenHits = 0;
        foreach ($intent['tokens'] as $tok) {
            if (strlen($tok) >= 3) {
                if (str_contains($keywords, $tok)) {
                    $tokenHits++;
                } elseif (str_contains($digest, $tok)) {
                    $tokenHits += 0.5;
                }
            }
        }
        if ($tokenHits > 0) {
            $kwScore = min(5.0, round($tokenHits * 1.5, 2));
            $score += $kwScore;
            $breakdown['keyword_overlap'] = $kwScore;
        }

        // B2B / Corporate Fit
        $isB2b = !empty($biz['accepts_lpo']) || str_contains($digest, 'b2b') || str_contains($digest, 'corporate');
        if ($intent['has_b2b_intent'] && $isB2b) {
            $score += 3.0;
            $breakdown['b2b_lpo_boost'] = 3.0;
        }

        // Operating Hours
        $openState = $hoursEval['state'] ?? 'UNKNOWN';
        if ($openState === 'OPEN_NOW') {
            $score += 2.0;
            $breakdown['open_now_boost'] = 2.0;
        } elseif ($openState === 'CLOSING_SOON') {
            $score += 1.0;
            $breakdown['closing_soon_boost'] = 1.0;
        }

        // Distance & Proximity
        $distKm = null;
        if ($userLat !== null && $userLng !== null && !empty($biz['latitude']) && !empty($biz['longitude'])) {
            $distKm = LocationEngine::haversineKm($userLat, $userLng, (float)$biz['latitude'], (float)$biz['longitude']);
            if ($distKm <= 5.0) {
                $score += 3.0;
                $breakdown['proximity_boost_5km'] = 3.0;
            } elseif ($distKm <= 15.0) {
                $score += 1.5;
                $breakdown['proximity_boost_15km'] = 1.5;
            } elseif ($distKm > 50.0) {
                $score -= 1.0;
                $breakdown['distance_penalty_50km'] = -1.0;
            }
        }

        // Engagement Momentum (Logarithmic)
        $engScore = max(0.0, (float)($biz['engagement_score'] ?? 0));
        if ($engScore > 0.0) {
            $engBoost = min(2.5, round(0.4 * log(1.0 + $engScore), 2));
            $score += $engBoost;
            $breakdown['engagement_momentum'] = $engBoost;
        }

        return [
            'total_score' => round($score, 2),
            'distance_km' => $distKm !== null ? round($distKm, 2) : null,
            'breakdown' => $breakdown,
            'direct_rationale' => $directRationale,
            'adjacent_meta' => $adjacentMeta,
        ];
    }

    /**
     * Apply Seeded Peer Fairness Rotation to Near-Tie Candidate Bands.
     *
     * Candidates within NEAR_TIE_SCORE_BAND points of each other share a rank band.
     * Within the same band, candidate order is rotated by a 2-hour slotted permutation
     * to guarantee that equal alumni peers rotate fairly across visits.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @param int $nowTs
     * @param string|null $userSeed
     * @return array<int, array<string, mixed>>
     */
    public static function applyPeerFairnessRotation(array $candidates, int $nowTs, ?string $userSeed = null): array
    {
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $params = EngagementRanker::computeTieRotationParams($nowTs);
        $slot = $params['slot'];
        $mult = $params['mult'];
        $add = $params['add'];
        $seedInt = $userSeed !== null ? (int)sprintf('%u', crc32($userSeed)) : 0;

        foreach ($candidates as &$c) {
            $score = (float)$c['relevance_score'];
            // Quantize into near-tie bands
            $band = (int)floor($score / self::NEAR_TIE_SCORE_BAND);
            $c['_tie_band'] = $band;

            // Deterministic rotation rank within band
            $bizId = (int)$c['id'];
            $perm = (($bizId + ($seedInt % 100)) * $mult + $add) % EngagementRanker::TIE_ROTATION_PRIME;
            $c['_rotation_key'] = $perm;
        }
        unset($c);

        usort($candidates, static function (array $a, array $b): int {
            // 1. Clearly higher tie-band always wins
            if ($a['_tie_band'] !== $b['_tie_band']) {
                return $b['_tie_band'] <=> $a['_tie_band'];
            }
            // 2. Inside the same near-tie band, sort by deterministic 2-hour rotated permutation
            if ($a['_rotation_key'] !== $b['_rotation_key']) {
                return $a['_rotation_key'] <=> $b['_rotation_key'];
            }
            // 3. Absolute deterministic tiebreaker
            return $a['id'] <=> $b['id'];
        });

        // Strip internal sorting fields
        foreach ($candidates as &$c) {
            unset($c['_tie_band'], $c['_rotation_key']);
        }
        unset($c);

        return $candidates;
    }
}
