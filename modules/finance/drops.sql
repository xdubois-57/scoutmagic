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

-- Fiscal years are now scout years (schema.sql), so
-- finance_transactions.fiscal_year_id was retargeted from
-- finance_fiscal_years(id) to scout_years(id) under a new constraint name
-- (fk_ft_scout_year — SchemaComparator matches FKs by name only, so
-- retargeting required a rename to force an ADD CONSTRAINT). The old
-- constraint is never re-added by anything and, left in place, blocks
-- every insert since no scout_years id ever exists in the now-unused
-- finance_fiscal_years table.
ALTER TABLE finance_transactions DROP FOREIGN KEY fk_ft_fiscal_year;

-- finance_category_rules replaced its single condition_type/
-- condition_value pair with three independent, combinable condition
-- columns (keyword_pattern/counterparty_account_pattern/amount_range —
-- schema.sql) so a rule can require several conditions at once. Reviewed
-- as safe to drop outright with no data-preserving migration: at the time
-- of this change finance_category_rules had zero rows in production.
ALTER TABLE finance_category_rules DROP COLUMN condition_type;
ALTER TABLE finance_category_rules DROP COLUMN condition_value;
