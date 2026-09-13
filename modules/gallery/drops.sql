-- Explicit, reviewed drops for the gallery module.
--
-- SchemaComparator never auto-drops anything it finds in the database but
-- no longer declared (a data-loss safety net). This file is the narrow,
-- reviewed exception: MigrationRunner::applyExplicitDrops() executes each
-- statement only while its target still exists, so every line here is
-- idempotent and a no-op on a fresh install.
--
-- All of it belongs to one change: storage locations left this module for
-- Core\Storage\Location, where the backups can reach them too. Order
-- matters — the two foreign keys have to go before the table they point
-- at, and the columns before nothing in particular, but after their
-- constraints.

-- The album's link to the module's own retired location table. Its
-- replacement (location_id, referencing storage_locations in
-- schema/core.sql) is a NEW column rather than the same one repointed,
-- because the old values were identifiers of the retired table and no
-- foreign key into the core one would have accepted them.
ALTER TABLE gallery_albums DROP FOREIGN KEY fk_gallery_albums_storage_location;
ALTER TABLE gallery_albums DROP FOREIGN KEY fk_gallery_albums_migration_target;
ALTER TABLE gallery_albums DROP COLUMN location_id;
ALTER TABLE gallery_albums DROP COLUMN migration_target_id;

-- The module's own location table, superseded by core's storage_locations.
-- Its rows are not migrated: the project is in its test phase and the
-- decision to redeclare rather than convert is recorded as D16 of
-- docs/chantiers/emplacements-de-stockage.md.
DROP TABLE gallery_storage_locations;

-- The singleton S3 secret that preceded the multi-location model. It had
-- exactly one reader left — the backfill that carried its value into the
-- location table — and that backfill goes with this change too, so nothing
-- reads it any more.
DROP TABLE gallery_s3_secret;
