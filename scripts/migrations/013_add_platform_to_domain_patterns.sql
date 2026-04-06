ALTER TABLE domain_patterns
    ADD COLUMN platform VARCHAR(50) NULL DEFAULT NULL AFTER debug_path,
    ADD COLUMN platform_confidence VARCHAR(10) NULL DEFAULT NULL AFTER platform;
