<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use DateTimeImmutable;
use DateTimeZone;

final class HoursEngine
{
    public const TIMEZONE = 'Africa/Nairobi';
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const PREV_DAY_MAP = [
        'mon' => 'sun',
        'tue' => 'mon',
        'wed' => 'tue',
        'thu' => 'wed',
        'fri' => 'thu',
        'sat' => 'fri',
        'sun' => 'sat',
    ];
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
     * Strictly validate HH:MM in 24-hour range (00:00..23:59, plus 24:00 when $allow2400 is true) (#184).
     */
    public static function parseTimeToMinutes(string $hhmm, bool $allow2400 = false): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $hour = (int)$m[1];
        $min = (int)$m[2];
        if ($allow2400 && $hour === 24 && $min === 0) {
            return 1440;
        }
        if ($hour < 0 || $hour > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return ($hour * 60) + $min;
    }

    /**
     * Validate operating_hours_json so out-of-range HH:MM values cannot be persisted (#184).
     */
    public static function validateScheduleJson(string $hoursJson): bool
    {
        $trimmed = trim($hoursJson);
        if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]') {
            return true;
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $key => $val) {
            if ($key === 'by_appointment') {
                continue;
            }
            if (!is_string($key) || !in_array($key, self::DAYS, true) || !is_array($val)) {
                return false;
            }
            foreach ($val as $shift) {
                if (!is_array($shift) || count($shift) < 2 || !is_string($shift[0] ?? null) || !is_string($shift[1] ?? null)) {
                    return false;
                }
                if (self::parseTimeToMinutes($shift[0], false) === null || self::parseTimeToMinutes($shift[1], true) === null) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Evaluate operating status in Africa/Nairobi (EAT, UTC+3), including previous-day overnight
     * carryover shifts after midnight (e.g. Mon 20:00-02:00 at Tue 01:00 and Sun 20:00-02:00 at Mon 01:00) (#184).
     */
    public static function evaluateStatus(string|array $hoursJson, ?DateTimeImmutable $nowOverride = null): array
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = $nowOverride ? $nowOverride->setTimezone($tz) : new DateTimeImmutable('now', $tz);

        $schedule = is_array($hoursJson)
            ? $hoursJson
            : (json_decode($hoursJson, true) ?: []);

        $todayKey = strtolower($now->format('D'));
        $prevDayKey = self::PREV_DAY_MAP[$todayKey] ?? 'sun';

        if (!empty($schedule['by_appointment'])) {
            return [
                'state' => 'BY_APPOINTMENT',
                'is_open' => true,
                'badge_text' => 'By Appointment Only',
                'today_key' => $todayKey,
                'schedule_table' => self::formatScheduleTable($schedule),
            ];
        }

        $nowMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');

        // 1. Check previous-day overnight carryover (close < open and close > 00:00) (#184)
        $prevShifts = $schedule[$prevDayKey] ?? [];
        if (is_array($prevShifts)) {
            foreach ($prevShifts as $shift) {
                if (!is_array($shift) || count($shift) < 2) {
                    continue;
                }
                $openMin = self::parseTimeToMinutes((string)$shift[0], false);
                $closeMin = self::parseTimeToMinutes((string)$shift[1], true);
                if ($openMin === null || $closeMin === null) {
                    continue;
                }
                if ($closeMin > 0 && $closeMin < 1440 && $closeMin < $openMin && $nowMinutes < $closeMin) {
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

        // 2. Check current-day intervals
        $todayShifts = $schedule[$todayKey] ?? [];
        if (is_array($todayShifts)) {
            foreach ($todayShifts as $shift) {
                if (!is_array($shift) || count($shift) < 2) {
                    continue;
                }
                $openMin = self::parseTimeToMinutes((string)$shift[0], false);
                $closeMin = self::parseTimeToMinutes((string)$shift[1], true);
                if ($openMin === null || $closeMin === null) {
                    continue;
                }
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
                    $oMin = self::parseTimeToMinutes((string)$s[0], false);
                    $cMin = self::parseTimeToMinutes((string)$s[1], true);
                    if ($oMin !== null && $cMin !== null) {
                        $parts[] = self::formatTime12h((string)$s[0]) . ' – ' . self::formatTime12h((string)$s[1]);
                    }
                }
            }
            $table[$dayKey] = [
                'label' => self::DAY_LABELS[$dayKey],
                'hours' => $parts ? implode(', ', $parts) : 'Closed',
            ];
        }
        return $table;
    }

    private static function formatTime12h(string $hhmm): string
    {
        $mins = self::parseTimeToMinutes($hhmm, true);
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

    public static function buildScheduleJsonFromPost(array $post, ?string $fallbackJson = null): string
    {
        if (!isset($post['hours_weekday_open']) && !isset($post['hours_sat_open']) && !isset($post['hours_sun_open'])) {
            if ($fallbackJson !== null && trim($fallbackJson) !== '') {
                if (!self::validateScheduleJson($fallbackJson)) {
                    throw new \InvalidArgumentException('Operating hours schedule contains invalid HH:MM times.');
                }
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

        if (!$weekdayClosed && (self::parseTimeToMinutes($weekdayOpen, false) === null || self::parseTimeToMinutes($weekdayClose, true) === null)) {
            throw new \InvalidArgumentException('Weekday operating hours must use valid 24-hour HH:MM format (00:00–23:59).');
        }
        if (!$satClosed && (self::parseTimeToMinutes($satOpen, false) === null || self::parseTimeToMinutes($satClose, true) === null)) {
            throw new \InvalidArgumentException('Saturday operating hours must use valid 24-hour HH:MM format (00:00–23:59).');
        }
        if (!$sunClosed && (self::parseTimeToMinutes($sunOpen, false) === null || self::parseTimeToMinutes($sunClose, true) === null)) {
            throw new \InvalidArgumentException('Sunday operating hours must use valid 24-hour HH:MM format (00:00–23:59).');
        }

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
