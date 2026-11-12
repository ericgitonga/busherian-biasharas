<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;

final class CategoryMatcher
{
    /**
     * 15 Standard Industry Sectors & 116 Pre-Populated Specialties
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
                'Author Coaching & Manuscript Advisory',
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
                'Book, Academic & Digital Publishing',
                'Editorial, Ghostwriting & Author Services',
                'Podcast, Broadcast & Content Studio',
            ],
        ],
        [
            'name' => 'Arts, Visual & Performing Arts',
            'slug' => 'arts-visual-performing-arts',
            'icon' => 'palette',
            'specialties' => [
                'Visual Arts, Fine Art & Galleries',
                'Performing Arts, Theatre & Dance',
                'Music, Live Bands, DJs & Sound Production',
                'Film, Cinema, Animation & Directing',
                'Sculpture, Ceramics, Pottery & Crafts',
                'Illustration, Comics & Digital Art',
                'Literary Arts, Poetry & Creative Writing',
                'Cultural Heritage, Curatorial & Museums',
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
        'sanaa' => ['Visual Arts', 'Performing Arts', 'Fine Art', 'Music', 'Sculpture'],
        'art' => ['Visual Arts', 'Fine Art', 'Performing Arts', 'Illustration', 'Galleries'],
        'arts' => ['Visual Arts', 'Performing Arts', 'Fine Art', 'Theatre', 'Music'],
        'artist' => ['Visual Arts', 'Fine Art', 'Performing Arts', 'Music', 'Sculpture'],
        'theatre' => ['Performing Arts', 'Theatre', 'Dance'],
        'gallery' => ['Visual Arts', 'Fine Art', 'Galleries'],
        'band' => ['Music', 'Live Bands', 'Performing Arts'],
        'mziki' => ['Music', 'Live Bands', 'Sound Production'],
        'muziki' => ['Music', 'Live Bands', 'Sound Production'],
        'publisher' => ['Publishing', 'Book', 'Editorial', 'Author Coaching', 'Printing'],
        'publishers' => ['Publishing', 'Book', 'Editorial', 'Author Coaching', 'Printing'],
        'publishing' => ['Publishing', 'Book', 'Editorial', 'Author Coaching', 'Bookshop'],
        'book' => ['Book', 'Publishing', 'Editorial', 'Bookshop', 'Author Coaching', 'Literary Arts'],
        'books' => ['Book', 'Publishing', 'Editorial', 'Bookshop', 'Author Coaching', 'Literary Arts'],
        'author' => ['Author Services', 'Author Coaching', 'Publishing', 'Editorial', 'Literary Arts'],
        'authors' => ['Author Services', 'Author Coaching', 'Publishing', 'Editorial', 'Literary Arts'],
        'editing' => ['Editorial', 'Ghostwriting', 'Publishing', 'Manuscript', 'Author Services'],
        'editor' => ['Editorial', 'Ghostwriting', 'Publishing', 'Manuscript'],
        'editorial' => ['Editorial', 'Publishing', 'Author Services', 'Manuscript'],
        'proofreading' => ['Editorial', 'Author Services', 'Manuscript', 'Publishing'],
        'ghostwriting' => ['Ghostwriting', 'Editorial', 'Author Services', 'Author Coaching'],
        'manuscript' => ['Manuscript', 'Author Coaching', 'Editorial', 'Publishing'],
        'isbn' => ['Book', 'Publishing', 'Author Services'],
        'mchapishaji' => ['Publishing', 'Book', 'Editorial', 'Printing'],
        'uchapishaji' => ['Publishing', 'Book', 'Editorial', 'Printing'],
        'vitabu' => ['Book', 'Publishing', 'Bookshop', 'Author Coaching', 'Literary Arts'],
        'kitabu' => ['Book', 'Publishing', 'Bookshop', 'Author Coaching', 'Literary Arts'],
        'mwandishi' => ['Author Services', 'Author Coaching', 'Editorial', 'Literary Arts', 'Publishing'],
        'podcast' => ['Podcast', 'Broadcast', 'Content Studio'],
        'app' => ['Mobile App Dev', 'Custom Software', 'Web Design'],
        'website' => ['Web Design', 'Custom Software', 'E-Commerce'],
    ];



    /**
     * Conversational English & Swahili stopwords stripped from natural-language descriptions (#276).
     */
    public const NL_STOPWORDS = [
        'i', 'im', 'iam', 'am', 'a', 'an', 'the', 'me', 'my', 'mine', 'myself',
        'we', 'our', 'ours', 'us', 'you', 'your', 'he', 'she', 'they', 'their', 'it', 'its',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'having',
        'do', 'does', 'did', 'doing', 'can', 'could', 'will', 'would', 'shall', 'should',
        'in', 'on', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into', 'through',
        'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down', 'of', 'off',
        'over', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'when', 'where',
        'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some',
        'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very',
        'and', 'but', 'or', 'as', 'if', 'while', 'who', 'whom', 'which', 'what', 'that', 'this', 'these', 'those',
        'offer', 'offers', 'offering', 'provide', 'provides', 'providing', 'run', 'runs', 'running',
        'make', 'makes', 'making', 'build', 'builds', 'building_verb', 'sell', 'sells', 'selling',
        'help', 'helps', 'helping', 'work', 'works', 'working', 'specialize', 'specializes', 'specializing',
        'specialist', 'specialists', 'professional', 'professionals', 'expert', 'experts',
        'business', 'businesses', 'biashara', 'company', 'companies', 'firm', 'firms', 'enterprise',
        'agency', 'practice', 'group', 'limited', 'ltd', 'inc', 'co', 'founder', 'owner',
        'service', 'services', 'solution', 'solutions', 'product', 'products', 'client', 'clients',
        'based', 'located', 'around', 'across', 'within', 'near', 'nairobi', 'kenya', 'kenyan', 'east', 'africa',
        'looking', 'need', 'needs', 'want', 'wants', 'seeking', 'find', 'someone', 'people',
        'mimi', 'sisi', 'ni', 'wa', 'ya', 'za', 'la', 'kwa', 'na', 'katika', 'hapa', 'wenye',
        'tunafanya', 'ninafanya', 'tunauza', 'ninauza', 'tunatoa', 'kampuni', 'huduma', 'biasharas',
    ];

