<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * The reference data the host security check compares against — one
 * method per kind of source, so each can be replaced on its own
 * ({@see ComponentFamily} says which family reads which).
 *
 * Implemented over HTTP by {@see HttpReferenceSource}; the tests hand the
 * service synthetic answers, and no test ever reaches a live bulletin.
 */
interface ReferenceSource
{
    /**
     * Every PHP branch php.net publishes.
     *
     * @return list<ReleaseBranch>
     * @throws ReferenceSourceUnavailable
     */
    public function phpBranches(): array;

    /**
     * Every release line endoflife.date publishes for one product.
     *
     * @return list<ReleaseBranch>
     * @throws ReferenceSourceUnavailable
     */
    public function endOfLifeBranches(string $product): array;

    /**
     * The advisories the NVD considers applicable to one exact CPE name.
     *
     * @return list<Advisory>
     * @throws ReferenceSourceUnavailable
     */
    public function advisories(string $cpeName): array;
}
