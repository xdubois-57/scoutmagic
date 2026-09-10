<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\Backup;
use Core\Maintenance\BackupFamily;
use PHPUnit\Framework\TestCase;

/**
 * Every backup type has a family, and the schema and PHP agree on the list.
 *
 * A ratchet, in the manner of `UserFacingExceptionInventoryTest`, and it
 * exists because the failure it guards is silent. `BackupFamily::
 * tryFromType()` answers null for a type it does not know, and the purge
 * deliberately leaves such a row alone — the safe direction, since
 * deleting what you cannot classify costs somebody their only copy. The
 * cost of that safety is that a type added without a family is never
 * purged at all, and nothing at runtime says so: the table simply grows
 * until the disk fills. IT-06 adds `portable`, which is exactly the moment
 * this would have happened.
 *
 * The schema is read as text rather than queried, so this runs without a
 * database — a check that only runs where MySQL is available is a check
 * that does not run on a contributor's laptop.
 */
final class BackupFamilyCoverageTest extends TestCase
{
    public function testEveryTypeTheSchemaShipsHasAFamily(): void
    {
        foreach ($this->schemaTypes() as $type) {
            $this->assertNotNull(
                BackupFamily::tryFromType($type),
                "Backup type '{$type}' belongs to no family, so nothing will ever purge it. "
                    . 'Add it to BackupFamily::tryFromType() and to docs/exigences-non-fonctionnelles.md §4bis.'
            );
        }
    }

    /**
     * `Backup::TYPES` is what the application validates against; the enum
     * in `schema/core.sql` is what the database accepts. Two lists that
     * drift give a type the code refuses and the column allows, or the
     * reverse — and the reverse is a row nobody can ever write.
     */
    public function testTheSchemaAndTheApplicationListTheSameTypes(): void
    {
        $schema = $this->schemaTypes();
        sort($schema);
        $application = Backup::TYPES;
        sort($application);

        $this->assertSame($application, $schema);
    }

    public function testEveryTypeIsSpelledForTheScreen(): void
    {
        foreach ($this->schemaTypes() as $type) {
            $this->assertNotSame(
                $type,
                Backup::typeLabel($type),
                "Type '{$type}' would be shown as-is, in English, in the backup list."
            );
        }
    }

    /** Every gallery-bearing type must be a type. */
    public function testTheGalleryTypesAreRealTypes(): void
    {
        foreach (Backup::GALLERY_TYPES as $type) {
            $this->assertContains($type, $this->schemaTypes());
        }
    }

    /**
     * @return string[]
     */
    private function schemaTypes(): array
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 3) . '/schema/core.sql');
        $found = preg_match(
            '/CREATE TABLE backups \(.*?\n\s*type ENUM\(([^)]*)\)/s',
            $schema,
            $matches
        );
        $this->assertSame(1, $found, 'The backups.type column was not found in schema/core.sql.');

        preg_match_all("/'([a-z_]+)'/", $matches[1], $types);

        return $types[1];
    }
}
