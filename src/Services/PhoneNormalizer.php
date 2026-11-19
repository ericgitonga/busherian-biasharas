<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

/**
 * Country-aware phone normalization to international (E.164) format (#318).
 *
 * Busherian Biasharas lists alumni businesses worldwide, so a local number must be
 * interpreted using the listing's own country — never assumed to be Kenyan.
 *
 * Rules:
 *  - "+<digits>" and "00<digits>" are already international: kept (only cleaned).
 *  - Otherwise, with a known country: strip that country's trunk prefix (e.g. "0", "1", "8", "06")
 *    and prepend its calling code. Numbers already starting with the calling code are accepted.
 *  - With an unknown/null country: never guess — return the cleaned local digits unchanged so the
 *    form can be normalized later against the selected country.
 */
final class PhoneNormalizer
{
    /**
     * Country => [ISO 3166-1 alpha-2, calling code, trunk prefix ('' = none / leading 0 is significant)].
     * Country names match LocationEngine::getPlaceHierarchy() keys.
     *
     * @var array<string, array{0:string,1:string,2:string}>
     */
    public const COUNTRIES = [
        'Afghanistan' => ['AF', '93', '0'],
        'Albania' => ['AL', '355', '0'],
        'Algeria' => ['DZ', '213', '0'],
        'Andorra' => ['AD', '376', ''],
        'Angola' => ['AO', '244', ''],
        'Antigua and Barbuda' => ['AG', '1', '1'],
        'Argentina' => ['AR', '54', '0'],
        'Armenia' => ['AM', '374', '0'],
        'Australia' => ['AU', '61', '0'],
        'Austria' => ['AT', '43', '0'],
        'Azerbaijan' => ['AZ', '994', '0'],
        'Bahamas' => ['BS', '1', '1'],
        'Bahrain' => ['BH', '973', ''],
        'Bangladesh' => ['BD', '880', '0'],
        'Barbados' => ['BB', '1', '1'],
        'Belarus' => ['BY', '375', '8'],
        'Belgium' => ['BE', '32', '0'],
        'Belize' => ['BZ', '501', ''],
        'Benin' => ['BJ', '229', ''],
        'Bhutan' => ['BT', '975', ''],
        'Bolivia' => ['BO', '591', '0'],
        'Bosnia and Herzegovina' => ['BA', '387', '0'],
        'Botswana' => ['BW', '267', ''],
        'Brazil' => ['BR', '55', '0'],
        'Brunei' => ['BN', '673', ''],
        'Bulgaria' => ['BG', '359', '0'],
        'Burkina Faso' => ['BF', '226', ''],
        'Burundi' => ['BI', '257', ''],
        'Cabo Verde' => ['CV', '238', ''],
        'Cambodia' => ['KH', '855', '0'],
        'Cameroon' => ['CM', '237', ''],
        'Canada' => ['CA', '1', '1'],
        'Central African Republic' => ['CF', '236', ''],
        'Chad' => ['TD', '235', ''],
        'Chile' => ['CL', '56', ''],
        'China' => ['CN', '86', '0'],
        'Colombia' => ['CO', '57', ''],
        'Comoros' => ['KM', '269', ''],
        'Congo (Brazzaville)' => ['CG', '242', ''],
        'Congo (Democratic Republic / DRC)' => ['CD', '243', '0'],
        'Costa Rica' => ['CR', '506', ''],
        'Croatia' => ['HR', '385', '0'],
        'Cuba' => ['CU', '53', '0'],
        'Cyprus' => ['CY', '357', ''],
        'Czechia (Czech Republic)' => ['CZ', '420', ''],
        "Côte d'Ivoire" => ['CI', '225', ''],
        'Denmark' => ['DK', '45', ''],
        'Djibouti' => ['DJ', '253', ''],
        'Dominica' => ['DM', '1', '1'],
        'Dominican Republic' => ['DO', '1', '1'],
        'Ecuador' => ['EC', '593', '0'],
        'Egypt' => ['EG', '20', '0'],
        'El Salvador' => ['SV', '503', ''],
        'Equatorial Guinea' => ['GQ', '240', ''],
        'Eritrea' => ['ER', '291', '0'],
        'Estonia' => ['EE', '372', ''],
        'Eswatini' => ['SZ', '268', ''],
        'Ethiopia' => ['ET', '251', '0'],
        'Fiji' => ['FJ', '679', ''],
        'Finland' => ['FI', '358', '0'],
        'France' => ['FR', '33', '0'],
        'Gabon' => ['GA', '241', ''],
        'Gambia' => ['GM', '220', ''],
        'Georgia' => ['GE', '995', '0'],
        'Germany' => ['DE', '49', '0'],
        'Ghana' => ['GH', '233', '0'],
        'Greece' => ['GR', '30', ''],
        'Grenada' => ['GD', '1', '1'],
        'Guatemala' => ['GT', '502', ''],
        'Guinea' => ['GN', '224', ''],
        'Guinea-Bissau' => ['GW', '245', ''],
        'Guyana' => ['GY', '592', ''],
        'Haiti' => ['HT', '509', ''],
        'Honduras' => ['HN', '504', ''],
        'Hungary' => ['HU', '36', '06'],
        'Iceland' => ['IS', '354', ''],
        'India' => ['IN', '91', '0'],
        'Indonesia' => ['ID', '62', '0'],
        'Iran' => ['IR', '98', '0'],
        'Iraq' => ['IQ', '964', '0'],
        'Ireland' => ['IE', '353', '0'],
        'Israel' => ['IL', '972', '0'],
        'Italy' => ['IT', '39', ''],
        'Jamaica' => ['JM', '1', '1'],
        'Japan' => ['JP', '81', '0'],
        'Jordan' => ['JO', '962', '0'],
        'Kazakhstan' => ['KZ', '7', '8'],
        'Kenya' => ['KE', '254', '0'],
        'Kiribati' => ['KI', '686', ''],
        'Korea (North)' => ['KP', '850', '0'],
        'Korea (South)' => ['KR', '82', '0'],
        'Kosovo' => ['XK', '383', '0'],
        'Kuwait' => ['KW', '965', ''],
        'Kyrgyzstan' => ['KG', '996', '0'],
        'Laos' => ['LA', '856', '0'],
        'Latvia' => ['LV', '371', ''],
        'Lebanon' => ['LB', '961', '0'],
        'Lesotho' => ['LS', '266', ''],
        'Liberia' => ['LR', '231', '0'],
        'Libya' => ['LY', '218', '0'],
        'Liechtenstein' => ['LI', '423', ''],
        'Lithuania' => ['LT', '370', '8'],
        'Luxembourg' => ['LU', '352', ''],
        'Madagascar' => ['MG', '261', '0'],
        'Malawi' => ['MW', '265', '0'],
        'Malaysia' => ['MY', '60', '0'],
        'Maldives' => ['MV', '960', ''],
        'Mali' => ['ML', '223', ''],
        'Malta' => ['MT', '356', ''],
        'Marshall Islands' => ['MH', '692', ''],
        'Mauritania' => ['MR', '222', ''],
        'Mauritius' => ['MU', '230', ''],
        'Mexico' => ['MX', '52', ''],
        'Micronesia' => ['FM', '691', ''],
        'Moldova' => ['MD', '373', '0'],
        'Monaco' => ['MC', '377', ''],
        'Mongolia' => ['MN', '976', '0'],
        'Montenegro' => ['ME', '382', '0'],
        'Morocco' => ['MA', '212', '0'],
        'Mozambique' => ['MZ', '258', ''],
        'Myanmar' => ['MM', '95', '0'],
        'Namibia' => ['NA', '264', '0'],
        'Nauru' => ['NR', '674', ''],
        'Nepal' => ['NP', '977', '0'],
        'Netherlands' => ['NL', '31', '0'],
        'New Zealand' => ['NZ', '64', '0'],
        'Nicaragua' => ['NI', '505', ''],
        'Niger' => ['NE', '227', ''],
        'Nigeria' => ['NG', '234', '0'],
        'North Macedonia' => ['MK', '389', '0'],
        'Norway' => ['NO', '47', ''],
        'Oman' => ['OM', '968', ''],
        'Pakistan' => ['PK', '92', '0'],
        'Palau' => ['PW', '680', ''],
        'Palestine' => ['PS', '970', '0'],
        'Panama' => ['PA', '507', ''],
        'Papua New Guinea' => ['PG', '675', ''],
        'Paraguay' => ['PY', '595', '0'],
        'Peru' => ['PE', '51', '0'],
        'Philippines' => ['PH', '63', '0'],
        'Poland' => ['PL', '48', ''],
        'Portugal' => ['PT', '351', ''],
        'Qatar' => ['QA', '974', ''],
        'Romania' => ['RO', '40', '0'],
        'Russia' => ['RU', '7', '8'],
        'Rwanda' => ['RW', '250', '0'],
        'Saint Kitts and Nevis' => ['KN', '1', '1'],
        'Saint Lucia' => ['LC', '1', '1'],
        'Saint Vincent and the Grenadines' => ['VC', '1', '1'],
        'Samoa' => ['WS', '685', ''],
        'San Marino' => ['SM', '378', ''],
        'Sao Tome and Principe' => ['ST', '239', ''],
        'Saudi Arabia' => ['SA', '966', '0'],
        'Senegal' => ['SN', '221', ''],
        'Serbia' => ['RS', '381', '0'],
        'Seychelles' => ['SC', '248', ''],
        'Sierra Leone' => ['SL', '232', '0'],
        'Singapore' => ['SG', '65', ''],
        'Slovakia' => ['SK', '421', '0'],
        'Slovenia' => ['SI', '386', '0'],
        'Solomon Islands' => ['SB', '677', ''],
        'Somalia' => ['SO', '252', '0'],
        'South Africa' => ['ZA', '27', '0'],
        'South Sudan' => ['SS', '211', '0'],
        'Spain' => ['ES', '34', ''],
        'Sri Lanka' => ['LK', '94', '0'],
        'Sudan' => ['SD', '249', '0'],
        'Suriname' => ['SR', '597', ''],
        'Sweden' => ['SE', '46', '0'],
        'Switzerland' => ['CH', '41', '0'],
        'Syria' => ['SY', '963', '0'],
        'Taiwan' => ['TW', '886', '0'],
        'Tajikistan' => ['TJ', '992', '8'],
        'Tanzania' => ['TZ', '255', '0'],
        'Thailand' => ['TH', '66', '0'],
        'Timor-Leste' => ['TL', '670', ''],
        'Togo' => ['TG', '228', ''],
        'Tonga' => ['TO', '676', ''],
        'Trinidad and Tobago' => ['TT', '1', '1'],
        'Tunisia' => ['TN', '216', ''],
        'Turkey' => ['TR', '90', '0'],
        'Turkmenistan' => ['TM', '993', '8'],
        'Tuvalu' => ['TV', '688', ''],
        'Uganda' => ['UG', '256', '0'],
        'Ukraine' => ['UA', '380', '0'],
        'United Arab Emirates' => ['AE', '971', '0'],
        'United Kingdom' => ['GB', '44', '0'],
        'United States' => ['US', '1', '1'],
        'Uruguay' => ['UY', '598', '0'],
        'Uzbekistan' => ['UZ', '998', '8'],
        'Vanuatu' => ['VU', '678', ''],
        'Vatican City' => ['VA', '39', ''],
        'Venezuela' => ['VE', '58', '0'],
        'Vietnam' => ['VN', '84', '0'],
        'Yemen' => ['YE', '967', '0'],
        'Zambia' => ['ZM', '260', '0'],
        'Zimbabwe' => ['ZW', '263', '0'],
    ];

