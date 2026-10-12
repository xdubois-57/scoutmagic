-- Explicit, reviewed drops for the registration module.
--
-- SchemaComparator never auto-drops anything it finds in the database but
-- no longer declared (a data-loss safety net). This file is the narrow,
-- reviewed exception: MigrationRunner::applyExplicitDrops() executes each
-- statement only while its target still exists, so every line here is
-- idempotent and a no-op on a fresh install.

-- One timestamp per decision type, replaced by registration_decision_emails
-- (issue #843, IT-05): a resend overwrote the first send's date, and an
-- acceptance e-mail sent before the decision changed made the next
-- acceptance visible to the family before its own e-mail. Not migrated —
-- the site is still in test, and a date with no decision attached cannot
-- say which decision it announced.
ALTER TABLE registration_requests DROP COLUMN accepted_email_sent_at;
ALTER TABLE registration_requests DROP COLUMN refused_email_sent_at;
