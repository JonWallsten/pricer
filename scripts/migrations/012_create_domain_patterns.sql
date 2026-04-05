CREATE TABLE IF NOT EXISTS domain_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    extraction_method VARCHAR(50) NOT NULL,
    pattern_type VARCHAR(50) NULL,
    css_selector VARCHAR(500) NULL,
    debug_path VARCHAR(1000) NULL,
    hit_count INT NOT NULL DEFAULT 1,
    fail_count INT NOT NULL DEFAULT 0,
    last_success_at DATETIME NOT NULL,
    last_fail_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_domain_method_selector (domain, extraction_method, css_selector(191)),
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
