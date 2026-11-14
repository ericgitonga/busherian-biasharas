<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;

/**
 * Biashara Concierge AI Listing Indexer (Chapter 2, Chunk 6, #330-#332).
 * Distills compact, prompt-injection-safe capability digests from verified approved listings,
 * computes deterministic content hashes for change detection, and syncs transactionally
 * with listing moderation, owner edits, quarantine, and deletion lifecycles.
 */
final class AiListingIndexer
{
    /**
     * Regex patterns for adversarial prompt injections, role spoofing, and exfiltration vectors.
     */
    private const INJECTION_PATTERNS = [
        // System instruction override phrases
        '/\b(?:ignore|disregard|forget|override|bypass)\s+(?:all\s+)?(?:previous|prior|above|system|existing)\s+(?:instructions?|prompts?|rules?|directives?|guidelines?)\b/i',
        '/\b(?:you\s+are\s+now|act\s+as\s+(?:an?\s+)?(?:unrestricted|jailbreak|DAN|evil|developer|root|admin|AI\s+model)|pretend\s+you\s+are)\b/i',
        '/\b(?:new\s+(?:prompt|instructions?|system\s+directive)|instruction\s+override)\s*[:=]/i',
        // Role delimiters & ChatML tokens
        '/<\|(?:im_start|im_end|endoftext|startoftext)\|>/i',
        '/\[\/?(?:INST|SYS)\]/i',
        '/<<\/?SYS>>/i',
        '/^(?:system|assistant|user|developer|directive)\s*:/im',
        // Markdown/HTML exfiltration & script injection
        '/!\[[^\]]*\]\((?:https?:|\/\/)[^\)]+\)/i',
        '/<script\b[^>]*>.*?<\/script>/is',
        '/javascript\s*:/i',
    ];

