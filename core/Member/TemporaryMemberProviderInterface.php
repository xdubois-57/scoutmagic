<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * Resolves the "temporary member" override for a given account, without
 * exposing the session to the service layer.
 *
 * MemberService is a Service: it must never read $_SESSION (AGENTS.md). It
 * therefore asks this collaborator, and the one session-aware implementation
 * (SessionTemporaryMemberProvider) is wired in the composition root. Every
 * other construction of MemberService — scheduled tasks, module factories,
 * the whole test suite — passes nothing and gets the null behavior.
 *
 * The parameter is an email rather than a bare getter on purpose: an
 * implementation must answer "is there a temporary member on THIS account's
 * list", never "is there a temporary member anywhere". Several callers pass
 * an email that is not necessarily the session's own, and a temporary member
 * must never leak into another account's computed list.
 */
interface TemporaryMemberProviderInterface
{
    /**
     * The member_years.id temporarily added to $email's list of animés for
     * the current session, or null when there is none (or when $email is
     * not the account that added it).
     */
    public function resolveMemberYearId(string $email): ?int;
}