    /** Common aliases people type or that appear in legacy rows. */
    private const ALIASES = [
        'usa' => 'United States', 'us' => 'United States', 'united states of america' => 'United States',
        'uk' => 'United Kingdom', 'great britain' => 'United Kingdom', 'england' => 'United Kingdom',
        'scotland' => 'United Kingdom', 'wales' => 'United Kingdom', 'northern ireland' => 'United Kingdom',
        'uae' => 'United Arab Emirates', 'czechia' => 'Czechia (Czech Republic)', 'czech republic' => 'Czechia (Czech Republic)',
        'drc' => 'Congo (Democratic Republic / DRC)', 'ivory coast' => "Côte d'Ivoire", 'south korea' => 'Korea (South)',
        'swaziland' => 'Eswatini', 'cape verde' => 'Cabo Verde', 'holland' => 'Netherlands',
    ];

    public static function resolveCountry(?string $country): ?string
    {
        $c = trim((string)($country ?? ''));
        if ($c === '') {
            return null;
        }
        if (isset(self::COUNTRIES[$c])) {
            return $c;
        }
        $lower = mb_strtolower($c);
        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }
        foreach (self::COUNTRIES as $name => $_) {
            if (mb_strtolower($name) === $lower) {
                return $name;
            }
        }
        return null;
    }

    public static function callingCodeFor(?string $country): ?string
    {
        $name = self::resolveCountry($country);
        return $name !== null ? self::COUNTRIES[$name][1] : null;
    }

    public static function iso2For(?string $country): ?string
    {
        $name = self::resolveCountry($country);
        return $name !== null ? self::COUNTRIES[$name][0] : null;
    }

    public static function countryFromIso2(string $iso2): ?string
    {
        $up = strtoupper(trim($iso2));
        if ($up === 'UK') {
            $up = 'GB';
        }
        foreach (self::COUNTRIES as $name => [$code]) {
            if ($code === $up) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Infer a country from a website's country-code TLD (e.g. ".co.ke" → Kenya, ".ch" → Switzerland).
     * Generic TLDs (.com, .org, .net, .io …) return null — never guess.
     */
    public static function countryFromUrl(string $url): ?string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '' || !str_contains($host, '.')) {
            return null;
        }
        $tld = substr($host, (int)strrpos($host, '.') + 1);
        if (strlen($tld) !== 2) {
            return null;
        }
        return self::countryFromIso2($tld);
    }

    /**
     * Separate dialable base phone digits from trailing extensions (e.g. "ext 104", "x205", "#12").
     *
     * @return array{number: string, extension: ?string}
     */
    public static function splitExtension(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['number' => '', 'extension' => null];
        }

        // Match trailing extension indicators: ext, ext., extension, post, x, #, or dialer pause (,)
        $pattern = '/(?:[\s,;]*(?:ext\.?|extension|post|x|#)[\s.:#]*|[,;]+)(\d+)\s*$/i';
        if (preg_match($pattern, $trimmed, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = (int)$matches[0][1];
            $extDigits = (string)$matches[1][0];
            $baseNumber = trim(substr($trimmed, 0, $offset));
            if ($baseNumber !== '') {
                return ['number' => $baseNumber, 'extension' => $extDigits];
            }
        }

        return ['number' => $trimmed, 'extension' => null];
    }

    /**
     * Check if a normalized phone string has a valid ITU-T E.164 length (7 to 15 digits).
     */
    public static function isValidE164Length(string $normalized): bool
    {
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $len = strlen($digits);
        return $len >= 7 && $len <= 15;
    }

    /**
     * Normalize a phone number to "+<calling code><national number>" using the listing's country.
     * Trailing extensions are separated so dialable links (wa.me, tel:) remain clean and valid.
     */
    public static function normalize(string $raw, ?string $country, bool $stripExtension = true): string
    {
        $split = self::splitExtension($raw);
        $target = $stripExtension ? $split['number'] : $raw;
        $trimmed = trim($target);
        if ($trimmed === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return '';
        }

        $name = self::resolveCountry($country);
        $isExplicitKenya = ($name === 'Kenya');

        // Kenyan mobile & national number patterns
        if ($isExplicitKenya) {
            // Transposition typo: 245 -> 254 (e.g. +245722366164 -> +254722366164)
            if (preg_match('/^245([71]\d{8})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            // Redundant trunk zero after country code: 2540 -> 254 (e.g. +254 (0) 746... -> +254746...)
            if (preg_match('/^2540([71]\d{8})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            if (preg_match('/^2540(20\d{6,7})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            // Standard full E.164 Kenya (12 digits mobile or 10-12 digits landline)
            if (preg_match('/^254([71]\d{8}|20\d{6,7}|\d{8,9})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            // Local 9-digit without trunk 0 (e.g. 746588589 or +746588589 missing 25, +110123456 missing 254)
            if (preg_match('/^[71]\d{8}$/', $digits)) {
                return '+254' . $digits;
            }
            // Local 10-digit with trunk 0 (e.g. 0746588589, 0110123456, +0746588589)
            if (preg_match('/^0([71]\d{8})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            // Nairobi / Kenyan landlines
            if (preg_match('/^0?(20\d{6,7})$/', $digits, $m)) {
                return '+254' . $m[1];
            }
            // Explicit international numbers entered for a Kenyan listing (e.g. +41..., +44..., +1...)
            if (str_starts_with(ltrim($trimmed), '+')) {
                return '+' . $digits;
            }
            if (str_starts_with($digits, '00') && strlen($digits) > 9) {
                return '+' . substr($digits, 2);
            }
            return '+254' . $digits;
        }

        // Already international for non-Kenyan countries
        if (str_starts_with(ltrim($trimmed), '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00') && strlen($digits) > 9) {
            return '+' . substr($digits, 2);
        }

        if ($name === null) {
            return $digits; // Unknown country: do not guess.
        }
        [, $code, $trunk] = self::COUNTRIES[$name];

        // Strip redundant trunk zero if preceded by calling code (e.g. UK +44 (0) 7911... -> 4407911...)
        if ($trunk !== '' && str_starts_with($digits, $code . $trunk) && strlen($digits) - strlen($code . $trunk) >= 6) {
            return '+' . $code . substr($digits, strlen($code . $trunk));
        }

        // NANP (+1): 10-digit national numbers, optional leading trunk "1"
        if ($code === '1') {
            if (strlen($digits) === 11 && $digits[0] === '1') {
                return '+' . $digits;
            }
            if (strlen($digits) === 10) {
                return '+1' . $digits;
            }
            return $digits;
        }

        // Trunk prefix (e.g. Kenya "0712…", Switzerland "079…", UK "07…", Hungary "06…", Russia "8…")
        if ($trunk !== '' && str_starts_with($digits, $trunk) && strlen($digits) - strlen($trunk) >= 6) {
            return '+' . $code . substr($digits, strlen($trunk));
        }

        // Already prefixed with the calling code, without "+" (e.g. "254712345678", "41791234567")
        // (Italy-style plans keep significant leading digits that can collide with "39", so require a longer total.)
        $minPrefixedTotal = in_array($code, ['39', '378'], true) ? 11 : 10;
        if (str_starts_with($digits, $code) && strlen($digits) - strlen($code) >= 7 && strlen($digits) >= $minPrefixedTotal) {
            return '+' . $digits;
        }

        // No trunk prefix used in this country (or none typed): prepend calling code
        if (strlen($digits) >= 6) {
            return '+' . $code . $digits;
        }
        return $digits;
    }

    /**
     * Remediate stored Kenyan phone numbers in businesses, vetting queue, and owners table.
     * Fixes malformed legacy numbers like '+746588589', '+245722366164', or un-prefixed '0746588589'.
     *
     * @return array{businesses_updated: int, vetting_updated: int, owners_updated: int}
     */
    public static function remediateStoredPhoneNumbers(\PDO $pdo): array
    {
        $bizUpdated = 0;
        $vettingUpdated = 0;
        $ownersUpdated = 0;

        // 1. Businesses table
        $stmt = $pdo->query("SELECT id, country, phone_primary, whatsapp_number FROM businesses");
        $updateStmt = $pdo->prepare("UPDATE businesses SET phone_primary = :pp, whatsapp_number = :wa WHERE id = :id");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $country = (string)($row['country'] ?? 'Kenya');
            $origPp = (string)($row['phone_primary'] ?? '');
            $origWa = (string)($row['whatsapp_number'] ?? '');

            $normPp = self::normalize($origPp, $country);
            $normWa = $origWa !== '' ? (self::normalize($origWa, $country) ?: null) : null;

            if ($normPp !== $origPp || $normWa !== $origWa) {
                $updateStmt->execute([
                    ':pp' => $normPp !== '' ? $normPp : $origPp,
                    ':wa' => $normWa,
                    ':id' => $row['id'],
                ]);
                $bizUpdated++;
            }
        }

        // 2. Business vetting queue table
        try {
            $vqStmt = $pdo->query("SELECT business_id, submitter_phone FROM business_vetting_queue");
            $vqUpdate = $pdo->prepare("UPDATE business_vetting_queue SET submitter_phone = :sp WHERE business_id = :id");
            while ($row = $vqStmt->fetch(\PDO::FETCH_ASSOC)) {
                $origSp = (string)($row['submitter_phone'] ?? '');
                $normSp = self::normalize($origSp, 'Kenya');
                if ($normSp !== '' && $normSp !== $origSp) {
                    $vqUpdate->execute([':sp' => $normSp, ':id' => $row['business_id']]);
                    $vettingUpdated++;
                }
            }
        } catch (\Throwable $e) {
            // Queue table may be empty or unmigrated
        }

        // 3. Business owners table
        try {
            $boStmt = $pdo->query("SELECT id, phone FROM business_owners WHERE phone IS NOT NULL AND phone != ''");
            $boUpdate = $pdo->prepare("UPDATE business_owners SET phone = :ph WHERE id = :id");
            while ($row = $boStmt->fetch(\PDO::FETCH_ASSOC)) {
                $origPh = (string)($row['phone'] ?? '');
                $normPh = self::normalize($origPh, 'Kenya');
                if ($normPh !== '' && $normPh !== $origPh) {
                    $boUpdate->execute([':ph' => $normPh, ':id' => $row['id']]);
                    $ownersUpdated++;
                }
            }
        } catch (\Throwable $e) {
            // Table may be absent
        }

        return [
            'businesses_updated' => $bizUpdated,
            'vetting_updated' => $vettingUpdated,
            'owners_updated' => $ownersUpdated,
        ];
    }

    /**
     * Normalize a phone number and preserve any extension for formatted display.
     *
     * @return array{e164: string, extension: ?string, display: string, is_valid_len: bool}
     */
    public static function normalizeWithExtension(string $raw, ?string $country): array
    {
        $split = self::splitExtension($raw);
        $e164 = self::normalize($split['number'], $country, true);
        $ext = $split['extension'];
        $display = $e164 !== '' ? ($ext !== null ? "{$e164} ext. {$ext}" : $e164) : '';
        return [
            'e164' => $e164,
            'extension' => $ext,
            'display' => $display,
            'is_valid_len' => self::isValidE164Length($e164),
        ];
    }
}
