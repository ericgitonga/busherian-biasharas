<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;

/**
 * NeedGraph — Kenyan Business Ecosystem Adjacency Graph
 *
 * Encodes real-world Kenyan business journeys, lifecycle dependencies, and companion
 * synergies across the 15 Level 1 Sectors and 116+ Level 2 Specialties.
 *
 * Maps upstream prerequisites, downstream execution steps, and companion services
 * with plain-English rationale explanations ("Why you might also need this").
 */
final class NeedGraph
{
    /**
     * Named ecosystem journey clusters representing common multi-disciplinary biashara endeavors.
     */
    public const ECOSYSTEM_CLUSTERS = [
        'company_launch' => [
            'label' => 'Company Formation & Business Launch',
            'description' => 'Incorporating, branding, structuring, and setting up commercial operations for a new enterprise.',
            'specialties' => [
                'Advocates & Law Firm',
                'Audit, Tax & Accounting (CPA)',
                'Company Secretarial & Governance',
                'Branding, Graphic Design & PR Agency',
                'Web Design & E-Commerce',
                'Bulk SMS, Voice, USSD & Communications APIs',
                'Intellectual Property & Trademarks',
                'HR Recruitment & Payroll',
                'Management & Strategy Consulting',
            ],
        ],
        'property_construction' => [
            'label' => 'Real Estate, Construction & Fit-Out',
            'description' => 'Land acquisition, architectural design, contracting, quantity surveying, fit-out, and property management.',
            'specialties' => [
                'Land Surveying & Valuation',
                'Advocates & Law Firm',
                'Architecture & Urban Planning',
                'Civil & Structural Engineering',
                'Quantity Surveying',
                'Building & General Contractor',
                'Hardware, Timber & Building Materials',
                'Plumbing, Electrical & Solar Installation',
                'Interior Design & Fit-Outs',
                'Property Sales, Letting & Management',
            ],
        ],
        'events_retreats' => [
            'label' => 'Corporate Offsites, Events & Celebrations',
            'description' => 'Organizing corporate retreats, milestone class reunions, conferences, and milestone weddings/gatherings.',
            'specialties' => [
                'Hotel, Boutique Resort & Airbnb',
                'Event Planning, Tent/Sound & Decor',
                'Event & Corporate Catering',
                'Commercial Photography & Videography',
                'Music, Live Bands, DJs & Sound Production',
                'Team Building & Bush Expeditions',
                'Car Hire, Chauffeur & Executive Transfer',
                'Printing, Signage & Branded Merchandise',
            ],
        ],
        'health_wellness' => [
            'label' => 'Healthcare, Diagnostics & Wellness',
            'description' => 'Clinical diagnosis, pharmacy fulfillment, specialized dental/eye care, physical therapy, and mental health.',
            'specialties' => [
                'General Medical & Family Clinic',
                'Pharmacy & Chemist',
                'Diagnostic Lab & Imaging',
                'Dental Clinic & Orthodontics',
                'Optometry & Eye Care',
                'Physiotherapy & Rehabilitation',
                'Mental Health & Counselling',
                'Pediatric & Maternal Care',
                'Gym, Fitness & Yoga Studio',
            ],
        ],
        'agribusiness_value_chain' => [
            'label' => 'Agribusiness, Value Addition & Farm Logistics',
            'description' => 'Farming operations, inputs, veterinary care, post-harvest processing, and fresh cold-chain logistics.',
            'specialties' => [
                'Dairy, Poultry & Livestock Farming',
                'Horticulture & Fresh Export',
                'Agrovet, Seeds & Crop Protection',
                'Farm Machinery, Irrigation & Greenhouses',
                'Animal Feeds & Veterinary Services',
                'Agri-Processing & Value Addition',
                'Courier, Last-Mile & Freight Logistics',
            ],
        ],
        'tech_digital_scaling' => [
            'label' => 'Digital Products & Cloud Operations',
            'description' => 'Custom software engineering, cloud architecture, cybersecurity, payment gateway integration, and customer messaging.',
            'specialties' => [
                'Custom Software & Mobile App Dev',
                'Cloud, DevOps & Hosting',
                'Cybersecurity & IT Audit',
                'Fintech & Payment Integration',
                'Data Analytics & AI Solutions',
                'Bulk SMS, Voice, USSD & Communications APIs',
                'Web Design & E-Commerce',
            ],
        ],
        'creative_publishing' => [
            'label' => 'Book Publishing & Creative Production',
            'description' => 'Manuscript coaching, editorial refinement, publishing, print distribution, copyright protection, and visual media.',
            'specialties' => [
                'Author Coaching & Manuscript Advisory',
                'Editorial, Ghostwriting & Author Services',
                'Book, Academic & Digital Publishing',
                'Printing, Signage & Branded Merchandise',
                'Intellectual Property & Trademarks',
                'Branding, Graphic Design & PR Agency',
                'Commercial Photography & Videography',
            ],
        ],
        'relocation_facility' => [
            'label' => 'Office/Home Relocation & Facility Care',
            'description' => 'Moving offices or residences, deep sanitation, pest control, security installation, and space fit-out.',
            'specialties' => [
                'Moving, Relocation & Storage',
                'Commercial & Residential Cleaning',
                'Pest Control & Fumigation',
                'CCTV, Electric Fencing & Guarding',
                'Interior Design & Fit-Outs',
                'Appliance Repair & Handyman',
            ],
        ],
    ];

