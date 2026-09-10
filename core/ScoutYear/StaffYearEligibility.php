<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

use Core\Security\Role;
use Core\Security\RoleResolver;

/**
 * Answers, for one address and one scout year, whether that address
 * reaches `intendant` **resolved in that year** — which is what
 * ScoutYearResolver asks before serving the staff year to anybody.
 *
 * It exists as a class rather than as a closure in the composition root
 * for one reason, and it is a bug that was written and then found:
 *
 * **The memo has to be keyed on the ADDRESS as well as the year.** A
 * request resolves the effective year once for the front controller,
 * long before routing — at which point a login request is still
 * anonymous — and then again inside the controller, after
 * `AuthSession::login()` has run. A cache keyed on the year alone
 * answers the second question with the first one's answer: the
 * animateur recruited for the year being prepared signs in, and the
 * session records their linked members against the public year, where
 * they have none. The identity changes mid-request, so the identity is
 * part of the key.
 *
 * The memo itself is not optional. `getEffectiveYear()` is called once
 * per controller that needs it — a dozen times on some pages — and each
 * answer costs a role resolution, which is several queries.
 */
class StaffYearEligibility
{
    /** @var array<string, bool> keyed on "<address>|<scout year id>" */
    private array $memo = [];

    public function __construct(private RoleResolver $roleResolver)
    {
    }

    /**
     * An empty address is nobody, and nobody is served the staff year —
     * an anonymous visitor belongs on the public year, which is where
     * `false` leaves them.
     */
    public function isEligible(?string $email, int $staffYearId): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        return $this->memo[$email . '|' . $staffYearId]
            ??= Role::fromString($this->roleResolver->resolve($email, $staffYearId))->hasAccess(Role::INTENDANT);
    }
}
