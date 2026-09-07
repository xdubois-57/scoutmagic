-- presences module
--
-- One row per (calendar event, animé). NOTHING IS CREATED IN ADVANCE: a
-- sheet does not exist until somebody opens it and taps a state, and an
-- animé with no row is « non renseigné ». That is what keeps the table
-- proportional to what was actually recorded rather than to
-- animés × évènements, and what makes « nobody took attendance that
-- evening » and « everybody was absent » two different, readable facts.
--
-- WHY THERE IS NO scout_year_id HERE. AGENTS.md § Database asks for one on
-- every member-related table, and names the exception this row falls under:
-- data that carries its own date and is not scoped to a school year the way
-- a member's function is. A presence hangs off a `calendar_events` row,
-- which has no scout_year_id either for exactly the same reason — and every
-- reader here starts from the events of one section over one year window
-- (Modules\Calendar\Api\SectionEventLookupInterface), then reads the rows
-- for those event ids. A denormalised year column would be a second,
-- derived answer to a question the event already answers, free to drift the
-- day an evening is moved across 1 September.
--
-- WHY member_id AND NOT member_year_id. The link points at the persistent
-- identity, same rule and same reason as `files.owner_member_id`
-- (ARCHITECTURE.md §4): losing an animé's attendance history every
-- September would be a bug, not a reset. The list of animés a sheet OFFERS
-- still comes from the section's composition at the effective scout year —
-- that is a live read, never a snapshot frozen when the event was created.
--
-- WHY calendar_event_id CARRIES NO FOREIGN KEY. It points into another
-- module's table, which AGENTS.md § Database forbids outright: the whole
-- schema is migrated in one pass, core first then modules alphabetically,
-- so `calendar` sorting before `presences` is a coincidence of the two
-- names rather than a guarantee, and on a fresh install neither table
-- exists yet. A row pointing at an event that resolves to nothing degrades
-- to « no such sheet », which is also what a deleted event should look
-- like.
CREATE TABLE IF NOT EXISTS presences_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_event_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    -- The fourth state, « non renseigné », is normally the ABSENCE of a
    -- row. It is spelled here too because a row can outlive the state that
    -- created it: an animateur who taps « Non renseigné » to undo a
    -- mistake, or who writes a comment about somebody they have not
    -- pointed yet, both need a row that says « rien de décidé ». A row
    -- that ends up carrying neither a state nor a comment is deleted
    -- rather than kept as 'unset' (Repository\PresenceRepository::save()).
    status ENUM('present', 'excused', 'absent', 'unset') NOT NULL DEFAULT 'unset',
    -- « Malade », « chez son père ce week-end », « parents en instance » —
    -- free text about a minor, written by the staff. Encrypted at rest and
    -- decrypted in the Repository only (SECURITY.md §5), never journaled,
    -- never in an error message, and never shown to the family.
    comment_encrypted BLOB NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- No "ON UPDATE CURRENT_TIMESTAMP": the migration system's
    -- ColumnDefinition does not model that clause and would silently drop
    -- it from the generated DDL. Written explicitly by the repository,
    -- same precedent as calendar_events.updated_at.
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    -- One row per (event, animé): the sheet is a set of decisions, not a
    -- log of taps. Every write is an upsert against this pair.
    UNIQUE INDEX idx_presences_event_member (calendar_event_id, member_id),
    -- The animé's own page and the export read by member across a whole
    -- year's events; without this index that is a table scan per animé.
    INDEX idx_presences_member (member_id),
    CONSTRAINT fk_presences_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_presences_updated_by FOREIGN KEY (updated_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