    /**
     * Curated adjacency relationships between Level 2 Specialties.
     *
     * Keys are normalized specialty names (or exact strings).
     * Values are lists of adjacency descriptors:
     * - target: Target specialty name
     * - type: 'upstream' | 'downstream' | 'companion'
     * - weight: 0.1 to 1.0 (relevance multiplier)
     * - need_label: Short human badge label
     * - rationale: Plain-English explanation
     */
    public const ADJACENCY_MAP = [
        'Advocates & Law Firm' => [
            [
                'target' => 'Audit, Tax & Accounting (CPA)',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Tax & Financial Compliance',
                'rationale' => 'When executing commercial contracts or establishing business entities, compliant KRA tax registration and ongoing accounting are essential.',
            ],
            [
                'target' => 'Company Secretarial & Governance',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Corporate Secretarial',
                'rationale' => 'Companies Act compliance, board filings, and official annual returns require certified company secretarial oversight.',
            ],
            [
                'target' => 'Intellectual Property & Trademarks',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'IP & Brand Protection',
                'rationale' => 'Protecting proprietary assets, brand names, and digital IP alongside legal entity structuring.',
            ],
            [
                'target' => 'Land Surveying & Valuation',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Title & Land Verification',
                'rationale' => 'Real estate conveyancing and property acquisition require official boundary surveying and valuation reports before completion.',
            ],
        ],
        'Audit, Tax & Accounting (CPA)' => [
            [
                'target' => 'Advocates & Law Firm',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Legal & Commercial Contracts',
                'rationale' => 'Tax advisory and financial auditing frequently uncover commercial contracts and legal structuring that require advocate review.',
            ],
            [
                'target' => 'Company Secretarial & Governance',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Statutory Compliance',
                'rationale' => 'Annual financial audits must align with statutory BRS annual returns and corporate governance records.',
            ],
            [
                'target' => 'HR Recruitment & Payroll',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Payroll & PAYE Compliance',
                'rationale' => 'Compliant financial management requires structured payroll systems, PAYE, SHA, and NSSF remittance processing.',
            ],
        ],
        'Company Secretarial & Governance' => [
            [
                'target' => 'Advocates & Law Firm',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Corporate Legal Counsel',
                'rationale' => 'Board governance, share transfers, and dispute management work hand-in-hand with corporate legal counsel.',
            ],
            [
                'target' => 'Audit, Tax & Accounting (CPA)',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Annual Audit & Tax Returns',
                'rationale' => 'Filing BRS annual returns requires audited or verified company financials and tax clearance certificates.',
            ],
        ],
        'Custom Software & Mobile App Dev' => [
            [
                'target' => 'Cloud, DevOps & Hosting',
                'type' => 'downstream',
                'weight' => 0.95,
                'need_label' => 'Cloud Infrastructure & Hosting',
                'rationale' => 'Deploying scalable web and mobile software requires reliable containerization, database hosting, and CI/CD pipelines.',
            ],
            [
                'target' => 'Cybersecurity & IT Audit',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Security Audit & DPA Compliance',
                'rationale' => 'Software handling customer data requires vulnerability assessment, penetration testing, and data protection compliance.',
            ],
            [
                'target' => 'Bulk SMS, Voice, USSD & Communications APIs',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'SMS, OTP & Customer Messaging',
                'rationale' => 'Customer authentication, transactional receipts, and engagement alerts rely on robust SMS and communications APIs.',
            ],
            [
                'target' => 'Fintech & Payment Integration',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'M-Pesa & Card Payments',
                'rationale' => 'Monetizing digital platforms requires seamless M-Pesa Daraja and card payment gateway integration.',
            ],
        ],
        'Web Design & E-Commerce' => [
            [
                'target' => 'Branding, Graphic Design & PR Agency',
                'type' => 'upstream',
                'weight' => 0.90,
                'need_label' => 'Brand Identity & Visual Assets',
                'rationale' => 'A high-converting website needs consistent typography, brand identity, logo assets, and professional imagery.',
            ],
            [
                'target' => 'Bulk SMS, Voice, USSD & Communications APIs',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Order Alerts & SMS Updates',
                'rationale' => 'E-commerce storefronts benefit from automated SMS dispatch notifications and customer status updates.',
            ],
            [
                'target' => 'Courier, Last-Mile & Freight Logistics',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Last-Mile Delivery',
                'rationale' => 'Selling physical goods online requires reliable countrywide or Nairobi metro door-to-door fulfillment.',
            ],
            [
                'target' => 'Cloud, DevOps & Hosting',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'High-Speed Web Hosting',
                'rationale' => 'Fast page load speeds and 99.9% uptime require robust SSD hosting and managed SSL security.',
            ],
        ],
        'Fintech & Payment Integration' => [
            [
                'target' => 'Cybersecurity & IT Audit',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'PCI-DSS & Financial Security',
                'rationale' => 'Payment systems and wallet architectures require stringent security review and regulatory compliance checks.',
            ],
            [
                'target' => 'Advocates & Law Firm',
                'type' => 'upstream',
                'weight' => 0.85,
                'need_label' => 'CBK & Regulatory Advisory',
                'rationale' => 'Operating payment settlement services involves Central Bank of Kenya (CBK) licensing and regulatory compliance.',
            ],
        ],
        'Architecture & Urban Planning' => [
            [
                'target' => 'Civil & Structural Engineering',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Structural Engineering',
                'rationale' => 'Architectural building designs require stamped structural engineering calculations for safety and county planning approvals.',
            ],
            [
                'target' => 'Quantity Surveying',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Bills of Quantities (BQs)',
                'rationale' => 'Translating architectural drawings into accurate material costing and contractor tender documents requires a certified QS.',
            ],
            [
                'target' => 'Building & General Contractor',
                'type' => 'downstream',
                'weight' => 0.95,
                'need_label' => 'Construction Contracting',
                'rationale' => 'Once blueprints and approvals are secured, an experienced contractor is needed to execute site construction.',
            ],
            [
                'target' => 'Interior Design & Fit-Outs',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Interior Architecture & Decor',
                'rationale' => 'Optimizing indoor living, lighting, cabinetry, and functional flow requires specialized interior design.',
            ],
        ],
        'Building & General Contractor' => [
            [
                'target' => 'Hardware, Timber & Building Materials',
                'type' => 'upstream',
                'weight' => 0.95,
                'need_label' => 'Building Supplies & Cement',
                'rationale' => 'Site execution requires reliable, high-grade steel, cement, aggregates, and structural timber supplies.',
            ],
            [
                'target' => 'Architecture & Urban Planning',
                'type' => 'upstream',
                'weight' => 0.90,
                'need_label' => 'Approved Architectural Plans',
                'rationale' => 'Contractors build according to approved architectural blueprints and municipal construction permits.',
            ],
            [
                'target' => 'Plumbing, Electrical & Solar Installation',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Electrical, Solar & Plumbing MEP',
                'rationale' => 'Modern structural construction requires integrated mechanical, electrical, and plumbing engineering.',
            ],
            [
                'target' => 'Interior Design & Fit-Outs',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Interior Fit-Out & Finishes',
                'rationale' => 'Completing the building to turnkey standards involves ceiling design, tiling, custom joinery, and lighting fixtures.',
            ],
        ],
        'Interior Design & Fit-Outs' => [
            [
                'target' => 'Home Decor, Furniture & Appliances',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Custom Furniture & Furnishings',
                'rationale' => 'Styling an office or home interior requires tailored ergonomic furniture, cabinetry, and bespoke decor.',
            ],
            [
                'target' => 'Plumbing, Electrical & Solar Installation',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Lighting & Electrical Fit-Out',
                'rationale' => 'Specialized ambient lighting, accent fixtures, and smart home automation require qualified electrical installation.',
            ],
        ],
        'Land Surveying & Valuation' => [
            [
                'target' => 'Advocates & Law Firm',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Property Conveyancing',
                'rationale' => 'Boundary beacon verification and land valuation are critical precursors to formal title deeds and land transfer contracts.',
            ],
            [
                'target' => 'Architecture & Urban Planning',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Topographical Site Planning',
                'rationale' => 'Architects require accurate survey beacons and contour maps to design foundations that fit the natural landscape.',
            ],
        ],
        'Property Sales, Letting & Management' => [
            [
                'target' => 'Advocates & Law Firm',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Lease Agreements & Title Transfer',
                'rationale' => 'Drafting tenancy leases or transferring freehold/leasehold titles requires experienced conveyancing advocates.',
            ],
            [
                'target' => 'Moving, Relocation & Storage',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Moving & Relocation',
                'rationale' => 'Tenants acquiring or vacating residential or commercial property need professional packing and transport services.',
            ],
            [
                'target' => 'Commercial & Residential Cleaning',
                'type' => 'downstream',
                'weight' => 0.80,
                'need_label' => 'Post-Tenancy Deep Cleaning',
                'rationale' => 'Preparing properties for new occupants requires deep sanitization, window washing, and floor buffing.',
            ],
        ],
        'Hotel, Boutique Resort & Airbnb' => [
            [
                'target' => 'Event & Corporate Catering',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Event Catering & Banqueting',
                'rationale' => 'Hosting retreats, workshops, or private gatherings at hospitality venues pairs with tailored catering options.',
            ],
            [
                'target' => 'Team Building & Bush Expeditions',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Outdoor Team Building',
                'rationale' => 'Corporate retreats and weekend groups at lodges often include facilitated team building, hiking, or game drives.',
            ],
            [
                'target' => 'Car Hire, Chauffeur & Executive Transfer',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Airport & Group Transport',
                'rationale' => 'Ensuring guests and executives arrive comfortably with coordinated safari vans or chauffeur transfers.',
            ],
            [
                'target' => 'Commercial Photography & Videography',
                'type' => 'companion',
                'weight' => 0.80,
                'need_label' => 'Event Media & Coverage',
                'rationale' => 'Documenting executive offsites, family reunions, and milestone lodge getaways with high-resolution photography.',
            ],
        ],
        'Restaurant, Nyama Choma & Bistro' => [
            [
                'target' => 'Butchery & Fresh Farm Produce',
                'type' => 'upstream',
                'weight' => 0.90,
                'need_label' => 'Quality Meat & Farm Supplies',
                'rationale' => 'Consistent culinary quality depends on vetted local butcheries and fresh farm produce suppliers.',
            ],
            [
                'target' => 'Event & Corporate Catering',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Outside Catering Services',
                'rationale' => 'Popular bistros and grills frequently take their signature recipes outdoors for corporate catering and private events.',
            ],
        ],
        'Event Planning, Tent/Sound & Decor' => [
            [
                'target' => 'Event & Corporate Catering',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Catering & Dining Hospitality',
                'rationale' => 'Event tenting, staging, and floral decor seamlessly coordinate with banquet meal and beverage service.',
            ],
            [
                'target' => 'Commercial Photography & Videography',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Photography & Live Video',
                'rationale' => 'Capturing high-production events, conferences, and milestone gatherings requires professional camera crews.',
            ],
            [
                'target' => 'Music, Live Bands, DJs & Sound Production',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Sound Production & DJ/Band',
                'rationale' => 'Quality acoustics, wireless microphones, and curated music keep event attendees engaged throughout the program.',
            ],
            [
                'target' => 'Hotel, Boutique Resort & Airbnb',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Venue & Guest Accommodation',
                'rationale' => 'Planning destination celebrations or multi-day retreats requires venue hire and accommodation booking.',
            ],
        ],
        'Event & Corporate Catering' => [
            [
                'target' => 'Event Planning, Tent/Sound & Decor',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Tents, Chairs & Event Styling',
                'rationale' => 'Catered dining requires comfortable guest seating, shade, buffet stations, and elegant table arrangements.',
            ],
            [
                'target' => 'Commercial Photography & Videography',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Media & Memory Documentation',
                'rationale' => 'Corporate launches and banquets benefit from dedicated event photographers to capture moments for public relations.',
            ],
        ],
        'Safari Operator & Tour Packages' => [
            [
                'target' => 'Eco-Camp & Safari Lodge',
                'type' => 'companion',
                'weight' => 0.95,
                'need_label' => 'Safari Camp Accommodation',
                'rationale' => 'Organized bush safaris and park itineraries coordinate with certified eco-camps and wilderness lodges.',
            ],
            [
                'target' => 'Car Hire, Chauffeur & Executive Transfer',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => '4x4 Safari Land Cruisers',
                'rationale' => 'Comfortable wildlife game drives require custom pop-up roof 4x4 vehicles and experienced driver guides.',
            ],
            [
                'target' => 'Flight Ticketing & Visa Assistance',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Bush Flights & Ticketing',
                'rationale' => 'Connecting to the Maasai Mara, Samburu, or Diani via scheduled light aircraft charters.',
            ],
        ],
        'General Medical & Family Clinic' => [
            [
                'target' => 'Pharmacy & Chemist',
                'type' => 'downstream',
                'weight' => 0.95,
                'need_label' => 'Prescription Medicine',
                'rationale' => 'Clinical consultations and treatment plans require immediate, verified prescription medication dispensing.',
            ],
            [
                'target' => 'Diagnostic Lab & Imaging',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Lab Tests & Ultrasound Imaging',
                'rationale' => 'Accurate medical diagnosis often requires specialized blood work, radiology, or ultrasound diagnostics.',
            ],
            [
                'target' => 'Physiotherapy & Rehabilitation',
                'type' => 'downstream',
                'weight' => 0.80,
                'need_label' => 'Physical Therapy & Recovery',
                'rationale' => 'Musculoskeletal injuries, post-operative care, and joint rehabilitation benefit from certified physiotherapy.',
            ],
        ],
        'Dental Clinic & Orthodontics' => [
            [
                'target' => 'Pharmacy & Chemist',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Dental Pain & Antibiotic Care',
                'rationale' => 'Post-extraction or root canal procedures require targeted analgesics and antiseptic mouthwashes.',
            ],
            [
                'target' => 'Diagnostic Lab & Imaging',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'OPG & Dental X-Rays',
                'rationale' => 'Orthodontic alignment and impaction treatments require panoramic dental X-rays (OPG) and 3D imaging.',
            ],
        ],
        'Branding, Graphic Design & PR Agency' => [
            [
                'target' => 'Printing, Signage & Branded Merchandise',
                'type' => 'downstream',
                'weight' => 0.95,
                'need_label' => 'Corporate Printing & Signage',
                'rationale' => 'Transforming brand assets into reality through vehicle branding, office signage, business cards, and merchandise.',
            ],
            [
                'target' => 'Web Design & E-Commerce',
                'type' => 'companion',
                'weight' => 0.90,
                'need_label' => 'Website & Online Presence',
                'rationale' => 'Fresh corporate branding pairs with a modern, responsive website to establish immediate market credibility.',
            ],
            [
                'target' => 'Intellectual Property & Trademarks',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'Trademark & Logo Registration',
                'rationale' => 'Newly created brand names, logos, and taglines should be registered with KIPI to prevent copycats.',
            ],
        ],
        'Book, Academic & Digital Publishing' => [
            [
                'target' => 'Editorial, Ghostwriting & Author Services',
                'type' => 'upstream',
                'weight' => 0.95,
                'need_label' => 'Manuscript Editing & Proofreading',
                'rationale' => 'High-quality publishing requires developmental editing, copy-editing, and line-by-line proofreading.',
            ],
            [
                'target' => 'Author Coaching & Manuscript Advisory',
                'type' => 'upstream',
                'weight' => 0.95,
                'need_label' => 'Author Coaching & Concept Polish',
                'rationale' => 'Writers and executives refining book concepts benefit from structured manuscript coaching before typesetting.',
            ],
            [
                'target' => 'Printing, Signage & Branded Merchandise',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Book Printing & Binding',
                'rationale' => 'Producing tangible paperbacks and hardcovers with high-grade paper stock and perfect binding.',
            ],
            [
                'target' => 'Intellectual Property & Trademarks',
                'type' => 'companion',
                'weight' => 0.85,
                'need_label' => 'KECOBO Copyright Registration',
                'rationale' => 'Securing statutory copyright protection with the Kenya Copyright Board (KECOBO) for literary works.',
            ],
        ],
        'Author Coaching & Manuscript Advisory' => [
            [
                'target' => 'Editorial, Ghostwriting & Author Services',
                'type' => 'downstream',
                'weight' => 0.95,
                'need_label' => 'Editorial Refinement',
                'rationale' => 'Once coaching shapes the book structure, professional editors polish language, style, and tone.',
            ],
            [
                'target' => 'Book, Academic & Digital Publishing',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Publishing & ISBN Distribution',
                'rationale' => 'Turning the finished manuscript into an officially published book with ISBN registration and distribution.',
            ],
        ],
        'Dairy, Poultry & Livestock Farming' => [
            [
                'target' => 'Animal Feeds & Veterinary Services',
                'type' => 'upstream',
                'weight' => 0.95,
                'need_label' => 'Veterinary Care & Nutritious Feeds',
                'rationale' => 'Healthy yields in dairy and poultry require veterinary vaccinations, deworming, and high-protein feed supplements.',
            ],
            [
                'target' => 'Agri-Processing & Value Addition',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Value Addition & Packaging',
                'rationale' => 'Processing raw milk into yoghurt/cheese or dressing poultry increases farm margins and product shelf-life.',
            ],
            [
                'target' => 'Courier, Last-Mile & Freight Logistics',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Cold-Chain Produce Logistics',
                'rationale' => 'Transporting perishable farm goods to urban buyers requires reliable, timely freight dispatch.',
            ],
        ],
        'Moving, Relocation & Storage' => [
            [
                'target' => 'Commercial & Residential Cleaning',
                'type' => 'downstream',
                'weight' => 0.90,
                'need_label' => 'Move-In/Move-Out Cleaning',
                'rationale' => 'Post-move cleaning ensures the old premises are handed over spotless and the new space is clean and sanitized.',
            ],
            [
                'target' => 'Pest Control & Fumigation',
                'type' => 'downstream',
                'weight' => 0.85,
                'need_label' => 'Fumigation & Pest Prevention',
                'rationale' => 'Treating new homes or commercial offices for insects and pests prior to unpacking furniture.',
            ],
            [
                'target' => 'CCTV, Electric Fencing & Guarding',
                'type' => 'companion',
                'weight' => 0.80,
                'need_label' => 'Premises Security & Access Control',
                'rationale' => 'Securing new residential or business property with electric fencing, intruder alarms, and CCTV cameras.',
            ],
            [
                'target' => 'Interior Design & Fit-Outs',
                'type' => 'companion',
                'weight' => 0.80,
                'need_label' => 'Space Layout & Styling',
                'rationale' => 'Arranging furniture, window blinds, and decorative styling in the newly occupied premises.',
            ],
        ],
        'Auto Garage, Service & Body Shop' => [
            [
                'target' => 'Auto Spares, Tyres & Batteries',
                'type' => 'upstream',
                'weight' => 0.95,
                'need_label' => 'Genuine Spares, Tyres & Batteries',
                'rationale' => 'Vehicle maintenance and body repair require authentic replacement parts, premium oils, and certified tyres.',
            ],
            [
                'target' => 'Car Dealership & Import Financing',
                'type' => 'companion',
                'weight' => 0.80,
                'need_label' => 'Vehicle Upgrades & Trade-Ins',
                'rationale' => 'Evaluating whether to repair an aging vehicle or trade in for a direct import with asset financing.',
            ],
        ],
    ];

