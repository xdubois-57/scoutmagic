<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Badge\Badge;
use Core\Badge\BadgeService;
use Core\Config\ScoutYearService;
use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\Registration\Api\ExternalMailingListProvider;
use Modules\Registration\Api\ProjectedPopulationProvider;

/**
 * Owns all "kinds" of mailing list (module spec): default lists — one
 * per active section plus "Membres actifs"/"Animateurs uniquement" — are
 * computed here on every call from Core\Member\SectionService, never
 * stored as rows, so a section becoming inactive (or a new one appearing)
 * at the next Desk import is reflected immediately with no sync step of
 * its own to run. Custom lists are the only ones backed by
 * Repository\MailingListRepository. The "external" kind is contributed,
 * optionally, by another module's own Api\ExternalMailingListProvider
 * (ARCHITECTURE.md §7.5) — currently only the registration module
 * publishes one; $externalListProvider is null (and the list simply
 * doesn't appear anywhere) whenever that module is disabled.
 */
class MailingListService
{
    public const ACTIVE_MEMBERS_LABEL = 'Membres actifs';
    public const CHIEFS_LABEL = 'Animateurs uniquement';

    public function __construct(
        private MailingListRepository $listRepository,
        private MemberResolutionRepository $resolutionRepository,
        private SectionService $sectionService,
        private FunctionRepository $functionRepository,
        /**
         * The third criteria axis. Nullable and defaulted only so the
         * many tests that drive a list by its two original axes keep
         * their constructor call — the composition roots always pass it,
         * and a null one simply offers no badge to choose from.
         */
        private ?BadgeService $badgeService = null,
        private ?ExternalMailingListProvider $externalListProvider = null,
        /**
         * The projection (ARCHITECTURE.md §7.5, `registration`'s
         * `Api\ProjectedPopulationProvider`) — null when that module is
         * disabled, and every future-year list then falls back to Desk,
         * which is exactly what it used to do.
         */
        private ?ProjectedPopulationProvider $projectedPopulation = null,
        private ?ScoutYearResolver $scoutYearResolver = null,
        private ?ScoutYearService $scoutYearService = null
    ) {
    }

    /**
     * Whether `$scoutYearId` is a year the unit has not reached yet.
     *
     * Compared by LABEL, never by id: a scout year row is created the
     * moment something needs it, so next year's id can be lower than this
     * year's (the same trap `SectionMembershipRepository` fell into) and
     * `id > publicId` would answer at random. Labels are `YYYY-YYYY`, so a
     * string comparison is the chronology.
     */
    public function isFutureScoutYear(int $scoutYearId): bool
    {
        if ($this->scoutYearResolver === null || $this->scoutYearService === null) {
            return false;
        }

        $year = $this->scoutYearService->findById($scoutYearId);
        if ($year === null) {
            return false;
        }

        return (string) $year['label'] > (string) $this->scoutYearResolver->getCurrentPublicYear()['label'];
    }

    /**
     * What to tell somebody about to send to a year that has not happened
     * yet — null for the current year and for any past one, because a
     * warning shown on every ordinary send is a warning nobody reads.
     *
     * Two texts, because there are two situations and they are not equally
     * reassuring: with the registration module the list already knows about
     * decided passages, accepted registrations and announced departures and
     * is merely incomplete; without it there is nothing but Desk, which for
     * a year nobody has imported means very little.
     */
    public function futureAudienceWarning(int $scoutYearId): ?string
    {
        if (!$this->isFutureScoutYear($scoutYearId)) {
            return null;
        }

        $label = (string) ($this->scoutYearService?->findById($scoutYearId)['label'] ?? '');

        if ($this->projectedPopulation !== null) {
            return "Cette liste vise l'année {$label}. Elle tient compte des passages décidés, des inscriptions "
                . "acceptées et des départs annoncés. Tant que tout n'est pas encodé dans Desk, elle reste une "
                . 'projection : des destinataires peuvent manquer ou changer de section.';
        }

        return "Cette liste vise l'année {$label}. Le module Inscriptions étant désactivé, elle ne repose que sur "
            . "les données Desk : elle ne sera exacte qu'une fois l'année suivante entièrement encodée dans Desk.";
    }

