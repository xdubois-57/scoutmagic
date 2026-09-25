<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Service;

/**
 * One line of Configuration > Supervision > Correspondances (issue #356):
 * a Desk value that reporting installations carry and that this code does
 * not recognise.
 *
 * Almost everything here is derived from the reports on hand. Only
 * `id`, `firstSeenAt` and `ignored` come from the stored table, because
 * only those three cannot be recomputed (D6).
 */
final class DeskMappingGapRow
{
    /**
     * @param string      $kind           `Core\Import\DeskMappingGapKind`'s value
     * @param string      $valueRaw       the first spelling seen, shown as-is
     * @param int         $installations  how many installations report it right now
     * @param list<string> $instances     which ones, by host — the payload's own
     *                                    `instance_url`, never anything about a person
     * @param ?string     $oldestVersion  the oldest ScoutMagic still reporting it,
     *                                    which is what says whether a fix has shipped
     *                                    and simply not been installed yet
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $kind,
        public readonly string $valueRaw,
        public readonly int $installations,
        public readonly array $instances,
        public readonly ?string $firstSeenAt,
        public readonly ?string $lastSeenAt,
        public readonly ?string $oldestVersion,
        public readonly bool $ignored
    ) {
    }
}
