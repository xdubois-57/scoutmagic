-- Reviewed, explicit column drops for the inbound_mail module.
--
-- Core\Database\MigrationRunner never drops a column it finds in the
-- database but no longer finds in schema.sql — that is the data-loss safety
-- net. This file is the narrow, reviewed exception (ARCHITECTURE.md §10):
-- each statement runs only while the column still exists, so it is
-- idempotent and safe on every request.
--
-- Once every installation has migrated past it, delete the line.

-- NOT here yet, deliberately: `inbound_messages.consumer_id`,
-- `business_reference` and `link_origin`.
--
-- They moved to `inbound_message_links`, nothing writes them any more, and
-- schema.sql now declares them nullable so an INSERT that ignores them
-- works. But the one-time backfill that turns each of them into a link
-- (InboundMessageRepository::backfillLinks(), guarded by the
-- `inbound_mail_links_migrated` setting) runs from the composition root —
-- which is reached long after MigrationRunner has already applied this
-- file, on the very same request. Dropping them here in the same release
-- would delete every existing association before anything could read it.
--
-- So they go in the release that FOLLOWS the one shipping the backfill, by
-- which time every installation has run it at least once. The same
-- expand-then-contract sequencing `rental_assets.calendar_id` needed, and
-- for the same reason.

-- The per-module settings of a box (« analyse ce courrier », « qui peut
-- lire »), and the box's own name. A box now has one of two purposes —
-- the unit's general mail, or reserved to one module — and that answer
-- alone decides who analyses and who reads it (§8.58); its account address
-- is its name everywhere. Nothing carries the old rows over, deliberately:
-- the instance this ships to is a test one, and every box keeps its
-- `purpose` and `dedicated_to`.
DROP TABLE inbound_mailbox_consumers;
ALTER TABLE inbound_mailboxes DROP COLUMN name;
