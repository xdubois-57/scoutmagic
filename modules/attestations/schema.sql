-- ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
-- Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
--
-- Attestations module: one batch per PDF the federation sent, split into
-- one nominative document per member.
--
-- ANY edit to this file MUST bump "version" in module.json in the same
-- change: ModuleManager only prunes settings and records a new version when
-- the declared version is greater than the recorded one, and the working
-- rule this project keeps (AGENTS.md § Database, docs/module-development.md)
-- is that touching a module schema and bumping its version are one action.


-- attestation_batches: ONE DEPOSITED FILE, never a year and never a
-- campaign. Several PDFs arrive each season and most of them are partial —
-- a first send in February, a top-up in March for the late registrations,
-- sometimes a correction. A batch keyed on (member, year) could not hold
-- that, which is why it is keyed on nothing but its own id.
--
-- Nothing here is personal data. A batch names a scout year, a category, a
-- label the chef d'unité typed, and counts — never a member. The people are
-- in attestation_batch_lines (added in the iteration that introduces the
-- verification screen) and, once published, in core's member_documents.
CREATE TABLE IF NOT EXISTS attestation_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Chosen at upload and valid for the whole batch. The site deduces NO
    -- date of its own: a tax certificate covering calendar 2025 is usually
    -- handled during scout year 2025-2026, but a late batch or a catch-up
    -- falls elsewhere, and an attendance certificate belongs to no calendar
    -- year at all. The user chooses, full stop.
    scout_year_id INT UNSIGNED NOT NULL,

    -- 'tax' | 'attendance' | 'other'. Short, closed vocabulary declared in
    -- Modules\Attestations\Value\AttestationCategory — never a free string,
    -- because this is what two batches are reconciled on. It configures
    -- nothing: it exists so "has this member already had theirs?" and "who
    -- still has none?" can be answered at all, which a free label cannot do
    -- (a tax certificate and an attendance certificate are two perfectly
    -- legitimate documents for the same person in the same year).
    category VARCHAR(20) NOT NULL,

    -- What the family reads on the member's page: « Attestation fiscale
    -- 2025 », « Attestation présence camp Éclaireurs 2026 ». This is what
    -- carries the covered period, the camp, or any other precision — and
    -- what tells two batches of the same category and year apart.
    label VARCHAR(255) NOT NULL,

    -- 'draft'    — split, waiting for the human check. Nothing published.
    -- 'published'— the documents exist on the members' pages.
    -- Declared in Modules\Attestations\Value\BatchStatus.
    status VARCHAR(20) NOT NULL DEFAULT 'draft',

    -- Read off the deposited file at analysis time and kept for the record:
    -- six months later, "why 43 attestations for 55 members?" has to have
    -- an answer. Counts, never names — the discarded lines are deleted at
    -- validation and the site keeps how many there were, not who they were.
    page_count INT UNSIGNED NOT NULL DEFAULT 0,
    pages_per_document INT UNSIGNED NOT NULL DEFAULT 0,
    document_count INT UNSIGNED NOT NULL DEFAULT 0,
    discarded_count INT UNSIGNED NOT NULL DEFAULT 0,

    -- Written from PHP, never left to the column default: the test suite
    -- runs on SQLite, whose CURRENT_TIMESTAMP is UTC while everything else
    -- here is Europe/Brussels (docs/module-development.md § Timestamps).
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,

    -- Who deposited it. ON DELETE SET NULL: losing the account must not
    -- lose the batch, which is a fact about the unit rather than about them.
    created_by INT UNSIGNED NULL,

    INDEX idx_ab_year_category (scout_year_id, category),
    CONSTRAINT fk_ab_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ab_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
