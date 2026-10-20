<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\FunctionRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListService;

/**
 * Creates the custom mailing lists of MailingListBlueprint, through the
 * three services the list's page drives.
 *
 *  - `MailingListService::createCustomList()` — « Nouvelle liste ».
 *  - `ListBlockService::createMemberGroup()` — the member-group dialog's
 *    « Enregistrer », which journals the block.
 *  - `ContactGroupImportService::analyse()` then `createGroup()` — the
 *    contact-group dialog: the file analysed first, and the contacts the
 *    analysis returned saved with the name, in one transaction. The
 *    analysis is what validates, deduplicates and refuses lines, so
 *    reading the file here any other way would bypass the very rules the
 *    dataset is meant to exercise.
 *
 * The MailingListService is built with only what these calls read — the
 * list repository, and the function list a member group's labels are
 * resolved against. Resolution, the year and the projection play no part
 * in creating a list.
 */
final class MailingListSeeder
{
    private readonly MailingListService $listService;

    private readonly ListBlockService $blockService;

    private readonly ContactGroupImportService $contactGroups;

    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        SectionService $sectionService,
        private readonly string $datasetRoot,
        private readonly ?int $actorId,
    ) {
        $journal = new JournalService(new JournalRepository($pdo));
        $listRepository = new MailingListRepository($pdo);
        $blockRepository = new ListBlockRepository($pdo);
        $contactRepository = new ContactRepository($pdo, $encryption);

        $this->listService = new MailingListService(
            $listRepository,
            new MemberResolutionRepository($pdo, $encryption),
            $sectionService,
            new FunctionRepository($pdo),
            null,
            $blockRepository,
            $contactRepository,
        );
        $this->blockService = new ListBlockService($blockRepository, $contactRepository, $listRepository, $journal);
        $this->contactGroups = new ContactGroupImportService(
            $blockRepository,
            $contactRepository,
            $this->blockService,
            new SettingService(new SettingRepository($pdo)),
            $journal,
            new SuppressedAddressRepository($pdo),
        );
    }

    /**
     * @return array{lists: int, memberGroups: int, contactGroups: int, contacts: int}
     */
    public function seed(): array
    {
        $counts = ['lists' => 0, 'memberGroups' => 0, 'contactGroups' => 0, 'contacts' => 0];
        $functionIds = $this->functionIdsByLabel();

        foreach (MailingListBlueprint::LISTS as $declared) {
            $list = $this->listService->createCustomList($declared['name'], $declared['description'], $this->actorId);
            $counts['lists']++;

            foreach ($declared['memberGroups'] as $functionLabels) {
                $this->blockService->createMemberGroup(
                    $list->id,
                    $this->resolve($functionLabels, $functionIds),
                    [],
                    [],
                    $this->actorId,
                );
                $counts['memberGroups']++;
            }

            foreach ($declared['contactGroups'] as $group) {
                $path = $this->datasetRoot . '/' . MailingListBlueprint::DIRECTORY . '/' . $group['file'];
                $preview = $this->contactGroups->analyse(null, $path);
                if ($preview->errors !== []) {
                    // Our own file: a refused line is a drift in the
                    // analysis rules, not data to model.
                    throw new \RuntimeException(sprintf(
                        'The contact file %s has refused lines: %s',
                        $group['file'],
                        implode(' | ', $preview->errors),
                    ));
                }
                $this->contactGroups->createGroup($list->id, $group['name'], $preview->contacts, $this->actorId);
                $counts['contactGroups']++;
                $counts['contacts'] += count($preview->contacts);
            }
        }

        return $counts;
    }

    /**
     * Every function id per label. Desk can carry one label under several
     * codes; a member group naming the label means all of them.
     *
     * @return array<string, list<int>>
     */
    private function functionIdsByLabel(): array
    {
        $ids = [];
        foreach ($this->listService->getAllFunctions() as $function) {
            $ids[(string) $function['label']][] = (int) $function['id'];
        }

        return $ids;
    }

    /**
     * @param list<string> $labels
     * @param array<string, list<int>> $functionIds
     * @return list<int>
     */
    private function resolve(array $labels, array $functionIds): array
    {
        $resolved = [];
        foreach ($labels as $label) {
            if (!isset($functionIds[$label])) {
                throw new \RuntimeException(sprintf(
                    'No function « %s » in the database: the Desk import no longer produces it.',
                    $label,
                ));
            }
            array_push($resolved, ...$functionIds[$label]);
        }

        return $resolved;
    }
}
