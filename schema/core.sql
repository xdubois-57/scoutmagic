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

CREATE TABLE user_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_encrypted BLOB NOT NULL,
    email_blind_index CHAR(64) NOT NULL,
    first_name_encrypted BLOB,
    last_name_encrypted BLOB,
    password_hash VARCHAR(255),
    is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME,
    UNIQUE INDEX idx_email_blind (email_blind_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE magic_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_blind_index CHAR(64) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN NOT NULL DEFAULT FALSE,
    confirmed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_email_blind (email_blind_index),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE editable_contents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_key VARCHAR(100) NOT NULL,
    content_type ENUM('rich_text', 'image') NOT NULL,
    content_value MEDIUMTEXT,
    module_id VARCHAR(50),
    modified_at DATETIME,
    modified_by INT UNSIGNED,
    UNIQUE INDEX idx_content_key (content_key),
    INDEX idx_module (module_id),
    CONSTRAINT fk_editable_modified_by FOREIGN KEY (modified_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relative_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    module_id VARCHAR(50),
    role_min VARCHAR(20) NOT NULL DEFAULT 'public',
    custom_resolver VARCHAR(100),
    encrypted BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    UNIQUE INDEX idx_path (relative_path),
    CONSTRAINT fk_file_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE age_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    desk_code VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    UNIQUE INDEX idx_desk_code (desk_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    age_branch_id INT UNSIGNED NOT NULL,
    desk_code VARCHAR(50) NOT NULL,
    name VARCHAR(100),
    email VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_desk_code (desk_code),
    CONSTRAINT fk_section_branch FOREIGN KEY (age_branch_id) REFERENCES age_branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
