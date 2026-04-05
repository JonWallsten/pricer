-- Add campaign detection and Omnibus 30-day lowest price fields
ALTER TABLE product_urls
  ADD COLUMN regular_price DECIMAL(10,2) NULL AFTER current_price,
  ADD COLUMN previous_lowest_price DECIMAL(10,2) NULL AFTER regular_price,
  ADD COLUMN is_campaign TINYINT(1) NOT NULL DEFAULT 0 AFTER previous_lowest_price,
  ADD COLUMN campaign_type VARCHAR(50) NULL AFTER is_campaign,
  ADD COLUMN campaign_label VARCHAR(255) NULL AFTER campaign_type,
  ADD COLUMN campaign_json TEXT NULL AFTER campaign_label;

ALTER TABLE products
  ADD COLUMN regular_price DECIMAL(10,2) NULL AFTER current_price,
  ADD COLUMN previous_lowest_price DECIMAL(10,2) NULL AFTER regular_price,
  ADD COLUMN is_campaign TINYINT(1) NOT NULL DEFAULT 0 AFTER previous_lowest_price,
  ADD COLUMN campaign_type VARCHAR(50) NULL AFTER is_campaign,
  ADD COLUMN campaign_label VARCHAR(255) NULL AFTER campaign_type,
  ADD COLUMN campaign_json TEXT NULL AFTER campaign_label;
