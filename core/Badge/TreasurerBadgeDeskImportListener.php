<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

use Core\Import\DeskImportListener;
use Core\Journal\JournalService;

/**
 * Says, once per Desk import, that somebody's Trésorier badge has stopped
 * meaning anything (issue #222).
 *
 * ## The half a refusal cannot cover
 *
 * `BadgeService::toggleAssignment()` refuses the badge to a person whose
 * function does not animate a section, because the badge grants exactly
 * one thing — the finance account of a section its holder animates — and
 * granting nothing while saying « attribué » is the bug. That closes the
 * door somebody walks through deliberately.
 *
 * An import walks in through the other one. An animateur who carried the
 * badge in September appears as `Intendant` in the next Desk export: the
 * assignment is untouched, still valid, still displayed — and from that
 * moment it grants nothing at all. Nobody opens a ticket about a
 * permission that used to work and now silently does not, so this is
 * written down at the only moment the change is visible.
 *
 * ## One entry, and no name in it
 *
 * One line per import carrying a COUNT and the member_year ids, never one
 * line per person and never a name — same rule as Modules\Leadership\
 * Service\LeadershipDeskImportListener, whose docblock states it at
 * length. The ids are what makes the line actionable on the Staffs page;
 * who they are belongs to that page, for whoever may see it.
 *
 * An import that strands nobody writes nothing at all.
 *
 * ## Inside the import transaction
 *
 * `DeskImportListener` runs before the commit and a throw rolls the whole
 * import back, so this does bounded reads and at most one insert — no
 * mail, no HTTP, nothing that cannot be replayed.
 */
class TreasurerBadgeDeskImportListener implements DeskImportListener
{
    public function __construct(
        private BadgeService $badgeService,
        private JournalService $journal
    ) {
    }

    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void
    {
        $stranded = $this->badgeService->findStrandedTreasurerMemberYearIds($scoutYearId);
        if ($stranded === []) {
            return;
        }

        $this->journal->log(
            'core',
            'treasurer_badge_stranded',
            'warning',
            sprintf(
                'Après l\'import, %d personne(s) portent le badge « Trésorier » sans animer de section : '
                . 'leur badge ne donne plus accès à aucun compte de section.',
                count($stranded)
            ),
            ['scout_year_id' => $scoutYearId, 'member_year_ids' => $stranded, 'count' => count($stranded)],
            null
        );
    }
}
