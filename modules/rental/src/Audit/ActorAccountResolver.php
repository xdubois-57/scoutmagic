<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Audit;

use Core\Config\ScoutYearService;
use Core\Member\MemberService;
use Core\Security\UserAccountRepository;

/**
 * The one place this module turns "which member did this" into "which
 * account did this".
 *
 * The module's own vocabulary is the member: a comment's author, a
 * booking's manager and a compliance entry's owner are all member ids,
 * because that is what a unit's roster is keyed on. `Core\Audit` speaks
 * accounts, because an account is what a name and a login belong to and
 * what survives a member leaving the unit. The bridge between the two is
 * the address they share.
 *
 * **Null is a normal answer**, not a failure: a manager who has never
 * logged in has no account, and a member whose Desk address differs from
 * the one they log in with resolves to nothing. The timeline then shows
 * the change without a name, which is honest — the site genuinely cannot
 * say who — and `BookingAudit` still records it as a human change so the
 * reader is not told the application did it on its own.
 *
 * Cached per request: a booking page reads its whole history in one go,
 * and forty entries by the same manager must not be forty lookups.
 */
final class ActorAccountResolver
{
    /** @var array<int, int|null> */
    private array $cache = [];

    public function __construct(
        private MemberService $members,
        private UserAccountRepository $accounts,
        private ScoutYearService $scoutYears
    ) {
    }

    public function accountIdFor(?int $memberId): ?int
    {
        if ($memberId === null) {
            return null;
        }

        if (array_key_exists($memberId, $this->cache)) {
            return $this->cache[$memberId];
        }

        $scoutYearId = (int) $this->scoutYears->getCurrentYear()['id'];
        $email = $this->members->findEmailsByMemberIds([$memberId], $scoutYearId)[$memberId] ?? null;
        $account = $email !== null && $email !== '' ? $this->accounts->findByEmail($email) : null;

        return $this->cache[$memberId] = $account?->id;
    }
}
