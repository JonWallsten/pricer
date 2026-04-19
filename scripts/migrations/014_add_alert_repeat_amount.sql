ALTER TABLE alerts
    ADD COLUMN renotify_drop_amount DECIMAL(10,2) NULL DEFAULT NULL
    AFTER notify_back_in_stock;
