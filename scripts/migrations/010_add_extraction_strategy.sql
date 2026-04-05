-- Migration 010: Add extraction_strategy column to product_urls
-- Supports multi-strategy extraction: 'auto' (default) tries all methods,
-- 'selector' prioritizes the CSS selector.

ALTER TABLE product_urls
    ADD COLUMN extraction_strategy ENUM('auto','selector') NOT NULL DEFAULT 'auto'
    AFTER css_selector;

-- Also add to products table for backward compatibility / primary URL
ALTER TABLE products
    ADD COLUMN extraction_strategy ENUM('auto','selector') NOT NULL DEFAULT 'auto'
    AFTER css_selector;
