-- Explicit, reviewed column drops.
--
-- SchemaComparator deliberately never auto-drops a column or table it finds
-- in the database but not in core.sql (a data-loss safety net — see its
-- class doc comment). This file is the one narrow, explicit exception:
-- each statement below was hand-written and reviewed as part of the change
-- that stopped declaring the column, and MigrationRunner only executes it
-- while the column still exists (idempotent — safe to run on every
-- request, and a no-op on fresh installs that never had it).
--
-- Only `ALTER TABLE <table> DROP COLUMN <column>;`,
-- `ALTER TABLE <table> DROP FOREIGN KEY <constraint>;` and
-- `DROP TABLE <table>;` statements are recognized here. See
-- MigrationRunner::applyExplicitDrops().

-- Removed with the badge logo/icon picker feature.
ALTER TABLE badges DROP COLUMN icon;

-- Removed with the notification centre feature (Lot 2) — replaced by the
-- nullable read_at DATETIME, which also carries "when" a notification was
-- read, not just whether.
ALTER TABLE notifications DROP COLUMN is_read;