    /**
     * Retrieve adjacent companion specialties for a given specialty.
     *
     * @param string|int $specialty Specialty name or ID
     * @param string|null $contextFilter Optional filter ('upstream', 'downstream', 'companion')
     * @param PDO|null $pdo Optional PDO connection to resolve integer ID
     * @return array<int, array{target_specialty: string, relationship_type: string, weight: float, underlying_need_label: string, rationale: string}>
     */
    public static function getAdjacentSpecialties(string|int $specialty, ?string $contextFilter = null, ?PDO $pdo = null): array
    {
        $name = is_int($specialty) && $pdo !== null
            ? self::resolveSpecialtyNameById($pdo, $specialty)
            : (string)$specialty;

        if ($name === null || $name === '') {
            return [];
        }

        $canonicalKey = self::matchCanonicalSpecialtyKey($name);
        if ($canonicalKey === null || !isset(self::ADJACENCY_MAP[$canonicalKey])) {
            return [];
        }

        $results = [];
        foreach (self::ADJACENCY_MAP[$canonicalKey] as $item) {
            if ($contextFilter !== null && $item['type'] !== $contextFilter) {
                continue;
            }
            $results[] = [
                'target_specialty' => $item['target'],
                'relationship_type' => $item['type'],
                'weight' => (float)$item['weight'],
                'underlying_need_label' => $item['need_label'],
                'rationale' => $item['rationale'],
            ];
        }

        return $results;
    }

