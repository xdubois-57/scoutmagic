<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Journal\JournalService;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Value\HealthSheet;

/**
 * What happens to a health sheet, as opposed to how it is stored.
 *
 * Thin on purpose: the Repository owns the cipher and the JSON, the
 * controller owns the screen, and what is left here is the one thing
 * neither of them should decide — **what is allowed to be written down
 * about this.**
 *
 * The rule, from the chantier and not negotiable: **no health data in the
 * journal.** Not a field, not a count of fields, not « the allergies
 * section was filled in ». A journal entry here carries the member's
 * numeric id and nothing else, because that is the most that can be said
 * without saying something about a child's health. The same goes for every
 * exception and every message this class could produce — which is why it
 * produces none.
 *
 * Why journal at all, then: « Tout effacer » destroys data irreversibly,
 * and a site that cannot say *that it happened* cannot answer a family
 * asking why their sheet is gone. The id alone answers that.
 */
class HealthSheetService
{
    private const JOURNAL_CATEGORY = 'official_documents';

    public function __construct(
        private readonly HealthSheetRepository $repository,
        private readonly ?JournalService $journal = null
    ) {
    }

    /**
     * This member's sheet — an empty one when they have none, so the screen
     * has a form to draw either way.
     */
    public function forMember(int $memberId): HealthSheet
    {
        return $this->repository->findForMember($memberId) ?? HealthSheet::empty();
    }

    /**
     * Whether this member has a stored sheet at all, and when it was last
     * used — what the member page needs to say « commencée le 12 mars »
     * beside a link, without reading a single answer.
     */
    public function lastUsedAt(int $memberId): ?\DateTimeImmutable
    {
        return $this->repository->lastUsedAt($memberId);
    }

    /**
     * Save what the family typed.
     *
     * Not journaled. An ordinary save is not an event anybody needs an
     * audit trail for, and the only interesting thing to say about it
     * would be what changed — which is precisely what must never be
     * written down.
     */
    public function save(int $memberId, HealthSheet $sheet, \DateTimeImmutable $now): void
    {
        $this->repository->save($memberId, $sheet, $now);
    }

    /**
     * « Tout effacer » — the family asking the site to stop holding their
     * child's health data.
     *
     * Journaled with the member id alone, and only when something was
     * actually removed: an entry for a sheet that was not there would say
     * « this family cleared their data » about a family that had none.
     */
    public function clear(int $memberId, ?int $userAccountId = null): bool
    {
        $removed = $this->repository->delete($memberId);

        if ($removed) {
            $this->journal?->log(
                self::JOURNAL_CATEGORY,
                'health_sheet_cleared',
                'info',
                'Fiche santé effacée par la famille',
                // The id, and nothing else. Never a field name, never a
                // count of what was in it.
                ['member_id' => $memberId],
                $userAccountId
            );
        }

        return $removed;
    }

    /**
     * The retention purge: forget every sheet no family has touched since
     * the cutoff.
     *
     * Journaled one entry per member, carrying **the member id and nothing
     * else** — the same shape as `clear()`, and for the same reason. A
     * family asking why their child's sheet is empty deserves an answer,
     * and « la conservation l'a effacée le 3 mars » is one; anything about
     * what the sheet contained would be the purge writing down the health
     * data it exists to destroy.
     *
     * Silent towards the family, per the chantier: no notification, no
     * warning beforehand. The journal is the site's own record, not a
     * message to anybody.
     *
     * @return list<int> the member ids whose sheets were removed
     */
    public function purgeUnusedSince(\DateTimeImmutable $cutoff, int $retentionMonths): array
    {
        $memberIds = $this->repository->deleteUnusedSince($cutoff);

        foreach ($memberIds as $memberId) {
            $this->journal?->log(
                self::JOURNAL_CATEGORY,
                'health_sheet_purged',
                'info',
                'Fiche santé effacée par la conservation après ' . $retentionMonths . ' mois sans usage',
                // The id, and nothing else. Never a field name, never a
                // count of what was in it, never a date of birth.
                ['member_id' => $memberId],
                null
            );
        }

        return $memberIds;
    }

    /**
     * Say that this sheet is still in use — called when a document is
     * generated from it (IT-04), which postpones the retention purge
     * exactly as retyping it would.
     */
    public function markUsed(int $memberId, \DateTimeImmutable $now): void
    {
        $this->repository->touch($memberId, $now);
    }
}
