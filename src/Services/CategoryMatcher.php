<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;

final class CategoryMatcher
{
    /**
     * 14 Standard Level 1 Industry Sectors & 105 Pre-Populated Level 2 Specialties
     */
    public const DEFAULT_TAXONOMY = [
        [
            'name' => 'Legal, Accounting & Advisory',
            'slug' => 'legal-accounting-advisory',
            'icon' => 'scale',
            'specialties' => [
                'Advocates & Law Firm',
                'Audit, Tax & Accounting (CPA)',
                'Company Secretarial & Governance',
                'Management & Strategy Consulting',
                'HR Recruitment & Payroll',
                'Actuarial & Risk Advisory',
                'Arbitration & Mediation',
                'Intellectual Property & Trademarks',
            ],
        ],
        [
            'name' => 'Health, Medical & Wellness',
            'slug' => 'health-medical-wellness',
            'icon' => 'heart-pulse',
            'specialties' => [
                'General Medical & Family Clinic',
                'Dental Clinic & Orthodontics',
                'Optometry & Eye Care',
                'Pharmacy & Chemist',
                'Diagnostic Lab & Imaging',
                'Physiotherapy & Rehabilitation',
                'Mental Health & Counselling',
                'Pediatric & Maternal Care',
                'Gym, Fitness & Yoga Studio',
            ],
        ],
        [
            'name' => 'Technology, Software & Digital',
            'slug' => 'technology-software-digital',
            'icon' => 'cpu',
            'specialties' => [
                'Custom Software & Mobile App Dev',
                'Web Design & E-Commerce',
                'Cybersecurity & IT Audit',
                'Cloud, DevOps & Hosting',
                'Data Analytics & AI Solutions',
                'IT Hardware & Networking',
                'Fintech & Payment Integration',
                'ERP & Business Automation',
                'Bulk SMS, Voice, USSD & Communications APIs',
            ],
        ],
        [
            'name' => 'Real Estate, Construction & Architecture',
            'slug' => 'real-estate-construction-architecture',
            'icon' => 'building-2',
            'specialties' => [
                'Architecture & Urban Planning',
                'Civil & Structural Engineering',
                'Building & General Contractor',
                'Quantity Surveying',
                'Interior Design & Fit-Outs',
                'Property Sales, Letting & Management',
                'Land Surveying & Valuation',
                'Hardware, Timber & Building Materials',
                'Plumbing, Electrical & Solar Installation',
            ],
        ],
        [
            'name' => 'Food, Beverage & Hospitality',
            'slug' => 'food-beverage-hospitality',
            'icon' => 'utensils',
            'specialties' => [
                'Restaurant, Nyama Choma & Bistro',
                'Coffee Shop, Bakery & Pastry',
                'Event & Corporate Catering',
                'Hotel, Boutique Resort & Airbnb',
                'Specialty Coffee, Tea & Honey Brand',
                'Butchery & Fresh Farm Produce',
                'Bar, Lounge & Wines/Spirits',
                'Food Processing & Packaging',
            ],
        ],
        [
            'name' => 'Finance, Insurance & Investment',
            'slug' => 'finance-insurance-investment',
            'icon' => 'landmark',
            'specialties' => [
                'Insurance Agency & Brokerage',
                'Wealth Management & Investment Advisory',
                'SACCO, Microfinance & Credit',
                'Forex, Remittances & Treasury',
                'Venture Capital & Private Equity',
                'Pension & Retirement Planning',
                'Stockbrokerage & Capital Markets',
                'Mortgages & Property Finance',
            ],
        ],
        [
            'name' => 'Retail, Fashion & Consumer Goods',
            'slug' => 'retail-fashion-consumer-goods',
            'icon' => 'shopping-bag',
            'specialties' => [
                'Bespoke Tailoring, Suits & Bridal',
                'Apparel, Footwear & Streetwear',
                'Jewelry, Leather & African Crafts',
                'Beauty, Cosmetics & Hair Salon',
                'Home Decor, Furniture & Appliances',
                'Bookshop, Stationery & Gifts',
                'Supermarket & Minimart',
                'Baby, Kids & Maternity Store',
            ],
        ],
        [
            'name' => 'Agriculture & Agribusiness',
            'slug' => 'agriculture-agribusiness',
            'icon' => 'sprout',
            'specialties' => [
                'Dairy, Poultry & Livestock Farming',
                'Horticulture & Fresh Export',
                'Agrovet, Seeds & Crop Protection',
                'Farm Machinery, Irrigation & Greenhouses',
                'Animal Feeds & Veterinary Services',
                'Agri-Processing & Value Addition',
                'Beekeeping & Apiculture',
            ],
        ],
        [
            'name' => 'Automotive, Logistics & Transport',
            'slug' => 'automotive-logistics-transport',
            'icon' => 'truck',
            'specialties' => [
                'Auto Garage, Service & Body Shop',
                'Car Dealership & Import Financing',
                'Auto Spares, Tyres & Batteries',
                'Car Hire, Chauffeur & Executive Transfer',
                'Courier, Last-Mile & Freight Logistics',
                'Clearing, Forwarding & Customs',
                'Moving, Relocation & Storage',
            ],
        ],
        [
            'name' => 'Education, Training & Coaching',
            'slug' => 'education-training-coaching',
            'icon' => 'graduation-cap',
            'specialties' => [
                'Kindergarten, Primary & High School',
                'Academic Tutoring & Exam Prep (CBC/IGCSE)',
                'Corporate Training & Executive Coaching',
                'Music, Arts & Coding Academy',
                'Study Abroad & University Placement',
                'Driving School & Technical TVET',
            ],
        ],
        [
            'name' => 'Creative, Media, Marketing & Events',
            'slug' => 'creative-media-marketing-events',
            'icon' => 'camera',
            'specialties' => [
                'Branding, Graphic Design & PR Agency',
                'Digital Marketing, SEO & Social Media',
                'Commercial Photography & Videography',
                'Event Planning, Tent/Sound & Decor',
                'Printing, Signage & Branded Merchandise',
                'Publishing, Podcast & Content Studio',
            ],
        ],
        [
            'name' => 'Manufacturing, Energy & Industrial',
            'slug' => 'manufacturing-energy-industrial',
            'icon' => 'factory',
            'specialties' => [
                'Solar PV, Inverters & Clean Energy',
                'Water Borehole Drilling & Treatment',
                'Metal Fabrication, Welding & Machining',
                'Chemical, Soap & Detergent Manufacturing',
                'Plastics, Packaging & Paper Products',
                'Industrial Safety (PPE) & Fire Equipment',
            ],
        ],
        [
            'name' => 'Travel, Tours & Safari',
            'slug' => 'travel-tours-safari',
            'icon' => 'compass',
            'specialties' => [
                'Safari Operator & Tour Packages',
                'Flight Ticketing & Visa Assistance',
                'Team Building & Bush Expeditions',
                'Eco-Camp & Safari Lodge',
                'Travel Insurance & Concierge',
                'Airport Transfers & VIP Meet-and-Greet',
                'Conference & MICE Tourism',
            ],
        ],
        [
            'name' => 'Home, Security & Facility Services',
            'slug' => 'home-security-facility-services',
            'icon' => 'shield-check',
            'specialties' => [
                'CCTV, Electric Fencing & Guarding',
                'Commercial & Residential Cleaning',
                'Pest Control & Fumigation',
                'Landscaping & Gardening',
                'Laundry & Dry Cleaning',
                'Appliance Repair & Handyman',
                'Moving, Packing & Storage',
                'borehole Pump Servicing & Plumbing',
            ],
        ],
    ];

