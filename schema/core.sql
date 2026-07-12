-- Core schema
-- This file describes the complete desired state of the core database tables.
-- The migration runner compares this to the actual database and generates DDL accordingly.
-- NEVER create incremental migration files — edit this file directly.

CREATE TABLE scout_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_id VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_id (desk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
