<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Journal\JournalService;

class MappingResolver
{
    private int $newFunctionsCount = 0;

    /**
     * What this import has already journalled, as « kind|value ».
     *
     * An import calls resolveBranch() once per CSV row, so without this a
     * unit with four hundred members would write four hundred identical
     * « branche non reconnue » entries and bury everything else in the
     * journal. Cleared by {@see self::resetImportState()}, which makes the
     * guarantee « once per import » rather than « once ever »: a value
     * still unresolved at the next import is worth saying again.
     *
     * @var array<string, true>
     */
    private array $journalled = [];

    /**
     * What this import had to create because the installation had never
     * seen it. Counting was enough while the only consumer was a line on
     * the import page; the report has to NAME the new functions, because
     * a function nobody has qualified yet leaves its holders seeing
     * nothing (SECURITY.md §3).
     *
     * @var array{functions: int[], sections: int[], branches: int[], fee_categories: int[]}
     */
    private array $created = ['functions' => [], 'sections' => [], 'branches' => [], 'fee_categories' => []];

    public function __construct(
        private FunctionRepository $functionRepo,
        private AgeBranchRepository $ageBranchRepo,
        private ImportSectionRepository $sectionRepo,
        private FeeCategoryRepository $feeCategoryRepo,
        /**
         * Where an unrecognised value is written down (issue #356). Null
         * leaves every resolution exactly as it was — the import is not
         * the place to discover that a dependency is missing — which is
         * also what keeps the existing test call sites valid.
         */
        private ?JournalService $journal = null
    ) {
    }

    /**
     * Forget what the previous import created.
     *
     * This object is built once per request and an import is normally the
     * only thing a request does, so in production the counters were never
     * observed to accumulate — but "normally" is not a guarantee, and a
     * second import through the same instance would otherwise report the
     * first one's new functions as its own. Called at the top of
     * {@see DeskImportService::import()}.
     */
    public function resetImportState(): void
    {
        $this->newFunctionsCount = 0;
        $this->created = ['functions' => [], 'sections' => [], 'branches' => [], 'fee_categories' => []];
        $this->journalled = [];
    }

    /**
     * Resolve a raw function code from CSV to a functions table ID.
     * New functions get role='identified' and confirmed=false — NEVER elevated automatically.
     */
    public function resolveFunction(string $deskCode): int
    {
        $existing = $this->functionRepo->findByDeskCode($deskCode);
        if ($existing !== null) {
            // Still waiting for a role, one import later. Said again for
            // the same reason a branch stuck on 99 is: what makes a value
            // unresolved is its STATE, not the day it was created, and a
            // report that only watched creations would fall silent on
            // exactly the values nobody has got round to.
            if (!$existing['confirmed']) {
                $this->journalGap(
                    DeskMappingGapKind::FUNCTION,
                    $deskCode,
                    'Fonction Desk toujours sans rôle'
                );
            }

            return $existing['id'];
        }

        $id = $this->functionRepo->create($deskCode, $deskCode, 'identified', false);
        $this->newFunctionsCount++;
        $this->created['functions'][] = $id;
        $this->journalGap(
            DeskMappingGapKind::FUNCTION,
            $deskCode,
            'Fonction Desk inconnue, créée au rôle le plus bas'
        );

        return $id;
    }

    /**
     * Resolve a raw branch code. Auto-creates if not found.
     */
    public function resolveBranch(string $deskCode): int
    {
        $existing = $this->ageBranchRepo->findByDeskCode($deskCode);
        if ($existing !== null) {
            // Fix sort_order if it was set to default 0
            $expectedOrder = AgeBranchRepository::canonicalSortOrder($existing['label']);
            if ($existing['sort_order'] !== $expectedOrder) {
                $this->ageBranchRepo->updateSortOrder($existing['id'], $expectedOrder);
            }
            // A branch already in the table can be just as unrecognised as
            // a new one — it was created by an earlier import and has sat
            // on 99 ever since, which is the case that never announces
            // itself (issue #356).
            $this->journalBranchIfNotCanonical($existing['label'], $expectedOrder);

            return $existing['id'];
        }

        $id = $this->ageBranchRepo->create($deskCode, $deskCode);
        $this->created['branches'][] = $id;
        $this->journalBranchIfNotCanonical($deskCode, AgeBranchRepository::canonicalSortOrder($deskCode));

        return $id;
    }