    /**
     * Irregular & occupational root normalization map (#276) so agent nouns ("photographer",
     * "dentist", "architect", "baker", "accountant") match specialty nouns ("Photography",
     * "Dental", "Architecture", "Bakery", "Accounting").
     */
    public const OCCUPATION_STEM_MAP = [
        'photographer' => 'photograph', 'photographers' => 'photograph', 'photography' => 'photograph',
        'photographic' => 'photograph', 'photographed' => 'photograph', 'photo' => 'photograph', 'photos' => 'photograph',
        'videographer' => 'videograph', 'videographers' => 'videograph', 'videography' => 'videograph',
        'video' => 'videograph', 'videos' => 'videograph',
        'dentist' => 'dent', 'dentists' => 'dent', 'dentistry' => 'dent', 'dental' => 'dent',
        'orthodontist' => 'orthodont', 'orthodontists' => 'orthodont', 'orthodontics' => 'orthodont', 'orthodontic' => 'orthodont',
        'optometrist' => 'optometr', 'optometrists' => 'optometr', 'optometry' => 'optometr', 'optician' => 'optometr', 'optical' => 'optometr',
        'pharmacist' => 'pharmac', 'pharmacists' => 'pharmac', 'pharmacy' => 'pharmac', 'pharmacies' => 'pharmac', 'pharmaceutical' => 'pharmac',
        'pediatrician' => 'pediatr', 'paediatrician' => 'pediatr', 'pediatrics' => 'pediatr', 'paediatrics' => 'pediatr', 'pediatric' => 'pediatr', 'paediatric' => 'pediatr',
        'physiotherapist' => 'physiotherap', 'physiotherapists' => 'physiotherap', 'physiotherapy' => 'physiotherap', 'physio' => 'physiotherap',
        'psychologist' => 'psycholog', 'psychologists' => 'psycholog', 'psychology' => 'psycholog', 'psychiatrist' => 'psycholog', 'psychiatry' => 'psycholog',
        'therapist' => 'counsel', 'therapists' => 'counsel', 'therapy' => 'counsel', 'counsellor' => 'counsel', 'counselor' => 'counsel', 'counselling' => 'counsel', 'counseling' => 'counsel',
        'doctor' => 'medic', 'doctors' => 'medic', 'physician' => 'medic', 'medical' => 'medic', 'medicine' => 'medic', 'clinician' => 'medic',
        'advocate' => 'advocat', 'advocates' => 'advocat', 'lawyer' => 'advocat', 'lawyers' => 'advocat', 'attorney' => 'advocat', 'attorneys' => 'advocat', 'solicitor' => 'advocat',
        'accountant' => 'account', 'accountants' => 'account', 'accounting' => 'account', 'bookkeeper' => 'account', 'bookkeeping' => 'account',
        'auditor' => 'audit', 'auditors' => 'audit', 'auditing' => 'audit', 'audit' => 'audit',
        'actuary' => 'actuar', 'actuaries' => 'actuar', 'actuarial' => 'actuar',
        'arbitrator' => 'arbitrat', 'arbitrators' => 'arbitrat', 'arbitration' => 'arbitrat', 'mediator' => 'mediat', 'mediation' => 'mediat',
        'architect' => 'architect', 'architects' => 'architect', 'architecture' => 'architect', 'architectural' => 'architect',
        'surveyor' => 'survey', 'surveyors' => 'survey', 'surveying' => 'survey',
        'valuer' => 'valuat', 'valuers' => 'valuat', 'valuation' => 'valuat', 'valuations' => 'valuat',
        'plumber' => 'plumb', 'plumbers' => 'plumb', 'plumbing' => 'plumb',
        'electrician' => 'electr', 'electricians' => 'electr', 'electrical' => 'electr',
        'contractor' => 'contract', 'contractors' => 'contract', 'contracting' => 'contract',
        'baker' => 'bak', 'bakers' => 'bak', 'baking' => 'bak', 'bakery' => 'bak', 'bakeries' => 'bak',
        'caterer' => 'cater', 'caterers' => 'cater', 'catering' => 'cater',
        'butcher' => 'butcher', 'butchers' => 'butcher', 'butchery' => 'butcher',
        'tailor' => 'tailor', 'tailors' => 'tailor', 'tailoring' => 'tailor', 'seamstress' => 'tailor',
        'jeweler' => 'jewel', 'jeweller' => 'jewel', 'jewelry' => 'jewel', 'jewellery' => 'jewel',
        'beautician' => 'beaut', 'beauty' => 'beaut', 'hairdresser' => 'salon', 'barber' => 'salon',
        'farmer' => 'farm', 'farmers' => 'farm', 'farming' => 'farm',
        'veterinarian' => 'veterinar', 'veterinarians' => 'veterinar', 'veterinary' => 'veterinar', 'vet' => 'veterinar', 'vets' => 'veterinar',
        'beekeeper' => 'beekeep', 'beekeepers' => 'beekeep', 'beekeeping' => 'beekeep', 'apiarist' => 'beekeep', 'apiculture' => 'beekeep',
        'mechanic' => 'mechan', 'mechanics' => 'mechan', 'mechanical' => 'mechan',
        'chauffeur' => 'chauffeur', 'driver' => 'chauffeur', 'drivers' => 'chauffeur',
        'mover' => 'mov', 'movers' => 'mov', 'moving' => 'mov', 'relocation' => 'mov',
        'tutor' => 'tutor', 'tutors' => 'tutor', 'tutoring' => 'tutor', 'teacher' => 'tutor', 'teachers' => 'tutor', 'teaching' => 'tutor',
        'coach' => 'coach', 'coaches' => 'coach', 'coaching' => 'coach', 'mentor' => 'coach', 'mentoring' => 'coach', 'mentorship' => 'coach',
        'trainer' => 'train', 'trainers' => 'train', 'training' => 'train',
        'publisher' => 'publish', 'publishers' => 'publish', 'publishing' => 'publish', 'publish' => 'publish', 'published' => 'publish',
        'editor' => 'edit', 'editors' => 'edit', 'editing' => 'edit', 'editorial' => 'edit', 'proofreader' => 'proofread', 'proofreading' => 'proofread',
        'ghostwriter' => 'ghostwrit', 'ghostwriters' => 'ghostwrit', 'ghostwriting' => 'ghostwrit',
        'author' => 'author', 'authors' => 'author', 'writer' => 'writ', 'writers' => 'writ', 'writing' => 'writ', 'poet' => 'poet', 'poetry' => 'poet',
        'printer' => 'print', 'printers' => 'print', 'printing' => 'print', 'print' => 'print', 'prints' => 'print',
        'podcaster' => 'podcast', 'podcasters' => 'podcast', 'podcasting' => 'podcast', 'podcast' => 'podcast', 'podcasts' => 'podcast',
        'illustrator' => 'illustrat', 'illustrators' => 'illustrat', 'illustration' => 'illustrat', 'illustrations' => 'illustrat',
        'animator' => 'animat', 'animators' => 'animat', 'animation' => 'animat', 'animations' => 'animat',
        'sculptor' => 'sculpt', 'sculptors' => 'sculpt', 'sculpture' => 'sculpt', 'sculptures' => 'sculpt', 'potter' => 'potter', 'pottery' => 'potter',
        'musician' => 'music', 'musicians' => 'music', 'musical' => 'music', 'music' => 'music', 'singer' => 'music', 'vocalist' => 'music',
        'filmmaker' => 'film', 'filmmakers' => 'film', 'filmmaking' => 'film', 'cinema' => 'film', 'film' => 'film', 'films' => 'film',
        'painter' => 'paint', 'painters' => 'paint', 'painting' => 'paint', 'paintings' => 'paint',
        'curator' => 'curat', 'curators' => 'curat', 'curatorial' => 'curat',
        'welder' => 'weld', 'welders' => 'weld', 'welding' => 'weld', 'fabricator' => 'fabricat', 'fabrication' => 'fabricat',
        'cleaner' => 'clean', 'cleaners' => 'clean', 'cleaning' => 'clean',
        'gardener' => 'garden', 'gardeners' => 'garden', 'gardening' => 'garden', 'landscaper' => 'landscap', 'landscaping' => 'landscap',
        'fumigator' => 'fumigat', 'fumigation' => 'fumigat',
        'developer' => 'develop', 'developers' => 'develop', 'development' => 'develop', 'programmer' => 'develop', 'coder' => 'develop',
        'designer' => 'design', 'designers' => 'design', 'designing' => 'design', 'design' => 'design',
        'consultant' => 'consult', 'consultants' => 'consult', 'consulting' => 'consult', 'consultancy' => 'consult',
        'advisor' => 'advis', 'advisors' => 'advis', 'advisory' => 'advis',
        'broker' => 'broker', 'brokers' => 'broker', 'brokerage' => 'broker',
        'insurer' => 'insur', 'insurance' => 'insur',
        'customs' => 'customs_duty', 'machining' => 'cnc_machin', 'machinist' => 'cnc_machin', 'refrigerator' => 'fridg', 'refrigerators' => 'fridg', 'fridge' => 'fridg', 'fridges' => 'fridg',
    ];

