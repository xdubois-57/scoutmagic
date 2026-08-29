-- Explicit, reviewed column drops for the sos_staff module.
-- See schema/drops.sql's header comment and MigrationRunner::applyExplicitDrops()
-- for how this file is applied — idempotent, safe on every request.

-- Removed with the "manually-typed default number" feature: the default
-- number must now always resolve to a real Staff d'U member (auto-resolved
-- to the section's "responsable" when not explicitly chosen), never a
-- free-typed fallback number.
ALTER TABLE sos_settings DROP COLUMN default_number_manual_encrypted;

-- Removed when duty periods became virtual calendar events computed live
-- from sos_oncall_assignments (Calendar\SosVirtualEventProvider,
-- ARCHITECTURE.md §7.6): nothing writes to calendar_events anymore, so
-- there are no synced event ids to book-keep. Calendar events the OLD sync
-- created remain in the calendar module as ordinary, hand-deletable events
-- (they are titled "SOS Staff d'U : …" in the Animateurs calendar) — this
-- module has no write access to another module's table to purge them, and
-- that is the point of the change.
DROP TABLE sos_calendar_sync;