    /**
     * Return human explanation for an adjacency pair.
     */
    public static function explainAdjacency(string $sourceSpecialty, string $targetSpecialty): string
    {
        $canonicalSource = self::matchCanonicalSpecialtyKey($sourceSpecialty);
        if ($canonicalSource !== null && isset(self::ADJACENCY_MAP[$canonicalSource])) {
            $canonicalTarget = self::matchCanonicalSpecialtyKey($targetSpecialty) ?? $targetSpecialty;
            foreach (self::ADJACENCY_MAP[$canonicalSource] as $item) {
                if (strcasecmp($item['target'], $canonicalTarget) === 0 || strcasecmp($item['target'], $targetSpecialty) === 0) {
                    return $item['rationale'];
                }
            }
        }

        // Check inverse relationship
        $canonicalTargetKey = self::matchCanonicalSpecialtyKey($targetSpecialty);
        if ($canonicalTargetKey !== null && isset(self::ADJACENCY_MAP[$canonicalTargetKey])) {
            foreach (self::ADJACENCY_MAP[$canonicalTargetKey] as $item) {
                if (strcasecmp($item['target'], $canonicalSource ?? $sourceSpecialty) === 0) {
                    return $item['rationale'];
                }
            }
        }

        return "When engaging {$sourceSpecialty}, {$targetSpecialty} often provides complementary support for end-to-end execution.";
    }

