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
-- in attestation_batch_lines below and, once published, in core's
-- member_documents.
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

    -- The two instants that separate the three states a reader can act on:
    -- deposited (nothing published), published, and — the one that matters
    -- most — published with nobody told yet. A tax certificate has a short
    -- window of use: a family that does not know theirs is there will ask
    -- for it in June, by e-mail, to the treasurer.
    distribution_started_at DATETIME NULL,
    notified_at DATETIME NULL,

    -- Who deposited it. ON DELETE SET NULL: losing the account must not
    -- lose the batch, which is a fact about the unit rather than about them.
    created_by INT UNSIGNED NULL,

    INDEX idx_ab_year_category (scout_year_id, category),
    CONSTRAINT fk_ab_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ab_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- attestation_batch_lines: ONE CERTIFICATE, as read out of the deposited
-- file and already cut out of it.
--
-- The cut happens at deposit, not at publication, which is what makes the
-- verification screen a decision about documents that exist rather than a
-- promise about documents that do not. The lines a chef d'unité unchecks
-- are deleted at validation — row and bytes both — and the batch keeps
-- their COUNT and nothing else.
--
-- `read_name_encrypted` is a natural person's name and is therefore a BLOB
-- encrypted through Core\Security\EncryptionService, decrypted only in the
-- repository (SECURITY.md §5). It is the name as PRINTED, which is not
-- always the name the site holds — that is the whole reason the screen
-- shows it beside the member it was matched to.
--
-- No scout_year_id here: the batch carries it, and a line belongs to
-- exactly one batch (AGENTS.md § Database — "unless the data itself
-- genuinely isn't scout-year-scoped").
CREATE TABLE IF NOT EXISTS attestation_batch_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,

    -- Position in the deposited file, 1-based. What orders the screen, so
    -- the rows read in the same order as the pages they came from.
    position INT UNSIGNED NOT NULL,
    first_page INT UNSIGNED NOT NULL,
    last_page INT UNSIGNED NOT NULL,

    read_name_encrypted BLOB NULL,

    -- The resolved member, NULL while the line is unmatched or ambiguous.
    -- members.id, the persistent identity — a certificate covers a year
    -- that is over and often names somebody who has left, so member_years
    -- would be the wrong end of the relation (same reason as
    -- files.owner_member_id, ARCHITECTURE.md §8.3).
    member_id INT UNSIGNED NULL,

    -- 'matched' | 'unmatched' | 'ambiguous' (Value\MatchState). Kept
    -- alongside member_id rather than derived from it, because "resolved by
    -- a human out of two homonyms" and "matched outright" are the same
    -- member_id and not the same fact.
    state VARCHAR(20) NOT NULL,

    -- The certificate itself, already cut and stored encrypted at rest.
    file_id INT UNSIGNED NOT NULL,

    -- Checked by default: everything is distributed unless somebody says
    -- otherwise, which is the ordinary case. The inverse would mean ticking
    -- forty boxes for a normal batch.
    is_selected BOOLEAN NOT NULL DEFAULT TRUE,

    -- What publication put on the member's page. This is what makes a batch
    -- reversible: it names exactly the rows THIS batch created, so taking
    -- the batch back deletes what it produced and nothing else.
    -- ON DELETE SET NULL rather than CASCADE — losing the document must not
    -- lose the line, which still carries the page range and the count the
    -- batch is accountable for.
    member_document_id INT UNSIGNED NULL,

    -- 'pending' | 'sent' | 'no_address' | 'failed' (Value\DeliveryState).
    -- Telling the last two apart is the point: a family with no address on
    -- record and a family whose mail server refused the message need two
    -- different things from a chef d'unité, and « non envoyé » would say
    -- neither.
    delivery_state VARCHAR(20) NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_abl_batch_position (batch_id, position),
    INDEX idx_abl_member (member_id),
    CONSTRAINT fk_abl_batch FOREIGN KEY (batch_id) REFERENCES attestation_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_abl_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_abl_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_abl_document FOREIGN KEY (member_document_id) REFERENCES member_documents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- attestation_line_candidates: the members an AMBIGUOUS line could belong
-- to. One row per candidate, never a list in a column.
--
-- It exists so the screen can offer the choice AND so the server can check
-- the answer: a member id arriving in a request body is a request, never an
-- authority (SECURITY.md §3). Resolving a line to somebody who was never a
-- candidate is exactly the wrong-family outcome the ambiguous state exists
-- to prevent, so the check is a join rather than a comparison somebody has
-- to remember to write.
--
-- Rows are deleted the moment the line is resolved: a resolved line has no
-- candidates, only a member.
CREATE TABLE IF NOT EXISTS attestation_line_candidates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    line_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    UNIQUE INDEX idx_alc_line_member (line_id, member_id),
    CONSTRAINT fk_alc_line FOREIGN KEY (line_id) REFERENCES attestation_batch_lines(id) ON DELETE CASCADE,
    CONSTRAINT fk_alc_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

