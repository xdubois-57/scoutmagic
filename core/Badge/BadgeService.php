<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;

/**
 * Badges are transversal roles assignable to chiefs/chief-d'unité (e.g.
 * Infirmier, Trésorier) — a global concept configured once (Configuration
 * générale), with assignment scoped per member per scout year (via
 * member_year_id, see member_badges).
 */
class BadgeService
{
    /**
     * The two seeded badges, by the exact name they carry in the `badges`
     * table. Public because a consumer that has to FIND one of them has no
     * other handle: a default badge cannot be renamed or deleted (only
     * deactivated), so its name is a stable key — but a second spelling of
     * it somewhere else would silently stop matching. Today's consumer is
     * Modules\Finance\Service\TreasurerScopeService, which asks "who is a
     * treasurer this year".
     */
    public const BADGE_NURSE = 'Infirmier';
    public const BADGE_TREASURER = 'Trésorier';

    /** @var array<int, string> */
    private const DEFAULT_BADGES = [self::BADGE_NURSE, self::BADGE_TREASURER];
    private const REFERENT_PREFIX = 'Référent ';

    public function __construct(
        private BadgeRepository $badgeRepository,
        private MemberBadgeRepository $memberBadgeRepository,
        private SectionService $sectionService
    ) {
    }

    /**
     * Idempotent: seeds the default badges the first time they're missing.
     * Safe to call on every request (mirrors SettingService::register()).
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_BADGES as $name) {
            if ($this->badgeRepository->findByName($name) === null) {
                $this->badgeRepository->create($name, true);
            }
        }
    }

    /** @return Badge[] every badge, active or not — for the admin list */
    public function getAll(): array
    {
        return $this->badgeRepository->findAll();
    }

    /**
     * Idempotent: ensures exactly one "Référent {section}" badge exists for
     * every visible, non-Staff-d'U section (module spec: "the list is
     * created automatically based on the sections that are visible"),
     * renamed to match a section's current name. A badge is never created
     * for a section that's currently invisible, but once created its
     * active/inactive state is entirely manual from then on — same
     * "activatable/deactivatable like any other badge" behavior as
     * Infirmier/Trésorier — this never touches is_active on an existing
     * badge, so a section later losing visibility does not silently flip
     * its badge off. Safe to call on every relevant page load (mirrors
     * ensureDefaults()) and is also called directly after a section's
     * name/visibility changes for immediate effect.
     */
    public function syncSectionReferentBadges(): void
    {
        $badgesBySection = $this->badgeRepository->findAllByReferentSection();
        foreach ($this->sectionService->getAllWithBranches(includeHidden: true) as $section) {
            if ($section['desk_code'] === UnitStaffSectionService::DESK_CODE) {
                continue;
            }

            $badge = $badgesBySection[(int) $section['id']] ?? null;
            $expectedName = self::REFERENT_PREFIX . ($section['name'] ?? $section['desk_code']);

            if ($badge === null) {
                if ($section['is_visible']) {
                    $this->badgeRepository->create($expectedName, true, $section['id']);
                }
                continue;
            }

            if ($badge->name !== $expectedName) {
                $this->badgeRepository->update($badge->id, $expectedName);
            }
        }
    }

    /** @return Badge[] active badges only — for assignment pickers */
    public function getActive(): array
    {
        return array_values(array_filter($this->badgeRepository->findAll(), fn(Badge $b) => $b->isActive));
    }

    /**
     * @throws BadgeException on invalid name or a name collision
     */
    public function create(string $name): Badge
    {
        $name = trim($name);
        if ($name === '') {
            throw new BadgeException('Le nom du badge est obligatoire.');
        }
        if ($this->badgeRepository->findByName($name) !== null) {
            throw new BadgeException('Un badge avec ce nom existe déjà.');
        }

        $id = $this->badgeRepository->create($name, false);
        $badge = $this->badgeRepository->findById($id);
        \assert($badge !== null);
        return $badge;
    }

