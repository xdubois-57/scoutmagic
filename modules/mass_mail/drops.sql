-- Explicit, reviewed column/constraint drops.
--
-- SchemaComparator deliberately never auto-drops a column, FK constraint,
-- or table it finds in the database but not in schema.sql (a data-loss
-- safety net — see its class doc comment). This file is the one narrow,
-- explicit exception: each statement below was hand-written and reviewed
-- as part of the change that stopped declaring the column/constraint, and
-- MigrationRunner only executes it while the column/constraint still
-- exists (idempotent — safe to run on every request, and a no-op on fresh
-- installs that never had it).
--
-- `ALTER TABLE <table> DROP COLUMN <column>;` and
-- `ALTER TABLE <table> DROP FOREIGN KEY <constraint>;` statements are
-- recognized here. See Core\Database\MigrationRunner::applyExplicitDrops().

-- mass_mail_emails.scout_year_id was replaced by the
-- mass_mail_email_scout_years join table (schema.sql) so an email can
-- target several scout years at once (multi-year selection with
-- merge/dedup). The single-column FK is retired outright.
ALTER TABLE mass_mail_emails DROP FOREIGN KEY fk_mme_scout_year;
ALTER TABLE mass_mail_emails DROP COLUMN scout_year_id;
