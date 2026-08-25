-- fees module ("Cotisations")
--
-- What this module stores is the unit's own reading of the federation's
-- invoices: which tariff each household is on, what the federation
-- actually billed, and where the two disagree.
--
-- What it deliberately does NOT store any more is the roster snapshot it
-- checks those invoices against. `fees_roster_snapshots` /
-- `fees_roster_snapshot_members` moved into `schema/core.sql`: a frozen
-- roster is a fact about members, produced by the core's own Desk import,
-- and a core that needed an optional module to describe its own import is
-- the inversion `ARCHITECTURE.md` §7.4 forbids. This module reads them
-- through `Core\Import\RosterSnapshotRepository`, as a consumer. The
-- table names kept their prefix on the way — it records where they were
-- born, not who owns them, and `schema/core.sql` says so where they now
-- live.
--
-- One consequence is now the opposite of what it used to be: a snapshot
-- exists from the first Desk import onwards, whether or not this module
-- has ever been enabled. Invoices issued before the site's first import
-- still cannot be checked line by line, but nobody has to remember to
-- switch a module on before November's deposit invoice.

-- The three household tariffs, as this unit's Desk encodes them and as the
-- federation prices them.
--
-- Two different jobs sit in one table because a chief sets them on one
-- collapsed panel, in one gesture:
--
--   fee_category_id — WHICH `fee_categories` row means "couple" here. The
--   comparison is impossible without it: `member_years.fee_category_id`
--   points at whatever string Desk exported ("Tarif normal",
--   "N_N_COTISATION NORMALE"), and only the unit knows which of its own
--   codes carries the household meaning. It is an OVERRIDE, not a
--   requirement: `Modules\Fees\Service\FeeCategoryClassifier` recognises
--   the usual wordings on its own, so a unit whose codes are ordinary never
--   opens this panel. Same shape, and the same reason, as
--   `leadership_formation_levels`.
--
--   amount_cents — what one person on that tariff costs. It exists ONLY to
--   turn a discrepancy into euros; nothing is computed from it and no
--   screen presents it as an amount owed. Absent, a discrepancy is shown
--   without a figure rather than with a wrong one. From IT-05 on it is read
--   off the invoices themselves.
--
-- `fee_categories` is a core table and is not extended for a module's need
-- (AGENTS.md § Architecture) — hence a table here, pointing at it.
CREATE TABLE IF NOT EXISTS fees_household_tariffs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- One of Core\Member\HouseholdFeeCategory: normal, couple, family.
    household_category VARCHAR(10) NOT NULL,
    fee_category_id INT UNSIGNED NULL,
    amount_cents INT UNSIGNED NULL,
    updated_at DATETIME NULL,
    UNIQUE INDEX idx_fht_category (household_category),
    CONSTRAINT fk_fht_fee FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A household a chef d'unité has set aside, with the reason they gave.
