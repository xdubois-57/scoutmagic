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
