<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use DateTimeImmutable;
use DateTimeZone;

final class HoursEngine
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const DAY_LABELS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * Evaluate operating status in Africa/Nairobi (EAT, UTC+3).
     * Returns ['state' => 'OPEN_NOW'|'CLOSING_SOON'|'CLOSED'|'BY_APPOINTMENT', 'badge_text' => ..., 'is_open' => bool, 'schedule_table' => ...]
     */
    public static function evaluateStatus(string|array $hoursJson, ?DateTimeImmutable $nowOverride = null): array
    {
        $tz = new DateTimeZone('Africa/Nairobi');
        $now = $nowOverride ? $nowOverride->setTimezone($tz) : new DateTimeImmutable('now', $tz);

        $schedule = is_array($hoursJson)
            ? $hoursJson
            : (json_decode($hoursJson, true) ?: []);

        if (!empty($schedule['by_appointment'])) {
            return [
                'state' => 'BY_APPOINTMENT',
                'is_open' => true,
                'badge_text' => 'By Appointment Only',
                'today_key' => strtolower($now->format('D')),
                'schedule_table' => self::formatScheduleTable($schedule),
            ];
        }

        $todayKey = strtolower($now->format('D'));
        $nowMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');

        $todayShifts = $schedule[$todayKey] ?? [];
        if (is_array($todayShifts)) {
            foreach ($todayShifts as $shift) {
                if (!is_array($shift) || count($shift) < 2) {
                    continue;
                }
                $openMin = self::parseTimeToMinutes((string)$shift[0]);
                $closeMin = self::parseTimeToMinutes((string)$shift[1]);
                if ($openMin === null || $closeMin === null) {
                    continue;
                }
                // Handle overnight shift closing after midnight
                if ($closeMin <= $openMin) {
                    $closeMin += 24 * 60;
                }
                if ($nowMinutes >= $openMin && $nowMinutes < $closeMin) {
                    $remaining = $closeMin - $nowMinutes;
                    $formattedClose = self::formatTime12h((string)$shift[1]);
                    if ($remaining <= 45) {
                        return [
                            'state' => 'CLOSING_SOON',
                            'is_open' => true,
                            'badge_text' => "Closing Soon • {$formattedClose} EAT",
                            'today_key' => $todayKey,
                            'schedule_table' => self::formatScheduleTable($schedule),
                        ];
                    }
                    return [
                        'state' => 'OPEN_NOW',
                        'is_open' => true,
                        'badge_text' => "Open Now • Closes {$formattedClose}",
                        'today_key' => $todayKey,
                        'schedule_table' => self::formatScheduleTable($schedule),
                    ];
                }
            }
        }

        return [
            'state' => 'CLOSED',
            'is_open' => false,
            'badge_text' => 'Closed Now',
            'today_key' => $todayKey,
            'schedule_table' => self::formatScheduleTable($schedule),
        ];
    }

    public static function formatScheduleTable(array $schedule): array
    {
        $table = [];
        foreach (self::DAYS as $dayKey) {
            $shifts = $schedule[$dayKey] ?? [];
            if (!is_array($shifts) || count($shifts) === 0) {
                $table[$dayKey] = [
                    'label' => self::DAY_LABELS[$dayKey],
                    'hours' => 'Closed',
                ];
                continue;
            }
            $parts = [];
            foreach ($shifts as $s) {
                if (is_array($s) && count($s) >= 2) {
                    $parts[] = self::formatTime12h((string)$s[0]) . ' – ' . self::formatTime12h((string)$s[1]);
                }
            }
            $table[$dayKey] = [
                'label' => self::DAY_LABELS[$dayKey],
                'hours' => $parts ? implode(', ', $parts) : 'Closed',
            ];
        }
        return $table;
    }

    private static function parseTimeToMinutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        return ((int)$m[1] * 60) + (int)$m[2];
    }

    private static function formatTime12h(string $hhmm): string
    {
        $mins = self::parseTimeToMinutes($hhmm);
        if ($mins === null) {
            return $hhmm;
        }
        $h24 = intdiv($mins, 60) % 24;
        $m = $mins % 60;
        $ampm = $h24 >= 12 ? 'PM' : 'AM';
        $h12 = $h24 % 12;
        if ($h12 === 0) {
            $h12 = 12;
        }
        return sprintf('%d:%02d %s', $h12, $m, $ampm);
    }

    /**
     * Build 7-day operating_hours_json from form POST fields (Mon-Fri, Saturday, and Sunday).
     */
    public static function buildScheduleJsonFromPost(array $post, ?string $fallbackJson = null): string
    {
        if (!isset($post['hours_weekday_open']) && !isset($post['hours_sat_open']) && !isset($post['hours_sun_open'])) {
            if ($fallbackJson !== null && trim($fallbackJson) !== '') {
                return $fallbackJson;
            }
        }

        $weekdayOpen  = trim((string)($post['hours_weekday_open'] ?? '08:00')) ?: '08:00';
        $weekdayClose = trim((string)($post['hours_weekday_close'] ?? '17:30')) ?: '17:30';
        $weekdayClosed = !empty($post['hours_weekday_closed']);

        $satOpen  = trim((string)($post['hours_sat_open'] ?? '09:00'));
        $satClose = trim((string)($post['hours_sat_close'] ?? '14:00'));
        $satClosed = !empty($post['hours_sat_closed']) || $satOpen === '' || $satClose === '';

        $sunOpen  = trim((string)($post['hours_sun_open'] ?? ''));
        $sunClose = trim((string)($post['hours_sun_close'] ?? ''));
        $sunClosed = !empty($post['hours_sun_closed']) || $sunOpen === '' || $sunClose === '';

        $weekdayShift = $weekdayClosed ? [] : [[$weekdayOpen, $weekdayClose]];
        $satShift     = $satClosed ? [] : [[$satOpen, $satClose]];
        $sunShift     = $sunClosed ? [] : [[$sunOpen, $sunClose]];

        return (string)json_encode([
            'mon' => $weekdayShift,
            'tue' => $weekdayShift,
            'wed' => $weekdayShift,
            'thu' => $weekdayShift,
            'fri' => $weekdayShift,
            'sat' => $satShift,
            'sun' => $sunShift,
        ]);
    }

    /**
     * Extract form values for Mon-Fri, Saturday, and Sunday inputs from operating_hours_json.
     */
    public static function extractFormValues(?string $hoursJson): array
    {
        $decoded = is_string($hoursJson) && $hoursJson !== '' ? (json_decode($hoursJson, true) ?: []) : [];
        $mon = $decoded['mon'][0] ?? ['08:00', '17:30'];
        $sat = $decoded['sat'][0] ?? null;
        $sun = $decoded['sun'][0] ?? null;

        return [
            'weekday_open'   => (string)($mon[0] ?? '08:00'),
            'weekday_close'  => (string)($mon[1] ?? '17:30'),
            'weekday_closed' => empty($decoded['mon']) && !empty($decoded),
            'sat_open'       => (string)($sat[0] ?? '09:00'),
            'sat_close'      => (string)($sat[1] ?? '14:00'),
            'sat_closed'     => empty($sat),
            'sun_open'       => (string)($sun[0] ?? '10:00'),
            'sun_close'      => (string)($sun[1] ?? '16:00'),
            'sun_closed'     => empty($sun),
        ];
    }
}
