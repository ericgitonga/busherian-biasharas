<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

final class LocationEngine
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const OLC_ALPHABET = '23456789CFGHJMPQRVWX';

    private const KENYA_CITY_CENTROIDS = [
        'nairobi'  => [-1.286389, 36.817223],
        'kikuyu'   => [-1.246300, 36.662900],
        'mombasa'  => [-4.043477, 39.668206],
        'kisumu'   => [-0.091702, 34.767956],
        'nakuru'   => [-0.303099, 36.080025],
        'eldoret'  => [0.514277, 35.269779],
        'thika'    => [-1.033260, 37.069330],
        'nyeri'    => [-0.420130, 36.947590],
        'kiambu'   => [-1.171390, 36.835560],
        'machakos' => [-1.517680, 37.263410],
    ];

    /**
     * 2-Level Place Taxonomy (Level 1: Country -> Level 2: City / County / State) (#96, #97, #98).
     * Includes all 47 Kenyan Counties + key towns, East African neighbors, and major Busherian diaspora hubs.
     */
    public const PLACE_HIERARCHY = [
        'Kenya' => ['Nairobi', 'Kiambu', 'Kikuyu', 'Thika', 'Mombasa', 'Kisumu', 'Nakuru', 'Naivasha', 'Uasin Gishu (Eldoret)', 'Machakos', 'Kajiado', 'Nyeri', 'Meru', 'Kilifi', 'Kwale', 'Laikipia (Nanyuki)', 'Murang\'a', 'Kirinyaga', 'Embu', 'Kakamega', 'Bungoma', 'Kisii', 'Nyamira', 'Kericho', 'Bomet', 'Nandi', 'Trans Nzoia (Kitale)', 'Narok', 'Nyandarua', 'Tharaka-Nithi', 'Kitui', 'Makueni', 'Taita-Taveta', 'Garissa', 'Isiolo', 'Marsabit', 'Turkana', 'Baringo', 'Elgeyo-Marakwet', 'West Pokot', 'Samburu', 'Siaya', 'Homa Bay', 'Migori', 'Busia', 'Vihiga', 'Lamu', 'Tana River', 'Wajir', 'Mandera'],
        'Afghanistan' => ['Kabul', 'Kandahar', 'Herat', 'Mazar-i-Sharif'],
        'Albania' => ['Tirana', 'Durrës', 'Vlorë', 'Shkodër'],
        'Algeria' => ['Algiers', 'Oran', 'Constantine', 'Annaba'],
        'Andorra' => ['Andorra la Vella', 'Escaldes-Engordany'],
        'Angola' => ['Luanda', 'Huambo', 'Lobito', 'Benguela', 'Lubango'],
        'Antigua and Barbuda' => ['Saint John\'s'],
        'Argentina' => ['Buenos Aires', 'Córdoba', 'Rosario', 'Mendoza'],
        'Armenia' => ['Yerevan', 'Gyumri', 'Vanadzor'],
        'Australia' => ['New South Wales (Sydney)', 'Victoria (Melbourne)', 'Queensland (Brisbane)', 'Western Australia (Perth)', 'South Australia (Adelaide)', 'Australian Capital Territory (Canberra)', 'Tasmania (Hobart)', 'Northern Territory (Darwin)'],
        'Austria' => ['Vienna', 'Graz', 'Linz', 'Salzburg', 'Innsbruck'],
        'Azerbaijan' => ['Baku', 'Ganja', 'Sumqayit'],
        'Bahamas' => ['Nassau', 'Freeport'],
        'Bahrain' => ['Manama', 'Riffa', 'Muharraq'],
        'Bangladesh' => ['Dhaka', 'Chattogram', 'Khulna', 'Sylhet'],
        'Barbados' => ['Bridgetown'],
        'Belarus' => ['Minsk', 'Gomel', 'Brest', 'Grodno'],
        'Belgium' => ['Brussels', 'Antwerp', 'Ghent', 'Charleroi', 'Liège', 'Bruges'],
        'Belize' => ['Belmopan', 'Belize City', 'San Ignacio'],
        'Benin' => ['Cotonou', 'Porto-Novo', 'Parakou'],
        'Bhutan' => ['Thimphu', 'Phuntsholing', 'Paro'],
        'Bolivia' => ['La Paz', 'Santa Cruz de la Sierra', 'Cochabamba', 'Sucre'],
        'Bosnia and Herzegovina' => ['Sarajevo', 'Banja Luka', 'Tuzla', 'Mostar'],
        'Botswana' => ['Gaborone', 'Francistown', 'Maun', 'Kasane'],
        'Brazil' => ['São Paulo', 'Rio de Janeiro', 'Brasília', 'Salvador', 'Belo Horizonte', 'Curitiba'],
        'Brunei' => ['Bandar Seri Begawan', 'Kuala Belait'],
        'Bulgaria' => ['Sofia', 'Plovdiv', 'Varna', 'Burgas'],
        'Burkina Faso' => ['Ouagadougou', 'Bobo-Dioulasso'],
        'Burundi' => ['Bujumbura', 'Gitega', 'Ngozi'],
        'Cabo Verde' => ['Praia', 'Mindelo', 'Santa Maria'],
        'Cambodia' => ['Phnom Penh', 'Siem Reap', 'Sihanoukville'],
        'Cameroon' => ['Douala', 'Yaoundé', 'Bamenda', 'Garoua', 'Bafoussam'],
        'Canada' => ['Ontario (Toronto / Ottawa)', 'British Columbia (Vancouver / Victoria)', 'Alberta (Calgary / Edmonton)', 'Quebec (Montreal / Quebec City)', 'Manitoba (Winnipeg)', 'Saskatchewan (Saskatoon / Regina)', 'Nova Scotia (Halifax)', 'New Brunswick (Moncton / Fredericton)', 'Newfoundland and Labrador (St. John\'s)', 'Prince Edward Island (Charlottetown)'],
        'Central African Republic' => ['Bangui', 'Bimbo'],
        'Chad' => ['N\'Djamena', 'Moundou', 'Sarh'],
        'Chile' => ['Santiago', 'Valparaíso', 'Concepción', 'Antofagasta'],
        'China' => ['Beijing', 'Shanghai', 'Guangzhou', 'Shenzhen', 'Hong Kong', 'Chengdu', 'Hangzhou', 'Wuhan'],
        'Colombia' => ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena'],
        'Comoros' => ['Moroni', 'Mutsamudu'],
        'Congo (Brazzaville)' => ['Brazzaville', 'Pointe-Noire'],
        'Congo (Democratic Republic / DRC)' => ['Kinshasa', 'Lubumbashi', 'Goma', 'Kisangani', 'Bukavu'],
        'Costa Rica' => ['San José', 'Alajuela', 'Cartago', 'Heredia'],
        'Côte d\'Ivoire' => ['Abidjan', 'Yamoussoukro', 'Bouaké', 'San-Pédro'],
        'Croatia' => ['Zagreb', 'Split', 'Rijeka', 'Dubrovnik'],
        'Cuba' => ['Havana', 'Santiago de Cuba', 'Camagüey'],
        'Cyprus' => ['Nicosia', 'Limassol', 'Larnaca', 'Paphos'],
        'Czechia (Czech Republic)' => ['Prague', 'Brno', 'Ostrava', 'Plzeň'],
        'Denmark' => ['Copenhagen', 'Aarhus', 'Odense', 'Aalborg'],
        'Djibouti' => ['Djibouti City', 'Ali Sabieh'],
        'Dominica' => ['Roseau'],
        'Dominican Republic' => ['Santo Domingo', 'Santiago de los Caballeros', 'Punta Cana'],
        'Ecuador' => ['Quito', 'Guayaquil', 'Cuenca'],
        'Egypt' => ['Cairo', 'Alexandria', 'Giza', 'Sharm El Sheikh', 'Luxor'],
        'El Salvador' => ['San Salvador', 'Santa Ana', 'San Miguel'],
        'Equatorial Guinea' => ['Malabo', 'Bata'],
        'Eritrea' => ['Asmara', 'Massawa', 'Keren'],
        'Estonia' => ['Tallinn', 'Tartu', 'Pärnu'],
        'Eswatini' => ['Mbabane', 'Manzini'],
        'Ethiopia' => ['Addis Ababa', 'Hawassa', 'Bahir Dar', 'Adama', 'Dire Dawa', 'Mekelle'],
        'Fiji' => ['Suva', 'Nadi', 'Lautoka'],
        'Finland' => ['Helsinki', 'Espoo', 'Tampere', 'Turku', 'Oulu'],
        'France' => ['Paris', 'Lyon', 'Marseille', 'Toulouse', 'Nice', 'Bordeaux', 'Lille', 'Strasbourg'],
        'Gabon' => ['Libreville', 'Port-Gentil', 'Franceville'],
        'Gambia' => ['Banjul', 'Serekunda', 'Brikama'],
        'Georgia' => ['Tbilisi', 'Batumi', 'Kutaisi'],
        'Germany' => ['Berlin', 'Munich (Bavaria)', 'Frankfurt (Hesse)', 'Hamburg', 'Cologne (NRW)', 'Stuttgart', 'Düsseldorf', 'Leipzig'],
        'Ghana' => ['Accra', 'Kumasi', 'Tamale', 'Takoradi', 'Tema'],
        'Greece' => ['Athens', 'Thessaloniki', 'Patras', 'Heraklion'],
        'Grenada' => ['St. George\'s'],
        'Guatemala' => ['Guatemala City', 'Antigua Guatemala', 'Quetzaltenango'],
        'Guinea' => ['Conakry', 'Nzérékoré', 'Kankan'],
        'Guinea-Bissau' => ['Bissau'],
        'Guyana' => ['Georgetown', 'Linden'],
        'Haiti' => ['Port-au-Prince', 'Cap-Haïtien'],
        'Honduras' => ['Tegucigalpa', 'San Pedro Sula', 'La Ceiba'],
        'Hungary' => ['Budapest', 'Debrecen', 'Szeged'],
        'Iceland' => ['Reykjavík', 'Akureyri'],
        'India' => ['Mumbai', 'New Delhi', 'Bengaluru', 'Hyderabad', 'Chennai', 'Kolkata', 'Pune', 'Ahmedabad'],
        'Indonesia' => ['Jakarta', 'Surabaya', 'Bandung', 'Bali (Denpasar)', 'Medan'],
        'Iran' => ['Tehran', 'Mashhad', 'Isfahan', 'Shiraz'],
        'Iraq' => ['Baghdad', 'Erbil', 'Basra', 'Sulaymaniyah'],
        'Ireland' => ['Dublin', 'Cork', 'Galway', 'Limerick', 'Waterford'],
        'Israel' => ['Tel Aviv', 'Jerusalem', 'Haifa'],
        'Italy' => ['Rome', 'Milan', 'Naples', 'Turin', 'Florence', 'Bologna', 'Venice'],
        'Jamaica' => ['Kingston', 'Montego Bay', 'Ocho Rios'],
        'Japan' => ['Tokyo', 'Osaka', 'Yokohama', 'Nagoya', 'Kyoto', 'Fukuoka', 'Sapporo'],
        'Jordan' => ['Amman', 'Zarqa', 'Irbid', 'Aqaba'],
        'Kazakhstan' => ['Almaty', 'Astana', 'Shymkent'],
        'Kiribati' => ['South Tarawa'],
        'Korea (North)' => ['Pyongyang'],
        'Korea (South)' => ['Seoul', 'Busan', 'Incheon', 'Daegu', 'Daejeon'],
        'Kosovo' => ['Pristina', 'Prizren'],
        'Kuwait' => ['Kuwait City', 'Hawalli', 'Salmiya'],
        'Kyrgyzstan' => ['Bishkek', 'Osh'],
        'Laos' => ['Vientiane', 'Luang Prabang'],
        'Latvia' => ['Riga', 'Daugavpils', 'Liepāja'],
        'Lebanon' => ['Beirut', 'Tripoli', 'Sidon'],
        'Lesotho' => ['Maseru', 'Teyateyaneng'],
        'Liberia' => ['Monrovia', 'Gbarnga'],
        'Libya' => ['Tripoli', 'Benghazi', 'Misrata'],
        'Liechtenstein' => ['Vaduz', 'Schaan'],
        'Lithuania' => ['Vilnius', 'Kaunas', 'Klaipėda'],
        'Luxembourg' => ['Luxembourg City', 'Esch-sur-Alzette'],
        'Madagascar' => ['Antananarivo', 'Toamasina', 'Antsirabe', 'Mahajanga'],
        'Malawi' => ['Lilongwe', 'Blantyre', 'Mzuzu', 'Zomba'],
        'Malaysia' => ['Kuala Lumpur', 'George Town (Penang)', 'Johor Bahru', 'Kota Kinabalu', 'Kuching'],
        'Maldives' => ['Malé', 'Addu City'],
        'Mali' => ['Bamako', 'Sikasso', 'Mopti'],
        'Malta' => ['Valletta', 'St. Julian\'s', 'Sliema'],
        'Marshall Islands' => ['Majuro'],
        'Mauritania' => ['Nouakchott', 'Nouadhibou'],
        'Mauritius' => ['Port Louis', 'Ebène', 'Curepipe', 'Grand Baie'],
        'Mexico' => ['Mexico City', 'Guadalajara', 'Monterrey', 'Cancún', 'Puebla', 'Querétaro'],
        'Micronesia' => ['Palikir', 'Weno'],
        'Moldova' => ['Chișinău', 'Bălți'],
        'Monaco' => ['Monaco (Monte Carlo)'],
        'Mongolia' => ['Ulaanbaatar', 'Erdenet'],
        'Montenegro' => ['Podgorica', 'Budva', 'Kotor'],
        'Morocco' => ['Casablanca', 'Rabat', 'Marrakesh', 'Tangier', 'Fes'],
        'Mozambique' => ['Maputo', 'Beira', 'Nampula', 'Pemba'],
        'Myanmar' => ['Yangon', 'Mandalay', 'Naypyidaw'],
        'Namibia' => ['Windhoek', 'Walvis Bay', 'Swakopmund'],
        'Nauru' => ['Yaren'],
        'Nepal' => ['Kathmandu', 'Pokhara', 'Lalitpur'],
        'Netherlands' => ['Amsterdam (North Holland)', 'Rotterdam / The Hague (South Holland)', 'Utrecht', 'Eindhoven', 'Groningen', 'Maastricht'],
        'New Zealand' => ['Auckland', 'Wellington', 'Christchurch', 'Hamilton', 'Queenstown'],
        'Nicaragua' => ['Managua', 'León', 'Granada'],
        'Niger' => ['Niamey', 'Zinder', 'Maradi'],
        'Nigeria' => ['Lagos', 'Abuja (FCT)', 'Port Harcourt (Rivers)', 'Ibadan (Oyo)', 'Kano', 'Enugu'],
        'North Macedonia' => ['Skopje', 'Bitola', 'Ohrid'],
        'Norway' => ['Oslo', 'Bergen', 'Stavanger', 'Trondheim'],
        'Oman' => ['Muscat', 'Salalah', 'Sohar'],
        'Pakistan' => ['Karachi', 'Lahore', 'Islamabad', 'Rawalpindi', 'Faisalabad'],
        'Palau' => ['Ngerulmud', 'Koror'],
        'Palestine' => ['Ramallah', 'Gaza City', 'Bethlehem', 'Hebron', 'Nablus'],
        'Panama' => ['Panama City', 'Colón', 'David'],
        'Papua New Guinea' => ['Port Moresby', 'Lae'],
        'Paraguay' => ['Asunción', 'Ciudad del Este'],
        'Peru' => ['Lima', 'Arequipa', 'Cusco', 'Trujillo'],
        'Philippines' => ['Metro Manila', 'Cebu City', 'Davao City', 'Quezon City'],
        'Poland' => ['Warsaw', 'Kraków', 'Wrocław', 'Gdańsk', 'Poznań'],
        'Portugal' => ['Lisbon', 'Porto', 'Braga', 'Faro'],
        'Qatar' => ['Doha', 'Al Wakrah', 'Al Khor', 'Lusail'],
        'Romania' => ['Bucharest', 'Cluj-Napoca', 'Timișoara', 'Iași'],
        'Russia' => ['Moscow', 'Saint Petersburg', 'Novosibirsk', 'Yekaterinburg'],
        'Rwanda' => ['Kigali', 'Musanze', 'Rubavu', 'Huye', 'Rwamagana'],
        'Saint Kitts and Nevis' => ['Basseterre'],
        'Saint Lucia' => ['Castries', 'Vieux Fort'],
        'Saint Vincent and the Grenadines' => ['Kingstown'],
        'Samoa' => ['Apia'],
        'San Marino' => ['City of San Marino'],
        'Sao Tome and Principe' => ['São Tomé'],
        'Saudi Arabia' => ['Riyadh', 'Jeddah', 'Dammam', 'Mecca', 'Medina', 'Khobar'],
        'Senegal' => ['Dakar', 'Thiès', 'Saint-Louis'],
        'Serbia' => ['Belgrade', 'Novi Sad', 'Niš'],
        'Seychelles' => ['Victoria (Mahé)', 'Praslin'],
        'Sierra Leone' => ['Freetown', 'Bo', 'Kenema'],
        'Singapore' => ['Singapore'],
        'Slovakia' => ['Bratislava', 'Košice'],
        'Slovenia' => ['Ljubljana', 'Maribor'],
        'Solomon Islands' => ['Honiara'],
        'Somalia' => ['Mogadishu', 'Hargeisa', 'Garowe', 'Kismayo', 'Bosaso', 'Baidoa'],
        'South Africa' => ['Johannesburg (Gauteng)', 'Pretoria (Gauteng)', 'Cape Town (Western Cape)', 'Durban (KwaZulu-Natal)', 'Gqeberha / Port Elizabeth (Eastern Cape)', 'Bloemfontein (Free State)', 'Polokwane (Limpopo)'],
        'South Sudan' => ['Juba', 'Wau', 'Malakal', 'Yei', 'Bor'],
        'Spain' => ['Madrid', 'Barcelona', 'Valencia', 'Seville', 'Bilbao', 'Málaga'],
        'Sri Lanka' => ['Colombo', 'Kandy', 'Galle'],
        'Sudan' => ['Khartoum', 'Port Sudan', 'Omdurman'],
        'Suriname' => ['Paramaribo'],
        'Sweden' => ['Stockholm', 'Gothenburg', 'Malmö', 'Uppsala'],
        'Switzerland' => ['Zurich', 'Geneva', 'Basel', 'Bern', 'Lausanne'],
        'Syria' => ['Damascus', 'Aleppo', 'Homs'],
        'Taiwan' => ['Taipei', 'Kaohsiung', 'Taichung', 'Tainan'],
        'Tajikistan' => ['Dushanbe', 'Khujand'],
        'Tanzania' => ['Dar es Salaam', 'Arusha', 'Dodoma', 'Mwanza', 'Zanzibar', 'Moshi', 'Mbeya', 'Tanga'],
        'Thailand' => ['Bangkok', 'Chiang Mai', 'Phuket', 'Pattaya'],
        'Timor-Leste' => ['Dili'],
        'Togo' => ['Lomé', 'Kara'],
        'Tonga' => ['Nuku\'alofa'],
        'Trinidad and Tobago' => ['Port of Spain', 'San Fernando', 'Chaguanas'],
        'Tunisia' => ['Tunis', 'Sfax', 'Sousse'],
        'Turkey' => ['Istanbul', 'Ankara', 'Izmir', 'Antalya', 'Bursa'],
        'Turkmenistan' => ['Ashgabat', 'Türkmenabat'],
        'Tuvalu' => ['Funafuti'],
        'Uganda' => ['Kampala', 'Entebbe', 'Wakiso', 'Jinja', 'Mbarara', 'Gulu', 'Mbale', 'Fort Portal'],
        'Ukraine' => ['Kyiv', 'Lviv', 'Odesa', 'Kharkiv', 'Dnipro'],
        'United Arab Emirates' => ['Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman', 'Ras Al Khaimah', 'Fujairah'],
        'United Kingdom' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Edinburgh', 'Glasgow', 'Bristol', 'Liverpool', 'Oxford', 'Cambridge', 'Reading', 'Nottingham', 'Sheffield', 'Cardiff', 'Belfast', 'Milton Keynes', 'Coventry'],
        'United States' => ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'District of Columbia (Washington, D.C.)', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'],
        'Uruguay' => ['Montevideo', 'Punta del Este'],
        'Uzbekistan' => ['Tashkent', 'Samarkand', 'Bukhara'],
        'Vanuatu' => ['Port Vila'],
        'Vatican City' => ['Vatican City'],
        'Venezuela' => ['Caracas', 'Maracaibo', 'Valencia'],
        'Vietnam' => ['Ho Chi Minh City', 'Hanoi', 'Da Nang'],
        'Yemen' => ['Sana\'a', 'Aden', 'Taiz'],
        'Zambia' => ['Lusaka', 'Ndola', 'Kitwe', 'Livingstone'],
        'Zimbabwe' => ['Harare', 'Bulawayo', 'Mutare', 'Victoria Falls', 'Gweru'],
    ];

    public static function getPlaceHierarchy(): array
    {
        return self::PLACE_HIERARCHY;
    }

    /**
     * Resolve Level 1 Country and Level 2 City/County/State from POST payload, supporting custom "Other" values.
     *
     * @return array{country: string, city_county: string}
     */
    public static function resolvePlaceFromPost(array $post, string $defaultCountry = 'Kenya', string $defaultCity = 'Nairobi'): array
    {
        $countrySel = trim((string)($post['country'] ?? ''));
        $countryCustom = trim((string)($post['country_custom'] ?? ''));
        $country = ($countrySel === '__custom__') ? $countryCustom : $countrySel;
        if ($country === '') {
            $country = $defaultCountry;
        }

        $citySel = trim((string)($post['city_county'] ?? ''));
        $cityCustom = trim((string)($post['city_county_custom'] ?? ''));
        $city = ($citySel === '__custom__') ? $cityCustom : $citySel;
        if ($city === '') {
            $city = $defaultCity;
        }

        return [
            'country' => mb_substr($country, 0, 80),
            'city_county' => mb_substr($city, 0, 100),
        ];
    }

    /**
     * Exact Spherical Haversine distance in kilometers.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Bounding-box pre-filter coordinates for indexed SQL queries before exact Haversine sorting.
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $latDelta = $radiusKm / 111.045;
        $cosLat = max(0.01, abs(cos(deg2rad($lat))));
        $lngDelta = $radiusKm / (111.045 * $cosLat);

        return [
            'minLat' => round($lat - $latDelta, 7),
            'maxLat' => round($lat + $latDelta, 7),
            'minLng' => round($lng - $lngDelta, 7),
            'maxLng' => round($lng + $lngDelta, 7),
        ];
    }

    /**
     * Parse coordinates from a Google Maps URL or raw "lat, lng" string.
     */
    public static function parseCoordinatesFromInput(string $input): ?array
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }

        // Pattern 1: !3d-1.2647!4d36.8021 (exact pin in Google Maps URLs)
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $raw, $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 2: @-1.2647,36.8021 or ?q=-1.2647,36.8021 or ll=-1.2647,36.8021
        if (preg_match('/(?:@|[?&](?:q|ll|center| destination)=)(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/i', urldecode($raw), $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 3: Raw "lat, lng"
        if (preg_match('/^\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\s*$/', $raw, $m)) {
            return self::validateCoords((float)$m[1], (float)$m[2]);
        }

        // Pattern 4: Open Location Code / Plus Code (e.g. "6GCRPPP3+9M" or "PPP3+9M Nairobi")
        return self::decodePlusCode($raw);
    }

    /**
     * Decode full (8+2) or short (4+2 with city name) Open Location Code (Plus Code).
     */
    public static function decodePlusCode(string $input): ?array
    {
        $clean = strtoupper(trim($input));
        if (!str_contains($clean, '+')) {
            return null;
        }

        if (!preg_match('/([23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3})(?:\s+([A-Z]+))?/', $clean, $m)) {
            return null;
        }

        $code = $m[1];
        $locality = strtolower($m[2] ?? 'nairobi');
        [$prefix, $suffix] = explode('+', $code, 2);

        // If short code (4 or 6 chars before '+'), recover full 8-char prefix using reference city centroid
        if (strlen($prefix) < 8) {
            $ref = self::KENYA_CITY_CENTROIDS[$locality] ?? self::KENYA_CITY_CENTROIDS['nairobi'];
            $fullRef = self::encodePlusCode($ref[0], $ref[1]);
            $fullPrefix = substr($fullRef, 0, 8 - strlen($prefix)) . $prefix;
            $prefix = $fullPrefix;
        }

        $full = $prefix . $suffix;
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $lat = -90.0;
        $lng = -180.0;

        $pairs = min(5, intdiv(strlen($full), 2));
        for ($i = 0; $i < $pairs; $i++) {
            $latChar = $full[$i * 2];
            $lngChar = $full[$i * 2 + 1];
            $latIdx = strpos(self::OLC_ALPHABET, $latChar);
            $lngIdx = strpos(self::OLC_ALPHABET, $lngChar);
            if ($latIdx === false || $lngIdx === false) {
                return null;
            }
            $lat += $latIdx * $weights[$i];
            $lng += $lngIdx * $weights[$i];
        }

        $res = $weights[$pairs - 1];
        return self::validateCoords(round($lat + ($res / 2.0), 6), round($lng + ($res / 2.0), 6));
    }

    public static function encodePlusCode(float $lat, float $lng): string
    {
        $adjLat = max(0.0, min(179.999999, $lat + 90.0));
        $adjLng = max(0.0, min(359.999999, $lng + 180.0));
        $weights = [20.0, 1.0, 0.05, 0.0025, 0.000125];
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $w = $weights[$i];
            $latDigit = (int)floor($adjLat / $w) % 20;
            $lngDigit = (int)floor($adjLng / $w) % 20;
            $out .= self::OLC_ALPHABET[$latDigit] . self::OLC_ALPHABET[$lngDigit];
            $adjLat -= $latDigit * $w;
            $adjLng -= $lngDigit * $w;
            if ($i === 3) {
                $out .= '+';
            }
        }
        return $out;
    }

    private static function validateCoords(float $lat, float $lng): ?array
    {
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }
        return ['latitude' => round($lat, 6), 'longitude' => round($lng, 6)];
    }

    /**
     * Compute human-readable 8-point compass bearing from (lat1,lng1) to (lat2,lng2).
     */
    public static function compassBearing(float $lat1, float $lng1, float $lat2, float $lng2): string
    {
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
           - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLng);
        $brng = fmod((rad2deg(atan2($y, $x)) + 360.0), 360.0);
        $dirs = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];
        return $dirs[(int)round($brng / 45.0) % 8];
    }

    /**
     * Calculate exact straight-line distance, driving road distance, estimated drive duration,
     * road polyline geometry, and turn-by-turn street directions (OSRM with automatic offline fallback).
     */
    public static function calculateRouteAndDirections(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        bool $allowExternalRouting = true
    ): array {
        $straightKm = self::haversineKm($fromLat, $fromLng, $toLat, $toLng);
        $bearing = self::compassBearing($fromLat, $fromLng, $toLat, $toLng);

        if ($allowExternalRouting && function_exists('curl_init')) {
            $osrmUrl = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%0.6F,%0.6F;%0.6F,%0.6F?overview=full&geometries=geojson&steps=true',
                $fromLng,
                $fromLat,
                $toLng,
                $toLat
            );
            $ch = curl_init($osrmUrl);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'BusherianBiasharasDirectory/0.3 (+https://www.busherianbiasharas.com)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($raw) && $code === 200) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && ($json['code'] ?? '') === 'Ok' && !empty($json['routes'][0])) {
                        $route = $json['routes'][0];
                        $roadKm = round(((float)($route['distance'] ?? 0)) / 1000.0, 2);
                        $durationMin = max(1, (int)round(((float)($route['duration'] ?? 0)) / 60.0));

                        $latLngs = [];
                        foreach (($route['geometry']['coordinates'] ?? []) as $pt) {
                            if (is_array($pt) && count($pt) >= 2) {
                                $latLngs[] = [round((float)$pt[1], 6), round((float)$pt[0], 6)];
                            }
                        }

                        // Consolidate OSRM steps into natural Kenyan left/right road transitions (no compass bearings)
                        $segments = [];
                        $pendingMod = '';
                        $pendingType = '';

                        foreach (($route['legs'][0]['steps'] ?? []) as $st) {
                            $maneuver = (array)($st['maneuver'] ?? []);
                            $mType = strtolower(trim((string)($maneuver['type'] ?? '')));
                            $mMod = strtolower(trim((string)($maneuver['modifier'] ?? '')));
                            if ($mType === 'arrive') {
                                continue;
                            }
                            $rName = trim((string)($st['name'] ?? ''));
                            $dM = (float)($st['distance'] ?? 0.0);
                            $rLower = strtolower($rName);

                            if ($rName === '' || $rLower === 'unnamed road' || str_contains($rLower, 'roundabout')) {
                                if (($mMod !== '' && (str_contains($mMod, 'left') || str_contains($mMod, 'right'))) && $pendingMod === '') {
                                    $pendingMod = $mMod;
                                    $pendingType = $mType;
                                }
                                if (!empty($segments)) {
                                    $segments[count($segments) - 1]['distance'] += $dM;
                                }
                                continue;
                            }

                            $lastIdx = count($segments) - 1;
                            if ($lastIdx >= 0 && self::isSameRoadName($segments[$lastIdx]['name'], $rName)) {
                                $segments[$lastIdx]['distance'] += $dM;
                            } else {
                                $useType = $mType;
                                $useMod = $mMod;
                                if ($useMod === '' && $pendingMod !== '') {
                                    $useMod = $pendingMod;
                                    $useType = $pendingType !== '' ? $pendingType : $useType;
                                }
                                $segments[] = [
                                    'name' => $rName,
                                    'distance' => $dM,
                                    'type' => $useType,
                                    'modifier' => $useMod,
                                ];
                                $pendingMod = '';
                                $pendingType = '';
                            }
                        }

                        // Absorb intermediate micro-crossings (< 120m) so roundabout islands don't split a continuous road
                        $cleaned = [];
                        $segCount = count($segments);
                        foreach ($segments as $idx => $seg) {
                            $isFirst = ($idx === 0);
                            $isLast = ($idx === $segCount - 1);
                            if (!$isFirst && !$isLast && $seg['distance'] < 120.0 && !empty($cleaned)) {
                                $cleaned[count($cleaned) - 1]['distance'] += $seg['distance'];
                                continue;
                            }
                            $cLast = count($cleaned) - 1;
                            if ($cLast >= 0 && self::isSameRoadName($cleaned[$cLast]['name'], $seg['name'])) {
                                $cleaned[$cLast]['distance'] += $seg['distance'];
                            } else {
                                $cleaned[] = $seg;
                            }
                        }

                        $corridors = [];
                        $steps = [];
                        foreach ($cleaned as $i => $seg) {
                            $rName = $seg['name'];
                            $corridors[] = $rName;
                            $dM = (float)$seg['distance'];
                            $dLabel = $dM >= 1000.0
                                ? (round($dM / 1000.0, 1) . ' km')
                                : (max(10, (int)round($dM / 10.0) * 10) . ' m');
                            $t = $seg['type'];
                            $mod = $seg['modifier'];

                            if ($i === 0) {
                                if (str_contains($mod, 'left') && $t !== 'depart') {
                                    $instr = "Start by turning left onto {$rName}";
                                } elseif (str_contains($mod, 'right') && $t !== 'depart') {
                                    $instr = "Start by turning right onto {$rName}";
                                } else {
                                    $instr = "Start on {$rName} and keep straight";
                                }
                            } else {
                                if (in_array($t, ['roundabout', 'rotary', 'exit roundabout', 'exit rotary'], true)) {
                                    if (str_contains($mod, 'left')) {
                                        $instr = "At the roundabout, turn left onto {$rName}";
                                    } elseif (str_contains($mod, 'right')) {
                                        $instr = "At the roundabout, turn right onto {$rName}";
                                    } else {
                                        $instr = "At the roundabout, take the exit onto {$rName}";
                                    }
                                } elseif (str_contains($mod, 'left')) {
                                    $instr = "Turn left onto {$rName}";
                                } elseif (str_contains($mod, 'right')) {
                                    $instr = "Turn right onto {$rName}";
                                } elseif ($mod === 'uturn') {
                                    $instr = "Make a U-turn onto {$rName}";
                                } else {
                                    $instr = "Continue straight onto {$rName}";
                                }
                            }

                            $steps[] = [
                                'instruction' => $instr,
                                'distance' => $dLabel,
                            ];
                        }

                        if (empty($steps)) {
                            $turnWord = ($toLng >= $fromLng) ? 'Turn right' : 'Turn left';
                            $steps[] = [
                                'instruction' => "{$turnWord} onto the main road and continue toward destination",
                                'distance' => "{$roadKm} km (~{$durationMin} min)",
                            ];
                        }

                        if (!empty($latLngs)) {
                            return [
                                'mode' => 'osrm_driving',
                                'straight_km' => $straightKm,
                                'road_km' => $roadKm,
                                'duration_min' => $durationMin,
                                'corridors' => $corridors,
                                'polyline' => $latLngs,
                                'steps' => $steps,
                            ];
                        }
                    }
                }
            }
        }

        $estRoadKm = round($straightKm * 1.28, 2);
        $estDurationMin = max(2, (int)round(($estRoadKm / 30.0) * 60.0));
        $turnDir = ($toLng >= $fromLng) ? 'right' : 'left';
        return [
            'mode' => 'estimated_direct',
            'straight_km' => $straightKm,
            'road_km' => $estRoadKm,
            'duration_min' => $estDurationMin,
            'polyline' => [
                [round($fromLat, 6), round($fromLng, 6)],
                [round($toLat, 6), round($toLng, 6)],
            ],
            'corridors' => [],
            'steps' => [
                [
                    'instruction' => 'Start on your nearest main road and keep straight',
                    'distance' => '',
                ],
                [
                    'instruction' => "Turn {$turnDir} at the main junction toward destination",
                    'distance' => "~{$estRoadKm} km (~{$estDurationMin} min)",
                ],
            ],
        ];
    }

    private static function isSameRoadName(string $a, string $b): bool
    {
        $ca = strtolower(trim($a));
        $cb = strtolower(trim($b));
        if ($ca === $cb) {
            return true;
        }
        similar_text($ca, $cb, $pct);
        return $pct >= 88.0;
    }

    /**
     * Built-in Kenyan street, neighborhood, and town coordinates for instant/offline fallback.
     */
    public const KENYA_PLACE_INDEX = [
        'marist lane' => [-1.344470, 36.728656, 'Marist Lane, Karen, Nairobi'],
        'karen' => [-1.321000, 36.704200, 'Karen, Nairobi'],
        'langata' => [-1.348900, 36.747900, 'Langata, Nairobi'],
        'lavington' => [-1.278800, 36.771200, 'Lavington, Nairobi'],
        'kilimani' => [-1.292100, 36.787900, 'Kilimani, Nairobi'],
        'kileleshwa' => [-1.281400, 36.782800, 'Kileleshwa, Nairobi'],
        'westlands' => [-1.263500, 36.803200, 'Westlands, Nairobi'],
        'parklands' => [-1.261200, 36.817500, 'Parklands, Nairobi'],
        'gigiri' => [-1.236400, 36.812200, 'Gigiri, Nairobi'],
        'runda' => [-1.219500, 36.819000, 'Runda, Nairobi'],
        'muthaiga' => [-1.249800, 36.836500, 'Muthaiga, Nairobi'],
        'upper hill' => [-1.298600, 36.814700, 'Upper Hill, Nairobi'],
        'hurlingham' => [-1.293500, 36.796200, 'Hurlingham, Nairobi'],
        'ngong road' => [-1.300500, 36.768900, 'Ngong Road, Nairobi'],
        'south c' => [-1.319800, 36.828600, 'South C, Nairobi'],
        'south b' => [-1.310200, 36.840500, 'South B, Nairobi'],
        'industrial area' => [-1.308800, 36.855200, 'Industrial Area, Nairobi'],
        'mombasa road' => [-1.328500, 36.854200, 'Mombasa Road, Nairobi'],
        'jkia' => [-1.319167, 36.927500, 'JKIA Airport, Nairobi'],
        'syokimau' => [-1.359200, 36.935800, 'Syokimau, Machakos'],
        'athi river' => [-1.456300, 36.978200, 'Athi River, Machakos'],
        'lukenya' => [-1.482500, 37.051400, 'Lukenya, Machakos'],
        'kitengela' => [-1.474800, 36.960200, 'Kitengela, Kajiado'],
        'isinya' => [-1.683300, 36.850000, 'Isinya, Kajiado'],
        'ngong' => [-1.361800, 36.656600, 'Ngong, Kajiado'],
        'ongata rongai' => [-1.396400, 36.758200, 'Ongata Rongai, Kajiado'],
        'kikuyu' => [-1.246300, 36.662900, 'Kikuyu (Alliance / Bush), Kiambu'],
        'alliance' => [-1.246300, 36.662900, 'Alliance (Bush), Kikuyu'],
        'lower kabete' => [-1.240500, 36.762200, 'Lower Kabete, Nairobi'],
        'waiyaki way' => [-1.264700, 36.792100, 'Waiyaki Way, Nairobi'],
        'thika road' => [-1.232200, 36.878800, 'Thika Road, Nairobi'],
        'kasarani' => [-1.221000, 36.897500, 'Kasarani, Nairobi'],
        'roysambu' => [-1.218500, 36.887000, 'Roysambu, Nairobi'],
        'ruaka' => [-1.204500, 36.776500, 'Ruaka, Kiambu'],
        'kiambu' => [-1.171400, 36.835600, 'Kiambu Road / Town'],
        'tigoni' => [-1.135400, 36.674200, 'Tigoni, Limuru'],
        'limuru' => [-1.106900, 36.642900, 'Limuru, Kiambu'],
        'ruiru' => [-1.148600, 36.960500, 'Ruiru, Kiambu'],
        'juja' => [-1.102600, 37.013200, 'Juja, Kiambu'],
        'thika' => [-1.033300, 37.069300, 'Thika, Kiambu'],
        'cbd' => [-1.286389, 36.817223, 'Nairobi CBD'],
    ];

    /**
     * Geocode a Kenyan street, estate, landmark, Plus Code, or coordinate string into {latitude, longitude, label}.
     */
    public static function geocodeKenyanLocation(string $rawQuery, bool $allowExternal = true): ?array
    {
        $q = trim($rawQuery);
        if ($q === '') {
            return null;
        }

        // 1. Check if it is already coordinates, a Google Maps URL, or an Open Location Code (Plus Code)
        $parsed = self::parseCoordinatesFromInput($q);
        if ($parsed !== null) {
            return [
                'latitude' => $parsed['latitude'],
                'longitude' => $parsed['longitude'],
                'label' => $q,
                'source' => 'coordinates_or_plus_code',
            ];
        }

        $lower = strtolower($q);

        // 2. Query OpenStreetMap Nominatim (bounded to Kenya) when external requests are enabled
        if ($allowExternal && function_exists('curl_init')) {
            $searchQuery = str_contains($lower, 'kenya') ? $q : ($q . ', Kenya');
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $searchQuery,
                'format' => 'jsonv2',
                'countrycodes' => 'ke',
                'limit' => 1,
            ]);
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 4,
                    CURLOPT_CONNECTTIMEOUT => 2,
                    CURLOPT_USERAGENT => 'BusherianBiasharasDirectory/0.5 (+https://www.busherianbiasharas.com)',
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (is_string($raw) && $code === 200) {
                    $json = json_decode($raw, true);
                    if (is_array($json) && !empty($json[0]['lat']) && !empty($json[0]['lon'])) {
                        $lat = round((float)$json[0]['lat'], 6);
                        $lng = round((float)$json[0]['lon'], 6);
                        $disp = (string)($json[0]['display_name'] ?? $q);
                        $parts = array_slice(array_map('trim', explode(',', $disp)), 0, 3);
                        return [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'label' => implode(', ', $parts) ?: $q,
                            'source' => 'nominatim_ke',
                        ];
                    }
                }
            }
        }

        // 3. Fallback to built-in Kenyan place index (also ensures deterministic offline/CI tests)
        foreach (self::KENYA_PLACE_INDEX as $key => $info) {
            if (str_contains($lower, $key)) {
                return [
                    'latitude' => $info[0],
                    'longitude' => $info[1],
                    'label' => $info[2],
                    'source' => 'kenya_place_index',
                ];
            }
        }

        return null;
    }
}
