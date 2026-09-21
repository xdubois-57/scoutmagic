-- ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
-- Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
--
-- Official documents module: the health sheet a family fills in so the
-- federation's form comes out pre-filled.
--
-- `Core\Database\SchemaFiles::all()` migrates every module's schema whether
-- or not the module is enabled, so THIS TABLE EXISTS ON EVERY INSTALLATION.
-- What not activating the module guarantees is that no row is ever written
-- to it — never that the table is absent (specifications.md §44).


-- official_documents_health_sheets: ONE ROW PER MEMBER, replaced in place.
--
-- **One encrypted BLOB holding a JSON document**, not a column per field,
-- and that is a decision rather than a shortcut. This content is never
-- searched, never filtered, never sorted: it is read for one member at a
-- time, by that member's own household. Columns would buy nothing and cost
-- a migration every time the federation moves a line on its form. Same
-- reasoning as `entity_changes`, which encrypts its values unconditionally,
-- and as `mail_deferred_messages.payload_encrypted`.
--
-- Encryption and decryption live in the Repository and nowhere else. No
-- caller of this table ever sees ciphertext, and no caller but the
-- Repository ever sees the key.
--
-- **No scout_year_id**, which AGENTS.md § Database allows explicitly for
-- exactly this shape: the sheet describes a person as they are now, it is
-- replaced on the spot rather than versioned, and its freshness is carried
-- by `last_used_at`. Same key as `member_notes`, on `members.id` rather
-- than on a member-year: a child's allergies do not restart every
-- September.
CREATE TABLE IF NOT EXISTS official_documents_health_sheets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- UNIQUE: one sheet per member, full stop. A second row would be a
    -- second truth about somebody's health, and no screen could choose
    -- between them.
    --
    -- ON DELETE CASCADE, and it is the only defensible answer here: when a
    -- member is removed from the site their health data goes with them, in
    -- the same transaction, without anything having to remember to do it.
    member_id INT UNSIGNED NOT NULL,

    -- The whole sheet, as a JSON document, encrypted at rest. MEDIUMBLOB
    -- rather than BLOB: sixty free-text fields, several of which invite a
    -- paragraph ("informations utiles", "maladies et opérations"), and
    -- ciphertext is larger than what went in. BLOB's 64 KB would very
    -- probably hold it — but a family that filled the form in properly is
    -- the last person who should meet a truncation.
    content_encrypted MEDIUMBLOB NOT NULL,

    -- When this sheet was last USED — saved, or written onto a generated
    -- document (IT-04). Distinct from `updated_at`, which moves on any
    -- write: what the retention purge needs to know is whether the family
    -- still relies on this sheet, and re-downloading last year's document
    -- says yes just as clearly as retyping it would.
    last_used_at DATETIME NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Set explicitly by the Repository on write — no "ON UPDATE
    -- CURRENT_TIMESTAMP", which the migration system's ColumnDefinition
    -- does not model (same note as member_notes.updated_at).
    updated_at DATETIME NULL,

    UNIQUE KEY uq_odhs_member (member_id),

    -- The purge (IT-05) reads nothing but this column to decide what to
    -- delete, over the whole table, so it is indexed on its own.
    INDEX idx_odhs_last_used (last_used_at),

    CONSTRAINT fk_odhs_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
