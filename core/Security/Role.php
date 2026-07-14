<?php

declare(strict_types=1);

namespace Core\Security;

enum Role: string
{
    case PUBLIC = 'public';
    case IDENTIFIED = 'identified';
    case INTENDANT = 'intendant';
    case CHIEF = 'chief';
    // ADMIN is displayed as "Chef d'Unité"; SUPERADMIN is the top site administrator.
    case ADMIN = 'admin';
    case SUPERADMIN = 'superadmin';

    /**
     * Numeric level for comparison. Higher = more access.
     */
    public function level(): int
    {
        return match ($this) {
            self::PUBLIC => 0,
            self::IDENTIFIED => 1,
            self::INTENDANT => 2,
            self::CHIEF => 3,
            self::ADMIN => 4,
            self::SUPERADMIN => 5,
        };
    }

    /**
     * Check if this role has at least the access of $required.
     */
    public function hasAccess(Role $required): bool
    {
        return $this->level() >= $required->level();
    }

    /**
     * Get Role from string. Returns PUBLIC if unknown.
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::PUBLIC;
    }
}
