-- Busherian Biasharas — Production MySQL 8.0 / MariaDB 10.6 Schema
-- Strict Privacy Rule: `businesses` NEVER stores House, Year Left, or School Number.
-- Vetting credentials live temporarily in `business_vetting_queue` and are hard-deleted on review.

SET NAMES utf8mb4;
SET time_zone = '+03:00';

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL UNIQUE,
    icon VARCHAR(60) NULL,
    status ENUM('approved', 'pending_approval', 'rejected') NOT NULL DEFAULT 'approved',
    submitted_by_email VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_parent_status (parent_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS businesses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id VARCHAR(32) NULL UNIQUE,
    business_number INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL UNIQUE,
    tagline VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,

    -- School Affiliation Badge ONLY (No House, No Year Left, No School Number EVER stored here)
    school_badge ENUM('AHS', 'AGHS', 'BOTH') NOT NULL DEFAULT 'AHS',

    -- 2-Level Category Assignment
    sector_id INT UNSIGNED NOT NULL,
    specialty_id INT UNSIGNED NOT NULL,

    -- Service Mode & B2B / Alumni Offer
    service_mode ENUM('physical', 'hybrid', 'online') NOT NULL DEFAULT 'physical',
    alumni_perk VARCHAR(190) NULL,
    b2b_ready TINYINT(1) NOT NULL DEFAULT 0,
    accepts_lpo TINYINT(1) NOT NULL DEFAULT 0,
    regulator_name VARCHAR(60) NULL,
    regulator_license VARCHAR(80) NULL,

    -- Location & Pin
    branch_name VARCHAR(120) NULL,
    building_name VARCHAR(140) NULL,
    floor_suite VARCHAR(80) NULL,
    street_road VARCHAR(160) NULL,
    area_neighborhood VARCHAR(120) NULL,
    city_county VARCHAR(100) NOT NULL DEFAULT 'Nairobi',
    landmark_directions VARCHAR(255) NULL,
    plus_code VARCHAR(40) NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    google_maps_url VARCHAR(500) NULL,

    -- Operating Hours (JSON 7-day EAT schedule)
    operating_hours_json JSON NOT NULL,

    -- Direct Contact & Social Links
    phone_primary VARCHAR(40) NOT NULL,
    whatsapp_number VARCHAR(40) NULL,
    email_public VARCHAR(190) NULL,
    owner_email VARCHAR(190) NULL,
    website_url VARCHAR(350) NULL,
    social_instagram VARCHAR(255) NULL,
    social_linkedin VARCHAR(255) NULL,
    social_x VARCHAR(255) NULL,
    social_facebook VARCHAR(255) NULL,
    social_tiktok VARCHAR(255) NULL,
    social_youtube VARCHAR(255) NULL,

    -- Media & Moderation State
    logo_path VARCHAR(255) NULL,
    photos_json TEXT NULL,
    status ENUM('pending', 'approved', 'rejected', 'quarantined_dpa') NOT NULL DEFAULT 'pending',
    verified_at TIMESTAMP NULL,
    last_confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_biz_sector FOREIGN KEY (sector_id) REFERENCES categories(id),
    CONSTRAINT fk_biz_specialty FOREIGN KEY (specialty_id) REFERENCES categories(id),
    INDEX idx_status_sector_spec (status, sector_id, specialty_id),
    INDEX idx_coords (latitude, longitude),
    INDEX idx_city (city_county),
    INDEX idx_biz_number (business_number),
    FULLTEXT idx_ft_search (name, tagline, description, area_neighborhood, city_county)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ephemeral Vetting Queue: rows are HARD-DELETED the instant the business is approved or rejected
CREATE TABLE IF NOT EXISTS business_vetting_queue (
    business_id INT UNSIGNED PRIMARY KEY,
    school_code ENUM('AHS', 'AGHS') NOT NULL,
    house_name VARCHAR(80) NOT NULL,
    year_left SMALLINT UNSIGNED NOT NULL,
    school_number VARCHAR(30) NULL,
    submitter_name VARCHAR(150) NOT NULL,
    submitter_email VARCHAR(190) NOT NULL,
    submitter_phone VARCHAR(40) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vetting_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anti-Spam Single-Use Nonce Replay Lock
CREATE TABLE IF NOT EXISTS used_nonces (
    nonce_hash CHAR(64) PRIMARY KEY,
    expires_at INT UNSIGNED NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keyed HMAC-SHA256 IP Rate Limiting (Zero Plaintext IPs per Kenya DPA 2019 s.31/41)
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hmac CHAR(64) NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    INDEX idx_ip_action_time (ip_hmac, action_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable Outbound Email Outbox
CREATE TABLE IF NOT EXISTS email_outbox (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status ENUM('queued', 'sent', 'simulated', 'failed') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anonymous Click Attribution Counters
CREATE TABLE IF NOT EXISTS click_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_biz_event (business_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consented First-Party Usage Metrics (Strictly Opt-In per Kenya DPA 2019 s.32)
CREATE TABLE IF NOT EXISTS usage_metrics (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(40) NOT NULL,
    visitor_hash CHAR(64) NOT NULL,
    page_path VARCHAR(160) NULL,
    business_id INT UNSIGNED NULL,
    meta_label VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_type_time (event_type, created_at),
    INDEX idx_metric_visitor (visitor_hash, created_at),
    INDEX idx_metric_biz (business_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Persistent Verified Business Owners (Strictly zero House / Year Left / School Number columns)
CREATE TABLE IF NOT EXISTS business_owners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    full_name VARCHAR(150) NULL,
    phone VARCHAR(40) NULL,
    google_sub VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_owner_google (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-Many Owner <-> Business Links (Supports single owner with multiple businesses & co-owners)
CREATE TABLE IF NOT EXISTS business_owner_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id INT UNSIGNED NOT NULL,
    business_id INT UNSIGNED NOT NULL,
    linked_via VARCHAR(40) NOT NULL DEFAULT 'auto_match',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_business (owner_id, business_id),
    INDEX idx_link_business (business_id),
    CONSTRAINT fk_link_owner FOREIGN KEY (owner_id) REFERENCES business_owners(id) ON DELETE CASCADE,
    CONSTRAINT fk_link_biz FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-Use Passwordless Magic Links & Claim Tokens
CREATE TABLE IF NOT EXISTS owner_auth_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    claim_business_id INT UNSIGNED NULL,
    expires_at INT UNSIGNED NOT NULL,
    used_at INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key-Value Runtime Settings (e.g. Google OAuth Client ID / Secret configurable from Admin Console)
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
