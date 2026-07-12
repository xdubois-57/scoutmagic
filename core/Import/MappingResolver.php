<?php

declare(strict_types=1);

namespace Core\Import;

class MappingResolver
{
    private int $newFunctionsCount = 0;

    public function __construct(
        private FunctionRepository $functionRepo,
        private AgeBranchRepository $ageBranchRepo,
        private ImportSectionRepository $sectionRepo,
        private FeeCategoryRepository $feeCategoryRepo
    ) {
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
        return $id;
    }

    /**
     * Resolve a raw branch code. Auto-creates if not found.
     */
    public function resolveBranch(string $deskCode): int
    {
        $existing = $this->ageBranchRepo->findByDeskCode($deskCode);
        if ($existing !== null) {
            return $existing['id'];
        }

        return $this->ageBranchRepo->create($deskCode, $deskCode);
    }

    /**
     * Resolve a raw section code. Auto-creates if not found.
     */
    public function resolveSection(string $sectionCode, int $branchId, ?string $sectionName): int
    {
        $existing = $this->sectionRepo->findByDeskCode($sectionCode);
        if ($existing !== null) {
            return $existing['id'];
        }

        return $this->sectionRepo->create($sectionCode, $branchId, $sectionName);
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

        return $this->feeCategoryRepo->create($deskCode, $deskCode);
    }

    /**
     * Get count of newly created functions during this import session.
     */
    public function getNewFunctionsCount(): int
    {
        return $this->newFunctionsCount;
    }
}
