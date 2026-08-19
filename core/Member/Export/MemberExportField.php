<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

use Core\Security\Role;

/**
 * One canonical export column: a French header, the minimum role allowed
 * to see it (never higher than the exporting page's own role_min, but a
 * column can require MORE — e.g. health data — so an intendant-level page
 * can still export a strictly smaller column set than a chief/admin would
 * get from the very same mechanism), a value type (for Excel formatting),
 * and how to read the value out of a MemberExportRow.
 */
final class MemberExportField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_MULTI = 'multi';
    public const TYPE_DATE = 'date';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';

    /**
     * @param \Closure(MemberExportRow): (string|int|float|bool|string[]|null) $resolver
     */
    public function __construct(
        public readonly string $key,
        public readonly string $header,
        public readonly Role $minRole,
        public readonly string $type,
        public readonly \Closure $resolver
    ) {
    }
}
