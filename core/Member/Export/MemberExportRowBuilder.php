<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Export;

use Core\Config\ScoutYearService;
use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementResult;
use Core\Member\Movement\MemberMovementStatus;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionRosterEntry;
use Core\Member\SectionRosterRepository;
use Core\Member\SectionService;
use Core\Service\TextNormalizerService;

/**
 * Builds the canonical MemberExportRow[] for "every member (animateurs,
 * intendants, animés) of these sections, this scout year" — the same
 * roster query the on-screen page uses (SectionRosterRepository), extended
 * with the extra fields the export needs but the screen doesn't (address,
 * every function, formation/insurance/handicap, departure marking).
 *
 * This is the one place a screen-specific selection turns into the core's
 * canonical export row shape — a future screen with a different selection
 * (e.g. "only animés", "one specific member") would get its own small
 * builder/query but still hand MemberExportRow[] to the same
 * MemberExportService, never redefine columns itself.
 */
final class MemberExportRowBuilder
{
    public function __construct(
        private SectionRosterRepository $rosterRepository,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService,
        private MemberEmailRepository $memberEmailRepository,
        private MemberMovementClassifierService $movementClassifier
    ) {
    }

    /**
     * @param int[] $sectionIds
     * @return MemberExportRow[]
     */
    public function buildForSections(array $sectionIds, int $scoutYearId): array
    {
        $entries = $this->rosterRepository->findRosterEntries($sectionIds, $scoutYearId);

        return $this->buildRows(
            $entries,
            $this->rosterRepository->findExportRecords(
                array_map(fn(SectionRosterEntry $e) => $e->memberYearId, $entries)
            ),
            $scoutYearId
        );
    }

    /**
     * The admin member-search export's selection: an explicit set of
     * member_year ids (all search results, or the checked ones). Unlike
     * buildForSections() this must not drop a member without any function
     * or an inactive one — the search page shows them, so its export
     * includes them, with empty section/function columns (a synthetic
     * sectionless entry, excluded from the movement classifier's input
     * so their movement status honestly reads "unknown").
     *
     * @param int[] $memberYearIds
     * @return MemberExportRow[]
     */
    public function buildForMemberYears(array $memberYearIds, int $scoutYearId): array
    {
        $memberYearIds = array_values(array_unique(array_map('intval', $memberYearIds)));
        $entries = $this->rosterRepository->findEntriesByMemberYears($memberYearIds);
        $records = $this->rosterRepository->findExportRecords($memberYearIds);

        $covered = [];
        foreach ($entries as $entry) {
            $covered[$entry->memberYearId] = true;
        }
        $uncoveredIds = array_values(array_filter($memberYearIds, fn(int $id) => !isset($covered[$id])));
        if ($uncoveredIds !== []) {
            foreach ($uncoveredIds as $memberYearId) {
                if (!isset($records[$memberYearId])) {
                    continue;
                }
                $entries[] = new SectionRosterEntry(
                    sectionId: 0,
                    ageBranchId: 0,
                    memberYearId: $memberYearId,
                    memberId: $records[$memberYearId]->memberId,
                    bucket: SectionRosterEntry::BUCKET_ANIME,
                    functionLabel: '',
                    isMainFunction: false
                );
            }
        }

        return $this->buildRows($entries, $records, $scoutYearId);
    }

