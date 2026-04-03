CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_id     VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    name          VARCHAR(255) NOT NULL DEFAULT '',
    picture_url   TEXT NULL,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_id (google_id),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
