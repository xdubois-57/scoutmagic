-- Explicit, reviewed drops for the news module.
--
-- SchemaComparator never auto-drops anything it finds in the database but
-- no longer declared (a data-loss safety net). This file is the narrow,
-- reviewed exception: MigrationRunner::applyExplicitDrops() executes each
-- statement only while its target still exists, so every line here is
-- idempotent and a no-op on a fresh install.

-- The daily digest's on/off switch, replaced by `digest_email` — an
-- address, which is the thing the switch could never carry (issue #738).
-- Its values are NOT migrated, and they could not be: the column recorded
-- that somebody wanted a digest, never where it should go. The old
-- behaviour sent it to the article's author, and writing that address into
-- the new column at migration time would be this code deciding on a
-- recipient the unit never typed — and pinning it to whoever happened to
-- create the article, which is exactly the coupling the issue removes. A
-- form that had the digest on therefore wants its address typed once, in
-- the form's settings, where it is now visible.
ALTER TABLE news_forms DROP COLUMN daily_digest_enabled;
