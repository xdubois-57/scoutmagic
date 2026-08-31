<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Core\Import\DeskImportListener;
use Core\Journal\JournalService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;

/**
 * Writes down, once per Desk import, the formation wordings the site did
 * not understand (roadmap IT-19).
 *
 * The Formations page already lists them — but only for whoever opens it,
 * and only for the year they are looking at. Support hears about a wrong
 * ONE ratio months later, from a unit that has never opened that page,
 * and the one question worth answering then is « what did Desk actually
 * export? ». The journal is where that question is answerable after the
 * fact.
 *
 * ## One entry, never one per member
 *
 * A unit of two hundred members with three unrecognised wordings produces
 * ONE line carrying `{"BACV": 4, "Formation en cours": 2}`, not two
 * hundred. An event per member would drown the journal — a mistake this
 * project has made before — and would say nothing the counts do not.
 *
 * **No member reference of any kind.** Support needs the vocabulary, not
 * who holds it; the pairing of a wording with a person is on the
 * Formations page, for whoever is entitled to see it (SECURITY.md §11).
 *
 * ## Inside the import transaction
 *
 * `DeskImportListener` is invoked before the commit and an exception here
 * rolls the whole import back, so this does exactly two bounded reads and
 * at most one insert — no mail, no HTTP, nothing that cannot be replayed.
 * When everything is recognised, nothing at all is written: an import that
 * went well should leave no trace to read past.
 */
class LeadershipDeskImportListener implements DeskImportListener
{
    public function __construct(
        private LeadershipRepository $repository,
        private FormationLevelMappingRepository $mappingRepository,
        private FormationLevelResolver $resolver,
        private JournalService $journal
    ) {
    }

    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void
    {
        $resolver = $this->resolver->withMapping($this->mappingRepository->findAll());

        $unrecognised = [];
        foreach ($this->repository->countFormationLevels($scoutYearId) as $rawValue => $holders) {
            if ($resolver->resolve((string) $rawValue) !== FormationStep::UNKNOWN) {
                continue;
            }
            $unrecognised[(string) $rawValue] = $holders;
        }

        if ($unrecognised === []) {
            return;
        }

        // Sorted, so two imports of the same roster produce the same line
        // whatever order the database returned the groups in.
        ksort($unrecognised);

        $distinct = count($unrecognised);
        $this->journal->log(
            'leadership',
            'leadership_formation_levels_unrecognised',
            'info',
            $distinct > 1
                ? "Import Desk : {$distinct} niveaux de formation non reconnus"
                : 'Import Desk : 1 niveau de formation non reconnu',
            [
                'scout_year_id' => $scoutYearId,
                'levels' => $unrecognised,
            ]
        );
    }
}
