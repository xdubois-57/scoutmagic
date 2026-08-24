<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * The per-request answer to TreasurerScopeService's question, so that
 * FinanceService can ask it without going looking for the session itself
 * (ARCHITECTURE.md §13: a Service never reads $_SESSION).
 *
 * It exists because the two halves of the question live in different
 * places. The RULE is pure and reusable — it takes member ids and a scout
 * year, and a file-ownership checker calls it with the ids
 * Core\File\FileOwnershipCheckerInterface hands it. The ANSWER for the
 * current request needs data only the composition root has: which members
 * this login resolves to, and which scout year is in effect. Passing that
 * pair into every finance service and controller would mean every call
 * site could forget it; passing this object once means none of them can.
 *
 * Resolution is lazy and memoized. Finance is enabled on installations
 * whose visitors mostly never open a finance page, and the rule costs two
 * queries; deciding it eagerly in public/index.php would charge every
 * page load for a question most of them never ask. Memoized because a
 * single dashboard render asks several times (the account picker, the
 * selected account, each write route) and the answer cannot change
 * mid-request.
 */
class TreasurerScope
{
    private bool $resolved = false;

    /** @var int[]|null */
    private ?array $sectionIds = null;

    /**
     * @param int[] $linkedMemberIds persistent members.id values the session is linked to
     */
    private function __construct(
        private ?TreasurerScopeService $rule,
        private array $linkedMemberIds,
        private int $scoutYearId
    ) {
    }

    /**
     * The normal case: a request made by somebody, narrowed against the
     * members that somebody resolves to.
     *
     * @param int[] $linkedMemberIds
     */
    public static function forSession(TreasurerScopeService $rule, array $linkedMemberIds, int $scoutYearId): self
    {
        return new self($rule, $linkedMemberIds, $scoutYearId);
    }

    /**
     * A caller with no session to narrow against — a seeder, a scheduled
     * task, a CLI fixture: something acting for the installation rather
     * than for a person. Same shape as the null viewer role the calendar's
     * event service accepts for its system caller.
     *
     * Deliberately "rule disabled" rather than "no section": such a caller
     * is not a treasurer of nothing, it is outside the question entirely,
     * and answering [] would quietly deny it every section account it
     * legitimately maintains. It is not reachable from a request — nothing
     * routes through it — so this cannot become a way for a person to
     * escape the rule.
     */
    public static function systemCaller(): self
    {
        return new self(null, [], 0);
    }

    /**
     * @return int[]|null section ids, or null when the rule is disabled —
     *                    see TreasurerScopeService::getTreasurerSectionIds()
     */
    public function sectionIds(): ?array
    {
        if (!$this->resolved) {
            $this->sectionIds = $this->rule?->getTreasurerSectionIds($this->linkedMemberIds, $this->scoutYearId);
            $this->resolved = true;
        }

        return $this->sectionIds;
    }
}