    /**
     * Common English/Swahili stop words to exclude from normalized keyword index.
     */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'has', 'he',
        'in', 'is', 'it', 'its', 'of', 'on', 'that', 'the', 'to', 'was', 'were', 'will',
        'with', 'na', 'ya', 'wa', 'kwa', 'katika', 'ni', 'la', 'za', 'cha', 'vya', 'au',
        'we', 'our', 'us', 'all', 'any', 'can', 'do', 'if', 'or', 'so', 'up', 'out'
    ];

    /**
     * Neutralize prompt-injection tokens, role spoofing, and exfiltration attempts from free text.
     */
    public static function sanitizePromptInjection(string $text): string
    {
        $sanitized = $text;
        foreach (self::INJECTION_PATTERNS as $pattern) {
            $sanitized = (string)preg_replace($pattern, ' [filtered] ', $sanitized);
        }
        // Normalize whitespace and newlines
        $sanitized = (string)preg_replace('/[ \t]+/', ' ', $sanitized);
        $sanitized = (string)preg_replace('/(?:\r?\n){3,}/', "\n\n", $sanitized);
        return trim($sanitized);
    }

    /**
     * Distill a structured, prompt-injection-safe capability digest from verified business fields.
     */
    public static function buildCapabilityDigest(array $biz): string
    {
        $name = self::sanitizePromptInjection((string)($biz['name'] ?? ''));
        $tagline = self::sanitizePromptInjection((string)($biz['tagline'] ?? ''));
        $description = self::sanitizePromptInjection((string)($biz['description'] ?? ''));

        $sector = trim((string)($biz['sector_name'] ?? ''));
        $specialty = trim((string)($biz['specialty_name'] ?? ''));
        $primaryCategory = ($sector !== '' && $specialty !== '') ? "{$sector} > {$specialty}" : ($specialty ?: $sector);

        $secSector = trim((string)($biz['secondary_sector_name'] ?? ''));
        $secSpecialty = trim((string)($biz['secondary_specialty_name'] ?? ''));
        $secondaryCategory = ($secSector !== '' && $secSpecialty !== '') ? "{$secSector} > {$secSpecialty}" : ($secSpecialty ?: $secSector);

        $serviceMode = strtolower(trim((string)($biz['service_mode'] ?? 'physical')));
        $modeLabel = match ($serviceMode) {
            'online' => 'Online Only',
            'hybrid' => 'Physical & Online',
            default => 'Physical Premises',
        };

        $cityCounty = trim((string)($biz['city_county'] ?? ''));
        $country = trim((string)($biz['country'] ?? 'Kenya'));
        $locationStr = $cityCounty !== '' ? "{$cityCounty}, {$country}" : $country;

        $b2bReady = !empty($biz['b2b_ready']);
        $acceptsLpo = !empty($biz['accepts_lpo']);
        $b2bStr = $b2bReady ? ('B2B Ready' . ($acceptsLpo ? ' (Accepts LPO)' : '')) : 'Consumer (B2C)';

        $alumniPerk = self::sanitizePromptInjection(trim((string)($biz['alumni_perk'] ?? '')));
        $perkStr = $alumniPerk !== '' ? $alumniPerk : 'None listed';

        $schoolBadge = in_array($biz['school_badge'] ?? '', ['AHS', 'AGHS', 'BOTH'], true) ? $biz['school_badge'] : 'BOTH';

        $lines = [
            "Business: {$name}",
            "School Heritage: {$schoolBadge}",
            "Primary Category: {$primaryCategory}",
        ];
        if ($secondaryCategory !== '') {
            $lines[] = "Secondary Category: {$secondaryCategory}";
        }
        if ($tagline !== '') {
            $lines[] = "Tagline: {$tagline}";
        }
        if ($description !== '') {
            $lines[] = "Description: {$description}";
        }
        $lines[] = "Service Mode: {$modeLabel}";
        $lines[] = "Location: {$locationStr}";
        $lines[] = "Commercial Capabilities: {$b2bStr}";
        if ($perkStr !== 'None listed') {
            $lines[] = "Alumni Perk: {$perkStr}";
        }

        return implode("\n", $lines);
    }

    /**
     * Extract normalized, space-separated searchable capability keywords capped at 500 characters.
     */
    public static function buildNormalizedKeywords(array $biz): string
    {
        $sources = [
            (string)($biz['name'] ?? ''),
            (string)($biz['tagline'] ?? ''),
            (string)($biz['description'] ?? ''),
            (string)($biz['sector_name'] ?? ''),
            (string)($biz['specialty_name'] ?? ''),
            (string)($biz['secondary_sector_name'] ?? ''),
            (string)($biz['secondary_specialty_name'] ?? ''),
            (string)($biz['city_county'] ?? ''),
            (string)($biz['country'] ?? ''),
            (string)($biz['alumni_perk'] ?? ''),
        ];
        $combined = mb_strtolower(implode(' ', $sources), 'UTF-8');
        $sanitized = self::sanitizePromptInjection($combined);

        // Match alphanumeric tokens (3+ chars)
        preg_match_all('/\b[\p{L}\p{N}]{3,30}\b/u', $sanitized, $matches);
        $tokens = $matches[0] ?? [];

        $stopMap = array_flip(self::STOP_WORDS);
        $seen = [];
        $unique = [];
        foreach ($tokens as $t) {
            $norm = mb_strtolower(trim($t), 'UTF-8');
            if (isset($stopMap[$norm]) || isset($seen[$norm]) || $norm === 'filtered') {
                continue;
            }
            $seen[$norm] = true;
            $unique[] = $norm;
        }

        $res = implode(' ', $unique);
        if (mb_strlen($res) > 500) {
            $res = mb_substr($res, 0, 500);
            $lastSpace = mb_strrpos($res, ' ');
            if ($lastSpace !== false) {
                $res = mb_substr($res, 0, $lastSpace);
            }
        }
        return $res;
    }

    /**
     * Compute a deterministic SHA-256 content hash of all public fields that feed the capability index.
     */
    public static function computeContentHash(array $biz): string
    {
        $payload = implode('|', [
            trim((string)($biz['name'] ?? '')),
            trim((string)($biz['tagline'] ?? '')),
            trim((string)($biz['description'] ?? '')),
            (string)($biz['school_badge'] ?? 'BOTH'),
            (int)($biz['sector_id'] ?? 0),
            (int)($biz['specialty_id'] ?? 0),
            (int)($biz['secondary_sector_id'] ?? 0),
            (int)($biz['secondary_specialty_id'] ?? 0),
            trim((string)($biz['service_mode'] ?? '')),
            !empty($biz['b2b_ready']) ? '1' : '0',
            !empty($biz['accepts_lpo']) ? '1' : '0',
            trim((string)($biz['alumni_perk'] ?? '')),
            trim((string)($biz['city_county'] ?? '')),
            trim((string)($biz['country'] ?? '')),
        ]);
        return hash('sha256', $payload);
    }

    /**
     * Hydrate a business record with joined sector/specialty category names.
     *
     * @return array<string, mixed>|null
     */
    public static function fetchFullBusinessRow(PDO $pdo, int $businessId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT b.*,
                   sec.name AS sector_name, sec.slug AS sector_slug,
                   spec.name AS specialty_name, spec.slug AS specialty_slug,
                   sec2.name AS secondary_sector_name, sec2.slug AS secondary_sector_slug,
                   spec2.name AS secondary_specialty_name, spec2.slug AS secondary_specialty_slug
            FROM businesses b
            INNER JOIN categories sec ON sec.id = b.sector_id
            INNER JOIN categories spec ON spec.id = b.specialty_id
            LEFT JOIN categories sec2 ON sec2.id = b.secondary_sector_id
            LEFT JOIN categories spec2 ON spec2.id = b.secondary_specialty_id
            WHERE b.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Index or update an approved business record in `business_ai_index`.
     * If the business is not approved or cannot be found, it is purged from the index.
     */
    public static function indexBusiness(PDO $pdo, int $businessId, ?array $businessRow = null): bool
    {
        if ($businessId <= 0) {
            return false;
        }

        $row = $businessRow;
        if ($row === null || empty($row['sector_name']) || empty($row['specialty_name'])) {
            $row = self::fetchFullBusinessRow($pdo, $businessId);
        }

        if (!$row || ($row['status'] ?? '') !== 'approved') {
            self::purgeBusiness($pdo, $businessId);
            return false;
        }

        $contentHash = self::computeContentHash($row);
        $capabilityDigest = self::buildCapabilityDigest($row);
        $keywordsNorm = self::buildNormalizedKeywords($row);
        $now = time();

        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO business_ai_index (
                    business_id, capability_digest, keywords_norm, embedding_json,
                    content_hash, indexed_at, updated_at
                ) VALUES (
                    :biz_id, :digest, :keywords, NULL, :content_hash, :indexed_at, :updated_at
                )
                ON CONFLICT(business_id) DO UPDATE SET
                    capability_digest = excluded.capability_digest,
                    keywords_norm = excluded.keywords_norm,
                    content_hash = excluded.content_hash,
                    updated_at = excluded.updated_at
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO business_ai_index (
                    business_id, capability_digest, keywords_norm, embedding_json,
                    content_hash, indexed_at, updated_at
                ) VALUES (
                    :biz_id, :digest, :keywords, NULL, :content_hash, :indexed_at, :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    capability_digest = VALUES(capability_digest),
                    keywords_norm = VALUES(keywords_norm),
                    content_hash = VALUES(content_hash),
                    updated_at = VALUES(updated_at)
            ");
        }

        return $stmt->execute([
            ':biz_id' => $businessId,
            ':digest' => $capabilityDigest,
            ':keywords' => $keywordsNorm,
            ':content_hash' => $contentHash,
            ':indexed_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    /**
     * Remove a business from `business_ai_index`.
     */
    public static function purgeBusiness(PDO $pdo, int $businessId): bool
    {
        if ($businessId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("DELETE FROM business_ai_index WHERE business_id = :id");
        return $stmt->execute([':id' => $businessId]);
    }

    /**
     * Rebuild or update the AI capability index across all approved businesses,
     * skipping records whose content_hash has not changed and pruning orphans.
     *
     * @return array{indexed: int, updated: int, unchanged: int, purged: int, total_approved: int}
     */
    public static function rebuildAllApproved(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT b.*,
                   sec.name AS sector_name, sec.slug AS sector_slug,
                   spec.name AS specialty_name, spec.slug AS specialty_slug,
                   sec2.name AS secondary_sector_name, sec2.slug AS secondary_sector_slug,
                   spec2.name AS secondary_specialty_name, spec2.slug AS secondary_specialty_slug
            FROM businesses b
            INNER JOIN categories sec ON sec.id = b.sector_id
            INNER JOIN categories spec ON spec.id = b.specialty_id
            LEFT JOIN categories sec2 ON sec2.id = b.secondary_sector_id
            LEFT JOIN categories spec2 ON spec2.id = b.secondary_specialty_id
            WHERE b.status = 'approved'
            ORDER BY b.id ASC
        ");
        $approvedList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch existing index state
        $existing = [];
        $idxStmt = $pdo->query("SELECT business_id, content_hash FROM business_ai_index");
        while ($row = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(int)$row['business_id']] = (string)$row['content_hash'];
        }

        $indexed = 0;
        $updated = 0;
        $unchanged = 0;
        $approvedIds = [];

        foreach ($approvedList as $biz) {
            $bId = (int)$biz['id'];
            $approvedIds[$bId] = true;
            $newHash = self::computeContentHash($biz);

            if (!isset($existing[$bId])) {
                self::indexBusiness($pdo, $bId, $biz);
                $indexed++;
            } elseif ($existing[$bId] !== $newHash) {
                self::indexBusiness($pdo, $bId, $biz);
                $updated++;
            } else {
                $unchanged++;
            }
        }

        // Purge orphan rows (businesses no longer approved or deleted)
        $purged = 0;
        foreach (array_keys($existing) as $existingId) {
            if (!isset($approvedIds[$existingId])) {
                self::purgeBusiness($pdo, $existingId);
                $purged++;
            }
        }

        return [
            'indexed' => $indexed,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'purged' => $purged,
            'total_approved' => count($approvedList),
        ];
    }

    /**
     * Retrieve summary statistics of the AI capability index.
     *
     * @return array{indexed_count: int, approved_count: int, coverage_pct: float}
     */
    public static function getIndexStats(PDO $pdo): array
    {
        $indexedCount = (int)$pdo->query("SELECT COUNT(*) FROM business_ai_index")->fetchColumn();
        $approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM businesses WHERE status = 'approved'")->fetchColumn();
        $coveragePct = $approvedCount > 0 ? round(($indexedCount / $approvedCount) * 100.0, 1) : 100.0;
        return [
            'indexed_count' => $indexedCount,
            'approved_count' => $approvedCount,
            'coverage_pct' => $coveragePct,
        ];
    }
}
