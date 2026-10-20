<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Journal\JournalService;
use Modules\MassMail\Repository\Contact;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;

/**
 * The blocks of a custom list, and the three rules that make one valid
 * (CHANTIER-listes-de-diffusion-blocs.md §2.1):
 *
 * - **a member group carries at least one choice** — a function, a
 *   section or a badge. An empty axis is not a constraint, so a group
 *   with three empty axes would mean « nobody » to the resolution and
 *   « everybody » to anyone reading three « Peu importe »: neither is a
 *   thing to produce by accident;
 * - **a contact group carries a name**, 150 characters at most — it has
 *   no sentence to describe it the way a member group does;
 * - **a block's kind never changes.** Each kind has its own update, and
 *   each refuses a block of the other kind.
 *
 * A contact group's contacts are not written here: they change only by
 * file, through Service\ContactGroupImportService, which calls back into
 * contactGroupName() for the name rule so it lives in one place.
 *
 * The unsubscribe is here too, because it is the one other write a
 * contact can receive — and it is global, every group of every list.
 */
class ListBlockService
{
    private const NAME_MAX_LENGTH = 150;

    public function __construct(
        private ListBlockRepository $blockRepository,
        private ContactRepository $contactRepository,
        private MailingListRepository $listRepository,
        private JournalService $journal
    ) {
    }

    /**
     * @return ListBlock[] member groups first, then contact groups, each by creation
     */
    public function findForList(int $listId): array
    {
        return $this->blockRepository->findForList($listId);
    }

    public function findById(int $blockId): ?ListBlock
    {
        return $this->blockRepository->findById($blockId);
    }

