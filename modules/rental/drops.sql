-- Reviewed, explicit column drops for the rental module.
--
-- Core\Database\MigrationRunner never drops a column it finds in the
-- database but no longer finds in schema.sql — that is the data-loss safety
-- net. This file is the narrow, reviewed exception (ARCHITECTURE.md §10):
-- each statement runs only while the column still exists, so it is
-- idempotent and safe on every request.
--
-- Once every installation has migrated past it, delete the line.

-- The per-asset "afficher dans le menu" flag. The module now contributes
-- exactly one entry to "Notre unité" — the /locations index — because a
-- unit with six assets pushed everything else in that menu off the screen,
-- and the index page already lists them with the type, capacity and photo a
-- bare menu label cannot carry. The column has no reader left.
ALTER TABLE rental_assets DROP COLUMN show_in_menu;

-- NOT here, deliberately: `rental_assets.calendar_id`. An asset now
-- publishes onto as many calendars as it belongs on
-- (rental_asset_calendars), and the old single column no longer appears in
-- schema.sql — which means MigrationRunner leaves it alone, exactly as its
-- data-loss safety net is meant to. It stays until every installation has
-- carried its value over, which
-- RentalAssetRepository::adoptLegacyCalendarColumn() does the first time an
-- asset's calendars are read. Adding the DROP here in the same release
-- would race that backfill and silently lose whichever calendar a unit had
-- already chosen.

-- The renter tracking token's old password_hash() column. It was replaced
-- by `tracking_token_encrypted` when the module started emailing the renter
-- its decisions: a hash answers "is this the token?" and nothing else, and
-- an email has to carry the link itself (see the note in schema.sql).
--
-- Nothing carries the old values over, deliberately. A hash cannot be
-- turned back into a token by anyone, this installation included — that
-- was its whole merit. Bookings that predate the change therefore keep a
-- link that no longer opens, and the fix for one is a manager pressing
-- « Régénérer le lien de suivi », which mints a fresh token and emails it.
ALTER TABLE rental_bookings DROP COLUMN tracking_token_hash;

-- The per-booking history table (§6.15). It was the first of its kind on
-- the site; core later generalised it as `Core\Audit` (§8.66) for Camps,
-- and two implementations of one idea is one too many — with the worse of
-- the two here: values in clear, and a rule ("no personal data in a
-- summary") that only held for as long as everybody remembered it. A
-- history is precisely where a name ends up in a field nobody thought to
-- classify, so the module now records into `entity_changes`, where every
-- value is encrypted unconditionally.
--
-- Nothing carries the old rows over, deliberately: the instance this ships
-- to is a test one, and a backfill would mean decrypting nothing into
-- something and guessing which account each `actor_member_id` belonged to.
-- A booking that predates this release starts its history here.
DROP TABLE IF EXISTS rental_booking_events;
