<?php

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
