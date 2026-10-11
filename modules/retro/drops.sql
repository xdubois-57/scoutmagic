-- Explicit, reviewed drops for the retro module.
--
-- SchemaComparator never auto-drops anything it finds in the database but
-- no longer declared (a data-loss safety net). This file is the narrow,
-- reviewed exception: MigrationRunner::applyExplicitDrops() executes each
-- statement only while its target still exists, so every line here is
-- idempotent and a no-op on a fresh install.

-- The three settings that looked like access control and were not, replaced
-- by retro_boards.access_mode (issue #833). `listed` was read nowhere but a
-- badge, `link_visibility` only decided who saw the link in the calendar,
-- and `anti_duplicate_mode` now follows from the access mode. Their values
-- are NOT migrated: the site was still in test when the change was made,
-- the maintainer said so on the issue, and every existing board takes the
-- column's default, « animators » — the most closed of the three.
ALTER TABLE retro_boards DROP COLUMN listed;
ALTER TABLE retro_boards DROP COLUMN anti_duplicate_mode;
ALTER TABLE retro_boards DROP COLUMN link_visibility;