    /**
     * Natural-language concept & domain keyword map across all 116 Level 2 Specialties (#276).
     * Enables conversational first-person descriptions (e.g. "I am an arthropod macro photographer",
     * "We do root canals and braces", "I clear cargo at Mombasa port") to match instantaneously.
     */
    public const SPECIALTY_CONCEPT_KEYWORDS = [
        // 1. Legal, Accounting & Advisory
        'Advocates & Law Firm' => 'lawyer attorney litigation conveyancing contracts commercial law commissioner oaths notary wills probate court dispute legal wakili sheria',
        'Audit, Tax & Accounting (CPA)' => 'accountant bookkeeping kra tax returns vat paye payroll tax financial statements statutory audit cpa icpak ushuru',
        'Company Secretarial & Governance' => 'company secretary board governance compliance registrar companies annual returns cr12 incorporation icpsk',
        'Management & Strategy Consulting' => 'business consultant strategy turnaround organizational advisory operations consulting feasibility study transformation',
        'HR Recruitment & Payroll' => 'human resources headhunting talent acquisition staffing hiring outsourcing payroll processing employee training ihrm',
        'Actuarial & Risk Advisory' => 'actuary risk modeling enterprise risk solvency valuation insurance math task',
        'Arbitration & Mediation' => 'arbitrator mediator dispute resolution adr commercial mediation conciliation ciarb',
        'Intellectual Property & Trademarks' => 'patent copyright trademark brand protection kipi kecobo licensing ip law',

        // 2. Health, Medical & Wellness
        'General Medical & Family Clinic' => 'doctor physician outpatient gp consultation family medicine healthcare hospital checkup vaccination daktari hospitali',
        'Dental Clinic & Orthodontics' => 'dentist orthodontist teeth tooth root canal braces aligners whitening implants oral surgery dental hygiene meno',
        'Optometry & Eye Care' => 'optometrist optician eyeglasses spectacles contact lenses vision eye exam cataract ophthalmology macho',
        'Pharmacy & Chemist' => 'pharmacist prescription medicine drugs supplements over counter chemist dispensary dawa',
        'Diagnostic Lab & Imaging' => 'laboratory blood test pathology xray ultrasound mri ct scan radiology diagnostics medical lab',
        'Physiotherapy & Rehabilitation' => 'physiotherapist physio sports injury chiropractor occupational therapy back pain stroke rehab mobility',
        'Mental Health & Counselling' => 'psychologist psychiatrist therapist counsellor counseling mental wellness trauma anxiety depression marriage family therapy',
        'Pediatric & Maternal Care' => 'pediatrician paediatrician children health baby clinic immunisation antenatal postnatal obstetrics gynaecology obgyn midwife',
        'Gym, Fitness & Yoga Studio' => 'personal trainer workout CrossFit pilates yoga aerobics strength conditioning weight loss wellness studio',

        // 3. Technology, Software & Digital
        'Custom Software & Mobile App Dev' => 'software engineer developer mobile app android ios flutter react python php saas platform api coding bespoke software',
        'Web Design & E-Commerce' => 'website builder wordpress shopify online store woocommerce mpesa checkout landing page ui ux frontend web developer',
        'Cybersecurity & IT Audit' => 'penetration testing pentest infosec soc vulnerability assessment iso27001 data protection cyber defense ethical hacking',
        'Cloud, DevOps & Hosting' => 'aws azure gcp linux servers domain registration web hosting kubernetes docker ci cd sysadmin cloud migration',
        'Data Analytics & AI Solutions' => 'machine learning artificial intelligence llm data science business intelligence powerbi tableau predictive modeling automation',
        'IT Hardware & Networking' => 'laptops computers servers structured cabling wifi routers switches printers pos hardware IT support',
        'Fintech & Payment Integration' => 'mpesa daraja payment gateway digital wallet lending platform remittance tech banking api',
        'ERP & Business Automation' => 'sap odoo dynamics erp crm inventory system pos software school management hospital management workflow',
        'Bulk SMS, Voice, USSD & Communications APIs' => 'shortcodes sender id otp sms gateway whatsapp api ussd code ivr call center airtime disbursement',

        // 4. Real Estate, Construction & Architecture
        'Architecture & Urban Planning' => 'architect blueprints building design 3d renders masterplan town planning sustainable architecture boraqs',
        'Civil & Structural Engineering' => 'structural engineer civil works roads bridges drainage steel concrete design geotechnical ebk',
        'Building & General Contractor' => 'construction company house builder commercial building renovation masonry site contractor nca mjengo',
        'Quantity Surveying' => 'quantity surveyor bill of quantities boq construction cost estimation project costing tendering',
        'Interior Design & Fit-Outs' => 'interior designer office fitout home makeover cabinetry gypsum ceiling lighting curtains space planning',
        'Property Sales, Letting & Management' => 'real estate agent realtor apartments houses plots commercial space rent lease property manager earb',
        'Land Surveying & Valuation' => 'land surveyor beacon subdivision title deed property valuer appraisal gis mapping shamba',
        'Hardware, Timber & Building Materials' => 'cement steel bars roofing sheets mabati tiles paint timber mbao plumbing supplies hardware store',
        'Plumbing, Electrical & Solar Installation' => 'plumber electrician wiring sockets lighting solar water heater piping leak repair fundi stima',

        // 5. Food, Beverage & Hospitality
        'Restaurant, Nyama Choma & Bistro' => 'dining eatery cafe swahili dishes grill steakhouse lunch dinner takeaway nyama choma chakula',
        'Coffee Shop, Bakery & Pastry' => 'baker cakes wedding cake birthday cake sourdough bread croissants pastries espresso barista kahawa keki',
        'Event & Corporate Catering' => 'outside catering buffet corporate lunch wedding food private chef cocktailbites chafing dishes',
        'Hotel, Boutique Resort & Airbnb' => 'accommodation guest house bed breakfast serviced apartment staycation conference rooms country lodge',
        'Specialty Coffee, Tea & Honey Brand' => 'coffee roaster loose leaf tea raw organic honey artisanal food brand gourmet',
        'Butchery & Fresh Farm Produce' => 'fresh meat beef goat chicken pork organic vegetables fruits groceries farm to table',
        'Bar, Lounge & Wines/Spirits' => 'wine shop liquor store craft beer cocktails lounge wines spirits distributor',
        'Food Processing & Packaging' => 'milling flour spices sauces snacks canningjuices value added food manufacturer',

        // 6. Finance, Insurance & Investment
        'Insurance Agency & Brokerage' => 'medical cover motor insurance life policytravel insurance underwriter claims broker bima',
        'Wealth Management & Investment Advisory' => 'portfolio manager unit trusts money market fund offshore investing financial planner high net worth',
        'SACCO, Microfinance & Credit' => 'loans savings credit cooperative sme financing logbook loan asset finance microcredit',
        'Forex, Remittances & Treasury' => 'foreign exchange bureau currency exchange cross border money transfer hedging',
        'Venture Capital & Private Equity' => 'angel investor startup funding growth equity seed capital impact investment deal advisory',
        'Pension & Retirement Planning' => 'pension scheme provident fund umbrella retirement annuity rba',
        'Stockbrokerage & Capital Markets' => 'nse shares equities treasury bills infrastructure bonds investment bank stockbroker',
        'Mortgages & Property Finance' => 'home loan construction finance equity release mortgage broker',

        // 7. Retail, Fashion & Consumer Goods
        'Bespoke Tailoring, Suits & Bridal' => 'tailor custom suits tuxedo wedding gown bridesmaids ankara kitenge dressmaker alterations nguo',
        'Apparel, Footwear & Streetwear' => 'clothing brand boutique tshirts hoodies sneakers shoes menswear womenswear fashion line',
        'Jewelry, Leather & African Crafts' => 'handcrafted bags wallets belts beadwork silver gold rings necklaces Maasai crafts curios',
        'Beauty, Cosmetics & Hair Salon' => 'salon barbershop kinyozi braids locs skincare makeup artist nail bar spa facials massage',
        'Home Decor, Furniture & Appliances' => 'sofas beds dining tables office furniture kitchenware electronics rugs curtains',
        'Bookshop, Stationery & Gifts' => 'bookstore textbooks novels notebooks office stationery corporate hampers gift shop vitabu',
        'Supermarket & Minimart' => 'retail store household essentials groceries convenience shop minimart',
        'Baby, Kids & Maternity Store' => 'infant clothing toys strollers car seats diapers nursery maternity wear',

        // 8. Agriculture & Agribusiness
        'Dairy, Poultry & Livestock Farming' => 'milk cows layers broilers eggs pigs goats sheep cattle ranching kuku maziwa',
        'Horticulture & Fresh Export' => 'avocados french beans flowers herbs macadamia mangoes export farming packhouse',
        'Agrovet, Seeds & Crop Protection' => 'fertilizers pesticides herbicides hybrid seeds crop nutrition agronomy mbegu dawa',
        'Farm Machinery, Irrigation & Greenhouses' => 'drip irrigation solar water pumps tractors dam liners shade nets greenhouse construction',
        'Animal Feeds & Veterinary Services' => 'vet doctor livestock vaccination artificial insemination dairy meal poultry feed pet clinic',
        'Agri-Processing & Value Addition' => 'cold pressed oil dried fruit nut butter dairy processing grain drying',
        'Beekeeping & Apiculture' => 'honeybees hives langstroth beeswax propolis pollination entomology insects apiary',

        // 9. Automotive, Logistics & Transport
        'Auto Garage, Service & Body Shop' => 'car mechanic panel beating spray painting wheel alignment engine diagnostics gearbox service gari',
        'Car Dealership & Import Financing' => 'used cars japan uk car imports showroom trade in vehicle sales',
        'Auto Spares, Tyres & Batteries' => 'spare parts brake pads shock absorbers tyres rims car battery lubricants',
        'Car Hire, Chauffeur & Executive Transfer' => 'self drive car rental vip chauffeur suv hire wedding cars shuttle',
        'Courier, Last-Mile & Freight Logistics' => 'parcel delivery rider fleet trucking haulage cold chain distribution warehousing',
        'Clearing, Forwarding & Customs' => 'customs agent cargo container containers port clearing airfreight sea freight import export documentation kra icd mombasa',
        'Moving, Relocation & Storage' => 'house movers office relocation international moving self storage units packing',

        // 10. Education, Training & Coaching
        'Kindergarten, Primary & High School' => 'montessori creche daycare preschool primary secondary international school shule',
        'Academic Tutoring & Exam Prep (CBC/IGCSE)' => 'private tutor homeschooling math science languages sat ielts igcse kcpe kcse cbc mwalimu',
        'Corporate Training & Executive Coaching' => 'leadership coach career coach life coach team facilitation public speaking board training',
        'Music, Arts & Coding Academy' => 'piano guitar violin lessons ballet swimming lessons robotics coding for kids stem camp',
        'Study Abroad & University Placement' => 'overseas university admissions student visa scholarships uk usa canada australia',
        'Driving School & Technical TVET' => 'learner driver license vocational college hospitality plumbing electrical trade school',
        'Author Coaching & Manuscript Advisory' => 'writing coach book coach memoir coaching non fiction book proposal self publishing mentor developmental guidance mwandishi',

        // 11. Creative, Media, Marketing & Events
        'Branding, Graphic Design & PR Agency' => 'logo design brand identity corporate communications public relations media buying creative agency',
        'Digital Marketing, SEO & Social Media' => 'social media manager google ads meta ads influencer marketing content creator email marketing',
        'Commercial Photography & Videography' => 'photographer videographer macro photography arthropod insect wildlife nature bird aerial drone portrait headshot product wedding event documentary studio camera lens picha',
        'Event Planning, Tent/Sound & Decor' => 'event planner wedding planner mc dj pa sound stage lighting floral decor tents chairs harusi',
        'Printing, Signage & Branded Merchandise' => 'commercial printer offset digital print fine art prints posters banners 3d signage mugs tshirts promotional merchandise',
        'Book, Academic & Digital Publishing' => 'book publisher publishing house textbooks children books ebooks audiobooks isbn distribution royalties mchapishaji uchapishaji vitabu',
        'Editorial, Ghostwriting & Author Services' => 'copyediting proofreading ghostwriter manuscript editing typesetting book cover layout indexing',
        'Podcast, Broadcast & Content Studio' => 'podcast studio audio recording voiceover livestreaming youtube studio radio production',

        // 12. Arts, Visual & Performing Arts
        'Visual Arts, Fine Art & Galleries' => 'fine artist painter canvas gallery exhibition wildlife art nature photography arthropod botanical prints art collector sanaa',
        'Performing Arts, Theatre & Dance' => 'stage play theatre troupe choreographer contemporary traditional dance drama',
        'Music, Live Bands, DJs & Sound Production' => 'live band wedding band jazz acoustic dj music producer recording studio mastering mziki',
        'Film, Cinema, Animation & Directing' => 'film director screenwriter documentary filmmaker 2d 3d animation vfx color grading',
        'Sculpture, Ceramics, Pottery & Crafts' => 'sculptor wood carving stone bronze ceramicist pottery wheel handmade tableware',
        'Illustration, Comics & Digital Art' => 'illustrator children book illustration comic book storyboard character design concept art',
        'Literary Arts, Poetry & Creative Writing' => 'poet spoken word novelist creative writing workshop storytelling anthology',
        'Cultural Heritage, Curatorial & Museums' => 'museum curator archival preservation cultural festival heritage tour oral history',

        // 13. Manufacturing, Energy & Industrial
        'Solar PV, Inverters & Clean Energy' => 'solar panels hybrid inverter lithium battery off grid commercial solar clean energy epra',
        'Water Borehole Drilling & Treatment' => 'hydrogeological survey borehole drilling reverse osmosis water purifier desalination tanks maji',
        'Metal Fabrication, Welding & Machining' => 'steel gates grills structural steel cnc machining lathe stainless steel fabrication welder',
        'Chemical, Soap & Detergent Manufacturing' => 'industrial chemicals liquid detergent sanitizer cosmetics formulation cleaning products',
        'Plastics, Packaging & Paper Products' => 'corrugated cartons biodegradable packaging paper bags bottles labels flexible packaging',
        'Industrial Safety (PPE) & Fire Equipment' => 'fire extinguishers fire alarm safety boots helmets reflective vests osh compliance',

        // 14. Travel, Tours & Safari
        'Safari Operator & Tour Packages' => 'maasai mara amboseli tsavo wildlife safari bush tour holiday packages travel agent',
        'Flight Ticketing & Visa Assistance' => 'air tickets flight booking schengen us visa application passport travel desk',
        'Team Building & Bush Expeditions' => 'corporate retreat mount kenya kilimanjaro hiking camping overland adventure',
        'Eco-Camp & Safari Lodge' => 'tented camp bush lodge conservancy eco tourism wildlife photography camp',
        'Travel Insurance & Concierge' => 'vip travel concierge itinerary planning emergency medical evacuation travel cover',
        'Airport Transfers & VIP Meet-and-Greet' => 'jkia sgr transfer airport pickup fast track vip lounge chauffeur',
        'Conference & MICE Tourism' => 'international conference delegate management exhibition organizer retreat venue sourcing',

        // 15. Home, Security & Facility Services
        'CCTV, Electric Fencing & Guarding' => 'security guards alarm response access control biometrics razor wire cctv cameras psra',
        'Commercial & Residential Cleaning' => 'office cleaning post construction cleaning carpet sofa upholstery wash window cleaning',
        'Pest Control & Fumigation' => 'termites bedbugs cockroaches rodents mosquito control disinfection fumigator',
        'Landscaping & Gardening' => 'lawn care garden design tree pruning paving cabro outdoor Pergola nursery plants',
        'Laundry & Dry Cleaning' => 'dry cleaner wash fold ironing duvet curtains laundry pickup delivery',
        'Appliance Repair & Handyman' => 'fridge refrigerator freezer washing machine dishwasher oven cooker microwave television tv repair fix broken appliances carpentry odd jobs handyman',
        'Moving, Packing & Storage' => 'household packing fragile crating furniture assembly storage facility',
        'borehole Pump Servicing & Plumbing' => 'submersible pump repair booster pump water tank cleaning blocked drain plumber',
    ];