    /**
     * The same caveat as futureAudienceWarning(), stated where a list is
     * DEFINED rather than where a year is picked — so the configuration
     * page can say what « l'année prochaine » will mean before anybody
     * discovers it in a dialog seconds before sending.
     *
     * Not year-specific and never null: this page has no year to name, and
     * a sentence that appeared and disappeared depending on the calendar
     * would be worse than one that is simply always true.
     */
    public function futureAudienceNotice(): string
    {
        if ($this->projectedPopulation !== null) {
            return 'Pour une année scoute à venir, les listes de section et « tous les membres actifs » '
                . "s'appuient sur la projection du module Inscriptions — passages décidés, inscriptions "
                . "acceptées, départs annoncés — tant que Desk n'est pas encodé.";
        }

        return 'Pour une année scoute à venir, les listes ne reposent que sur les données Desk : elles ne '
            . "seront exactes qu'une fois l'année entièrement encodée dans Desk.";
    }

    /**
     * The default lists, in the fixed order the module spec describes:
     * one "Section - {nom}" per currently-active (and visible) section,
     * then the two unit-wide ones. Every list carries a fixed, generated
     * description — descriptions are mandatory across the module (custom
     * lists enforce this too, see createCustomList()/updateCustomList()),
     * so the picker never shows an undocumented list.
     *
     * @return array<int, array{list_type: string, list_section_id: ?int, label: string, description: string}>
     */
    public function getDefaultLists(): array
    {
        $lists = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $lists[] = [
                'list_type' => 'default_section',
                'list_section_id' => $section['id'],
                'label' => 'Section - ' . $section['name'],
                'description' => "Tous les membres ayant une fonction dans la section « {$section['name']} » "
                    . '(animateurs, intendants, animés) pour l\'année scoute sélectionnée.',
            ];
        }
        $lists[] = [
            'list_type' => 'default_active_members',
            'list_section_id' => null,
            'label' => self::ACTIVE_MEMBERS_LABEL,
            'description' => "Tous les membres actifs de l'unité, toutes sections confondues, pour l'année scoute "
                . "sélectionnée.",
        ];
        $lists[] = [
            'list_type' => 'default_chiefs',
            'list_section_id' => null,
            'label' => self::CHIEFS_LABEL,
            'description' => "Les membres ayant une fonction de chef ou plus (chef, chef d'unité, "
                . "super-administrateur), "
                . "toutes sections confondues, pour l'année scoute sélectionnée.",
        ];

        if ($this->externalListProvider !== null) {
            $external = $this->externalListProvider->describeMailingList();
            $lists[] = [
                'list_type' => Email::LIST_TYPE_EXTERNAL,
                'list_section_id' => null,
                'label' => $external['label'],
                'description' => $external['description'],
            ];
        }

