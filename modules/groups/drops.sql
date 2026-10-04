-- Explicit, reviewed drops for the discussion groups module.
--
-- SchemaComparator never auto-drops anything it finds in the database but
-- no longer declared (a data-loss safety net). This file is the narrow,
-- reviewed exception: MigrationRunner::applyExplicitDrops() executes each
-- statement only while its target still exists, so every line here is
-- idempotent and a no-op on a fresh install.

-- The optional link between a post and a calendar event ("on parle de la
-- réunion de samedi"), removed with the field that fed it (issue #711).
-- The link only ever went one way — to the calendar's month view — and
-- nothing on the calendar side, nor any other module, ever read it, so
-- there is nothing to migrate the values into. The column had no foreign
-- key by design (calendar is independently enable-able), which is why
-- this is one statement and not three.
ALTER TABLE discussion_group_posts DROP COLUMN calendar_event_id;
