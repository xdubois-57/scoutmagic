<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\SectionService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;

/**
 * One discussion group per visible, active section per scout year,
 * created automatically.
 *
 * **Idempotent and self-healing**, deliberately, and modelled on
 * Core\Badge\BadgeService::syncSectionReferentBadges(): it asks what
 * already exists and creates only what is missing, so running it twice,
 * a hundred times, or halfway through a failure is indistinguishable from
 * running it once. That is what lets it be called from two places — the
 * daily Task\EnsureSectionGroupsHandler AND the module's own page load —
 * without either having to know about the other.
 *
 * **Why no core hook into the Desk import.** A group has to appear when a
 * new year's members are imported, and the obvious way would be a
 * callback from the import. This module deliberately does not add one:
 * core would then carry a reference to a module (backwards, and dead
 * weight when the module is disabled), and an import that failed halfway
 * would leave the groups half-created with nothing to notice it. Asking
 * "which sections have no group yet" at page load and once a day is
 * cheap, needs no hook, self-heals after any failure, and — this is the
 * part a hook could not do — also picks up a section made visible, or a
 * year imported, long after the fact.
 *
 * **A section going hidden never deletes anything.** The filter below
 * only decides what to CREATE. Hiding a section stops new groups being
 * made for it; the conversations that already happened in the existing
 * one are not the site's to throw away. Deletion is the retention
 * purge's job and nobody else's (Service\GroupLifecycleService).
 */
class SectionGroupSyncService
{
    public function __construct(
        private SectionService $sectionService,
        private GroupRepository $groupRepository,
        private GroupSectionRepository $sectionRepository
    ) {
    }

    /**
     * Ensures every eligible section has its group for $scoutYearId.
     *
     * @return int how many groups were created — 0 on every run after the
     *         first, which is what "idempotent" means here
     */
    public function sync(int $scoutYearId): int
    {
        $created = 0;

        // includeHidden: false is the filter itself — getAllWithBranches()
        // already drops inactive sections in its WHERE clause, and passing
        // false drops hidden ones too. Staff d'U is NOT excluded: it is a
        // real section with real members who need a group like any other
        // (unlike Core\Badge\BadgeService, which skips it because a
        // "Référent Staff d'U" badge would be meaningless).
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionId = (int) $section['id'];
            if ($this->groupRepository->findSectionGroup($sectionId, $scoutYearId) !== null) {
                continue;
            }

            $this->create($sectionId, $this->nameFor($section), $scoutYearId);
            $created++;
        }

        return $created;
    }

    /**
     * Created with no creator and therefore no moderator row — see
     * schema.sql on discussion_groups.created_by_member_id for why naming
     * an arbitrary member would be worse than naming none. Until a chief
     * grants the flag, the group's moderators are the site admins, which
     * Service\GroupAccessService::canModerate() already accepts.
     *
     * The two writes are the same pair Service\GroupService::
     * createSectionGroup() performs, minus that moderator row; they are
     * repeated here rather than reached through a nullable parameter on
     * that method, because "a chief created this group" and "the site
     * created this group" are genuinely different acts and reading either
     * call site should say which one it is.
     */
    private function create(int $sectionId, string $name, int $scoutYearId): void
    {
        $groupId = $this->groupRepository->create($name, $scoutYearId, $sectionId, null);
        $this->sectionRepository->add($groupId, $sectionId);
    }

    /**
     * @param array<string, mixed> $section
     */
    private function nameFor(array $section): string
    {
        $name = isset($section['name']) && is_string($section['name']) ? trim($section['name']) : '';

        // desk_code is NOT NULL, so a section with no display name still
        // gets an identifiable group rather than an empty one.
        return $name !== '' ? $name : (string) $section['desk_code'];
    }
}
