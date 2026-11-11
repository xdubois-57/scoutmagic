<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Modules\DataMigration\Importer\ImporterRegistry;
use Modules\DataMigration\Importer\SourceItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestImporter;

/**
 * The one filter every surface goes through: an import whose module is
 * off does not exist — not on the home page, not at its address.
 */
#[Group('database')]
final class ImporterRegistryTest extends TestCase
{
    public function testAnImportExistsOnlyWhileItsModuleIsEnabled(): void
    {
        $enabled = ['gallery' => false];
        $registry = new ImporterRegistry(static function (string $module) use (&$enabled): bool {
            return $enabled[$module] ?? false;
        });
        $pdo = new \PDO('sqlite::memory:');
        $registry->add(new TestImporter($pdo, 'coeur'));
        $registry->add(new TestImporter($pdo, 'galeries', 'gallery'));

        $this->assertNotNull($registry->find('coeur'));
        $this->assertNull($registry->find('galeries'));
        $this->assertNull($registry->find('inconnu'));
        $this->assertSame(['coeur'], array_map(static fn ($i): string => $i->key(), $registry->available()));

        $enabled['gallery'] = true;
        $this->assertNotNull($registry->find('galeries'));
        $this->assertCount(2, $registry->available());
    }

    public function testTwoImportsCannotShareAKey(): void
    {
        $registry = new ImporterRegistry(static fn (string $module): bool => true);
        $pdo = new \PDO('sqlite::memory:');
        $registry->add(new TestImporter($pdo, 'photos'));

        $this->expectException(\LogicException::class);
        $registry->add(new TestImporter($pdo, 'photos'));
    }

    public function testAKeyIsAnAddressSegment(): void
    {
        $registry = new ImporterRegistry(static fn (string $module): bool => true);

        $this->expectException(\InvalidArgumentException::class);
        $registry->add(new TestImporter(new \PDO('sqlite::memory:'), '../photos'));
    }

    public function testASourceIdentifierIsAStringAndNeverANumber(): void
    {
        $this->assertSame('007', SourceItem::idFrom('007'));
        $this->assertNull(SourceItem::idFrom(7));
        $this->assertNull(SourceItem::idFrom(''));
        $this->assertNull(SourceItem::idFrom(str_repeat('x', 191)));
    }
}
