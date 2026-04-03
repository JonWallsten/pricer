CREATE TABLE IF NOT EXISTS alerts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id          INT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    target_price        DECIMAL(10,2) NOT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    last_notified_price DECIMAL(10,2) NULL,
    last_notified_at    DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alerts_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_product_id (product_id),
    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
