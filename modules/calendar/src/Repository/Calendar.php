<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Repository;

class Calendar
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_CHIEF = 'chief';
    public const VISIBILITY_ADMIN = 'admin';

    /**
     * The two halves of "who may touch this calendar", deliberately apart:
     * $visibility answers who SEES it, $editRoleMin who WRITES in it. One
     * column used to answer both, which made "visible by the animateurs,
     * editable by the chefs d'unité" impossible to express.
     */
    public const EDIT_ROLE_CHIEF = 'chief';
    public const EDIT_ROLE_ADMIN = 'admin';

    public function __construct(
        public readonly int $id,
        public readonly ?int $sectionId,
        public readonly ?string $name,
        public readonly ?string $color,
        public readonly bool $isDefault,
        public readonly string $visibility,
        public readonly string $editRoleMin,
        public readonly ?string $icsToken
    ) {
    }

    public function isSectionCalendar(): bool
    {
        return $this->sectionId !== null;
    }
}
