<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * What an import knows about the run it is working for.
 */
final class ImportContext
{
    /**
     * @param \Closure(string): ?int $scoutYearResolver
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $installationId,
        public readonly string $format,
        public readonly ?int $userAccountId,
        public readonly ImportSource $source,
        private \Closure $scoutYearResolver
    ) {
    }

    /**
     * The id of a scout year by label, or null when this site does not
     * have it. During the execution a year the administrator chose to
     * create (D5) has been created already; one they did not choose is
     * null, and the module ignores the item before {@see Importer::write()}
     * is ever called.
     */
    public function scoutYearId(string $label): ?int
    {
        return ($this->scoutYearResolver)($label);
    }
}
