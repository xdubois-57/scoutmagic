<?php

declare(strict_types=1);

namespace Modules\Calendar\Repository;

class Calendar
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_CHIEF = 'chief';
    public const VISIBILITY_ADMIN = 'admin';

    public function __construct(
        public readonly int $id,
        public readonly ?int $sectionId,
        public readonly ?string $name,
        public readonly ?string $color,
        public readonly bool $isDefault,
        public readonly string $visibility,
        public readonly ?string $icsToken
    ) {
    }

    public function isSectionCalendar(): bool
    {
        return $this->sectionId !== null;
    }
}
