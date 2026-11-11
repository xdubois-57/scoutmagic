<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * The imports this installation knows, and which of them exist right now.
 *
 * **One line per import, in the composition root** (`public/index.php`,
 * the data_migration block):
 *
 *     $dataMigrationImporters->add(new PhotosImporter(…));
 *
 * Every import is registered whatever its module's state; {@see find()} and
 * {@see available()} filter on {@see Importer::requiredModule()} at the
 * moment they are asked. That single filter is what the home page, the
 * import's address, its AJAX endpoints and every slice all go through, so
 * an import whose module is off does not exist anywhere — 404, the same
 * answer as an import that was never written.
 */
final class ImporterRegistry
{
    /** @var array<string, Importer> */
    private array $importers = [];

    /**
     * @param \Closure(string): bool $isModuleEnabled
     */
    public function __construct(private \Closure $isModuleEnabled)
    {
    }

    public function add(Importer $importer): void
    {
        $key = $importer->key();
        if (preg_match('/^[a-z0-9-]{1,50}$/', $key) !== 1) {
            throw new \InvalidArgumentException('An importer key is 1 to 50 of a-z, 0-9 and -.');
        }
        if (isset($this->importers[$key])) {
            throw new \LogicException('Two importers share the key ' . $key . '.');
        }
        if (($importer->archiveFormat() === null) !== ($importer instanceof FormSourceImporter)) {
            throw new \LogicException('Importer ' . $key . ' must have an archive format or a form source, not both.');
        }

        $this->importers[$key] = $importer;
    }

    /** The import at this key, or null when it does not exist or its module is off. */
    public function find(string $key): ?Importer
    {
        $importer = $this->importers[$key] ?? null;

        return $importer !== null && $this->isAvailable($importer) ? $importer : null;
    }

    /**
     * The imports offered right now, in registration order.
     *
     * @return list<Importer>
     */
    public function available(): array
    {
        return array_values(array_filter($this->importers, fn (Importer $importer): bool => $this->isAvailable($importer)));
    }

    private function isAvailable(Importer $importer): bool
    {
        $module = $importer->requiredModule();

        return $module === null || ($this->isModuleEnabled)($module);
    }
}
