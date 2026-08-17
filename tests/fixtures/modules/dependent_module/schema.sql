-- Present only so ModuleManagerTest can prove that activate() refuses an
-- unsatisfied module BEFORE it migrates any schema.
CREATE TABLE IF NOT EXISTS dependent_module_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
