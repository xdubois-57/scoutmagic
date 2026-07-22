<?php

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Import\FunctionRepository;
use Core\Member\SectionService;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;

/**
 * Owns both "kinds" of mailing list (module spec): default lists — one
 * per active section plus "Membres actifs"/"Chefs uniquement" — are
 * computed here on every call from Core\Member\SectionService, never
 * stored as rows, so a section becoming inactive (or a new one appearing)
 * at the next Desk import is reflected immediately with no sync step of
 * its own to run. Custom lists are the only ones backed by
 * Repository\MailingListRepository.
 */
class MailingListService
{
    public const ACTIVE_MEMBERS_LABEL = 'Membres actifs';
    public const CHIEFS_LABEL = 'Chefs uniquement';

    public function __construct(
        private MailingListRepository $listRepository,
        private MemberResolutionRepository $resolutionRepository,
        private SectionService $sectionService,
        private FunctionRepository $functionRepository
    ) {
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
            'description' => "Tous les membres actifs de l'unité, toutes sections confondues, pour l'année scoute sélectionnée.",
        ];
        $lists[] = [
            'list_type' => 'default_chiefs',
            'list_section_id' => null,
            'label' => self::CHIEFS_LABEL,
            'description' => "Les membres ayant une fonction de chef ou plus (chef, chef d'unité, super-administrateur), "
                . "toutes sections confondues, pour l'année scoute sélectionnée.",
        ];

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
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @throws MailingListException on an invalid name/description or empty criteria
     */
    public function createCustomList(string $name, string $description, array $functionIds, array $sectionIds, ?int $createdBy): MailingList
    {
        $this->validateCriteria($name, $description, $functionIds, $sectionIds);

        $id = $this->listRepository->create(trim($name), trim($description), $functionIds, $sectionIds, $createdBy);
        $list = $this->listRepository->findById($id);
        \assert($list !== null);
        return $list;
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @throws MailingListException on an invalid name/description, empty criteria, or an unknown list
     */
    public function updateCustomList(int $id, string $name, string $description, array $functionIds, array $sectionIds): MailingList
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        $this->validateCriteria($name, $description, $functionIds, $sectionIds);

        $this->listRepository->update($id, trim($name), trim($description), $functionIds, $sectionIds);
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
            throw new MailingListException('Cette liste est utilisée par au moins un email — désactivez-la au lieu de la supprimer.');
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
        switch ($listType) {
            case 'default_section':
                \assert($listSectionId !== null);
                return $this->resolutionRepository->resolveSectionMembers($listSectionId, $scoutYearId);
            case 'default_active_members':
                return $this->resolutionRepository->resolveActiveMembers($scoutYearId);
            case 'default_chiefs':
                return $this->resolutionRepository->resolveChiefs($scoutYearId);
            case 'custom':
                \assert($listId !== null);
                $list = $this->listRepository->findById($listId);
                if ($list === null) {
                    throw new MailingListException('Liste introuvable.');
                }
                return $this->resolutionRepository->resolveCustomList(
                    $this->listRepository->getFunctionIds($listId),
                    $this->listRepository->getSectionIds($listId),
                    $scoutYearId
                );
            default:
                throw new MailingListException("Type de liste inconnu : {$listType}");
        }
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
     * @throws MailingListException on an unknown custom list id
     */
    public function resolveMembersForYears(string $listType, ?int $listId, ?int $listSectionId, array $scoutYearIds): array
    {
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

                $merged[] = ['member_id' => $member['member_id'], 'email' => $member['email'], 'scout_year_id' => $scoutYearId];
            }
        }

        return $merged;
    }

    /**
     * @return array<int, array{id: int, label: string, role: string}> every function, for the "Nouvelle liste" multi-select
     */
    public function getAllFunctions(): array
    {
        return array_map(
            fn(array $f) => ['id' => $f['id'], 'label' => $f['label'], 'role' => $f['role']],
            $this->functionRepository->findAll()
        );
    }

    /**
     * @return array<int, array{id: int, name: string}> active sections, for the "Nouvelle liste" multi-select
     */
    public function getAllSections(): array
    {
        return array_map(
            fn(array $s) => ['id' => $s['id'], 'name' => $s['name']],
            $this->sectionService->getAllWithBranches()
        );
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @throws MailingListException
     */
    private function validateCriteria(string $name, string $description, array $functionIds, array $sectionIds): void
    {
        if (trim($name) === '') {
            throw new MailingListException('Le nom de la liste est obligatoire.');
        }
        if (trim($description) === '') {
            throw new MailingListException('La description de la liste est obligatoire.');
        }
        if ($functionIds === [] || $sectionIds === []) {
            throw new MailingListException('Une liste doit combiner au moins une fonction et une section.');
        }
    }
}
