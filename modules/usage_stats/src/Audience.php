<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats;

use Core\Security\Role;

/**
 * Who was looking, at the coarsest resolution that still answers a real
 * question — and no finer, ever.
 *
 * Three buckets rather than six roles, because the six exist to decide
 * access and these exist to be read on a screen: « 51 comptes ont ouvert
 * les actualités » and « 3 comptes ont ouvert les cotisations » are the
 * same sentence about two different audiences, and telling an intendant
 * from a chief would add a column nobody would act on.
 *
 * The value stored is the case's lower-case name; the French label is what
 * a filter shows.
 */
enum Audience: string
{
    /** Nobody was logged in. */
    case Anonymous = 'anonymous';

    /** A member's household — the ordinary logged-in visitor. */
    case Identified = 'identified';

    /** Anyone the site trusts beyond their own family: intendant and up. */
    case Staff = 'staff';

    public static function forRole(Role $role): self
    {
        return match (true) {
            $role->hasAccess(Role::INTENDANT) => self::Staff,
            $role->hasAccess(Role::IDENTIFIED) => self::Identified,
            default => self::Anonymous,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Anonymous => 'Visiteurs anonymes',
            self::Identified => 'Membres identifiés',
            self::Staff => 'Staff',
        };
    }

    /**
     * The label a one-word filter tab carries — « Anonymes », not
     * « Visiteurs anonymes », which would not fit a rail of four tabs on
     * a phone.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Anonymous => 'Anonymes',
            self::Identified => 'Identifiés',
            self::Staff => 'Staff',
        };
    }
}