    /**
     * @throws BadgeException on invalid name, a name collision, an unknown
     *                        badge, or an attempt to rename a default badge
     */
    public function update(int $id, string $name): Badge
    {
        $badge = $this->badgeRepository->findById($id);
        if ($badge === null) {
            throw new BadgeException('Badge introuvable.');
        }
        if ($badge->isDefault) {
            throw new BadgeException('Un badge par défaut ne peut pas être renommé.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new BadgeException('Le nom du badge est obligatoire.');
        }
        $existing = $this->badgeRepository->findByName($name);
        if ($existing !== null && $existing->id !== $id) {
            throw new BadgeException('Un badge avec ce nom existe déjà.');
        }

        $this->badgeRepository->update($id, $name);
        $updated = $this->badgeRepository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws BadgeException when the badge doesn't exist
     */
    public function setActive(int $id, bool $active): void
    {
        if ($this->badgeRepository->findById($id) === null) {
            throw new BadgeException('Badge introuvable.');
        }
        $this->badgeRepository->setActive($id, $active);
    }

    /**
     * Delete a badge. Default badges and badges already assigned to at
     * least one member (even in a past year) can never be deleted — only
     * deactivated, to preserve historical data.
     *
     * @throws BadgeException when the badge doesn't exist or can't be deleted
     */
    public function delete(int $id): void
    {
        $badge = $this->badgeRepository->findById($id);
        if ($badge === null) {
            throw new BadgeException('Badge introuvable.');
        }
        if ($badge->isDefault) {
            throw new BadgeException('Un badge par défaut ne peut pas être supprimé — désactivez-le.');
        }
        if ($this->memberBadgeRepository->badgeHasAnyAssignment($id)) {
            throw new BadgeException('Ce badge est déjà attribué à un membre — désactivez-le au lieu de le supprimer.');
        }

        $this->badgeRepository->delete($id);
    }

    /**
     * Ids of badges that have ever been assigned to a member (any scout
     * year) — used by the admin UI to disable the delete button instead of
     * letting the user hit the server-side guard in delete().
     *
     * @return int[]
     */
    public function getAssignedBadgeIds(): array
    {
        return $this->memberBadgeRepository->assignedBadgeIds();
    }

    /** @return Badge[] */
    public function getBadgesForMemberYear(int $memberYearId): array
    {
        return $this->memberBadgeRepository->getActiveBadgesForMemberYear($memberYearId);
    }

    /**
     * @param int[] $memberYearIds
     * @return array<int, Badge[]>
     */
    public function getBadgesForMemberYears(array $memberYearIds): array
    {
        return $this->memberBadgeRepository->getActiveBadgesForMemberYears($memberYearIds);
    }

    /**
     * Trésorier badge holders of a year whose functions no longer animate
     * any section — the badge is there, and it grants nothing.
     *
     * toggleAssignment() refuses to CREATE that situation, but a Desk
     * import can walk into it from the other side: an animateur who
     * carried the badge in September becomes intendant in the next
     * export, and their badge quietly stops meaning anything. Nobody
     * looks for a permission that used to work, so the import is where it
     * has to be said (Core\Badge\TreasurerBadgeDeskImportListener).
     *
     * Returns member_year ids and nothing else: who they are is on the
     * Staffs page, for whoever may see it (SECURITY.md §11).
     *
     * @return int[]
     */
    public function findStrandedTreasurerMemberYearIds(int $scoutYearId): array
    {
        $badge = $this->badgeRepository->findByName(self::BADGE_TREASURER);
        if ($badge === null || !$badge->isActive) {
            return [];
        }

        $stranded = [];
        foreach ($this->memberBadgeRepository->findMemberYearIdsForBadgeAndYear($badge->id, $scoutYearId) as $id) {
            if ($this->sectionService->getAnimatedSectionIds($id) === []) {
                $stranded[] = $id;
            }
        }

        return $stranded;
    }

    /**
     * Toggle a badge assignment for a member. Returns true if now assigned,
     * false if it was removed.
     *
     * @throws BadgeException when the badge doesn't exist or is inactive
     */
    public function toggleAssignment(int $memberYearId, int $badgeId, ?int $actorId): bool
    {
        $badge = $this->badgeRepository->findById($badgeId);
        if ($badge === null || !$badge->isActive) {
            throw new BadgeException('Badge indisponible.');
        }
        if ($badge->referentSectionId !== null
            && !$this->sectionService->isMemberYearInSection($memberYearId, UnitStaffSectionService::DESK_CODE)) {
            throw new BadgeException("Ce badge ne peut être attribué qu'à un membre du Staff d'U.");
        }

        // The Trésorier badge does exactly one thing: it grants its holder
        // the finance account of a section THEY ANIMATE (Modules\Finance\
        // Service\TreasurerScopeService). Given to somebody whose only
        // function is `intendant` — a role that exists precisely to reach
        // the finances without being a chief — it granted nothing, and the
        // page said « attribué » all the same. Worse: assigning it switched
        // the partition rule ON for the whole unit, so an intendant who had
        // been seeing every section's account before now saw none, and
        // nothing anywhere explained either half (issue #222).
        //
        // Refused rather than accepted-and-ignored, and only when it would
        // be turned ON: removing a badge somebody should never have had is
        // always allowed, which is what makes an installation that already
        // carries one repairable.
        if (
            $badge->name === self::BADGE_TREASURER
            && !$this->memberBadgeRepository->isAssigned($memberYearId, $badgeId)
            && $this->sectionService->getAnimatedSectionIds($memberYearId) === []
        ) {
            throw new BadgeException(
                'Le badge « Trésorier » ne peut être attribué qu\'à une personne qui anime une section : '
                . 'il donne accès au compte de sa section, et une fonction qui n\'anime pas (intendant, par '
                . 'exemple) n\'en a aucune.'
            );
        }

        if ($this->memberBadgeRepository->isAssigned($memberYearId, $badgeId)) {
            $this->memberBadgeRepository->unassign($memberYearId, $badgeId);
            return false;
        }

        $this->memberBadgeRepository->assign($memberYearId, $badgeId, $actorId);
        return true;
    }
}
