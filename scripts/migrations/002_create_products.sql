CREATE TABLE IF NOT EXISTS products (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED NOT NULL,
    name              VARCHAR(255) NOT NULL,
    url               TEXT NOT NULL,
    css_selector      VARCHAR(500) NULL,
    current_price     DECIMAL(10,2) NULL,
    currency          VARCHAR(10) NOT NULL DEFAULT 'SEK',
    last_checked_at   DATETIME NULL,
    last_check_status ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
    last_check_error  TEXT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_checked (last_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
