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
-- `ALTER TABLE <table> DROP COLUMN <column>;`,
-- `ALTER TABLE <table> DROP FOREIGN KEY <constraint>;` and
-- `DROP TABLE <table>;` statements are recognized here. See
-- Core\Database\MigrationRunner::applyExplicitDrops().

-- mass_mail_emails.scout_year_id was replaced by the
-- mass_mail_email_scout_years join table (schema.sql) so an email can
-- target several scout years at once (multi-year selection with
-- merge/dedup). The single-column FK is retired outright.
ALTER TABLE mass_mail_emails DROP FOREIGN KEY fk_mme_scout_year;
ALTER TABLE mass_mail_emails DROP COLUMN scout_year_id;

-- A custom list's single set of criteria and its own addresses, replaced
-- by blocks (issue #729): member groups carry their criteria in
-- mass_mail_block_functions/_sections/_badges, contact groups their
-- addresses in mass_mail_block_contacts. Nothing is converted, by the
-- maintainer's decision (the site is in its test phase): a list survives
-- with its name, description, state and the emails that cite it, and
-- holds no block until somebody adds one. The four tables only point at
-- mass_mail_lists and core tables, and nothing points at them, so they
-- go in any order.
DROP TABLE mass_mail_list_functions;
DROP TABLE mass_mail_list_sections;
DROP TABLE mass_mail_list_badges;
DROP TABLE mass_mail_list_addresses;

-- mass_mail_recipients carried three notions in one row — the message, the
-- member's history and the notification — and could count none of them
-- apart (issue #999). It is replaced by mass_mail_deliveries,
-- mass_mail_targets, mass_mail_delivery_targets and
-- mass_mail_notified_accounts (schema.sql). Nothing is converted, by the
-- maintainer's decision (the site is in its test phase): the tracking and
-- the member-page history of the emails sent before go with the table.
-- Nothing references it, so it goes whenever.
DROP TABLE mass_mail_recipients;