        return $lists;
    }

    /**
     * @return MailingList[]
     */
    public function getAllCustomLists(): array
    {
        return $this->listRepository->findAllOrdered();
    }

    /**
     * @return MailingList[] active custom lists only — for the compose dialog's list picker
     */
    public function getActiveCustomLists(): array
    {
        return array_values(array_filter($this->listRepository->findAllOrdered(), fn(MailingList $l) => $l->isActive));
    }

    public function getCustomListById(int $id): ?MailingList
    {
        return $this->listRepository->findById($id);
    }

    /**
     * @return int[]
     */
    public function getCustomListFunctionIds(int $listId): array
    {
        return $this->listRepository->getFunctionIds($listId);
    }

    /**
     * @return int[]
     */
    public function getCustomListSectionIds(int $listId): array
    {
        return $this->listRepository->getSectionIds($listId);
    }

    /**
     * @return int[]
     */
    public function getCustomListBadgeIds(int $listId): array
    {
        return $this->listRepository->getBadgeIds($listId);
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @throws MailingListException on an invalid name/description or empty criteria
     */
    public function createCustomList(
        string $name,
        string $description,
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        ?int $createdBy
    ): MailingList
    {
        $this->validateCriteria($name, $description, $functionIds, $sectionIds, $badgeIds);

        $id = $this->listRepository->create(
            trim($name),
            trim($description),
            $functionIds,
            $sectionIds,
            $badgeIds,
            $createdBy
        );
        $list = $this->listRepository->findById($id);
        \assert($list !== null);
        return $list;
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @throws MailingListException on an invalid name/description, empty criteria, or an unknown list
     */
    public function updateCustomList(
        int $id,
        string $name,
        string $description,
        array $functionIds,
        array $sectionIds,
        array $badgeIds
    ): MailingList
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        $this->validateCriteria($name, $description, $functionIds, $sectionIds, $badgeIds);

        $this->listRepository->update($id, trim($name), trim($description), $functionIds, $sectionIds, $badgeIds);
        $updated = $this->listRepository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws MailingListException when the list doesn't exist
     */
    public function setActive(int $id, bool $active): void
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        $this->listRepository->setActive($id, $active);
    }

    /**
     * Same "deactivate instead" precedent as Core\Badge\BadgeService::
     * delete() — a list already used by an email (any status, even a
     * draft) is never actually deletable, since Repository\EmailRepository
     * rows keep a plain FK to it.
     *
     * @throws MailingListException when the list doesn't exist or is referenced
     */
    public function deleteCustomList(int $id): void
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        if ($this->listRepository->isReferencedByAnyEmail($id)) {
            throw new MailingListException('Cette liste est utilisée par au moins un email — désactivez-la au lieu de '
                . 'la supprimer.');
        }

        $this->listRepository->delete($id);
    }

    /**
     * Resolves any list (default or custom) to its current member set —
     * the single entry point Service\MassMailService uses when freezing
     * recipients at send time.
     *
     * @return array<int, array{member_id: int, email: ?string}>
     * @throws MailingListException on an unknown custom list id
     */
    public function resolveMembers(string $listType, ?int $listId, ?int $listSectionId, int $scoutYearId): array
    {
        // A year the unit has not reached yet has, by definition, little or
        // nothing in Desk. When `registration` is enabled its projection
        // knows who is expected — decided passages, accepted registrations,
        // announced departures — so the two lists that mean something for a
        // population read it instead of an empty Desk year.
        //
        // The other two do not, and are deliberately left alone: a
        // projection is animés only and carries no FUNCTION, so it has
        // nothing to say about « les chefs » or about a custom list built
        // from functions. Answering those from it would be inventing
        // recipients.
        if (
            $this->projectedPopulation !== null
            && in_array($listType, ['default_section', 'default_active_members'], true)
            && $this->isFutureScoutYear($scoutYearId)
        ) {
            return $this->projectedMembers($scoutYearId, $listType === 'default_section' ? $listSectionId : null);
        }

        switch ($listType) {
            case 'default_section':
                \assert($listSectionId !== null);
                return $this->resolutionRepository->resolveSectionMembers($listSectionId, $scoutYearId);
            case 'default_active_members':
                return $this->resolutionRepository->resolveActiveMembers($scoutYearId);
            case 'default_chiefs':
                return $this->resolutionRepository->resolveChiefs($scoutYearId);
            case Email::LIST_TYPE_EXTERNAL:
                // $scoutYearId is ignored on purpose — the provider
                // resolves its own fixed target year internally (module
                // spec: this list is never re-scoped by the compose
                // dialog's own year selector).
                if ($this->externalListProvider === null) {
                    throw new MailingListException('Liste externe indisponible.');
                }
                return $this->externalListProvider->resolveMailingListMembers();
            case 'custom':
                \assert($listId !== null);
                $list = $this->listRepository->findById($listId);
                if ($list === null) {
                    throw new MailingListException('Liste introuvable.');
                }
                return $this->resolutionRepository->resolveCustomList(
                    $this->listRepository->getFunctionIds($listId),
                    $this->listRepository->getSectionIds($listId),
                    $this->listRepository->getBadgeIds($listId),
                    $scoutYearId
                );
            default:
                throw new MailingListException("Type de liste inconnu : {$listType}");
        }
    }

    /**
     * The projected audience of a future year, in the shape the rest of
     * this module already speaks: `{member_id, email}`.
     *
     * **Only the people who already have a Desk identity.** An accepted
     * registration that nobody has encoded yet is a real future member and
     * the warning says so, but it has no `member_id` — and everything
     * downstream is keyed on one: `MemberEmailService::
     * resolveValidAddressesForMassMail()`, the per-address recipient rows,
     * and the one-click unsubscribe link. Pushing a request through that
     * pipeline would mean inventing an identity for it, which is a much
     * larger change than showing a warning; a family whose child is not yet
     * encoded is one of the « destinataires [qui] peuvent manquer » the
     * warning names.
     *
     * @return array<int, array{member_id: int, email: ?string}>
     */
    private function projectedMembers(int $scoutYearId, ?int $sectionId): array
    {
        \assert($this->projectedPopulation !== null);

        $emails = [];
        foreach ($this->projectedPopulation->reachableRecipients($scoutYearId) as $recipient) {
            if ($recipient->memberId !== null) {
                $emails[$recipient->memberId] = $recipient->email;
            }
        }

        $members = [];
        foreach ($this->projectedPopulation->projectedPopulation($scoutYearId) as $person) {
            if ($person->memberId === null) {
                continue;
            }
            if ($sectionId !== null && $person->sectionId !== $sectionId) {
                continue;
            }

            $members[] = [
                'member_id' => $person->memberId,
                'email' => $emails[$person->memberId] ?? null,
            ];
        }

        return $members;
    }

    /**
     * Multi-year variant (module addendum: an email can target several
     * scout years at once, e.g. a "Montages dias" retrospective spanning
     * two promotions) — resolves the same list against each selected
     * year and merges the results, deduplicated so nobody receives two
     * copies: first by member_id (the same person matched via more than
     * one year keeps only their copy from whichever year comes FIRST in
     * $scoutYearIds), then by email address (two different members who
     * happen to share one address only count once). $scoutYearIds must
     * already be ordered most-recent-first by the caller (Service\
     * MassMailService, which resolves real chronological order via
     * Core\Config\ScoutYearService — scout_year_id order alone isn't
     * reliable, since a "previous" year's row can be created, and so get
     * its id, after "current"'s).
     *
     * @param int[] $scoutYearIds Most-recent-first.
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     * @throws MailingListException on an unknown custom list id, or when the external list is unavailable
     */
    public function resolveMembersForYears(
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds
    ): array
    {
        // The external list is never re-scoped by the compose dialog's own
        // year checkboxes (module spec) — resolved exactly once, tagged
        // with the provider's OWN target year rather than whichever of
        // previous/current/next happens to be checked, so a recipient's
        // scout_year_id always matches where their real member_years
        // profile actually lives.
        if ($listType === Email::LIST_TYPE_EXTERNAL) {
            if ($this->externalListProvider === null) {
                throw new MailingListException('Liste externe indisponible.');
            }

            return $this->deduplicateByMemberAndAddress(
                $this->externalListProvider->resolveMailingListMembers(),
                $this->externalListProvider->targetScoutYearId()
            );
        }

        $seenMemberIds = [];
        $seenAddresses = [];
        $merged = [];

        foreach ($scoutYearIds as $scoutYearId) {
            foreach ($this->resolveMembers($listType, $listId, $listSectionId, $scoutYearId) as $member) {
                if (isset($seenMemberIds[$member['member_id']])) {
                    continue;
                }

                $addressKey = $member['email'] !== null ? mb_strtolower(trim($member['email'])) : null;
                if ($addressKey !== null && isset($seenAddresses[$addressKey])) {
                    continue;
                }

                $seenMemberIds[$member['member_id']] = true;
                if ($addressKey !== null) {
                    $seenAddresses[$addressKey] = true;
                }

                $merged[] = [
                    'member_id' => $member['member_id'],
                    'email' => $member['email'],
                    'scout_year_id' => $scoutYearId
                ];
            }
        }

        return $merged;
    }

    /**
     * @param array<int, array{member_id: int, email: ?string}> $members
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     */
    private function deduplicateByMemberAndAddress(array $members, int $scoutYearId): array
    {
        $seenMemberIds = [];
        $seenAddresses = [];
        $merged = [];

        foreach ($members as $member) {
            if (isset($seenMemberIds[$member['member_id']])) {
                continue;
            }
            $addressKey = $member['email'] !== null ? mb_strtolower(trim($member['email'])) : null;
            if ($addressKey !== null && isset($seenAddresses[$addressKey])) {
                continue;
            }
            $seenMemberIds[$member['member_id']] = true;
            if ($addressKey !== null) {
                $seenAddresses[$addressKey] = true;
            }
            $merged[] = [
                'member_id' => $member['member_id'],
                'email' => $member['email'],
                'scout_year_id' => $scoutYearId
            ];
        }

        return $merged;
    }

    /**
     * @return array<
     *     int,
     *     array{id: int, label: string, role: string}
     * > every function, for the "Nouvelle liste" multi-select
     */
    public function getAllFunctions(): array
    {
        return array_map(
            fn(array $f) => ['id' => $f['id'], 'label' => $f['label'], 'role' => $f['role']],
            $this->functionRepository->findAll()
        );
    }

    /**
     * The sections offered as a criterion: the active, visible ones —
     * plus any section a list still names, however it was deactivated or
     * hidden since. See getAllBadges() for why the second half is not
     * optional.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllSections(): array
    {
        $sections = array_map(
            fn(array $s) => ['id' => $s['id'], 'name' => $s['name']],
            $this->sectionService->getAllWithBranches()
        );

        return [...$sections, ...$this->stillReferencedSections(array_column($sections, 'id'))];
    }

    /**
     * The badges offered as a criterion: the active ones — plus any badge
     * a list still names, marked as deactivated.
     *
     * The second half is not a courtesy. The three pickers are the only
     * place a list's criteria round-trip through: the form submits what
     * the pickers hold, and Repository\MailingListRepository::
     * replaceCriteria() deletes and reinserts from that submission. An id
     * with no item to be selected in is therefore an id that saving ANY
     * edit — even a description — silently drops. On a list whose badge
     * axis was that one badge, the axis would then stop constraining
     * anything at all and the list would quietly widen to everybody the
     * other axes match, which is the opposite of what deactivating a
     * badge means.
     *
     * They are offered greyed rather than merely preserved, because a
     * criterion nobody can see is a criterion nobody can remove — and the
     * name says why the list resolves to fewer members than its other
     * axes suggest.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllBadges(): array
    {
        if ($this->badgeService === null) {
            return [];
        }

        $active = array_map(
            fn(Badge $b) => ['id' => $b->id, 'name' => $b->name],
            $this->badgeService->getActive()
        );
        $activeIds = array_column($active, 'id');

        $stillReferenced = [];
        $referencedIds = $this->listRepository->findReferencedBadgeIds();
        foreach ($this->badgeService->getAll() as $badge) {
            if (in_array($badge->id, $activeIds, true) || !in_array($badge->id, $referencedIds, true)) {
                continue;
            }
            $stillReferenced[] = ['id' => $badge->id, 'name' => $badge->name . ' (désactivé)'];
        }

        return [...$active, ...$stillReferenced];
    }

    /**
     * @param int[] $offeredIds
     * @return array<int, array{id: int, name: string}>
     */
    private function stillReferencedSections(array $offeredIds): array
    {
        $missingIds = array_values(array_filter(
            $this->listRepository->findReferencedSectionIds(),
            static fn(int $id): bool => !in_array($id, $offeredIds, true)
        ));
        if ($missingIds === []) {
            return [];
        }

        $sections = [];
        foreach ($this->sectionService->findByIds($missingIds) as $section) {
            $sections[] = [
                'id' => $section['id'],
                'name' => ($section['name'] ?? $section['desk_code']) . ' (retirée)',
            ];
        }

        return $sections;
    }

    /**
     * How many members the given criteria resolve to right now, for the
     * live counter on the criteria form — the same resolution a send
     * performs, against the same year, so the two can never disagree.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     */
    public function countMembersForCriteria(
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        int $scoutYearId
    ): int {
        return count($this->resolutionRepository->resolveCustomList(
            $functionIds,
            $sectionIds,
            $badgeIds,
            $scoutYearId
        ));
    }

    /**
     * An axis left empty is not a constraint (see
     * `MemberResolutionRepository::resolveCustomList()`), so demanding one
     * function AND one section would forbid « les intendants, toutes
     * sections confondues » — the very list the empty axis exists to
     * express. What stays forbidden is all three empty at once: that
     * resolves to nobody, and a list nothing can ever be added to is a
     * mistake, not a choice.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @throws MailingListException
     */
    private function validateCriteria(
        string $name,
        string $description,
        array $functionIds,
        array $sectionIds,
        array $badgeIds
    ): void {
        if (trim($name) === '') {
            throw new MailingListException('Le nom de la liste est obligatoire.');
        }
        if (trim($description) === '') {
            throw new MailingListException('La description de la liste est obligatoire.');
        }
        if ($functionIds === [] && $sectionIds === [] && $badgeIds === []) {
            throw new MailingListException(
                'Une liste doit porter au moins un critère — une fonction, une section ou un badge.'
            );
        }
    }
}
