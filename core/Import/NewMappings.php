<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * What one import had to create because this installation had never seen
 * it: a Desk function, a section, an age branch, a fee category.
 *
 * Kept separately from the roster snapshots because it cannot be derived
 * from them. Two snapshots say who moved where; neither says that
 * "Baladins 3" is a section nobody had ever heard of before today —
 * `MappingResolver` is the only thing that knows, because it is what
 * created the row.
 *
 * The functions are the ones that matter. A new Desk function is always
 * imported at the lowest role, `identified`, deliberately (SECURITY.md
 * §3: an import never elevates anybody). Until somebody qualifies it on
 * Config Desk, whoever holds it sees no more than an ordinary member —
 * which is the single most common cause of "I can't see anything any
 * more" after an import. The report has to name them as *to qualify*,
 * never list them as a neutral addition.
 */
final class NewMappings
{
    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $branchIds
     * @param int[] $feeCategoryIds
     */
    public function __construct(
        public readonly array $functionIds = [],
        public readonly array $sectionIds = [],
        public readonly array $branchIds = [],
        public readonly array $feeCategoryIds = []
    ) {
    }
}