    /**
     * Normalize an English/Swahili word to its morphological/occupational stem (#276).
     */
    public static function stemWord(string $word): string
    {
        $w = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $word) ?? ''));
        if ($w === '') {
            return '';
        }
        if (isset(self::OCCUPATION_STEM_MAP[$w])) {
            return self::OCCUPATION_STEM_MAP[$w];
        }
        $len = strlen($w);
        if ($len <= 4) {
            return $w;
        }
        $suffixes = [
            'ographer' => 'ograph',
            'ographers' => 'ograph',
            'ography' => 'ograph',
            'ographic' => 'ograph',
            'ologist' => 'olog',
            'ologists' => 'olog',
            'ology' => 'olog',
            'iatrician' => 'iatr',
            'iatrics' => 'iatr',
            'ications' => 'ic',
            'ication' => 'ic',
            'ations' => 'at',
            'ation' => 'at',
            'ments' => '',
            'ment' => '',
            'nesses' => '',
            'ness' => '',
            'ings' => '',
            'ing' => '',
            'ists' => '',
            'ist' => '',
            'ers' => '',
            'er' => '',
            'ors' => '',
            'or' => '',
            'ies' => 'y',
            'es' => '',
            's' => '',
        ];
        foreach ($suffixes as $suf => $rep) {
            $sLen = strlen($suf);
            if ($len - $sLen >= 4 && str_ends_with($w, $suf)) {
                return substr($w, 0, $len - $sLen) . $rep;
            }
        }
        return $w;
    }

    /**
     * Extract meaningful content tokens from a natural-language sentence (stripping stopwords).
     *
     * @return list<string>
     */
    public static function extractMeaningfulTokens(string $rawQuery): array
    {
        $clean = strtolower(trim(mb_substr($rawQuery, 0, 180)));
        if ($clean === '') {
            return [];
        }
        $rawTokens = preg_split('/[^a-z0-9]+/i', $clean) ?: [];
        $stopMap = array_fill_keys(self::NL_STOPWORDS, true);
        $kept = [];
        foreach ($rawTokens as $tok) {
            if ($tok === '' || strlen($tok) < 2) {
                continue;
            }
            if (isset($stopMap[$tok])) {
                continue;
            }
            $kept[] = $tok;
        }
        // Fallback: if the entire query consisted of stopwords (e.g. "it", "co"), keep non-empty raw tokens >= 2 chars
        if ($kept === []) {
            foreach ($rawTokens as $tok) {
                if (strlen($tok) >= 2) {
                    $kept[] = $tok;
                }
            }
        }
        return array_values(array_unique($kept));
    }

    /**
     * Sørensen–Dice character-bigram similarity (0.0..1.0) for typo tolerance on content words (#276).
     */
    public static function diceBigramSimilarity(string $a, string $b): float
    {
        $s1 = strtolower(trim($a));
        $s2 = strtolower(trim($b));
        if ($s1 === $s2) {
            return $s1 === '' ? 0.0 : 1.0;
        }
        $l1 = strlen($s1);
        $l2 = strlen($s2);
        if ($l1 < 2 || $l2 < 2) {
            return 0.0;
        }
        $bg1 = [];
        for ($i = 0; $i < $l1 - 1; $i++) {
            $k = substr($s1, $i, 2);
            $bg1[$k] = ($bg1[$k] ?? 0) + 1;
        }
        $intersection = 0;
        for ($j = 0; $j < $l2 - 1; $j++) {
            $k = substr($s2, $j, 2);
            if (($bg1[$k] ?? 0) > 0) {
                $intersection++;
                $bg1[$k]--;
            }
        }
        return (2.0 * $intersection) / (($l1 - 1) + ($l2 - 1));
    }

    /**
     * Server-side hierarchy check (#186):
     * - $sectorId MUST exist and be a top-level sector (`parent_id IS NULL`).
     * - $specialtyId MUST exist, be a child specialty (`parent_id IS NOT NULL`), and have `parent_id === $sectorId`.
     */
    public static function validateSectorAndSpecialtyPair(PDO $pdo, int $sectorId, int $specialtyId): bool
    {
        if ($sectorId <= 0 || $specialtyId <= 0 || $sectorId === $specialtyId) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id, parent_id, status FROM categories WHERE id IN (:sec, :spec)");
        $stmt->execute([':sec' => $sectorId, ':spec' => $specialtyId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int)$r['id']] = $r;
        }
        if (!isset($rows[$sectorId], $rows[$specialtyId])) {
            return false;
        }
        $secRow = $rows[$sectorId];
        $specRow = $rows[$specialtyId];
        if ($secRow['parent_id'] !== null) {
            return false;
        }
        if ($specRow['parent_id'] === null || (int)$specRow['parent_id'] !== $sectorId) {
            return false;
        }
        if (($secRow['status'] ?? 'approved') === 'rejected' || ($specRow['status'] ?? 'approved') === 'rejected') {
            return false;
        }
        return true;
    }

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
        $terms = [trim($rawQuery)];
        $contentTokens = self::extractMeaningfulTokens($rawQuery);
        foreach ($contentTokens as $tok) {
            if (strlen($tok) >= 3) {
                $terms[] = $tok;
            }
            $stem = self::stemWord($tok);
            if (strlen($stem) >= 4 && $stem !== $tok) {
                $terms[] = $stem;
            }
            if (isset(self::SYNONYM_LEXICON[$tok])) {
                foreach (self::SYNONYM_LEXICON[$tok] as $mapped) {
                    $terms[] = $mapped;
                }
            }
        }
        return array_slice(array_values(array_unique($terms)), 0, 14);
    }

    /**
     * Substring, normalized Levenshtein & similar_text() fuzzy similarity score between 0.0 and 1.0 (#189)
     */
    public static function similarity(string $a, string $b): float
    {
        $s1 = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', mb_substr($a, 0, 100)) ?? ''));
        $s2 = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', mb_substr($b, 0, 100)) ?? ''));
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
        $customName = mb_substr(trim($customName), 0, 80);
        if ($customName === '') {
            return [];
        }
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
        $ranked = self::searchSpecialties($pdo, mb_substr(trim($text), 0, 300), 1, false);
        if ($ranked === []) {
            return null;
        }
        $top = $ranked[0];
        if ($top['score'] < 5.0) {
            return null;
        }
        return [
            'sector_id' => $top['sector_id'],
            'sector_name' => $top['sector_name'],
            'specialty_id' => $top['specialty_id'],
            'specialty_name' => $top['specialty_name'],
            'confidence' => min(0.99, round($top['score'] / 24.0, 2)),
        ];
    }


    /**
     * Rank Level 2 specialties across all 15 sectors using direct substring/prefix matching
     * plus English/Swahili SYNONYM_LEXICON expansion (#273, #274).
     *
     * @return list<array{specialty_id:int, specialty_name:string, sector_id:int, sector_name:string, is_regulated:bool, score:float}>
     */
    public static function searchSpecialties(PDO $pdo, string $rawQuery, int $limit = 8, bool $includePending = false): array
    {
        $clean = strtolower(trim(mb_substr($rawQuery, 0, 180)));
        if ($clean === '') {
            return [];
        }

        $tokens = self::extractMeaningfulTokens($clean);
        if ($tokens === []) {
            return [];
        }

        $tokenStems = [];
        $synonymPhrases = [];
        foreach ($tokens as $tok) {
            $st = self::stemWord($tok);
            if ($st !== '') {
                $tokenStems[$tok] = $st;
            }
            if (isset(self::SYNONYM_LEXICON[$tok])) {
                foreach (self::SYNONYM_LEXICON[$tok] as $mapped) {
                    $synonymPhrases[] = strtolower($mapped);
                }
            }
            if (strlen($tok) >= 4) {
                foreach (self::SYNONYM_LEXICON as $lexKey => $mappedList) {
                    if (str_starts_with($lexKey, $tok) || self::stemWord($lexKey) === $st) {
                        foreach ($mappedList as $mapped) {
                            $synonymPhrases[] = strtolower($mapped);
                        }
                    }
                }
            }
        }
        $synonymPhrases = array_values(array_unique($synonymPhrases));
        $meaningfulPhrase = implode(' ', $tokens);

        $tree = self::getTaxonomyTree($pdo, $includePending);
        $scored = [];

        foreach ($tree as $sec) {
            $secId = (int)$sec['id'];
            $secName = (string)$sec['name'];
            $secLower = strtolower($secName);
            $secWords = preg_split('/[^a-z0-9]+/i', $secLower) ?: [];
            $secStems = array_values(array_filter(array_map([self::class, 'stemWord'], $secWords)));

            foreach ($sec['specialties'] as $sp) {
                $specName = (string)$sp['name'];
                $specLower = strtolower($specName);
                $specWords = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $specLower) ?: []));
                $specStems = array_values(array_filter(array_map([self::class, 'stemWord'], $specWords)));

                $conceptRaw = strtolower((string)(self::SPECIALTY_CONCEPT_KEYWORDS[$specName] ?? ''));
                $conceptWords = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $conceptRaw) ?: []));
                $conceptStems = array_values(array_filter(array_map([self::class, 'stemWord'], $conceptWords)));

                $score = 0.0;
                $matchedSignals = [];

                if ($specLower === $clean || ($meaningfulPhrase !== '' && $specLower === $meaningfulPhrase)) {
                    $score += 36.0;
                } elseif (strlen($meaningfulPhrase) >= 3 && str_starts_with($specLower, $meaningfulPhrase)) {
                    $score += 20.0;
                } elseif (strlen($meaningfulPhrase) >= 3 && str_contains($specLower, $meaningfulPhrase)) {
                    $score += 15.0;
                } elseif (strlen($meaningfulPhrase) >= 4 && $conceptRaw !== '' && str_contains($conceptRaw, $meaningfulPhrase)) {
                    $score += 13.0;
                }

                foreach ($tokens as $tok) {
                    $st = $tokenStems[$tok] ?? $tok;
                    $tokMatched = false;

                    // 1. Direct word / prefix match on specialty title
                    if (in_array($tok, $specWords, true)) {
                        $score += 14.0;
                        $tokMatched = true;
                    } elseif (strlen($tok) >= 3 && preg_match('/\b' . preg_quote($tok, '/') . '/i', $specName)) {
                        $score += 11.0;
                        $tokMatched = true;
                    } elseif (strlen($st) >= 3 && in_array($st, $specStems, true)) {
                        // Morphological / occupational root match ("photographer" -> "photograph" == "Photography")
                        $score += 12.5;
                        $tokMatched = true;
                    }

                    // 2. Concept & domain keyword match ("macro", "arthropod", "root canal", "mombasa", etc.)
                    if (in_array($tok, $conceptWords, true)) {
                        $score += 9.5;
                        $tokMatched = true;
                    } elseif (strlen($tok) >= 5 && $conceptRaw !== '' && preg_match('/\b' . preg_quote($tok, '/') . '/i', $conceptRaw)) {
                        $score += 7.5;
                        $tokMatched = true;
                    } elseif (strlen($st) >= 4 && in_array($st, $conceptStems, true)) {
                        $score += 8.0;
                        $tokMatched = true;
                    }

                    // 3. Parent sector word / stem match (lighter weight)
                    if (!$tokMatched) {
                        if (in_array($tok, $secWords, true) || (strlen($st) >= 4 && in_array($st, $secStems, true))) {
                            $score += 3.0;
                            $tokMatched = true;
                        }
                    }

                    // 4. Typo-tolerant character-bigram fallback (e.g. "photografer", "ortodontist")
                    if (!$tokMatched && strlen($tok) >= 5) {
                        $bestDice = 0.0;
                        foreach (array_merge($specWords, $conceptWords) as $candW) {
                            if (strlen($candW) < 4 || abs(strlen($candW) - strlen($tok)) > 3) {
                                continue;
                            }
                            $d = self::diceBigramSimilarity($tok, $candW);
                            if ($d > $bestDice) {
                                $bestDice = $d;
                            }
                        }
                        if ($bestDice >= 0.76) {
                            $score += round($bestDice * 7.5, 2);
                            $tokMatched = true;
                        }
                    }

                    if ($tokMatched) {
                        $matchedSignals[] = $tok;
                    }
                }

                foreach ($synonymPhrases as $syn) {
                    if ($syn === '') {
                        continue;
                    }
                    $synStem = self::stemWord($syn);
                    if (str_contains($specLower, $syn) || ($synStem !== '' && in_array($synStem, $specStems, true))) {
                        $score += 6.5;
                    } elseif ($conceptRaw !== '' && str_contains($conceptRaw, $syn)) {
                        $score += 4.0;
                    } elseif (str_contains($secLower, $syn)) {
                        $score += 2.0;
                    }
                }

                // Multi-signal synergy bonus when 2+ distinct content words match the same specialty
                $uniqueSignals = array_values(array_unique($matchedSignals));
                if (count($uniqueSignals) >= 2) {
                    $score += (count($uniqueSignals) - 1) * 4.0;
                }

                if ($score > 0.0) {
                    $scored[] = [
                        'specialty_id' => (int)$sp['id'],
                        'specialty_name' => $specName,
                        'sector_id' => $secId,
                        'sector_name' => $secName,
                        'is_regulated' => !empty($sp['is_regulated']),
                        'matched_terms' => $uniqueSignals,
                        'score' => round($score, 2),
                    ];
                }
            }
        }

        usort($scored, static function (array $a, array $b): int {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['specialty_name'], $b['specialty_name']);
        });

        return array_slice($scored, 0, max(1, $limit));
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
        self::ensureTaxonomyUpToDate($pdo);
    }

    public static function ensureTaxonomyUpToDate(PDO $pdo): void
    {
        // Safely migrate legacy bundled 'Publishing, Podcast & Content Studio' slug in-place (#273)
        // so any existing foreign-key references preserve their specialty ID without leaving duplicate studio rows.
        $legacySlug = 'creative-media-marketing-events-publishing-podcast-content-studio';
        $modernPodcastSlug = 'creative-media-marketing-events-podcast-broadcast-content-studio';
        $chkLegacy = $pdo->prepare("SELECT id FROM categories WHERE parent_id IS NOT NULL AND slug = :slug LIMIT 1");
        $chkLegacy->execute([':slug' => $legacySlug]);
        $legacyId = (int)$chkLegacy->fetchColumn();
        if ($legacyId > 0) {
            $chkModern = $pdo->prepare("SELECT id FROM categories WHERE parent_id IS NOT NULL AND slug = :slug LIMIT 1");
            $chkModern->execute([':slug' => $modernPodcastSlug]);
            if ((int)$chkModern->fetchColumn() <= 0) {
                $pdo->prepare("UPDATE categories SET name = :name, slug = :slug WHERE id = :id")
                    ->execute([
                        ':name' => 'Podcast, Broadcast & Content Studio',
                        ':slug' => $modernPodcastSlug,
                        ':id' => $legacyId,
                    ]);
            }
        }

        $findSector = $pdo->prepare("SELECT id FROM categories WHERE parent_id IS NULL AND slug = :slug LIMIT 1");
        $insertSector = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, status) VALUES (NULL, :name, :slug, :icon, 'approved')");
        $findSpec = $pdo->prepare("SELECT id FROM categories WHERE parent_id IS NOT NULL AND slug = :slug LIMIT 1");
        $insertSpec = $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, status) VALUES (:parent_id, :name, :slug, NULL, 'approved')");

        foreach (self::DEFAULT_TAXONOMY as $sector) {
            $findSector->execute([':slug' => $sector['slug']]);
            $sectorId = (int)$findSector->fetchColumn();
            if ($sectorId <= 0) {
                $insertSector->execute([
                    ':name' => $sector['name'],
                    ':slug' => $sector['slug'],
                    ':icon' => $sector['icon'],
                ]);
                $sectorId = (int)$pdo->lastInsertId();
            }

            foreach ($sector['specialties'] as $specName) {
                $specSlug = $sector['slug'] . '-' . self::slugify($specName);
                $findSpec->execute([':slug' => $specSlug]);
                $specId = (int)$findSpec->fetchColumn();
                if ($specId <= 0) {
                    $insertSpec->execute([
                        ':parent_id' => $sectorId,
                        ':name' => $specName,
                        ':slug' => $specSlug,
                    ]);
                }
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
                status, listing_provenance, admin_provenance_note, verified_at, last_confirmed_at
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
                'approved', 'admin_curated', 'Seeded initial verified alumni directory listing', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
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
