-- Explicit, reviewed column drops for the sos_staff module.
-- See schema/drops.sql's header comment and MigrationRunner::applyExplicitDrops()
-- for how this file is applied — idempotent, safe on every request.

-- Removed with the "manually-typed default number" feature: the default
-- number must now always resolve to a real Staff d'U member (auto-resolved
-- to the section's "responsable" when not explicitly chosen), never a
-- free-typed fallback number.
ALTER TABLE sos_settings DROP COLUMN default_number_manual_encrypted;
