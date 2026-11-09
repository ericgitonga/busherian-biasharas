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

    public static function getTaxonomyTree(PDO $pdo, bool $includePending = false): array
    {
        $statusClause = $includePending ? "status IN ('approved', 'pending_approval')" : "status = 'approved'";
        $sectors = $pdo->query("SELECT id, name, slug, icon, status FROM categories WHERE parent_id IS NULL AND {$statusClause} ORDER BY id ASC")->fetchAll();
        $specialties = $pdo->query("SELECT id, parent_id, name, slug, status FROM categories WHERE parent_id IS NOT NULL AND {$statusClause} ORDER BY name ASC")->fetchAll();

        $byParent = [];
        foreach ($specialties as $sp) {
            $pid = (int)$sp['parent_id'];
            $byParent[$pid][] = [
                'id' => (int)$sp['id'],
                'parent_id' => $pid,
                'name' => $sp['name'],
                'slug' => $sp['slug'],
                'status' => $sp['status'],
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
            'sat' => [['09:00', '13:30']],
            'sun' => [],
        ]);

        $hospitalityHours = json_encode([
            'mon' => [['07:00', '22:00']],
            'tue' => [['07:00', '22:00']],
            'wed' => [['07:00', '22:00']],
            'thu' => [['07:00', '22:30']],
            'fri' => [['07:00', '23:30']],
            'sat' => [['07:30', '23:30']],
            'sun' => [['08:00', '21:30']],
        ]);

        $samples = [
            [
                'name' => 'Githaiga & Associates Advocates',
                'slug' => 'githaiga-associates-advocates',
                'tagline' => 'Commercial litigation, conveyancing, IP trademarks, and corporate governance across East Africa.',
                'description' => 'Full-service corporate and commercial law firm founded by Busherian alumni. Specializing in real estate conveyancing, commercial contracts, employment law, IP protection, and dispute resolution.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Advocates'),
                'service_mode' => 'hybrid',
                'alumni_perk' => '15% off initial corporate structuring & conveyancing consultation for Busherians',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => 'LSK',
                'regulator_license' => 'LSK/2026/04819',
                'branch_name' => 'Upper Hill Chambers',
                'building_name' => 'Britam Tower',
                'floor_suite' => '14th Floor, Suite 14B',
                'street_road' => 'Hospital Road',
                'area_neighborhood' => 'Upper Hill',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Opposite KNH Gate, next to Mara Road roundabout',
                'plus_code' => '6GCRPR27+8R',
                'latitude' => -1.299150,
                'longitude' => 36.814620,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.299150,36.814620',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254722100201',
                'whatsapp_number' => '+254722100201',
                'email_public' => 'info@githaigalaw.co.ke',
                'website_url' => 'https://www.githaigalaw.co.ke',
                'social_linkedin' => 'https://www.linkedin.com/company/githaiga-advocates',
                'social_x' => 'https://x.com/githaigalawke',
            ],
            [
                'name' => 'Kikuyu Springs Dental & Orthodontics',
                'slug' => 'kikuyu-springs-dental-orthodontics',
                'tagline' => 'Specialist family dentistry, painless root canals, implants, and clear aligner orthodontics.',
                'description' => 'Modern specialist dental clinic led by an AGHS alumna. Equipped with 3D digital panoramic imaging, pediatric dentistry suites, and adult cosmetic orthodontics.',
                'school_badge' => 'AGHS',
                'cat' => $findCat('Dental Clinic'),
                'service_mode' => 'physical',
                'alumni_perk' => 'Complimentary digital dental checkup & 10% off scaling/polishing for alumni families',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => 'KMPDC',
                'regulator_license' => 'KMPDC-D-9921',
                'branch_name' => 'Westlands Clinic',
                'building_name' => 'The Mirage Towers',
                'floor_suite' => 'Tower 2, 5th Floor',
                'street_road' => 'Waiyaki Way',
                'area_neighborhood' => 'Westlands',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Across from GTC Westlands',
                'plus_code' => '6GCRPPP3+9M',
                'latitude' => -1.264700,
                'longitude' => 36.802100,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.264700,36.802100',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254733220440',
                'whatsapp_number' => '+254733220440',
                'email_public' => 'care@kikuyuspringsdental.co.ke',
                'website_url' => 'https://www.kikuyuspringsdental.co.ke',
                'social_instagram' => 'https://www.instagram.com/kikuyuspringsdental',
                'social_facebook' => 'https://www.facebook.com/kikuyuspringsdental',
            ],
            [
                'name' => 'StrongToServe Solar & Borehole Engineering',
                'slug' => 'strongtoserve-solar-borehole-engineering',
                'tagline' => 'Commercial & residential solar PV hybrid systems, borehole drilling, and water treatment across Kenya.',
                'description' => 'EPRA T1 and NCA licensed renewable energy and hydrogeological engineering firm co-founded by AHS & AGHS alumni. Turnkey C&I solar installations and solarized boreholes.',
                'school_badge' => 'BOTH',
                'cat' => $findCat('Solar PV'),
                'service_mode' => 'hybrid',
                'alumni_perk' => 'Free engineering site load audit within Nairobi & Kiambu',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => 'EBK',
                'regulator_license' => 'EBK/CORP/1184',
                'branch_name' => 'Karen Engineering Hub',
                'building_name' => 'Watermark Business Park',
                'floor_suite' => 'Ndege Court, Suite 4',
                'street_road' => 'Ndege Road',
                'area_neighborhood' => 'Karen',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Near Karen Blixen Coffee Garden',
                'plus_code' => '6GCRMP85+4C',
                'latitude' => -1.334600,
                'longitude' => 36.708500,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.334600,36.708500',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254711880990',
                'whatsapp_number' => '+254711880990',
                'email_public' => 'projects@strongtoservesolar.co.ke',
                'website_url' => 'https://www.strongtoservesolar.co.ke',
                'social_linkedin' => 'https://www.linkedin.com/company/strongtoserve-solar',
                'social_youtube' => 'https://www.youtube.com/@strongtoservesolar',
            ],
            [
                'name' => 'The Bush Roastery & Artisan Bistro',
                'slug' => 'the-bush-roastery-artisan-bistro',
                'tagline' => 'Single-origin Nyeri & Kirinyaga specialty coffee, wood-fired sourdough, and executive boardroom catering.',
                'description' => 'All-day artisan coffee roastery, breakfast bistro, and corporate catering kitchen in Kilimani with private meeting pods for alumni catch-ups.',
                'school_badge' => 'AGHS',
                'cat' => $findCat('Coffee Shop'),
                'service_mode' => 'physical',
                'alumni_perk' => '10% off specialty coffee bags & complimentary meeting pod booking',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => null,
                'regulator_license' => null,
                'branch_name' => 'Kilimani Flagship',
                'building_name' => 'Adlife Plaza Courtyard',
                'floor_suite' => 'Ground Floor',
                'street_road' => 'Chania Avenue',
                'area_neighborhood' => 'Kilimani',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Corner of Ring Road Kilimani & Chania Ave',
                'plus_code' => '6GCRPR5Q+7H',
                'latitude' => -1.291800,
                'longitude' => 36.788900,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.291800,36.788900',
                'operating_hours_json' => $hospitalityHours,
                'phone_primary' => '+254790334455',
                'whatsapp_number' => '+254790334455',
                'email_public' => 'hello@bushroastery.co.ke',
                'website_url' => 'https://www.bushroastery.co.ke',
                'social_instagram' => 'https://www.instagram.com/bushroasteryke',
                'social_tiktok' => 'https://www.tiktok.com/@bushroasteryke',
            ],
            [
                'name' => 'Savannah Cloud & FinTech Systems',
                'slug' => 'savannah-cloud-fintech-systems',
                'tagline' => 'Enterprise ERP, M-Pesa Daraja integrations, SOC2 cybersecurity audits, and custom software engineering.',
                'description' => 'Nairobi-based software engineering studio helping SACCOs, insurers, and FMCG distributors automate collections, reconciliation, and cloud infrastructure.',
                'school_badge' => 'AHS',
                'cat' => $findCat('Custom Software'),
                'service_mode' => 'online',
                'alumni_perk' => 'Free 90-minute architecture & cybersecurity posture review',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => 'ODPC',
                'regulator_license' => 'ODPC-DC-4410',
                'branch_name' => 'Riverside Studio',
                'building_name' => 'Keystone Park',
                'floor_suite' => 'Block B, 3rd Floor',
                'street_road' => 'Riverside Drive',
                'area_neighborhood' => 'Riverside',
                'city_county' => 'Nairobi',
                'landmark_directions' => '200m past German Embassy on Riverside Drive',
                'plus_code' => '6GCRPPP9+2V',
                'latitude' => -1.269900,
                'longitude' => 36.794500,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.269900,36.794500',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254705600700',
                'whatsapp_number' => '+254705600700',
                'email_public' => 'eng@savannahcloud.co.ke',
                'website_url' => 'https://www.savannahcloud.co.ke',
                'social_linkedin' => 'https://www.linkedin.com/company/savannahcloud',
                'social_x' => 'https://x.com/savannahcloudke',
            ],
            [
                'name' => 'Thogoto Heritage Architects & QS',
                'slug' => 'thogoto-heritage-architects-qs',
                'tagline' => 'BORAQS-registered architectural design, quantity surveying, and sustainable project management.',
                'description' => 'Award-winning architectural and quantity surveying practice designing residential estates, commercial offices, and educational campuses across East Africa.',
                'school_badge' => 'BOTH',
                'cat' => $findCat('Architecture'),
                'service_mode' => 'hybrid',
                'alumni_perk' => '15% discount on preliminary concept design & bill of quantities (BoQ)',
                'b2b_ready' => 1,
                'accepts_lpo' => 1,
                'regulator_name' => 'BORAQS',
                'regulator_license' => 'BORAQS/A/1402',
                'branch_name' => 'Lavington Studio',
                'building_name' => 'Lavington Curve',
                'floor_suite' => '3rd Floor',
                'street_road' => 'James Gichuru Road',
                'area_neighborhood' => 'Lavington',
                'city_county' => 'Nairobi',
                'landmark_directions' => 'Next to Jaffery Sports Club',
                'plus_code' => '6GCRPR98+5W',
                'latitude' => -1.281200,
                'longitude' => 36.767300,
                'google_maps_url' => 'https://www.google.com/maps?q=-1.281200,36.767300',
                'operating_hours_json' => $standardHours,
                'phone_primary' => '+254720911822',
                'whatsapp_number' => '+254720911822',
                'email_public' => 'studio@thogotoarchitects.co.ke',
                'website_url' => 'https://www.thogotoarchitects.co.ke',
                'social_instagram' => 'https://www.instagram.com/thogotoarchitects',
                'social_linkedin' => 'https://www.linkedin.com/company/thogotoarchitects',
            ],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO businesses (
                name, slug, tagline, description, school_badge,
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
                :name, :slug, :tagline, :description, :school_badge,
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

        foreach ($samples as $s) {
            $stmt->execute([
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
