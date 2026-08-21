<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

/**
 * One diagnostic gathered into the support package (ARCHITECTURE.md §8.42).
 *
 * A collector writes whatever it can into the context and returns. It never
 * decides whether the package is produced: Core\Support\SupportPackageService
 * runs each one inside its own try/catch, so throwing is a perfectly
 * acceptable way to report a failure — the archive is still produced with
 * everything else that worked.
 */
interface SupportCollectorInterface
{
    /**
     * Technical identifier, e.g. 'statistics'. Appears in
     * `collection-status.json` and nowhere a user reads.
     */
    public function name(): string;

    public function collect(SupportCollectorContext $context): void;
}
