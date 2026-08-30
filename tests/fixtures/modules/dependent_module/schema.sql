-- Present only so ModuleManagerTest can prove that activate() runs NO
-- schema migration of its own: this table must not exist after a
-- successful activation. Every module's tables come from the single pass
-- over the whole declared schema (Core\Database\SchemaFiles) that runs at
-- deploy time.
CREATE TABLE IF NOT EXISTS dependent_module_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
