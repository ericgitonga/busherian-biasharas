<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

/**
 * QueryScrubber — Visitor PII Redaction & Prompt Hygiene
 *
 * Implements Kenya Data Protection Act, 2019 (ss. 25, 32, 48–49) protections by
 * actively redacting personal identifying data (phone numbers, email addresses,
 * national ID/passport numbers, URLs) from natural language visitor questions
 * before any prompt is processed or passed to external AI inference endpoints.
 */
final class QueryScrubber
{
    public const MAX_QUERY_LENGTH = 300;

    /**
     * Regex patterns for PII detection and redaction.
     */
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i';

    private const URL_PATTERN = '/(?:https?:\/\/[^\s<>"]+|www\.[^\s<>"]+)/i';

    // Matches Kenyan +254/07/01 phone patterns and international standard numbers (7+ digits)
    private const PHONE_PATTERN = '/(?:\+?254|0)[17]\d{2}[-\s]?\d{3}[-\s]?\d{3}|\+?[1-9]\d{0,3}[-\s]?(?:\(\d{1,4}\)[-\s]?)?\d{2,4}[-\s]?\d{2,4}[-\s]?\d{2,9}/';

    // Matches National ID, Passport, Huduma/Alien ID identifiers
    private const ID_PATTERN = '/\b(?:id|national\s*id|id\s*no|passport(?:\s*no)?|huduma(?:\s*no)?)\s*(?:is|:|-|=|\s)\s*[A-Z0-9]{6,10}\b/i';

    // Matches sensitive health, medical conditions, criminal accusations or intimate personal life (Kenya DPA 2019 s. 2)
    private const SENSITIVE_HEALTH_LEGAL_PATTERN = '/\b(?:hiv|aids|arv|sti|std|cancer|oncolog(?:y|ist)|abort(?:ion)?|pregnan(?:t|cy)|psychiatr(?:y|ic|ist)|therapy|depression|bipolar|suicid(?:e|al)|rehab(?:ilitation)?|arrest(?:ed)?|jail|prison|criminal|prosecut(?:ed|ion)|lawsuit|defamation|bankrupt(?:cy)?)\b/i';

    // Matches identifiable residential street/plot/flat/apartment/box addresses
    private const ADDRESS_PII_PATTERN = '/\b(?:house\s*(?:no|number)?|plot\s*(?:no|number)?|flat\s*(?:no|number)?|apt\s*(?:no|number)?|apartment\s*(?:no|number)?|door\s*(?:no|number)?|p\.?o\.?\s*box)\s*[:#.-]?\s*\d+[a-zA-Z0-9\/-]*/i';

    // Matches self-identifying name introductions ("my name is Jane Doe", "I am John Kamau")
    private const NAME_INTRO_PATTERN = '/\b(?:my\s+name\s+is|call\s+me)\s+([A-Z][a-zA-Z]+(?:\s+[A-Z][a-zA-Z]+)*)\b/i';

    /**
     * Determine whether query contains sensitive personal data (health, medical, criminal allegations).
     */
    public static function containsSensitiveNarrative(string $query): bool
    {
        return (bool)preg_match(self::SENSITIVE_HEALTH_LEGAL_PATTERN, $query);
    }

    /**
     * Scrub accidental visitor PII from raw query string.
     *
     * @param string $rawQuery Raw input question from visitor
     * @return array{
     *     clean_query: string,
     *     original_query: string,
     *     pii_detected: bool,
     *     is_sensitive: bool,
     *     redacted_types: string[],
     *     character_count: int
     * }
     */
    public static function scrub(string $rawQuery): array
    {
        $original = trim($rawQuery);
        $clipped = mb_substr($original, 0, self::MAX_QUERY_LENGTH);

        $redactedTypes = [];
        $text = $clipped;

        // 1. Redact Email Addresses
        if (preg_match(self::EMAIL_PATTERN, $text)) {
            $text = (string)preg_replace(self::EMAIL_PATTERN, '[email redacted]', $text);
            $redactedTypes[] = 'email';
        }

        // 2. Redact URLs
        if (preg_match(self::URL_PATTERN, $text)) {
            $text = (string)preg_replace(self::URL_PATTERN, '[link redacted]', $text);
            $redactedTypes[] = 'url';
        }

        // 3. Redact National ID / Passport References
        if (preg_match(self::ID_PATTERN, $text)) {
            $text = (string)preg_replace(self::ID_PATTERN, '[id redacted]', $text);
            $redactedTypes[] = 'national_id';
        }

        // 4. Redact Residential Street / Plot / Box Addresses
        if (preg_match(self::ADDRESS_PII_PATTERN, $text)) {
            $text = (string)preg_replace(self::ADDRESS_PII_PATTERN, '[address redacted]', $text);
            $redactedTypes[] = 'address';
        }

        // 5. Redact Self-Identifying Name Introductions
        if (preg_match(self::NAME_INTRO_PATTERN, $text, $nameMatches)) {
            $text = str_replace($nameMatches[1], '[name redacted]', $text);
            $redactedTypes[] = 'name';
        }

        // 6. Redact Phone Numbers (min 7 digits to avoid matching small numbers or years)
        if (preg_match_all(self::PHONE_PATTERN, $text, $matches)) {
            $foundPhone = false;
            foreach ($matches[0] as $candidate) {
                $digitCount = strlen((string)preg_replace('/\D/', '', (string)$candidate));
                if ($digitCount >= 7 && $digitCount <= 16) {
                    $text = str_replace($candidate, '[phone redacted]', $text);
                    $foundPhone = true;
                }
            }
            if ($foundPhone) {
                $redactedTypes[] = 'phone';
            }
        }

        // Check for sensitive medical / legal narratives
        $isSensitive = (bool)preg_match(self::SENSITIVE_HEALTH_LEGAL_PATTERN, $clipped);
        if ($isSensitive) {
            $redactedTypes[] = 'sensitive_narrative';
        }

        // Collapse multiple spaces
        $clean = trim((string)preg_replace('/\s+/', ' ', $text));
        $redactedTypes = array_values(array_unique($redactedTypes));

        return [
            'clean_query' => $clean,
            'original_query' => $original,
            'pii_detected' => !empty($redactedTypes),
            'is_sensitive' => $isSensitive,
            'redacted_types' => $redactedTypes,
            'character_count' => mb_strlen($clean),
        ];
    }
}