--
-- This is the answer to shared custody and to two families at one number:
-- an address that normalizes to one string but is not one household. Rather
-- than build a merge/split of households — a data model nobody could keep
-- true — the screen lets a human say "not this one" and why.
--
-- **It comes back when the household changes.** `composition_hash` is a
-- fingerprint of the member ids that were at the address when the decision
-- was taken; a new arrival or a departure no longer matches it, and the
-- household reappears rather than staying silently excluded on the strength
-- of a judgement about a different set of people.
--
-- The reason is free text written by a chief about a family situation — a
-- separation, an arrangement, an illness — so it is BLOB + encrypted like
-- `member_years.leaving_comment_encrypted`, decrypted only in
-- `Modules\Fees\Repository\IgnoredHouseholdRepository`, and never journaled.
CREATE TABLE IF NOT EXISTS fees_ignored_households (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    -- The same HMAC blind index member_addresses carries: the household's
    -- identity, never a readable address.
    address_blind_index CHAR(64) NOT NULL,
    composition_hash CHAR(64) NOT NULL,
    reason_encrypted BLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    UNIQUE INDEX idx_fih_year_address (scout_year_id, address_blind_index),
    CONSTRAINT fk_fih_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_fih_account FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One invoice from the federation, as it was read.
--
-- Nothing lands here until the three arithmetic checks pass
-- (`Modules\Fees\Invoice\InvoiceParser`): there is no partial import and no
-- "imported with warnings". A document whose total does not fall on the
-- centime is not a document anything can be checked against, and half of it
-- in the database would be worse than none of it.
--
-- `document_number` is the identity: importing the same PDF twice updates
-- nothing and creates nothing. A treasurer who is not sure whether they
-- already imported January's invoice must be able to just try.
CREATE TABLE IF NOT EXISTS fees_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    -- As printed on the document. NULL only for an invoice whose number the
    -- template did not carry, which the import screen refuses rather than
    -- storing an anonymous one.
    document_number VARCHAR(100) NOT NULL,
    issue_date DATE NULL,
    total_cents INT NOT NULL,
    iban VARCHAR(34) NULL,
    structured_communication VARCHAR(30) NULL,
    -- Noted, never a condition: a template number that changed is
    -- information, not a reason to refuse a document that adds up.
    template_number VARCHAR(50) NULL,
    -- The parser's count of rows it recognised as neither a tariff line nor
    -- a nominative one. Compared against the last accepted import on the
    -- deposit screen: a jump is how a changed template announces itself.
    ignored_row_count INT UNSIGNED NOT NULL DEFAULT 0,
    -- The roster snapshot this invoice is checked against — the one closest
    -- to its issue date, chosen at import time and frozen here so a later
    -- import cannot silently change what a past verification compared to.
    snapshot_id INT UNSIGNED NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by INT UNSIGNED NULL,
    -- The file Finances gave back, when the PDF was kept
    -- (Modules\Finance\Api\ExpenseReceiptInterface, ARCHITECTURE.md §7.5)
    -- — an id `/files/{id}` opens, under the account's own rule (§8.70).
    -- No foreign key: finance is an OPTIONAL dependency and its receipts
    -- go away with it, so this is a loose reference the way files.owner_id
    -- is, and a NULL here simply means no PDF was kept.
    finance_file_id INT UNSIGNED NULL,
    UNIQUE INDEX idx_fi_document (scout_year_id, document_number),
    INDEX idx_fi_year_date (scout_year_id, issue_date),
    CONSTRAINT fk_fi_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_fi_snapshot FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id) ON DELETE SET NULL,
    CONSTRAINT fk_fi_account FOREIGN KEY (imported_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fees_invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    -- Verbatim from the document, never resolved against a list of known
    -- codes: an unknown reference is read and stored like any other.
    reference VARCHAR(60) NOT NULL,
    descriptor VARCHAR(255) NOT NULL,
    -- The code the document printed, kept even when it resolved to nothing
    -- — that is precisely the case a stale roster is diagnosed from.
    section_code VARCHAR(50) NULL,
    -- The site's own section, matched on sections.desk_code. An import is
    -- refused while this is NULL for a code the document did carry, so a
    -- stored line with a code always has its section.
    section_id INT UNSIGNED NULL,
    unit_price_cents INT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    amount_cents INT NOT NULL,
    -- fee / reduction / adjustment, read off the line's own shape.
    nature VARCHAR(12) NOT NULL,
    line_order INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_fil_invoice (invoice_id, line_order),
    CONSTRAINT fk_fil_invoice FOREIGN KEY (invoice_id) REFERENCES fees_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_fil_section FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per person a line names, and **the name is not in it**.
--
-- The invoice's names are matched against the year's member_years at import
-- time and only the resulting members.id is kept. A person the site could
-- not match keeps a row with a NULL member_id: the count stays right, and
-- the verification report can say "3 personnes facturées que le site n'a pas
-- reconnues" without this table ever holding a name or a birth date. Whoever
-- needs the name opens the PDF, which is exactly what keeping it is for.
CREATE TABLE IF NOT EXISTS fees_invoice_people (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_line_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NULL,
    INDEX idx_fip_line (invoice_line_id),
    INDEX idx_fip_member (member_id),
    CONSTRAINT fk_fip_line FOREIGN KEY (invoice_line_id) REFERENCES fees_invoice_lines(id) ON DELETE CASCADE,
    CONSTRAINT fk_fip_member FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
