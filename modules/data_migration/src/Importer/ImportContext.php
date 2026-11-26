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
        private \Closure $scoutYearResolver,
        /**
         * True while the run executes, false during the analysis. An import
         * whose {@see Importer::inspect()} can only decide by reading
         * something slow — a remote page — does so at the execution only,
         * and keeps the analysis fast. Last and defaulted so that adding it
         * moved no existing argument.
         */
        public readonly bool $executing = false,
        /**
         * Where this slice's working copies go (absolute; issue #1259):
         * under `storage/temp/data_migration/`, emptied at the start and the
         * end of every slice and swept by the purges — never the system's
         * temporary directory, which a request killed before its `finally`
         * would leave them in for good. Null only where no slice runs (a
         * test of an import's reading alone).
         */
        public readonly ?string $copiesDirectory = null
    ) {
    }

    /**
     * A new empty file (0600) in {@see $copiesDirectory}, for an import that
     * has to hand a target module a path rather than bytes. The import
     * still deletes it once used; the slice's cleanup is for a request that
     * died before it could.
     *
     * @throws \RuntimeException when the file cannot be created there
     */
    public function temporaryFile(string $prefix): string
    {
        $directory = $this->copiesDirectory
            ?? throw new \LogicException('This import context has no working directory.');

        return \Core\File\TemporaryFile::createIn($directory, $prefix);
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