    /**
     * Return concise underlying need label for an adjacency pair.
     */
    public static function getNeedLabel(string $sourceSpecialty, string $targetSpecialty): string
    {
        $canonicalSource = self::matchCanonicalSpecialtyKey($sourceSpecialty);
        if ($canonicalSource !== null && isset(self::ADJACENCY_MAP[$canonicalSource])) {
            $canonicalTarget = self::matchCanonicalSpecialtyKey($targetSpecialty) ?? $targetSpecialty;
            foreach (self::ADJACENCY_MAP[$canonicalSource] as $item) {
                if (strcasecmp($item['target'], $canonicalTarget) === 0 || strcasecmp($item['target'], $targetSpecialty) === 0) {
                    return $item['need_label'];
                }
            }
        }

        return "Companion Service";
    }

    /**
     * Return all registered ecosystem clusters.
     */
    public static function getEcosystemClusters(): array
    {
        return self::ECOSYSTEM_CLUSTERS;
    }

    /**
     * Given a list of specialty names or user query terms, identify the best fitting ecosystem cluster.
     *
     * @param string[] $specialties
     * @return array{cluster_key: string, label: string, description: string, score: int}|null
     */
    public static function getClusterForSpecialties(array $specialties): ?array
    {
        if (empty($specialties)) {
            return null;
        }

        $bestCluster = null;
        $bestScore = 0;

        foreach (self::ECOSYSTEM_CLUSTERS as $key => $cluster) {
            $score = 0;
            $clusterSpecialties = array_map('strtolower', $cluster['specialties']);
            foreach ($specialties as $sp) {
                $spLower = strtolower(trim($sp));
                foreach ($clusterSpecialties as $cs) {
                    if ($spLower === $cs || str_contains($spLower, $cs) || str_contains($cs, $spLower)) {
                        $score += 2;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCluster = [
                    'cluster_key' => $key,
                    'label' => $cluster['label'],
                    'description' => $cluster['description'],
                    'score' => $bestScore,
                ];
            }
        }

        return $bestScore > 0 ? $bestCluster : null;
    }

    /**
     * Resolve a category ID to its specialty name from the database.
     */
    public static function resolveSpecialtyNameById(PDO $pdo, int $id): ?string
    {
        $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Match a given string to a canonical key in ADJACENCY_MAP (case-insensitive and prefix tolerant).
     */
    public static function matchCanonicalSpecialtyKey(string $name): ?string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        foreach (self::ADJACENCY_MAP as $canonical => $list) {
            if (strcasecmp($canonical, $trimmed) === 0) {
                return $canonical;
            }
        }

        // Fuzzy substring match
        $lower = strtolower($trimmed);
        foreach (self::ADJACENCY_MAP as $canonical => $list) {
            $canLower = strtolower($canonical);
            if (str_contains($canLower, $lower) || str_contains($lower, $canLower)) {
                return $canonical;
            }
        }

        return null;
    }
}