    /**
     * Kenyan & Swahili/Sheng Business Synonym Lexicon
     * Maps local conversational terms to canonical English search keywords/specialties.
     */
    public const SYNONYM_LEXICON = [
        'wakili' => ['Advocates', 'Law Firm', 'Legal'],
        'lawyer' => ['Advocates', 'Law Firm', 'Legal'],
        'advocate' => ['Advocates', 'Law Firm', 'Legal'],
        'sheria' => ['Advocates', 'Law Firm', 'Legal'],
        'daktari' => ['Medical', 'Clinic', 'Dental', 'Health'],
        'hospitali' => ['Medical', 'Clinic', 'Health'],
        'chemist' => ['Pharmacy', 'Chemist', 'Medical'],
        'dawa' => ['Pharmacy', 'Chemist', 'Agrovet'],
        'macho' => ['Optometry', 'Eye Care'],
        'meno' => ['Dental', 'Orthodontics'],
        'mjengo' => ['Construction', 'Building', 'Hardware', 'Contractor'],
        'fundi' => ['Contractor', 'Plumbing', 'Electrical', 'Auto Garage', 'Tailoring'],
        'mabati' => ['Hardware', 'Building Materials', 'Construction'],
        'mbao' => ['Timber', 'Hardware', 'Furniture'],
        'stima' => ['Electrical', 'Solar'],
        'maji' => ['Borehole', 'Water', 'Plumbing'],
        'nyama' => ['Nyama Choma', 'Restaurant', 'Butchery', 'Catering'],
        'choma' => ['Nyama Choma', 'Restaurant'],
        'kahawa' => ['Coffee', 'Bakery'],
        'keki' => ['Bakery', 'Pastry', 'Catering'],
        'chakula' => ['Restaurant', 'Catering', 'Food'],
        'gari' => ['Auto Garage', 'Car Dealership', 'Car Hire', 'Auto Spares'],
        'mechanic' => ['Auto Garage', 'Auto Spares'],
        'garage' => ['Auto Garage', 'Body Shop'],
        'spares' => ['Auto Spares', 'Tyres'],
        'shamba' => ['Land Surveying', 'Property', 'Agriculture', 'Farming'],
        'kuku' => ['Poultry', 'Livestock', 'Animal Feeds'],
        'maziwa' => ['Dairy', 'Livestock'],
        'mbegu' => ['Agrovet', 'Seeds'],
        'shule' => ['School', 'Tutoring', 'Education'],
        'mwalimu' => ['Tutoring', 'Coaching', 'Education'],
        'picha' => ['Photography', 'Videography'],
        'harusi' => ['Event Planning', 'Catering', 'Bridal', 'Photography'],
        'nguo' => ['Tailoring', 'Apparel', 'Fashion'],
        'kinyozi' => ['Beauty', 'Hair Salon'],
        'salon' => ['Beauty', 'Hair Salon', 'Cosmetics'],
        'ushuru' => ['Tax', 'Accounting', 'Audit'],
        'bima' => ['Insurance'],
    ];

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    public static function expandSearchTerms(string $rawQuery): array
    {
        $clean = strtolower(trim($rawQuery));
        if ($clean === '') {
            return [];
        }
        $terms = [$rawQuery];
        $tokens = preg_split('/\s+/', $clean) ?: [];
        foreach ($tokens as $tok) {
            $tok = preg_replace('/[^a-z0-9]/', '', $tok) ?? '';
            if (isset(self::SYNONYM_LEXICON[$tok])) {
                foreach (self::SYNONYM_LEXICON[$tok] as $mapped) {
                    $terms[] = $mapped;
                }
            }
        }
        return array_values(array_unique($terms));
    }

