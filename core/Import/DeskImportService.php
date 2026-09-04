<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\File\EncryptedFileStorageService;
use Core\Member\Duplicate\DuplicateMemberDetector;
use Core\Journal\JournalService;
use Core\Member\AddressNormalizer;
use Core\Member\SectionMembershipService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;

class DeskImportService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private DeskCsvParser $parser,
        private MappingResolver $mappingResolver,
        private MemberRepository $memberRepository,
        private MemberYearRepository $memberYearRepository,
        private ImportJournalRepository $importJournalRepository,
        private UserAccountRepository $userAccountRepository,
        private UnitStaffSectionService $unitStaffSectionService,
        private SectionMembershipService $sectionMembershipService,
        private RosterReplacementGuard $replacementGuard,
        private JournalService $journal,
        private RosterSnapshotRepository $rosterSnapshotRepository,
        private EncryptedFileStorageService $encryptedFileStorage,
        private ImportDiffCalculator $diffCalculator,
        /**
         * Duplicate detection runs AFTER the commit — it decrypts names
         * and birth dates in bulk, and nothing that slow belongs inside a
         * transaction holding the whole roster's locks. Nullable so a
         * caller that has no use for it (a fixture replay) can leave it
         * out.
         */
        private ?DuplicateMemberDetector $duplicateDetector = null,
        /**
         * Modules reconciling their own member-referencing data at the end
         * of an import (ARCHITECTURE.md §7.4). A mutable registry rather
         * than an array, read at import time: a module block registers its
         * listener wherever it sits in `public/index.php`, long after this
         * service was constructed. Core never depends on any module —
         * empty registry (or none, for a fixture replay) means no
         * reconciliation.
         */
        private ?DeskImportListenerRegistry $importListeners = null
    ) {
    }

    /**
     * Import a Desk CSV file for a given scout year.
     *
     * $replacementConfirmed carries the typed confirmation word an admin
     * gave on the barrier screen ({@see RosterReplacementGuard}). It is
     * never enough on its own: the guard has verdicts it does not lift.
     *
     * @throws ImportException when the file cannot be read or its headers do not match
     * @throws RosterReplacementRefusedException when the file would replace the roster with something it should not
     */
    public function import(
        string $filePath,
        int $scoutYearId,
        int $importedBy,
        bool $replacementConfirmed = false
    ): ImportResult
    {
        $parsed = $this->parser->parse($filePath);
        $this->mappingResolver->resetImportState();
        $this->memberRepository->resetCreatedMemberIds();

        // The barrier sits exactly where header validation already sits:
        // after the file is read, before a single write. Refusing here
        // leaves the roster untouched; refusing after the transaction
        // would mean repairing what is already broken, from pages the
        // import may just have locked the admin out of.
        $this->assertReplacementAllowed($parsed, $scoutYearId, $importedBy, $replacementConfirmed);

        $warnings = [];
        $asOf = new \DateTimeImmutable();

        // The CSV is kept, encrypted, so a doubtful import can later be
        // investigated by replaying its report against the exact file that
        // produced it. Stored before the transaction because a `files` row
        // written inside it would vanish on a rollback while its blob
        // stayed on disk; a rollback below deletes it explicitly instead.
        //
        // This is where the rule "Desk CSV: deleted immediately after
        // import" was revoked — deliberately, and documented at the seven
        // places that stated or relied on it. What is NOT revoked is that
        // the plaintext never lingers: `ImportController` deletes the
        // deposited file in a `finally`, success or failure alike.
        $fileId = $this->storeSourceFile($filePath, $asOf, $importedBy);

        $this->pdo->beginTransaction();

        try {
            // Mark all existing member_years for this year as inactive
            $this->memberYearRepository->deactivateAllForYear($scoutYearId);

            // Mark all sections inactive — reactivated below as each is
            // referenced by a member (see MappingResolver::resolveSection()).
            // A section with no members this year ends up inactive: kept,
            // never deleted, hidden from the site until it has members again.
            $this->mappingResolver->deactivateAllSections();

            // "Staff d'U" is never referenced by a CSV "Section" column, so
            // deactivateAllSections() above would otherwise leave it
            // inactive — force it back on immediately.
            $this->unitStaffSectionService->ensureSection();

            foreach ($parsed->members as $member) {
                $this->importMember($member, $scoutYearId, $warnings, $asOf);
            }

            // Chef d'unité role is only known once a function is confirmed
            // on Config Desk, not from the CSV itself — recompute Staff d'U
            // membership from whatever functions are already role='admin'.
            $this->unitStaffSectionService->syncMembership($scoutYearId);

            // Freeze what Desk contained. member_years is overwritten
            // wholesale above, so without this the only roster the site
            // could ever describe is the current one — and an invoice, a
            // report or a diff describing "the day it happened" would have
            // nothing to describe it against.
            //
            // Taken here, by the core itself, rather than by a module
            // listening for the end of an import: it has to be present
            // whatever the module configuration, and everything built on
            // top of it (the import report, the diff between two imports)
            // is core's. Inside the transaction and before the listeners,
            // on the same roster the passes above just wrote.
            // The parent row first, so the snapshot can point at it: one
            // object, one lifecycle, one purge.
            $newFunctions = $this->mappingResolver->getNewFunctionsCount();
            $importId = $this->importJournalRepository->create(
                $scoutYearId,
                $importedBy,
                $parsed->lineCount,
                count($parsed->members),
                $newFunctions,
                $fileId
            );

            // The previous import and its snapshot, resolved BEFORE the
            // new snapshot is written so "the one before this one" cannot
            // accidentally mean this one.
            $previousImport = $this->importJournalRepository->findPreviousInYear($scoutYearId, $importId);
            $previousSnapshot = $previousImport !== null
                ? $this->rosterSnapshotRepository->findByImport($previousImport->id)
                : null;

            $snapshot = $this->rosterSnapshotRepository->capture($scoutYearId, $asOf, $importId);

            // Two consecutive snapshots ARE the diff — nothing to capture
            // before or after, everything is already in the database.
            // Computed once, here, and stored: it describes this day and
            // will never describe another one.
            $this->importJournalRepository->storeDiff(
                $importId,
                $this->diffCalculator->calculate(
                    $snapshot,
                    $previousSnapshot,
                    $previousImport?->id,
                    $this->mappingResolver->getNewMappings(),
                    ImportQuality::fromParsedImport($parsed)
                )
            );

            // Modules with their own references to members.id reconcile
            // here, on the same roster and inside the same transaction as
            // core's own deactivate-then-reactivate passes above. Before
            // the commit deliberately: derived state half-applied to core
            // but not to a module is worse than an import that failed and
            // can simply be retried (see DeskImportListener's docblock).
            $listeners = $this->importListeners?->all() ?? [];
            if ($listeners !== []) {
                $activeMemberIds = $this->memberYearRepository->findActiveMemberIdsForYear($scoutYearId);
                foreach ($listeners as $listener) {
                    $listener->onDeskImportCompleted($scoutYearId, $activeMemberIds);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            // The encrypted copy was written before the transaction, so
            // the rollback does not take it with it. An import that never
            // happened must leave no copy of the unit's personal data.
            if ($fileId !== null) {
                $this->encryptedFileStorage->delete($fileId);
            }
            throw $e;
        }

        if ($fileId !== null) {
            // Only now: FileAccessGuard denies any owner_type without a
            // registered checker, and the checker for this one keys on the
            // import id that only exists once the transaction committed.
            $this->encryptedFileStorage->assignOwner($fileId, DeskImportFileOwnershipChecker::OWNER_TYPE, $importId);
        }

        // A count and two ids, and nothing else: who was on the roster is
        // exactly what must not become readable in the journal
        // (SECURITY.md §11).
        $this->journal->log(
            'core',
            'roster_snapshot_taken',
            'info',
            'Composition du roster figée après un import Desk : ' . $snapshot->memberCount . ' membre(s)',
            ['snapshot_id' => $snapshot->id, 'scout_year_id' => $scoutYearId, 'count' => $snapshot->memberCount],
            $importedBy > 0 ? $importedBy : null
        );

        // Members this import CREATED, compared with earlier scout years:
        // somebody who left and came back was re-created in Desk under a
        // new code instead of having their old record reopened (§8.80).
        // After the commit, deliberately — a bulk decryption has no place
        // inside the import's transaction — and failing here must never
        // undo an import that has already succeeded.
        if ($this->duplicateDetector !== null) {
            try {
                $this->duplicateDetector->detect($this->memberRepository->getCreatedMemberIds(), $scoutYearId);
            } catch (\Throwable) {
                // The next import will propose the same pairs: a missed
                // detection costs a delay, never data.
            }
        }

        return new ImportResult(
            memberCount: count($parsed->members),
            lineCount: $parsed->lineCount,
            newFunctionsCount: $newFunctions,
            warnings: $warnings
        );
    }

    /**
     * Encrypt the deposited CSV and register it as a `FileRecord`.
     *
     * `role_min: 'admin'` and nothing else: the file is the whole unit's
     * personal data in clear, in one document, and it is served only by
     * `/files/{id}` under `FileAccessGuard`. No second storage path, no
     * dedicated route, no exception (SECURITY.md §13).
     *
     * Returns null when the file cannot be read — which the parser above
     * has already ruled out in practice, and which must in any case never
     * be the reason an otherwise valid import fails.
     */
    private function storeSourceFile(string $filePath, \DateTimeImmutable $asOf, int $importedBy): ?int
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return $this->encryptedFileStorage->store(
            $content,
            'text/csv',
            'desk_export_' . $asOf->format('Ymd_His') . '.csv',
            'imports',
            'admin',
            null,
            $importedBy > 0 ? $importedBy : null
        );
    }

    /**
     * Run the roster-replacement barrier and journal any refusal.
     *
     * The journal entry is `security` level and carries counters only —
     * how many members would go, how many sections the file names, how
     * many admins would remain. Never a name, never a Desk identifier,
     * never a line of CSV (SECURITY.md §11 and §13).
     *
     * @throws RosterReplacementRefusedException
     */
    private function assertReplacementAllowed(
        ParsedImport $parsed,
        int $scoutYearId,
        int $importedBy,
        bool $confirmed
    ): void
    {
        $assessment = $this->replacementGuard->assess($parsed, $scoutYearId, $importedBy);
        if ($assessment->isClear()) {
            return;
        }

        $overridden = $confirmed && $assessment->verdict->allowsOverride();

        $this->journal->log(
            'core',
            $overridden ? 'desk_import_barrier_overridden' : 'desk_import_barrier_triggered',
            'security',
            $overridden
                ? "Barrière d'import Desk franchie après confirmation typée"
                : "Import Desk refusé par la barrière de remplacement du roster",
            ['scout_year_id' => $scoutYearId] + $assessment->journalContext(),
            $importedBy > 0 ? $importedBy : null
        );

        if (!$overridden) {
            throw new RosterReplacementRefusedException($assessment);
        }
    }

    /**
     * @param string[] $warnings
     */
    private function importMember(
        ParsedMember $member,
        int $scoutYearId,
        array &$warnings,
        \DateTimeImmutable $asOf
    ): void
    {
        // Upsert member
        $memberId = $this->memberRepository->upsertByDeskId($member->deskId);

        // Resolve fee category
        $feeCategoryId = null;
        if ($member->feeCode !== null) {
            $feeCategoryId = $this->mappingResolver->resolveFee($member->feeCode);
        }

        // Encrypt personal data
        $emailBlindIndex = null;
        $emailEncrypted = null;
        if ($member->email !== null) {
            $normalizedEmail = strtolower($member->email);
            $emailBlindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');
            $emailEncrypted = $this->encryption->encrypt($normalizedEmail, 'member_years.email');

            // Auto-create user account
            $this->ensureUserAccount($normalizedEmail, $emailBlindIndex);
        }

        $encryptedData = [
            'first_name_encrypted' => $this->encryption->encrypt($member->firstName, 'member_years.first_name'),
            'last_name_encrypted' => $this->encryption->encrypt($member->lastName, 'member_years.last_name'),
            'gender_encrypted' => $member->gender !== null
                ? $this->encryption->encrypt($member->gender, 'member_years.gender')
                : null,
            'birth_date_encrypted' => $member->birthDate !== null
                ? $this->encryption->encrypt($member->birthDate, 'member_years.birth_date')
                : null,
            'phone_encrypted' => $member->phone !== null
                ? $this->encryption->encrypt($member->phone, 'member_years.phone')
                : null,
            'mobile_encrypted' => $member->mobile !== null
                ? $this->encryption->encrypt($member->mobile, 'member_years.mobile')
                : null,
            'email_encrypted' => $emailEncrypted,
            'email_blind_index' => $emailBlindIndex,
            'totem_encrypted' => $member->totem !== null
                ? $this->encryption->encrypt($member->totem, 'member_years.totem')
                : null,
            'quali_encrypted' => $member->quali !== null
                ? $this->encryption->encrypt($member->quali, 'member_years.quali')
                : null,
            'patrol_encrypted' => $member->patrol !== null
                ? $this->encryption->encrypt($member->patrol, 'member_years.patrol')
                : null,
            'formation_level' => $member->formationLevel,
            'federation_mail_consent' => $member->federationMailConsent,
            'unit_mail_consent' => $member->unitMailConsent,
            'fee_category_id' => $feeCategoryId,
            'unit_code' => $member->unitCode,
            // Handicap is health data (GDPR special category) → encrypted at rest.
            'handicap_encrypted' => $member->handicap !== null
                ? $this->encryption->encrypt($member->handicap, 'member_years.handicap')
                : null,
            'supplementary_insurance' => $member->supplementaryInsurance,
        ];

        // Upsert member_year
        $memberYearId = $this->memberYearRepository->upsert($memberId, $scoutYearId, $encryptedData);

        // Replace addresses
        $addresses = [];
        foreach ($member->addresses as $addr) {
            // Blind index of the comparison-normalized address (Core\
            // Member\AddressNormalizer, §8) — never stores the address in
            // clear, exact-match only, feeds Core\Member\
            // FeeEstimationService's household count.
            $normalized = AddressNormalizer::normalize($addr->street, $addr->number, $addr->box, $addr->postalCode);

            $addresses[] = [
                'address_type' => $addr->type,
                'street_encrypted' => $addr->street !== null
                    ? $this->encryption->encrypt($addr->street, 'member_addresses.street')
                    : null,
                'number_encrypted' => $addr->number !== null
                    ? $this->encryption->encrypt($addr->number, 'member_addresses.number')
                    : null,
                'box_encrypted' => $addr->box !== null
                    ? $this->encryption->encrypt($addr->box, 'member_addresses.box')
                    : null,
                'complement_encrypted' => $addr->complement !== null
                    ? $this->encryption->encrypt($addr->complement, 'member_addresses.complement')
                    : null,
                'postal_code_encrypted' => $addr->postalCode !== null
                    ? $this->encryption->encrypt($addr->postalCode, 'member_addresses.postal_code')
                    : null,
                'city_encrypted' => $addr->city !== null
                    ? $this->encryption->encrypt($addr->city, 'member_addresses.city')
                    : null,
                'country_encrypted' => $addr->country !== null
                    ? $this->encryption->encrypt($addr->country, 'member_addresses.country')
                    : null,
                'address_normalized_blind_index' => $normalized !== ''
                    ? $this->encryption->blindIndex($normalized, 'address')
                    : null,
            ];
        }
        $this->memberYearRepository->replaceAddresses($memberYearId, $addresses);

        // Replace functions.
        //
        // A Desk export carries ONE ROW PER (function × address), so a
        // member with two addresses and one function arrives here with
        // that function twice — identical in every field. Written through
        // as-is, that became two strictly identical `member_functions`
        // rows, and every reader without a DISTINCT counted the person
        // twice (section headcounts, the leadership ratio, member
        // statistics). Deduplicating here rather than at each of the
        // forty-odd read sites is the only place the two rows are still
        // known to come from one fact.
        //
        // The key is the WHOLE row: two entries differing on any field
        // are two real functions — « Animateur / Louveteaux » and
        // « Animateur / Baladins » is the ordinary case, and collapsing
        // those would lose information rather than restore it. First
        // occurrence wins, so the main function keeps its place at the
        // head of the list (Core\Member\MemberProfile::getMainFunction()
        // falls back to the first entry when nothing is flagged).
        $functions = [];
        $seenFunctions = [];
        foreach ($member->functions as $fn) {
            $functionId = $this->mappingResolver->resolveFunction($fn->functionCode);

            $branchId = null;
            if ($fn->branchCode !== null) {
                $branchId = $this->mappingResolver->resolveBranch($fn->branchCode);
            }

            $sectionId = null;
            if ($fn->sectionCode !== null && $branchId !== null) {
                $sectionId = $this->mappingResolver->resolveSection($fn->sectionCode, $branchId, $fn->sectionName);
            }

            $row = [
                'function_id' => $functionId,
                'section_id' => $sectionId,
                'age_branch_id' => $branchId,
                'start_date' => $this->parseDate($fn->startDate),
                'end_date' => $this->parseDate($fn->endDate),
                'mandate_end' => $this->parseDate($fn->mandateEnd),
                'is_main_function' => $fn->isMainFunction,
            ];

            // serialize() rather than json_encode(): it always returns a
            // string, and the row is built literally so its key order is
            // fixed.
            $key = serialize($row);
            if (isset($seenFunctions[$key])) {
                continue;
            }
            $seenFunctions[$key] = true;

            $functions[] = $row;
        }
        $this->memberYearRepository->replaceFunctions($memberYearId, $functions);

        $this->sectionMembershipService->syncForMember($memberId, $memberYearId, $scoutYearId, $asOf);
    }

    private function ensureUserAccount(string $email, string $blindIndex): void
    {
        $existing = $this->userAccountRepository->findByBlindIndex($blindIndex);
        if ($existing === null) {
            $this->userAccountRepository->create($email, false);
        }
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Try DD/MM/YYYY format
        $parts = explode('/', $value);
        if (count($parts) === 3) {
            return sprintf('%s-%s-%s', $parts[2], $parts[1], $parts[0]);
        }

        // Already in YYYY-MM-DD?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return null;
    }
}
