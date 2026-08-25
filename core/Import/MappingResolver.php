<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class MappingResolver
{
    private int $newFunctionsCount = 0;

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
        private FeeCategoryRepository $feeCategoryRepo
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
    }

    /**
     * Resolve a raw function code from CSV to a functions table ID.
     * New functions get role='identified' and confirmed=false — NEVER elevated automatically.
     */
    public function resolveFunction(string $deskCode): int
    {
        $existing = $this->functionRepo->findByDeskCode($deskCode);
        if ($existing !== null) {
            return $existing['id'];
        }

        $id = $this->functionRepo->create($deskCode, $deskCode, 'identified', false);
        $this->newFunctionsCount++;
        $this->created['functions'][] = $id;
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
            return $existing['id'];
        }

        $id = $this->ageBranchRepo->create($deskCode, $deskCode);
        $this->created['branches'][] = $id;

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
