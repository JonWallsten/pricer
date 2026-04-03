CREATE TABLE IF NOT EXISTS price_history (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id  INT UNSIGNED NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    currency    VARCHAR(10) NOT NULL DEFAULT 'SEK',
    recorded_at DATE NOT NULL,
    CONSTRAINT fk_history_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE INDEX uq_product_date (product_id, recorded_at),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