    /**
     * Resolve a raw section code. Auto-creates if not found; reactivates an
     * existing section (see deactivateAllSections()) since it's referenced
     * by this import.
     */
    public function resolveSection(string $sectionCode, int $branchId, ?string $sectionName): int
    {
        $existing = $this->sectionRepo->findByDeskCode($sectionCode);
        if ($existing !== null) {
            $this->sectionRepo->activate($existing['id']);
            return $existing['id'];
        }

        $id = $this->sectionRepo->create($sectionCode, $branchId, $sectionName);
        $this->created['sections'][] = $id;

        return $id;
    }

    /**
     * Mark every section inactive before an import — resolveSection()
     * reactivates each one actually referenced. See
     * ImportSectionRepository::deactivateAll().
     */
    public function deactivateAllSections(): void
    {
        $this->sectionRepo->deactivateAll();
    }

    /**
     * Resolve a raw fee code. Auto-creates if not found.
     */
    public function resolveFee(string $deskCode): int
    {
        $existing = $this->feeCategoryRepo->findByDeskCode($deskCode);
        if ($existing !== null) {
            return $existing['id'];
        }

        $id = $this->feeCategoryRepo->create($deskCode, $deskCode);
        $this->created['fee_categories'][] = $id;

        return $id;
    }

    /**
     * A branch whose label none of the seven needles matched. Says so
     * whether the row is new or years old: `sort_order = 99` is what
     * D1 of issue #356 calls the failure with no signal at all — no
     * default logo on the member page, and an arbitrary rank in every
     * picker — and nothing else in the application ever mentions it.
     */
    private function journalBranchIfNotCanonical(string $label, int $sortOrder): void
    {
        if ($sortOrder !== AgeBranchRepository::UNKNOWN_SORT_ORDER) {
            return;
        }

        $this->journalGap(
            DeskMappingGapKind::BRANCH,
            $label,
            'Branche Desk non reconnue, classée en dernier et sans logo'
        );
    }

    /**
     * One `info` entry per unrecognised value per import.
     *
     * The context carries the raw value and NOTHING else — SECURITY.md
     * §11. It is a federal label, not a member: the same word appears in
     * every unit's export, and the whole point of keeping it verbatim is
     * that « Animateur Baladinss » reads as a typo and « Animateur
     * Nutons » reads as a branch this software has yet to learn.
     */
    private function journalGap(DeskMappingGapKind $kind, string $rawValue, string $description): void
    {
        if ($this->journal === null) {
            return;
        }

        $key = $kind->value . '|' . $rawValue;
        if (isset($this->journalled[$key])) {
            return;
        }
        $this->journalled[$key] = true;

        $this->journal->log(
            'core',
            $kind->journalType(),
            'info',
            $description . ' : « ' . $rawValue . ' »',
            ['value' => $rawValue, 'code_table' => $kind->codeTable()]
        );
    }

    /**
     * Get count of newly created functions during this import session.
     */
    public function getNewFunctionsCount(): int
    {
        return $this->newFunctionsCount;
    }

    /**
     * Everything this import created on the way, for the import diff
     * ({@see ImportDiffCalculator}) — which cannot derive any of it from
     * the roster snapshots, since a snapshot says who holds what, never
     * that the "what" is brand new.
     */
    public function getNewMappings(): NewMappings
    {
        return new NewMappings(
            functionIds: array_values(array_unique($this->created['functions'])),
            sectionIds: array_values(array_unique($this->created['sections'])),
            branchIds: array_values(array_unique($this->created['branches'])),
            feeCategoryIds: array_values(array_unique($this->created['fee_categories']))
        );
    }
}