    /**
     * Jaro-Winkler & Levenshtein similarity score between 0.0 and 1.0
     */
    public static function similarity(string $a, string $b): float
    {
        $s1 = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $a) ?? ''));
        $s2 = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $b) ?? ''));
        if ($s1 === '' || $s2 === '') {
            return 0.0;
        }
        if ($s1 === $s2) {
            return 1.0;
        }
        if (str_contains($s1, $s2) || str_contains($s2, $s1)) {
            $minLen = min(strlen($s1), strlen($s2));
            $maxLen = max(strlen($s1), strlen($s2));
            if ($minLen >= 4) {
                return max(0.84, $minLen / $maxLen);
            }
        }
        $maxLen = max(strlen($s1), strlen($s2));
        $lev = levenshtein(substr($s1, 0, 255), substr($s2, 0, 255));
        $levSim = 1.0 - ($lev / $maxLen);

        similar_text($s1, $s2, $pct);
        $simText = $pct / 100.0;

        return round(max($levSim, $simText), 4);
    }

    /**
     * Find closest existing specialties to prevent duplicate custom categories.
     */
    public static function findFuzzyMatches(PDO $pdo, string $customName, ?int $parentId = null, float $threshold = 0.65): array
    {
        $sql = "SELECT c.id, c.parent_id, c.name, c.slug, p.name AS sector_name
                FROM categories c
                LEFT JOIN categories p ON p.id = c.parent_id
                WHERE c.parent_id IS NOT NULL AND c.status = 'approved'";
        $params = [];
        if ($parentId !== null && $parentId > 0) {
            $sql .= " AND c.parent_id = :pid";
            $params[':pid'] = $parentId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $matches = [];
        foreach ($rows as $row) {
            $score = self::similarity($customName, (string)$row['name']);
            // Also check individual word overlap
            foreach (explode('/', str_replace(['&', ','], '/', (string)$row['name'])) as $part) {
                $partScore = self::similarity($customName, trim($part));
                if ($partScore > $score) {
                    $score = $partScore;
                }
            }
            if ($score >= $threshold) {
                $matches[] = [
                    'id' => (int)$row['id'],
                    'parent_id' => (int)$row['parent_id'],
                    'name' => $row['name'],
                    'sector_name' => $row['sector_name'],
                    'similarity' => $score,
                ];
            }
        }

        usort($matches, static fn($x, $y) => $y['similarity'] <=> $x['similarity']);
        return array_slice($matches, 0, 3);
    }

    /**
     * Suggest Level 1 Sector & Level 2 Specialty from scraped website text / JSON-LD.
     */
    public static function suggestCategoryFromText(PDO $pdo, string $text): ?array
    {
        $haystack = strtolower($text);
        if (trim($haystack) === '') {
            return null;
        }

        $stmt = $pdo->query("
            SELECT c.id AS specialty_id, c.name AS specialty_name,
                   p.id AS sector_id, p.name AS sector_name
            FROM categories c
            INNER JOIN categories p ON p.id = c.parent_id
            WHERE c.status = 'approved'
        ");
        $specialties = $stmt->fetchAll();

        $best = null;
        $bestScore = 0.0;

        foreach ($specialties as $spec) {
            $score = 0.0;
            $words = preg_split('/[^a-z0-9]+/i', strtolower($spec['specialty_name'] . ' ' . $spec['sector_name'])) ?: [];
            foreach ($words as $w) {
                if (strlen($w) < 3 || in_array($w, ['and', 'the', 'for', 'care', 'services'], true)) {
                    continue;
                }
                if (str_contains($haystack, $w)) {
                    $score += (strlen($w) >= 6) ? 2.5 : 1.5;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'sector_id' => (int)$spec['sector_id'],
                    'sector_name' => $spec['sector_name'],
                    'specialty_id' => (int)$spec['specialty_id'],
                    'specialty_name' => $spec['specialty_name'],
                    'confidence' => min(0.99, round($score / 6.0, 2)),
                ];
            }
        }

        return ($bestScore >= 1.5) ? $best : null;
    }

    /**
     * Kenyan Statutory Regulators & License Hints for Regulated Level 2 Specialties.
     * Only specialties present in this map require & display Regulator + License/Reg No. fields.
     */
    public const REGULATED_SPECIALTIES = [
        // 1. Legal, Accounting & Advisory
        'Advocates & Law Firm' => [
            'default' => 'LSK (Law Society of Kenya)',
            'options' => [
                'LSK (Law Society of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. LSK/2026/04819 or P.105/...',
        ],
        'Audit, Tax & Accounting (CPA)' => [
            'default' => 'ICPAK (Institute of Certified Public Accountants of Kenya)',
            'options' => [
                'ICPAK (Institute of Certified Public Accountants of Kenya)',
                'KRA (Authorized Tax Agent)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. ICPAK Practising Cert / Reg No.',
        ],
        'Company Secretarial & Governance' => [
            'default' => 'ICPSK (Institute of Certified Public Secretaries of Kenya)',
            'options' => [
                'ICPSK (Institute of Certified Public Secretaries of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CPS(K) Practising No.',
        ],
        'HR Recruitment & Payroll' => [
            'default' => 'IHRM (Institute of Human Resource Management)',
            'options' => [
                'IHRM (Institute of Human Resource Management)',
                'NEA (National Employment Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. IHRM Practising Cert No.',
        ],
        'Actuarial & Risk Advisory' => [
            'default' => 'IRA (Insurance Regulatory Authority)',
            'options' => [
                'IRA (Insurance Regulatory Authority)',
                'TASK (The Actuarial Society of Kenya)',
                'RBA (Retirement Benefits Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. IRA / TASK Reg No.',
        ],
        'Arbitration & Mediation' => [
            'default' => 'CIArb (Chartered Institute of Arbitrators — Kenya)',
            'options' => [
                'CIArb (Chartered Institute of Arbitrators — Kenya)',
                'NCIA (Nairobi Centre for International Arbitration)',
                'Judiciary MAC (Mediation Accreditation Committee)',
                'LSK (Law Society of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CIArb / MAC Accreditation No.',
        ],
        'Intellectual Property & Trademarks' => [
            'default' => 'KIPI (Kenya Industrial Property Institute)',
            'options' => [
                'KIPI (Kenya Industrial Property Institute)',
                'LSK (Law Society of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KIPI Patent/TM Agent No. or LSK No.',
        ],

        // 2. Health, Medical & Wellness
        'General Medical & Family Clinic' => [
            'default' => 'KMPDC (Kenya Medical Practitioners and Dentists Council)',
            'options' => [
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'COC (Clinical Officers Council)',
                'NCK (Nursing Council of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KMPDC Facility / Practitioner Reg No.',
        ],
        'Dental Clinic & Orthodontics' => [
            'default' => 'KMPDC (Kenya Medical Practitioners and Dentists Council)',
            'options' => [
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KMPDC Dental Reg / Facility No.',
        ],
        'Optometry & Eye Care' => [
            'default' => 'KMPDC (Kenya Medical Practitioners and Dentists Council)',
            'options' => [
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'OCK (Optometrists Council / Association of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KMPDC / OCK Reg No.',
        ],
        'Pharmacy & Chemist' => [
            'default' => 'PPB (Pharmacy and Poisons Board)',
            'options' => [
                'PPB (Pharmacy and Poisons Board)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. PPB Premises / Superintendent License No.',
        ],
        'Diagnostic Lab & Imaging' => [
            'default' => 'KMLTTB (Kenya Medical Laboratory Technicians & Technologists Board)',
            'options' => [
                'KMLTTB (Kenya Medical Laboratory Technicians & Technologists Board)',
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'KNRA (Kenya Nuclear Regulatory Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KMLTTB / KMPDC Lab License No.',
        ],
        'Physiotherapy & Rehabilitation' => [
            'default' => 'PCK (Physiotherapy Council of Kenya)',
            'options' => [
                'PCK (Physiotherapy Council of Kenya)',
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. PCK Practising License No.',
        ],
        'Mental Health & Counselling' => [
            'default' => 'KCPC (Counsellors and Psychologists Board / KCPA)',
            'options' => [
                'KCPC (Counsellors and Psychologists Board / KCPA)',
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KCPC / KMPDC Reg No.',
        ],
        'Pediatric & Maternal Care' => [
            'default' => 'KMPDC (Kenya Medical Practitioners and Dentists Council)',
            'options' => [
                'KMPDC (Kenya Medical Practitioners and Dentists Council)',
                'NCK (Nursing Council of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KMPDC / NCK Reg No.',
        ],

        // 3. Technology, Software & Digital (Regulated Financial/Telecom Specialties Only)
        'Fintech & Payment Integration' => [
            'default' => 'CBK (Central Bank of Kenya)',
            'options' => [
                'CBK (Central Bank of Kenya — PSP / DCP)',
                'ODPC (Office of the Data Protection Commissioner)',
                'CA (Communications Authority of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CBK PSP/DCP License or ODPC Reg No.',
        ],
        'Bulk SMS, Voice, USSD & Communications APIs' => [
            'default' => 'CA (Communications Authority of Kenya)',
            'options' => [
                'CA (Communications Authority of Kenya — ASP / CSP)',
                'ODPC (Office of the Data Protection Commissioner)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CA ASP/CSP License No.',
        ],

        // 4. Real Estate, Construction & Architecture
        'Architecture & Urban Planning' => [
            'default' => 'BORAQS (Board of Registration of Architects and Quantity Surveyors)',
            'options' => [
                'BORAQS (Board of Registration of Architects and Quantity Surveyors)',
                'KIP (Kenya Institute of Planners)',
                'AAK (Architectural Association of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. BORAQS Architect Reg No. (A...)',
        ],
        'Civil & Structural Engineering' => [
            'default' => 'EBK (Engineers Board of Kenya)',
            'options' => [
                'EBK (Engineers Board of Kenya)',
                'NCA (National Construction Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. EBK Reg No. (A... / C...)',
        ],
        'Building & General Contractor' => [
            'default' => 'NCA (National Construction Authority)',
            'options' => [
                'NCA (National Construction Authority)',
                'EBK (Engineers Board of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. NCA Contractor Reg No. (NCA1–NCA8)',
        ],
        'Quantity Surveying' => [
            'default' => 'BORAQS (Board of Registration of Architects and Quantity Surveyors)',
            'options' => [
                'BORAQS (Board of Registration of Architects and Quantity Surveyors)',
                'IQSK (Institute of Quantity Surveyors of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. BORAQS QS Reg No. (Q...)',
        ],
        'Property Sales, Letting & Management' => [
            'default' => 'EARB (Estate Agents Registration Board)',
            'options' => [
                'EARB (Estate Agents Registration Board)',
                'ISK (Institution of Surveyors of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. EARB Reg / Practising Cert No.',
        ],
        'Land Surveying & Valuation' => [
            'default' => 'ISK / VRB (Valuers Registration Board / Land Surveyors Board)',
            'options' => [
                'ISK / VRB (Valuers Registration Board / Land Surveyors Board)',
                'VRB (Valuers Registration Board)',
                'LSB (Land Surveyors Board)',
                'ISK (Institution of Surveyors of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. VRB / LSB / ISK Reg No.',
        ],
        'Plumbing, Electrical & Solar Installation' => [
            'default' => 'EPRA (Energy & Petroleum Regulatory Authority)',
            'options' => [
                'EPRA (Energy & Petroleum Regulatory Authority)',
                'NCA (National Construction Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. EPRA Electrical/Solar License or NCA No.',
        ],

        // 5. Finance, Insurance & Investment
        'Insurance Agency & Brokerage' => [
            'default' => 'IRA (Insurance Regulatory Authority)',
            'options' => [
                'IRA (Insurance Regulatory Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. IRA License No. (IRA/05/...)',
        ],
        'Wealth Management & Investment Advisory' => [
            'default' => 'CMA (Capital Markets Authority)',
            'options' => [
                'CMA (Capital Markets Authority)',
                'RBA (Retirement Benefits Authority)',
                'CBK (Central Bank of Kenya)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CMA Investment Adviser License No.',
        ],
        'SACCO, Microfinance & Credit' => [
            'default' => 'SASRA (SACCO Societies Regulatory Authority)',
            'options' => [
                'SASRA (SACCO Societies Regulatory Authority)',
                'CBK (Central Bank of Kenya — Microfinance / DCP)',
                'State Dept for Co-operatives',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. SASRA / CBK / Co-op Reg No.',
        ],
        'Forex, Remittances & Treasury' => [
            'default' => 'CBK (Central Bank of Kenya)',
            'options' => [
                'CBK (Central Bank of Kenya)',
                'CMA (Capital Markets Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CBK Forex Bureau / MRP License No.',
        ],
        'Venture Capital & Private Equity' => [
            'default' => 'CMA (Capital Markets Authority)',
            'options' => [
                'CMA (Capital Markets Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CMA Fund Manager / License No.',
        ],
        'Pension & Retirement Planning' => [
            'default' => 'RBA (Retirement Benefits Authority)',
            'options' => [
                'RBA (Retirement Benefits Authority)',
                'IRA (Insurance Regulatory Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. RBA Administrator / Manager Reg No.',
        ],
        'Stockbrokerage & Capital Markets' => [
            'default' => 'CMA (Capital Markets Authority)',
            'options' => [
                'CMA (Capital Markets Authority)',
                'NSE (Nairobi Securities Exchange)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CMA Stockbroker / Dealer License No.',
        ],
        'Mortgages & Property Finance' => [
            'default' => 'CBK (Central Bank of Kenya)',
            'options' => [
                'CBK (Central Bank of Kenya)',
                'SASRA (SACCO Societies Regulatory Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. CBK / SASRA License No.',
        ],

        // 6. Agriculture & Agribusiness (Regulated Vet / Agrovet Specialties Only)
        'Agrovet, Seeds & Crop Protection' => [
            'default' => 'PCPB (Pest Control Products Board)',
            'options' => [
                'PCPB (Pest Control Products Board)',
                'KVB (Kenya Veterinary Board)',
                'KEPHIS (Kenya Plant Health Inspectorate Service)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. PCPB / KVB / KEPHIS License No.',
        ],
        'Animal Feeds & Veterinary Services' => [
            'default' => 'KVB (Kenya Veterinary Board)',
            'options' => [
                'KVB (Kenya Veterinary Board)',
                'KEBS (Kenya Bureau of Standards)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KVB Veterinary Practice / Reg No.',
        ],

        // 7. Automotive, Logistics & Transport (Customs Clearing Only)
        'Clearing, Forwarding & Customs' => [
            'default' => 'KRA (Kenya Revenue Authority — Licensed Customs Agent)',
            'options' => [
                'KRA (Kenya Revenue Authority — Licensed Customs Agent)',
                'KIFWA (Kenya International Freight and Warehousing Association)',
                'KMA (Kenya Maritime Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. KRA Customs Agent License No.',
        ],

        // 8. Education, Training & Coaching (Formal Schools & TVET/Driving Only)
        'Kindergarten, Primary & High School' => [
            'default' => 'MoE (Ministry of Education)',
            'options' => [
                'MoE (Ministry of Education)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. Ministry of Education School Reg No.',
        ],
        'Driving School & Technical TVET' => [
            'default' => 'TVETA (Technical and Vocational Education and Training Authority)',
            'options' => [
                'TVETA (Technical and Vocational Education and Training Authority)',
                'NTSA (National Transport and Safety Authority)',
                'NITA (National Industrial Training Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. TVETA / NTSA / NITA Reg No.',
        ],

        // 9. Manufacturing, Energy & Industrial (Regulated Energy & Water Only)
        'Solar PV, Inverters & Clean Energy' => [
            'default' => 'EPRA (Energy & Petroleum Regulatory Authority)',
            'options' => [
                'EPRA (Energy & Petroleum Regulatory Authority)',
                'NCA (National Construction Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. EPRA Solar PV License No. (T1/T2/T3/C1)',
        ],
        'Water Borehole Drilling & Treatment' => [
            'default' => 'WRA (Water Resources Authority)',
            'options' => [
                'WRA (Water Resources Authority)',
                'Ministry of Water & Irrigation',
                'NCA (National Construction Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. WRA Licensed Borehole Contractor No.',
        ],

        // 10. Travel, Tours & Safari (Regulated Tour Operators & Lodges Only)
        'Safari Operator & Tour Packages' => [
            'default' => 'TRA (Tourism Regulatory Authority)',
            'options' => [
                'TRA (Tourism Regulatory Authority)',
                'KATO (Kenya Association of Tour Operators)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. TRA Tour Operator License No.',
        ],
        'Eco-Camp & Safari Lodge' => [
            'default' => 'TRA (Tourism Regulatory Authority)',
            'options' => [
                'TRA (Tourism Regulatory Authority)',
                'NEMA (National Environment Management Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. TRA Hospitality License No.',
        ],

        // 11. Home, Security & Facility Services (Regulated Security & Pest Control Only)
        'CCTV, Electric Fencing & Guarding' => [
            'default' => 'PSRA (Private Security Regulatory Authority)',
            'options' => [
                'PSRA (Private Security Regulatory Authority)',
                'CA (Communications Authority of Kenya)',
                'NCA (National Construction Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. PSRA Corporate Registration No.',
        ],
        'Pest Control & Fumigation' => [
            'default' => 'PCPB (Pest Control Products Board)',
            'options' => [
                'PCPB (Pest Control Products Board)',
                'NEMA (National Environment Management Authority)',
                'Other Statutory Regulator',
            ],
            'placeholder' => 'e.g. PCPB Pest Control Operator License No.',
        ],
    ];

    /**
     * Return statutory regulation config for a Level 2 specialty name, or null if unregulated.
     */
    public static function getRegulationInfoForSpecialty(string $specialtyName): ?array
    {
        $clean = trim($specialtyName);
        if ($clean === '') {
            return null;
        }
        if (isset(self::REGULATED_SPECIALTIES[$clean])) {
            return self::REGULATED_SPECIALTIES[$clean];
        }
        foreach (self::REGULATED_SPECIALTIES as $name => $info) {
            if (strcasecmp($name, $clean) === 0) {
                return $info;
            }
        }
        return null;
    }

    /**
     * Look up statutory regulation config by database specialty category ID.
     */
    public static function getSpecialtyRegulationById(PDO $pdo, int $specialtyId): ?array
    {
        if ($specialtyId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = :id AND parent_id IS NOT NULL LIMIT 1");
        $stmt->execute([':id' => $specialtyId]);
        $name = $stmt->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            return null;
        }
        return self::getRegulationInfoForSpecialty($name);
    }

    /**
     * Return true if the given database specialty category ID belongs to a regulated specialty.
     */
    public static function isSpecialtyRegulated(PDO $pdo, int $specialtyId): bool
    {
        return self::getSpecialtyRegulationById($pdo, $specialtyId) !== null;
    }

    public static function getTaxonomyTree(PDO $pdo, bool $includePending = false): array
    {
        $statusClause = $includePending ? "status IN ('approved', 'pending_approval')" : "status = 'approved'";
        $sectors = $pdo->query("SELECT id, name, slug, icon, status FROM categories WHERE parent_id IS NULL AND {$statusClause} ORDER BY id ASC")->fetchAll();
        $specialties = $pdo->query("SELECT id, parent_id, name, slug, status FROM categories WHERE parent_id IS NOT NULL AND {$statusClause} ORDER BY name ASC")->fetchAll();

        $byParent = [];
        foreach ($specialties as $sp) {
            $pid = (int)$sp['parent_id'];
            $regInfo = self::getRegulationInfoForSpecialty((string)$sp['name']);
            $byParent[$pid][] = [
                'id' => (int)$sp['id'],
                'parent_id' => $pid,
                'name' => $sp['name'],
                'slug' => $sp['slug'],
                'status' => $sp['status'],
                'is_regulated' => $regInfo !== null,
                'regulator_default' => $regInfo['default'] ?? null,
                'regulator_options' => $regInfo['options'] ?? [],
                'regulator_placeholder' => $regInfo['placeholder'] ?? null,
            ];
        }

        $tree = [];
        foreach ($sectors as $sec) {
            $sid = (int)$sec['id'];
            $tree[] = [
                'id' => $sid,
                'name' => $sec['name'],
                'slug' => $sec['slug'],
                'icon' => $sec['icon'],
                'status' => $sec['status'],
                'specialties' => $byParent[$sid] ?? [],
            ];
        }
        return $tree;
    }

    public static function seedDefaultTaxonomy(PDO $pdo): void
    {
        $insertSector = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, status) VALUES (NULL, :name, :slug, :icon, 'approved')");
        $insertSpec = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, status) VALUES (:parent_id, :name, :slug, NULL, 'approved')");

        foreach (self::DEFAULT_TAXONOMY as $sector) {
            $insertSector->execute([
                ':name' => $sector['name'],
                ':slug' => $sector['slug'],
                ':icon' => $sector['icon'],
            ]);
            $sectorId = (int)$pdo->lastInsertId();

            foreach ($sector['specialties'] as $specName) {
                $specSlug = $sector['slug'] . '-' . self::slugify($specName);
                $insertSpec->execute([
                    ':parent_id' => $sectorId,
                    ':name' => $specName,
                    ':slug' => $specSlug,
                ]);
            }
        }
    }

    public static function seedSampleBusinesses(PDO $pdo): void
    {
        $findCat = static function (string $nameLike) use ($pdo): array {
            $stmt = $pdo->prepare("
                SELECT c.id AS specialty_id, c.parent_id AS sector_id
                FROM categories c
                WHERE c.parent_id IS NOT NULL AND c.name LIKE :q
                LIMIT 1
            ");
            $stmt->execute([':q' => '%' . $nameLike . '%']);
            $row = $stmt->fetch();
            return $row ? ['sector_id' => (int)$row['sector_id'], 'specialty_id' => (int)$row['specialty_id']] : ['sector_id' => 1, 'specialty_id' => 2];
        };

        $standardHours = json_encode([
            'mon' => [['08:00', '17:30']],
            'tue' => [['08:00', '17:30']],
            'wed' => [['08:00', '17:30']],
            'thu' => [['08:00', '17:30']],
            'fri' => [['08:00', '17:30']],
            'sat' => [['09:00', '14:00']],
            'sun' => [],
        ]);

        $hospitalityHours = json_encode([
            'mon' => [['08:00', '22:00']],
            'tue' => [['08:00', '22:00']],
            'wed' => [['08:00', '22:00']],
            'thu' => [['08:00', '22:30']],
            'fri' => [['08:00', '23:30']],
            'sat' => [['08:00', '23:30']],
            'sun' => [['09:00', '22:00']],
        ]);

        $samples = [
            [
                'name' => 'The Green Table Pizza',
                'slug' => 'the-green-table-pizza',
                'tagline' => 'Wood-fired stone oven artisan pizzas, fresh vegetarian-friendly dining, and garden terrace workspace in Lavington.',
                'description' => 'Artisan pizzeria and café at Lavington Corner Mall known for wood-fired stone oven pizzas, fresh farm ingredients, and a relaxing outdoor terrace with high-speed Starlink Wi-Fi.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Restaurant, Nyama Choma'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 0,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Lavington Flagship',
                'building_name' => 'Lavington Corner Mall',
                'floor_suite' => 'Ground Terrace',
                'street_road' => '02 James Gichuru Road',
                'area_neighborhood' => 'Lavington',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Lavington Corner Mall along James Gichuru Road',
                'plus_code' => '6GCRPRCF+GF',
                'latitude' => -1.278800,
                'longitude' => 36.771200,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.278800,36.771200',
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '+254701091194',
                'whatsapp_number' => '+254701091194',
                'email_public' => 'info@thegreentablepizza.com',
                'website_url' => 'https://www.thegreentablepizza.com/',
            ],
            [
                'name' => 'The Last Village Lodge',
                'slug' => 'the-last-village-lodge',
                'tagline' => 'Swahili-chic country lodge, workcation retreat, conference facility, and outdoor adventure destination in Lukenya.',
                'description' => 'Inspired by Swahili Chic — Ushwari (serenity), Usafi (neatness), and Uzuri (beauty). An adventure and workcation country lodge offering corporate team-building, conference facilities, swimming pool, quad biking, archery, and tranquil getaways off Mombasa Road.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Hotel, Boutique Resort'),
                'service_mode' => 'physical',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Lukenya Country Lodge',
                'building_name' => 'The Last Village Grounds',
                'floor_suite' => null,
                'street_road' => 'Mutungoni Road (Off Mombasa Road)',
                'area_neighborhood' => 'Lukenya, Athi River',
                'city_county' => 'Machakos',
                'landmark_directions' => 'Off Mombasa Road via Mutungoni Road, Lukenya Hills',
                'plus_code' => '6GCRG392+XH',
                'latitude' => -1.482500,
                'longitude' => 37.051400,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.482500,37.051400',
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '+254799149944',
                'whatsapp_number' => '+254799149944',
                'email_public' => 'reservations@thelastvillage.co.ke',
                'website_url' => 'https://thelastvillage.co.ke/',
                'social_instagram' => 'https://www.instagram.com/thelastvillagelodge',
                'social_x' => 'https://x.com/The_LastVillage',
                'social_facebook' => 'https://www.facebook.com/TheLastVillage/',
            ],
            [
                'name' => 'Tumaini Gardens Resort Isinya',
                'slug' => 'tumaini-gardens-resort-isinya',
                'tagline' => 'Serene 10-acre garden resort, cottage lodge, swimming pool, and corporate/wedding event venue along the Nairobi-Namanga Highway.',
                'description' => 'Expansive 10-acre garden resort and conference center blending modern architecture with rustic savannah charm. Features rooms with fireplaces, a 3-bedroom cottage, outdoor pool, restaurant, and manicured wedding/corporate event lawns.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Hotel, Boutique Resort'),
                'service_mode' => 'physical',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Isinya Resort',
                'building_name' => 'Tumaini Gardens Resort',
                'floor_suite' => null,
                'street_road' => 'Off Nairobi-Namanga Highway',
                'area_neighborhood' => 'Isinya',
                'city_county' => 'Kajiado',
                'landmark_directions' => 'Past Isinya town near Merishaw School along Nairobi-Namanga Highway',
                'plus_code' => '6GCR8V82+M2',
                'latitude' => -1.683300,
                'longitude' => 36.850000,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.683300,36.850000',
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '+254759473510',
                'whatsapp_number' => '+254759473510',
                'email_public' => 'tumainigardensresort@gmail.com',
                'website_url' => 'https://tumainigardensresortisinya.co.ke/',
            ],
            [
                'name' => 'Naishola Gardens',
                'slug' => 'naishola-gardens',
                'tagline' => 'Exclusive scenic events, wedding, and wellness destination nestled in the lush tea highlands of Tigoni, Limuru.',
                'description' => 'Premier outdoor events, luxury weddings, corporate retreats, and sensory garden venue set amidst tranquil tea plantations at Gathoni Park Farm on Limuru Road, Tigoni.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Event Planning'),
                'service_mode' => 'physical',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Tigoni Estate',
                'building_name' => 'Gathoni Park Farm',
                'floor_suite' => null,
                'street_road' => 'Limuru Road',
                'area_neighborhood' => 'Tigoni',
                'city_county' => 'Kiambu',
                'landmark_directions' => 'Gathoni Park Farm along Limuru Road, Tigoni',
                'plus_code' => '6GCRVM7F+RM',
                'latitude' => -1.135400,
                'longitude' => 36.674200,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.135400,36.674200',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254795656433',
                'whatsapp_number' => '+254795656433',
                'email_public' => 'naisholagardens@gmail.com',
                'website_url' => 'https://naishola.com/',
            ],
            [
                'name' => 'Bustani Bistro',
                'slug' => 'bustani-bistro',
                'tagline' => 'Garden restaurant, signature nyama choma platters, sports bar, event grounds, and car wash at the Kimbo exit in Ruiru.',
                'description' => 'Vibrant outdoor garden bistro, sports lounge, event grounds, and detailing carwash in Ruiru off Thika Superhighway (Kimbo exit), serving delicious family platters, grills, and weekend entertainment.',
                'school_badge' => 'AGHS',
                'cat' => $findCat('Restaurant, Nyama Choma'),
                'service_mode' => 'physical',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 0,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Ruiru Garden Bistro',
                'building_name' => 'Bustani Bistro Grounds',
                'floor_suite' => null,
                'street_road' => 'Kiganjo Road (Kimbo Exit, Thika Road)',
                'area_neighborhood' => 'Ruiru',
                'city_county' => 'Kiambu',
                'landmark_directions' => 'Along Kiganjo Road off the Kimbo Exit on Thika Superhighway',
                'plus_code' => '6GCRVX26+H6',
                'latitude' => -1.148600,
                'longitude' => 36.960500,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.148600,36.960500',
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '+254799909340',
                'whatsapp_number' => '+254799909340',
                'email_public' => 'bustanibistro@gmail.com',
                'website_url' => 'https://bustanibistro.com/',
                'social_instagram' => 'https://www.instagram.com/bustanibistro/',
                'social_facebook' => 'https://www.facebook.com/bustanibistro',
                'social_tiktok' => 'https://www.tiktok.com/@bustanibistro_',
            ],
            [
                'name' => 'Branfoot Enterprises Limited',
                'slug' => 'branfoot-enterprises',
                'tagline' => 'Road freight, distribution, route-to-market optimization, and transport network expertise moving East African businesses forward.',
                'description' => 'Leading Kenyan logistics, road freight, distribution, and supply-chain network optimization company headquartered at Western Heights in Westlands, Nairobi.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Courier, Last-Mile & Freight'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Westlands HQ',
                'building_name' => 'Western Heights',
                'floor_suite' => null,
                'street_road' => 'Karuna Road',
                'area_neighborhood' => 'Westlands',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Western Heights Building along Karuna Road, Westlands',
                'plus_code' => '6GCRPPP3+M6',
                'latitude' => -1.263300,
                'longitude' => 36.803100,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.263300,36.803100',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254733120007',
                'whatsapp_number' => '+254733120007',
                'email_public' => 'info@branfoot.com',
                'website_url' => 'https://www.branfoot.com/',
            ],
            [
                'name' => 'Akili AI',
                'slug' => 'akili-ai',
                'tagline' => 'Accelerating digital transformation across Africa with enterprise AI governance, strategy, and automated customer engagement.',
                'description' => 'Combining the policy expertise of the African Centre for Technology Studies and leading AI researchers from the University of Cambridge, Akili AI helps businesses, financial cooperatives, and institutions deploy profitable, responsible Artificial Intelligence across Africa.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Data Analytics & AI'),
                'service_mode' => 'online',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Nairobi Practice',
                'building_name' => null,
                'floor_suite' => null,
                'street_road' => 'UN Avenue / Gigiri',
                'area_neighborhood' => 'Gigiri',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Nairobi, Kenya',
                'plus_code' => '6GCRPR78+CV',
                'latitude' => -1.236400,
                'longitude' => 36.812200,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.236400,36.812200',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '',
                'whatsapp_number' => null,
                'email_public' => 'info@akili-ai.com',
                'website_url' => 'https://akili-ai.com/',
                'social_linkedin' => 'https://www.linkedin.com/company/akili-ai',
                'social_youtube' => 'https://www.youtube.com/@AkiliAI',
            ],
            [
                'name' => 'Prescott Engineering Solutions',
                'slug' => 'prescott-engineering-solutions',
                'tagline' => 'Heavy construction machinery, power generators, industrial tools, and plant equipment solutions across Kenya.',
                'description' => 'Kenyan engineering and industrial equipment supplier along Mombasa Road, Nairobi, specializing in construction machinery, concrete mixers, generators, material handling equipment, and technical servicing.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Hardware, Timber & Building'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Mombasa Road Showroom',
                'building_name' => 'Prescott Centre',
                'floor_suite' => null,
                'street_road' => 'Mombasa Road',
                'area_neighborhood' => 'Industrial Area / Mombasa Road',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Along Mombasa Road, Nairobi',
                'plus_code' => '6GCRMV93+JM',
                'latitude' => -1.328500,
                'longitude' => 36.854200,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.328500,36.854200',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254733284616',
                'whatsapp_number' => '+254733284616',
                'email_public' => 'sales@prescott.co.ke',
                'website_url' => 'https://prescott.co.ke/',
                'social_instagram' => 'https://instagram.com/PrescottAfrica',
                'social_linkedin' => 'https://www.linkedin.com/company/pescott-africa/',
                'social_x' => 'https://x.com/PrescottAfrica',
                'social_facebook' => 'https://facebook.com/PrescottAfrica',
            ],
            [
                'name' => "Harriet's Botanicals",
                'slug' => 'harriets-botanicals',
                'tagline' => 'Natural indigenous herbal formulations — including Arorwet and Tendwet — for digestive, hormonal, and reproductive wellness.',
                'description' => 'Pioneering Kenyan natural health and botanical wellness brand blending indigenous herbal knowledge (such as Arorwet and Tendwet) with modern quality standards to support digestive, hormonal, and reproductive health.',
                'school_badge' => 'AGHS',
                'cat' => $findCat('Pharmacy & Chemist'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 0,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Nairobi Wellness Hub',
                'building_name' => null,
                'floor_suite' => null,
                'street_road' => 'Westlands / Karen',
                'area_neighborhood' => 'Westlands',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Westlands, Nairobi',
                'plus_code' => '6GCRPPP5+4H',
                'latitude' => -1.267200,
                'longitude' => 36.808900,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.267200,36.808900',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254711082049',
                'whatsapp_number' => '+254711082049',
                'email_public' => 'info@harrietsbotanicals.co.ke',
                'website_url' => 'https://harrietsbotanicals.co.ke/',
                'social_instagram' => 'https://www.instagram.com/harrietsbotanicals',
                'social_x' => 'https://x.com/harrietsbotani1',
                'social_facebook' => 'https://www.facebook.com/Harriets-Botanicals-405225280037531/',
                'social_youtube' => 'https://www.youtube.com/channel/UC1rGId0gVvtN-7OBg3Ibdqw',
            ],
            [
                'name' => 'Mambo Pambo',
                'slug' => 'mambopambo',
                'tagline' => 'Reimagining Afro-contemporary fashion with bespoke statement pieces, structured silhouettes, and artisanal African textiles.',
                'description' => 'Nairobi-based Afro-contemporary fashion house specializing in bespoke and ready-to-wear statement garments, kimonos, and modern silhouettes crafted from vibrant African textiles.',
                'school_badge' => 'AGHS',
                'cat' => $findCat('Bespoke Tailoring'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 0,
                'accepts_lpo' => 0,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Nairobi Design Studio',
                'building_name' => null,
                'floor_suite' => null,
                'street_road' => 'Kilimani',
                'area_neighborhood' => 'Kilimani',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Nairobi Studio (By Appointment & Online)',
                'plus_code' => '6GCRPR5J+5R',
                'latitude' => -1.292100,
                'longitude' => 36.782000,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.292100,36.782000',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254700495600',
                'whatsapp_number' => '+254701118618',
                'email_public' => 'studio@mambopambo.com',
                'website_url' => 'http://www.mambopambo.com/',
                'social_instagram' => 'https://www.instagram.com/mambopamboworld/',
                'social_x' => 'https://twitter.com/MamboPamboWorld',
                'social_facebook' => 'https://www.facebook.com/mambopamboworld/',
            ],
            [
                'name' => 'Lasambara Wines',
                'slug' => 'lasambara-wines',
                'tagline' => 'Pioneering Kenyan estate winery in the volcanic highlands of Timau, Meru — crafting organic Chenin Blanc, Shiraz, and table wines.',
                'description' => 'Handcrafted Kenyan wines grown in the cool volcanic highlands of Timau, Northern Meru. Offering estate-bottled wines, vineyard tours, and curated tasting experiences.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Bar, Lounge & Wines/Spirits'),
                'service_mode' => 'hybrid',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Timau Vineyard & Estate',
                'building_name' => 'Lasambara Vineyard',
                'floor_suite' => null,
                'street_road' => 'Nanyuki-Meru Highway, Timau',
                'area_neighborhood' => 'Timau',
                'city_county' => 'Meru',
                'landmark_directions' => 'Timau Highlands between Nanyuki and Meru',
                'plus_code' => '6GGR36PR+4F',
                'latitude' => 0.085300,
                'longitude' => 37.241200,
                'google_maps_url' => 'https://www.google.com/maps?q=0.085300,37.241200',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254708802668',
                'whatsapp_number' => '+254722561219',
                'email_public' => 'order@lasambara.com',
                'website_url' => 'https://www.cal.eu/lasambara-wines',
            ],
            [
                'name' => 'SasaSignal',
                'slug' => 'sasasignal',
                'tagline' => 'Your All-In-One Business Communications Backbone: Instant Airtime Disbursement, Bulk SMS, Voice & USSD APIs.',
                'description' => 'Kenyan business communications and value-transfer platform supporting Safaricom, Airtel, and Telkom networks. Specializes in instant staff and loyalty airtime disbursement, flat-rate bulk SMS messaging, alphanumeric Sender ID registration, OTP authentication, and developer-friendly APIs.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Bulk SMS, Voice, USSD'),
                'service_mode' => 'online',
                'alumni_perk' => null,
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Cloud Platform & API Hub',
                'building_name' => null,
                'floor_suite' => null,
                'street_road' => null,
                'area_neighborhood' => 'Nairobi',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Cloud SaaS & Developer API Platform (Kenya-wide)',
                'plus_code' => null,
                'latitude' => -1.286389,
                'longitude' => 36.817223,
                'google_maps_url' => null,
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '',
                'whatsapp_number' => null,
                'email_public' => 'hey@sasasignal.com',
                'website_url' => 'https://sasasignal.com/',
                'social_instagram' => 'https://www.instagram.com/SasaSignal/',
                'social_x' => 'https://x.com/SasaSignal',
                'social_facebook' => 'https://www.facebook.com/SasaSignal',
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO businesses (
                business_id, business_number, name, slug, tagline, description, school_badge,
                sector_id, specialty_id, service_mode, alumni_perk,
                b2b_ready, accepts_lpo, regulator_name, regulator_license,
                branch_name, building_name, floor_suite, street_road,
                area_neighborhood, city_county, landmark_directions,
                plus_code, latitude, longitude, google_maps_url,
                operating_hours_json, phone_primary, whatsapp_number,
                email_public, website_url, social_instagram, social_linkedin,
                social_x, social_facebook, social_tiktok, social_youtube,
                status, verified_at, last_confirmed_at
            ) VALUES (
                :business_id, :business_number, :name, :slug, :tagline, :description, :school_badge,
                :sector_id, :specialty_id, :service_mode, :alumni_perk,
                :b2b_ready, :accepts_lpo, :regulator_name, :regulator_license,
                :branch_name, :building_name, :floor_suite, :street_road,
                :area_neighborhood, :city_county, :landmark_directions,
                :plus_code, :latitude, :longitude, :google_maps_url,
                :operating_hours_json, :phone_primary, :whatsapp_number,
                :email_public, :website_url, :social_instagram, :social_linkedin,
                :social_x, :social_facebook, :social_tiktok, :social_youtube,
                'approved', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $seqNum = 0;
        foreach ($samples as $s) {
            $seqNum++;
            $detUid = 'BBIZ-' . strtoupper(substr(md5($s['slug']), 0, 8));
            $stmt->execute([
                ':business_id' => $detUid,
                ':business_number' => $seqNum,
                ':name' => $s['name'],
                ':slug' => $s['slug'],
                ':tagline' => $s['tagline'],
                ':description' => $s['description'],
                ':school_badge' => $s['school_badge'],
                ':sector_id' => $s['cat']['sector_id'],
                ':specialty_id' => $s['cat']['specialty_id'],
                ':service_mode' => $s['service_mode'],
                ':alumni_perk' => $s['alumni_perk'],
                ':b2b_ready' => $s['b2b_ready'],
                ':accepts_lpo' => $s['accepts_lpo'],
                ':regulator_name' => $s['regulator_name'],
                ':regulator_license' => $s['regulator_license'],
                ':branch_name' => $s['branch_name'],
                ':building_name' => $s['building_name'],
                ':floor_suite' => $s['floor_suite'],
                ':street_road' => $s['street_road'],
                ':area_neighborhood' => $s['area_neighborhood'],
                ':city_county' => $s['city_county'],
                ':landmark_directions' => $s['landmark_directions'],
                ':plus_code' => $s['plus_code'],
                ':latitude' => $s['latitude'],
                ':longitude' => $s['longitude'],
                ':google_maps_url' => $s['google_maps_url'],
                ':operating_hours_json' => $s['operating_hours_json'],
                ':phone_primary' => $s['phone_primary'],
                ':whatsapp_number' => $s['whatsapp_number'],
                ':email_public' => $s['email_public'],
                ':website_url' => $s['website_url'],
                ':social_instagram' => $s['social_instagram'] ?? null,
                ':social_linkedin' => $s['social_linkedin'] ?? null,
                ':social_x' => $s['social_x'] ?? null,
                ':social_facebook' => $s['social_facebook'] ?? null,
                ':social_tiktok' => $s['social_tiktok'] ?? null,
                ':social_youtube' => $s['social_youtube'] ?? null,
            ]);
        }
    }
}
