ALTER TABLE products ADD COLUMN availability ENUM('in_stock','out_of_stock','preorder','unknown') NOT NULL DEFAULT 'unknown' AFTER image_url;

ALTER TABLE alerts ADD COLUMN notify_back_in_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
