-- fees module ("Cotisations")
--
-- One thing is stored here, and it is stored because nothing else in the
-- site keeps it: what the Desk roster CONTAINED at a given moment.
--
-- An invoice from the federation reflects Desk on the day it was issued.
-- `member_years` is overwritten wholesale at every import
-- (`MemberYearRepository::upsert()`, `deactivateAllForYear()`), so by the
-- time the January invoice is checked in March the site can only say what
-- Desk holds today. Comparing a February invoice against a March roster
-- manufactures differences that were never real, and a tool whose first
-- answer is a false alarm is a tool nobody opens twice.
--
-- Hence a snapshot, written by `Modules\Fees\Service\FeesDeskImportListener`
-- at the end of every Desk import (`Core\Import\DeskImportListener`).
--
-- **No personal data, deliberately.** Every column below is a foreign key
-- or a code. Names and birth dates stay where they already are, in
-- `member_years`, which persists for the whole scout year even for a
-- member gone inactive — so a snapshot row joins back to a readable person
-- through (member_id, the snapshot's scout_year_id) whenever a screen
-- genuinely needs one. Nothing here is a BLOB, nothing here is encrypted,
-- and there is no retention rule to invent: the rows are the size of the
-- roster and are the only record of a past composition there will ever be.
--
-- The consequence to state rather than discover: **a snapshot only exists
-- from the day this module is activated.** Invoices issued before that can
-- never be checked line by line. Operationally the module has to be on
-- before November's deposit invoice for the season to be usable.

CREATE TABLE IF NOT EXISTS fees_roster_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scout_year_id INT UNSIGNED NOT NULL,
    -- When the import that produced this snapshot ran. Compared against an
    -- invoice's own date, and the gap between the two is shown rather than
    -- hidden: one day of drift is enough to produce differences that are
    -- not differences.
    taken_at DATETIME NOT NULL,
    -- Denormalised on purpose: the count is read on a list of snapshots,
    -- one row per import, and counting the members of each would be one
    -- query per line.
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_frs_year_taken (scout_year_id, taken_at),
    CONSTRAINT fk_frs_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fees_roster_snapshot_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT UNSIGNED NOT NULL,
    -- The persistent identity, not member_years.id: the snapshot's own
    -- scout_year_id already says which year, and members.id is what an
    -- invoice line is eventually matched back to.
    member_id INT UNSIGNED NOT NULL,
    -- The fee category encoded in Desk at that moment — the thing an
    -- invoice line is checked against. NULL when Desk had none.
    fee_category_id INT UNSIGNED NULL,
    -- The section of the member's main function (or, when Desk flagged
    -- none, their first function). NULL for someone with no function at
    -- all, and for the fee lines that legitimately carry no section.
    section_id INT UNSIGNED NULL,
    -- The site role that function resolves to (identified/intendant/
    -- chief/admin…), which is what separates an animé from a staff member
    -- on an invoice line. A code, never a label.
    function_role VARCHAR(20) NULL,
    -- Desk's own formation wording, verbatim, in clear exactly as it is on
    -- member_years — this is what the "réduction animateur breveté" line of
    -- an invoice is cross-checked against.
    formation_level VARCHAR(100) NULL,
    -- Recorded as it stood, NEVER used as a filter here. Desk still holds a
    -- member marked leaving, and the federation still bills them; deciding
    -- what to do with the flag belongs to whoever reads the snapshot. A
    -- snapshot that filtered could not answer "what did Desk contain".
    leaving BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE INDEX idx_frsm_snapshot_member (snapshot_id, member_id),
    INDEX idx_frsm_snapshot_section (snapshot_id, section_id),
    CONSTRAINT fk_frsm_snapshot FOREIGN KEY (snapshot_id) REFERENCES fees_roster_snapshots(id) ON DELETE CASCADE,
    -- No ON DELETE on the three below, deliberately: nothing in this
    -- codebase deletes a member, a section or a fee category, and a
    -- snapshot that quietly lost rows when something did would be a
    -- history that lies. A refused DELETE is the better failure.
    CONSTRAINT fk_frsm_member FOREIGN KEY (member_id) REFERENCES members(id),
    CONSTRAINT fk_frsm_fee FOREIGN KEY (fee_category_id) REFERENCES fee_categories(id),
    CONSTRAINT fk_frsm_section FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
