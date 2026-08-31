<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Journal\JournalService;

/**
 * Departure marking (ARCHITECTURE.md §8) — a chief/animateur flags a
 * member_year as "won't be back next scout year" while Desk still lists
 * them active this year. Feeds place/headcount projections (a future
 * module, out of scope here — this is a plain fact about the member, not
 * inscriptions-specific). Scoped to the marked row's own scout year: it
 * resets naturally on the next Desk import (a new member_years row,
 * leaving defaults to false), no purge task needed.
 *
 * Consumed by the registration module's "Départs" page (ARCHITECTURE.md
 * §8.37), which never touches member_years directly.
 */
class DepartureService
{
    public function __construct(
        private DepartureRepository $repository,
        private JournalService $journal
    ) {
    }

    public function markLeaving(int $memberYearId, ?string $comment, ?int $actingUserAccountId = null): void
    {
        $this->repository->markLeaving($memberYearId, $comment);
        $this->log('member_leaving_marked', 'Départ marqué pour un membre', $memberYearId, $actingUserAccountId);
    }

    public function unmarkLeaving(int $memberYearId, ?int $actingUserAccountId = null): void
    {
        $this->repository->unmarkLeaving($memberYearId);
        $this->log('member_leaving_unmarked', 'Marquage de départ retiré', $memberYearId, $actingUserAccountId);
    }

    /**
     * The departure flag as a family's own answer about next year sets
     * it — never a staff gesture (roadmap IT-16, spec §11.8).
     *
     * Two things separate it from markLeaving()/unmarkLeaving():
     *
     * - it writes the FLAG ONLY. The comment beside it is the staff's
     *   internal note, and a family's answer has no business erasing it;
     *   the family's own words live in the registration module's own
     *   table and never come here.
     * - it says so in the journal. `member_leaving_set_by_family` and
     *   its counterpart are what tells a chief reading the journal that
     *   a box moved without anybody on the staff touching it — with the
     *   member id and nothing else, like every other entry here
     *   (SECURITY.md §11).
     *
     * WHO decides when this is called at all is not core's business: the
     * "the staff has the last word" rule lives with the answers, in
     * Modules\Registration\Service\ReenrollmentDepartureService, which
     * is this method's only caller.
     */
    public function setLeavingFromFamilyAnswer(int $memberYearId, bool $leaving, ?int $actingUserAccountId = null): void
    {
        $this->repository->updateLeaving($memberYearId, $leaving);
        $this->log(
            $leaving ? 'member_leaving_set_by_family' : 'member_leaving_cleared_by_family',
            $leaving
                ? 'Départ marqué par la réponse de la famille'
                : 'Marquage de départ retiré par la réponse de la famille',
            $memberYearId,
            $actingUserAccountId
        );
    }

    public function getStatus(int $memberYearId): ?DepartureStatus
    {
        return $this->repository->getStatus($memberYearId);
    }

    /**
     * Comment-only update — see DepartureRepository::updateComment()'s own
     * docblock for why this stays a separate write from markLeaving().
     * Never journaled, same rule as markLeaving()/unmarkLeaving() never
     * logging the comment itself: a content-only edit isn't a state
     * transition worth an event of its own.
     */
    public function updateComment(int $memberYearId, ?string $comment): void
    {
        $this->repository->updateComment($memberYearId, $comment);
    }

    /**
     * Never the comment, never anything beyond the member_id reference —
     * SECURITY.md §11.
     */
    private function log(string $type, string $description, int $memberYearId, ?int $actingUserAccountId): void
    {
        $memberId = $this->repository->findMemberId($memberYearId);
        $this->journal->log(
            'core',
            $type,
            'info',
            $description,
            $memberId !== null ? ['member_id' => $memberId] : [],
            $actingUserAccountId
        );
    }
}
