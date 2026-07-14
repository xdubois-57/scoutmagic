<?php

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Immutable value object describing the scout year in effect for the current
 * request, and whether/how it was overridden from the public current year.
 */
class EffectiveScoutYear
{
    /**
     * @param string|null $overrideType 'session', 'staff', or null (no override)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly ?string $overrideType
    ) {
    }

    public function isOverridden(): bool
    {
        return $this->overrideType !== null;
    }
}