    /**
     * @param SectionRosterEntry[] $entries
     * @param array<int, MemberExportRecord> $records keyed by member_year id
     * @return MemberExportRow[]
     */
    private function buildRows(array $entries, array $records, int $scoutYearId): array
    {
        if ($entries === []) {
            return [];
        }

        $scoutYear = $this->scoutYearService->findById($scoutYearId);
        $scoutYearLabel = $scoutYear['label'] ?? '';

        $memberYearIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberYearId, $entries)));
        $memberIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberId, $entries)));

        $functionLabelsByMemberYear = $this->rosterRepository->findAllFunctionLabels($memberYearIds);
        $validEmailsByMember = $this->memberEmailRepository->findValidByMemberIds($memberIds);

        // Synthetic sectionless entries (buildForMemberYears()) carry
        // sectionId 0 — never fed to the classifier, whose input is a real
        // (section, branch) placement; they fall back to UNKNOWN below.
        $currentRoster = array_map(
            fn(SectionRosterEntry $e) => [
                'member_id' => $e->memberId,
                'section_id' => $e->sectionId,
                'age_branch_id' => $e->ageBranchId,
            ],
            array_values(array_filter($entries, fn(SectionRosterEntry $e) => $e->sectionId > 0))
        );
        $movementByMemberId = $this->movementClassifier->classifyBatch($scoutYearId, $currentRoster);

        $sectionIdsNeeded = array_values(array_filter(array_unique(array_merge(
            array_map(fn(SectionRosterEntry $e) => $e->sectionId, $entries),
            array_filter(array_map(fn(MemberMovementResult $r) => $r->previousSectionId, $movementByMemberId))
        ))));
        $sectionsById = $this->sectionService->findByIds($sectionIdsNeeded);

        $rows = [];
        foreach ($entries as $entry) {
            $record = $records[$entry->memberYearId] ?? null;
            if ($record === null) {
                continue;
            }

            $rows[] = $this->buildRow(
                $entry,
                $record,
                $scoutYearLabel,
                $functionLabelsByMemberYear[$entry->memberYearId] ?? [],
                $validEmailsByMember[$entry->memberId] ?? [],
                $movementByMemberId[$entry->memberId] ?? new MemberMovementResult(MemberMovementStatus::UNKNOWN),
                $sectionsById[$entry->sectionId] ?? null,
                $sectionsById
            );
        }

        usort(
            $rows,
            fn(
                MemberExportRow $a,
                MemberExportRow $b
            ) => [$a->sectionName, $a->lastName, $a->firstName] <=> [$b->sectionName, $b->lastName, $b->firstName]
        );

        return $rows;
    }

    /**
     * @param string[] $functionLabels
     * @param \Core\Member\MemberEmail[] $validSecondaryEmails
     * @param array{id: int, desk_code: string, name: ?string, branch_name: string}|null $section
     * @param array<int, array{id: int, desk_code: string, name: ?string, branch_name: string}> $sectionsById
     */
    private function buildRow(
        SectionRosterEntry $entry,
        MemberExportRecord $record,
        string $scoutYearLabel,
        array $functionLabels,
        array $validSecondaryEmails,
        MemberMovementResult $movement,
        ?array $section,
        array $sectionsById
    ): MemberExportRow {
        $emails = [];
        if ($record->email !== null) {
            $emails[] = $record->email;
        }
        foreach ($validSecondaryEmails as $secondary) {
            if (!in_array($secondary->email, $emails, true)) {
                $emails[] = $secondary->email;
            }
        }

        $phones = [];
        if ($record->phone !== null) {
            $phones[] = 'Téléphone : ' . TextNormalizerService::normalizePhone($record->phone);
        }
        if ($record->mobile !== null) {
            $phones[] = 'GSM : ' . TextNormalizerService::normalizePhone($record->mobile);
        }

        $previousSection = $movement->previousSectionId !== null
            ? ($sectionsById[$movement->previousSectionId] ?? null)
            : null;

        $bucketLabel = match ($entry->bucket) {
            SectionRosterEntry::BUCKET_ANIMATEUR => 'Animateur',
            SectionRosterEntry::BUCKET_INTENDANT => 'Intendant',
            default => 'Animé',
        };

        return new MemberExportRow(
            memberId: $entry->memberId,
            memberYearId: $entry->memberYearId,
            deskId: $record->deskId,
            scoutYearLabel: $scoutYearLabel,
            firstName: $record->firstName,
            lastName: $record->lastName,
            totem: $record->totem,
            quali: $record->quali,
            gender: $record->gender,
            birthDate: $record->birthDate,
            emails: $emails,
            phones: $phones,
            street: $record->street,
            number: $record->number,
            box: $record->box,
            postalCode: $record->postalCode,
            city: $record->city,
            country: $record->country,
            sectionName: $section['name'] ?? $section['desk_code'] ?? null,
            sectionCode: $section['desk_code'] ?? null,
            branchName: $section['branch_name'] ?? null,
            roleBucketLabel: $bucketLabel,
            functionLabels: $functionLabels,
            isActive: $record->isActive,
            scoutYearOffset: $record->scoutYearOffset,
            formationLevel: $record->formationLevel,
            supplementaryInsurance: $record->supplementaryInsurance,
            leaving: $record->leaving,
            leavingComment: $record->leavingComment,
            handicap: $record->handicap,
            movementStatusLabel: $movement->status->label(),
            previousSectionName: $previousSection['name'] ?? $previousSection['desk_code'] ?? null,
            previousBranchName: $previousSection['branch_name'] ?? null
        );
    }
}
