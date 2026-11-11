<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * What {@see FormSourceImporter::acceptSource()} made of a form.
 *
 * `$installationId` stands for the source in the deduplication key (an
 * ICS import uses a hash of the address); `$parameters` is kept encrypted
 * with the run and handed back through {@see ImportSource::$parameters};
 * `$filePath`, when the import fetched something, lies inside the
 * workspace it was given and is purged with the run.
 */
final class AcceptedSource
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $installationId,
        public readonly string $format,
        public readonly array $parameters = [],
        public readonly ?string $filePath = null,
        public readonly ?string $label = null
    ) {
    }
}
