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

    /**
     * Scrub accidental visitor PII from raw query string.
     *
     * @param string $rawQuery Raw input question from visitor
     * @return array{
     *     clean_query: string,
     *     original_query: string,
     *     pii_detected: bool,
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

        // 4. Redact Phone Numbers (min 7 digits to avoid matching small numbers or years)
        if (preg_match_all(self::PHONE_PATTERN, $text, $matches)) {
            $foundPhone = false;
            foreach ($matches[0] as $candidate) {
                // Ensure candidate has at least 7 digits to avoid scrubbing years or small street numbers
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

        // Collapse multiple spaces
        $clean = trim((string)preg_replace('/\s+/', ' ', $text));
        $redactedTypes = array_values(array_unique($redactedTypes));

        return [
            'clean_query' => $clean,
            'original_query' => $original,
            'pii_detected' => !empty($redactedTypes),
            'redacted_types' => $redactedTypes,
            'character_count' => mb_strlen($clean),
        ];
    }
}
