-- Data migration (chantier « Migration de données », lot A).
--
-- Two tables, and neither references another module's table
-- (ModuleSchemaBoundariesTest): each import writes through its target
-- module's Api\ contract, and the only link kept here is the
-- correspondence below, by type name and id.

-- One import of one file (or one form-supplied source): its state, where it
-- has got to and what it found. Nothing in a row names a person — the
-- analysis and the report keep counters, reasons and the OLD SITE's
-- references only (D8). The deposited file itself lives under
-- storage/temp/data_migration and is purged 24 h after the run stopped
-- moving (Task\PurgeUploadsHandler); file_path is cleared when it goes.
CREATE TABLE IF NOT EXISTS data_migration_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    importer_key VARCHAR(50) NOT NULL,
    -- uploaded, analysed, confirmed, running, interrupted, done, abandoned
    state VARCHAR(20) NOT NULL DEFAULT 'uploaded',
    -- Relative to storage/, NULL once purged or for a source with no file.
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size BIGINT UNSIGNED NULL,
    -- What a form-supplied source was given (an ICS address can carry a
    -- private token), encrypted as JSON.
    source_parameters BLOB NULL,
    format VARCHAR(100) NULL,
    format_version VARCHAR(20) NULL,
    installation_id VARCHAR(190) NULL,
    export_id VARCHAR(190) NULL,
    exported_at VARCHAR(40) NULL,
    -- JSON: {phase, position, measured, item_count}
    progress TEXT NULL,
    -- JSON: counters, reasons, sample references, missing years
    analysis MEDIUMTEXT NULL,
    -- JSON: chosen past years (D5)
    chosen_years TEXT NULL,
    -- JSON: imported / present / ignored / errors and their samples
    report MEDIUMTEXT NULL,
    rejection_reason VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- The last movement: every state change and every slice stamps it.
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    finished_at DATETIME NULL,
    file_purged_at DATETIME NULL,
    INDEX idx_data_migration_runs_importer (importer_key, id),
    INDEX idx_data_migration_runs_state (state, updated_at),
    CONSTRAINT fk_data_migration_runs_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenance and deduplication (D12): which object of this site an object
-- of the old site became. The key is (source installation, format, kind of
-- source object, source id) — the kind because one export can give an
-- article and its form the same id. Compared byte for byte (VARBINARY, as
-- user_passkeys.credential_id in core):
-- `A-1` and `a-1`, or `007` and `7`, are different objects of the old site.
-- Never deleted by this module, including when it is disabled: it is what
-- makes a later re-import skip what is already here.
CREATE TABLE IF NOT EXISTS data_migration_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    installation_id VARBINARY(190) NOT NULL,
    format VARBINARY(100) NOT NULL,
    source_kind VARBINARY(50) NOT NULL,
    source_id VARBINARY(190) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id VARCHAR(64) NOT NULL,
    run_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_data_migration_items_source (installation_id, format, source_kind, source_id),
    INDEX idx_data_migration_items_target (target_type, target_id),
    CONSTRAINT fk_data_migration_items_run FOREIGN KEY (run_id) REFERENCES data_migration_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
