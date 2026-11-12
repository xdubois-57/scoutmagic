<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * An import whose {@see Importer::inspect()} is slow while the run
 * executes — it reads a remote page — and that asks the module to inspect
 * no more than a few items per execution slice.
 *
 * The slice's five-second deadline is checked between two items only, so
 * it cannot interrupt one: an inspection of several seconds started just
 * before the deadline would carry the request well past it. Capping the
 * number of inspections bounds a slice by what one item can take, which
 * the import knows and the module does not.
 *
 * Only the inspections count: an item the correspondence table already
 * knows, or one that cannot be read, costs nothing and does not use the
 * allowance. The analysis is not capped — {@see ImportContext::$executing}
 * is false there, and an import implementing this keeps its analysis free
 * of anything slow.
 *
 * Optional, and separate from {@see Importer} so that adding it changed no
 * existing import.
 */
interface ThrottledImporter extends Importer
{
    /** At least 1: how many items one execution slice may inspect. */
    public function inspectionsPerExecutionSlice(): int;
}
