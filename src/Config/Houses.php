<?php
declare(strict_types=1);

namespace BusherianBiasharas\Config;

final class Houses
{
    /**
     * Confirmed Alliance High School (AHS — Bush) Houses (13 Houses)
     * Used strictly for one-time alumni vetting and never displayed publicly.
     */
    public const AHS_HOUSES = [
        'Livingstone',
        'Aggrey',
        'Wilberforce',
        'Grieve',
        'Arthur',
        'Francis',
        'Smith',
        'Sellwood',
        'Campbell',
        'Sanders',
        'Wangai',
        'Githaiga',
        'Maneno',
    ];

    /**
     * Confirmed Alliance Girls High School (AGHS — Bush) Houses (current confirmed list)
     * Used strictly for one-time alumni vetting and never displayed publicly.
     */
    public const AGHS_HOUSES = [
        'Watson',
        'Stephenson',
        'Burns',
        'Kenya',
        'McPherson',
        'Bruce',
        'Dorcas Luseno',
        'Joan Waithaka',
        'Joan Mjomba',
        'Rebecca Karanja',
        'Other / Unlisted House',
    ];

    public static function getForSchool(string $schoolCode): array
    {
        $code = strtoupper(trim($schoolCode));
        if ($code === 'AHS') {
            return self::AHS_HOUSES;
        }
        if ($code === 'AGHS') {
            return self::AGHS_HOUSES;
        }
        return [];
    }

    public static function isValidHouse(string $schoolCode, string $houseName): bool
    {
        $house = trim($houseName);
        if ($house === '') {
            return false;
        }
        $allowed = self::getForSchool($schoolCode);
        if (in_array($house, $allowed, true)) {
            return true;
        }
        // For AGHS, allow custom house name when "Other / Unlisted House" is selected
        if (strtoupper(trim($schoolCode)) === 'AGHS' && mb_strlen($house) >= 2 && mb_strlen($house) <= 60) {
            return true;
        }
        return false;
    }
}
