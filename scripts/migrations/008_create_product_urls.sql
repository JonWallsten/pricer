-- Migration 008: Create product_urls table for multi-site price tracking
-- Each product can have multiple URLs (retailers). The products table keeps
-- current_price / url / image_url etc. synced to the cheapest URL.

CREATE TABLE IF NOT EXISTS product_urls (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    url           TEXT NOT NULL,
    css_selector  VARCHAR(500) DEFAULT NULL,
    current_price DECIMAL(10,2) DEFAULT NULL,
    currency      VARCHAR(10) NOT NULL DEFAULT 'SEK',
    image_url     VARCHAR(2048) DEFAULT NULL,
    availability  ENUM('in_stock','out_of_stock','preorder','unknown') NOT NULL DEFAULT 'unknown',
    last_checked_at  DATETIME DEFAULT NULL,
    last_check_status ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
    last_check_error TEXT DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed existing product URLs into the new table
INSERT INTO product_urls (product_id, url, css_selector, current_price, currency, image_url, availability, last_checked_at, last_check_status, last_check_error, created_at)
SELECT id, url, css_selector, current_price, currency, image_url, availability, last_checked_at, last_check_status, last_check_error, created_at
FROM products
WHERE url IS NOT NULL AND url != '';