    /**
     * A list without any block is absent from the result.
     *
     * @return array<int, array{member_group: int, contact_group: int}> list id => counts
     */
    public function countKindsByList(): array
    {
        return $this->blockRepository->countKindsByList();
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @throws MailingListException on an unknown list or a group without any choice
     */
    public function createMemberGroup(
        int $listId,
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        ?int $actorId
    ): ListBlock {
        $this->requireList($listId);
        [$functionIds, $sectionIds, $badgeIds] = self::validCriteria($functionIds, $sectionIds, $badgeIds);

        $blockId = $this->blockRepository->atomically(function () use (
            $listId,
            $functionIds,
            $sectionIds,
            $badgeIds,
            $actorId
        ): int {
            $blockId = $this->blockRepository->create($listId, ListBlock::KIND_MEMBER_GROUP, null, $actorId);
            $this->blockRepository->replaceCriteria($blockId, $functionIds, $sectionIds, $badgeIds);

            return $blockId;
        });

        $this->journal->log(
            'mass_mail',
            'list_block_created',
            'info',
            'Groupe de membres ajouté à une liste de diffusion',
            ['list_id' => $listId, 'block_id' => $blockId, 'kind' => ListBlock::KIND_MEMBER_GROUP],
            $actorId
        );

        return $this->requireBlock($blockId);
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @throws MailingListException on an unknown block, a contact group, or a group without any choice
     */
    public function updateMemberGroup(
        int $blockId,
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        ?int $actorId
    ): ListBlock {
        $block = $this->requireBlock($blockId);
        if (!$block->isMemberGroup()) {
            throw new MailingListException('Ce bloc est un groupe de contacts : il se modifie par son fichier.');
        }
        [$functionIds, $sectionIds, $badgeIds] = self::validCriteria($functionIds, $sectionIds, $badgeIds);

        $this->blockRepository->atomically(function () use ($blockId, $functionIds, $sectionIds, $badgeIds): void {
            $this->blockRepository->replaceCriteria($blockId, $functionIds, $sectionIds, $badgeIds);
        });

        $this->journal->log(
            'mass_mail',
            'list_block_updated',
            'info',
            'Groupe de membres d\'une liste de diffusion modifié',
            ['list_id' => $block->listId, 'block_id' => $blockId, 'kind' => $block->kind],
            $actorId
        );

        return $this->requireBlock($blockId);
    }

    /**
     * Removes a block of either kind. A contact group's rows go with it —
     * its unsubscribes stay true all the same, because every unsubscribe
     * was also written to mass_mail_suppressed_addresses, which a
     * removed group does not touch.
     *
     * @return ListBlock the block as it was, for the caller's message
     * @throws MailingListException on an unknown block
     */
    public function delete(int $blockId, ?int $actorId): ListBlock
    {
        $block = $this->requireBlock($blockId);

        $this->blockRepository->atomically(function () use ($blockId): void {
            $this->blockRepository->delete($blockId);
        });

        $this->journal->log(
            'mass_mail',
            'list_block_deleted',
            'info',
            $block->isMemberGroup()
                ? 'Groupe de membres retiré d\'une liste de diffusion'
                : 'Groupe de contacts retiré d\'une liste de diffusion',
            ['list_id' => $block->listId, 'block_id' => $blockId, 'kind' => $block->kind],
            $actorId
        );

        return $block;
    }

    /**
     * Every contact of a contact group, decrypted and sorted — read-only:
     * the screen searches and pages through them in the browser, and
     * changes them only by file.
     *
     * @return Contact[]
     * @throws MailingListException on an unknown block or a member group
     */
    public function findContacts(int $blockId): array
    {
        $this->requireContactGroup($blockId);

        return $this->contactRepository->findForBlock($blockId);
    }

    /**
     * The unsubscribe, applied to every contact group of every list at
     * once (the module's standing decision, and the only reason the blind
     * index carries an index of its own).
     *
     * @return int how many rows were newly unsubscribed
     */
    public function unsubscribeEverywhere(string $email): int
    {
        $count = $this->contactRepository->unsubscribeEverywhere($email);
        if ($count > 0) {
            $this->journal->log(
                'mass_mail',
                'list_contact_unsubscribed',
                'info',
                'Désinscription d\'un contact de liste de diffusion',
                // A count only — an address is personal data and never
                // reaches a journal entry (SECURITY.md §11).
                ['rows' => $count]
            );
        }

        return $count;
    }

    /**
     * A contact group's name as it will be stored, or the refusal.
     *
     * @throws MailingListException
     */
    public function contactGroupName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new MailingListException('Le nom du groupe de contacts est obligatoire.');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new MailingListException(
                'Le nom du groupe de contacts ne peut pas dépasser ' . self::NAME_MAX_LENGTH . ' caractères.'
            );
        }

        return $name;
    }

    /**
     * @throws MailingListException
     */
    public function requireContactGroup(int $blockId): ListBlock
    {
        $block = $this->requireBlock($blockId);
        if (!$block->isContactGroup()) {
            throw new MailingListException('Ce bloc est un groupe de membres : il n\'a pas de contacts.');
        }

        return $block;
    }

    /**
     * @throws MailingListException
     */
    public function requireList(int $listId): void
    {
        if ($this->listRepository->findById($listId) === null) {
            throw new MailingListException('Liste introuvable.');
        }
    }

    /**
     * @throws MailingListException
     */
    private function requireBlock(int $blockId): ListBlock
    {
        $block = $this->blockRepository->findById($blockId);
        if ($block === null) {
            throw new MailingListException('Groupe introuvable.');
        }

        return $block;
    }

    /**
     * At least one choice on any axis. One function and one section are
     * NOT both required: an empty axis is « Peu importe », which is what
     * makes « les intendants, toutes sections confondues » expressible.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @return array{0: int[], 1: int[], 2: int[]}
     * @throws MailingListException
     */
    private static function validCriteria(array $functionIds, array $sectionIds, array $badgeIds): array
    {
        $clean = static fn(array $ids): array => array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        $functionIds = $clean($functionIds);
        $sectionIds = $clean($sectionIds);
        $badgeIds = $clean($badgeIds);

        if ($functionIds === [] && $sectionIds === [] && $badgeIds === []) {
            throw new MailingListException('Choisissez au moins une fonction, une section ou un badge.');
        }

        return [$functionIds, $sectionIds, $badgeIds];
    }
}
