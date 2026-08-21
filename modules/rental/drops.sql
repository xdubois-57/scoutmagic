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
