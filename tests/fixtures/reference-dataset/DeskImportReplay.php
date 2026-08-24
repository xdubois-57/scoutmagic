<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\ScoutYearService;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskCsvParser;
use Core\Import\DeskImportService;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportResult;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionMembershipService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

/**
 * Replays the three committed Desk exports through the real import pipeline.
 *
 * Deliberately shared by the end-to-end import test and — from IT-05 — by the
 * CLI builder, so there is exactly ONE replay path. A test that exercised its
 * own copy of the wiring would keep passing on the day the builder's copy
 * broke, which is the entire failure mode this dataset exists to avoid.
 *
 * It composes services and calls their public API; it adds no behaviour of its
 * own and never writes to a table directly.
 *
 * Two details are not incidental:
 *
 *   - **Every import is handed a COPY of the committed export.**
 *     DeskImportService::import() ends with `@unlink($filePath)`, so pointing
 *     it at `desk/2024-2025.csv` would delete a versioned fixture on the first
 *     run. This is the one place that has to remember.
 *   - **Roles are confirmed after all three imports, then membership is
 *     resynced for every year.** A Desk import can never know a role: a new
 *     FONCTION always lands as `identified`, unconfirmed, and only Config Desk
 *     (FunctionsController::updateRole) turns it into `chief`/`admin`/
 *     `intendant`. Staff d'U is populated by that second step, never by the
 *     import — which is exactly why the dataset has to perform both.
 */
final class DeskImportReplay
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly EncryptionService $encryption,
        private readonly string $datasetRoot,
    ) {
    }

    /**
     * Create the dataset's three scout years, oldest first, and return their
     * ids keyed by label.
     *
     * Uses the real ScoutYearService::ensureYear(), which is idempotent — and
     * which also stamps every row it inserts with `is_current = 1` and never
     * clears the column on any other. Nothing reads it (the site's current
     * year lives in the `current_scout_year_id` setting, see
     * Core\ScoutYear\ScoutYearResolver), so the three rows all carrying a 1 is
     * harmless; it is noted here so nobody builds on it.
     *
     * @return array<string, int>
     */
    public function ensureYears(): array
    {
        $service = new ScoutYearService($this->pdo);

        $ids = [];
        foreach (UnitBlueprint::YEARS as $label) {
            $ids[$label] = $service->ensureYear($label);
        }

        return $ids;
    }

    /**
     * Import the three exports in chronological order.
     *
     * The order is the point: continuity, the inherited scout_year_offset and
     * the deactivate-then-reactivate passes on sections and member_years all
     * depend on A1 having happened before A2.
     *
     * @param array<string, int> $yearIds
     * @return array<string, ImportResult> keyed by scout year label
     */
    public function importAll(array $yearIds, int $importedBy): array
    {
        $results = [];
        foreach (UnitBlueprint::YEARS as $label) {
            $results[$label] = $this->importYear($label, $yearIds[$label], $importedBy);
        }

        return $results;
    }

    /**
     * Import one year on its own.
     *
     * Separate from importAll() because some state can only be observed by
     * interleaving: `scout_year_offset` is inherited on INSERT and never on
     * UPDATE (MemberYearRepository::inheritedScoutYearOffset(), so that a
     * chief's correction survives a re-import), so a chief's offset has to be
     * set between two imports to be inherited by the later one — replaying the
     * whole sequence afterwards would only ever take the UPDATE branch.
     */
    public function importYear(string $label, int $yearId, int $importedBy): ImportResult
    {
        $source = $this->datasetRoot . '/' . DatasetGenerator::DESK_DIRECTORY . '/' . $label . '.csv';
        if (!is_file($source)) {
            throw new \RuntimeException("Export Desk introuvable : {$source}");
        }

        // import() unlinks what it is given — never hand it the fixture.
        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset');
        copy($source, $copy);

        try {
            return $this->buildImportService()->import($copy, $yearId, $importedBy);
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }

    /**
     * Confirm every FONCTION's role, the same way Config Desk does: set the
     * role and mark it confirmed, then resync Staff d'U membership.
     *
     * The FONCTION → role table is declarative data (UnitBlueprint::FUNCTIONS),
     * not code: adding a function to the dataset means adding a line there.
     *
     * A function present in the database but absent from that table is left
     * untouched and unconfirmed on purpose — that is what a brand-new function
     * looks like to a chief who has not been to Config Desk yet, and the
     * dataset is meant to contain one.
     *
     * @param array<string, int> $yearIds
     * @return list<string> desk codes left unconfirmed
     */
    public function confirmFunctionRoles(array $yearIds): array
    {
        $repository = new FunctionRepository($this->pdo);
        $unitStaff = new UnitStaffSectionService($this->pdo);

        $unconfirmed = [];
        foreach ($repository->findAll() as $function) {
            $role = UnitBlueprint::FUNCTIONS[$function['desk_code']] ?? null;
            if ($role === null) {
                $unconfirmed[] = $function['desk_code'];
                continue;
            }

            $repository->updateRole($function['id'], $role, true);
        }

        // FunctionsController resyncs only the year in effect for the request;
        // a dataset carrying three years has to do all three, or Staff d'U
        // would be populated in one year and empty in the others.
        foreach ($yearIds as $yearId) {
            $unitStaff->syncMembership($yearId);
        }

        return $unconfirmed;
    }

    /**
     * The composition the application performs in public/index.php, rebuilt
     * here for a context that has no request.
     *
     * No DeskImportListener is wired: those are module-provided
     * (ARCHITECTURE.md §7.4) and the composition root supplies them from the
     * enabled modules. The builder passes the real ones in IT-06; the import
     * itself behaves identically without them.
     */
    private function buildImportService(): DeskImportService
    {
        $functionRepository = new FunctionRepository($this->pdo);
        $ageBranchRepository = new AgeBranchRepository($this->pdo);
        $sectionRepository = new ImportSectionRepository($this->pdo);
        $feeRepository = new FeeCategoryRepository($this->pdo);

        return new DeskImportService(
            $this->pdo,
            $this->encryption,
            new DeskCsvParser(),
            new MappingResolver($functionRepository, $ageBranchRepository, $sectionRepository, $feeRepository),
            new MemberRepository($this->pdo),
            new MemberYearRepository($this->pdo),
            new ImportJournalRepository($this->pdo),
            new UserAccountRepository($this->pdo, $this->encryption),
            new UnitStaffSectionService($this->pdo),
            new SectionMembershipService(
                new SectionMembershipRepository($this->pdo),
                new ScoutYearService($this->pdo),
            ),
        );
    }
}
