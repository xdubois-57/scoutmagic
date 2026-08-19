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
use Core\Security\EncryptionService;

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
        private EncryptionService $encryption,
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
        if ($entries === []) {
            return [];
        }

        $scoutYear = $this->scoutYearService->findById($scoutYearId);
        $scoutYearLabel = $scoutYear['label'] ?? '';

        $memberYearIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberYearId, $entries)));
        $memberIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberId, $entries)));

        $memberYearRows = $this->rosterRepository->findMemberYearRows($memberYearIds);
        $addressesByMemberYear = $this->rosterRepository->findAddressRows($memberYearIds);
        $functionLabelsByMemberYear = $this->rosterRepository->findAllFunctionLabels($memberYearIds);
        $validEmailsByMember = $this->memberEmailRepository->findValidByMemberIds($memberIds);

        $currentRoster = array_map(fn(SectionRosterEntry $e) => [
            'member_id' => $e->memberId,
            'section_id' => $e->sectionId,
            'age_branch_id' => $e->ageBranchId,
        ], $entries);
        $movementByMemberId = $this->movementClassifier->classifyBatch($scoutYearId, $currentRoster);

        $sectionIdsNeeded = array_values(array_unique(array_merge(
            array_map(fn(SectionRosterEntry $e) => $e->sectionId, $entries),
            array_filter(array_map(fn(MemberMovementResult $r) => $r->previousSectionId, $movementByMemberId))
        )));
        $sectionsById = $this->sectionService->findByIds($sectionIdsNeeded);

        $rows = [];
        foreach ($entries as $entry) {
            $memberYearRow = $memberYearRows[$entry->memberYearId] ?? null;
            if ($memberYearRow === null) {
                continue;
            }

            $rows[] = $this->buildRow(
                $entry,
                $memberYearRow,
                $scoutYearLabel,
                $addressesByMemberYear[$entry->memberYearId][0] ?? null,
                $functionLabelsByMemberYear[$entry->memberYearId] ?? [],
                $validEmailsByMember[$entry->memberId] ?? [],
                $movementByMemberId[$entry->memberId] ?? new MemberMovementResult(MemberMovementStatus::UNKNOWN),
                $sectionsById[$entry->sectionId] ?? null,
                $sectionsById
            );
        }

        usort($rows, fn(MemberExportRow $a, MemberExportRow $b) => [$a->sectionName, $a->lastName, $a->firstName] <=> [$b->sectionName, $b->lastName, $b->firstName]);

        return $rows;
    }

    /**
     * @param array<string, mixed> $memberYearRow
     * @param array<string, mixed>|null $addressRow
     * @param string[] $functionLabels
     * @param \Core\Member\MemberEmail[] $validSecondaryEmails
     * @param array{id: int, desk_code: string, name: ?string, branch_name: string}|null $section
     * @param array<int, array{id: int, desk_code: string, name: ?string, branch_name: string}> $sectionsById
     */
    private function buildRow(
        SectionRosterEntry $entry,
        array $memberYearRow,
        string $scoutYearLabel,
        ?array $addressRow,
        array $functionLabels,
        array $validSecondaryEmails,
        MemberMovementResult $movement,
        ?array $section,
        array $sectionsById
    ): MemberExportRow {
        $emails = [];
        if (!empty($memberYearRow['email_encrypted'])) {
            $emails[] = $this->encryption->decrypt($memberYearRow['email_encrypted']);
        }
        foreach ($validSecondaryEmails as $secondary) {
            if (!in_array($secondary->email, $emails, true)) {
                $emails[] = $secondary->email;
            }
        }

        $previousSection = $movement->previousSectionId !== null ? ($sectionsById[$movement->previousSectionId] ?? null) : null;

        $bucketLabel = match ($entry->bucket) {
            SectionRosterEntry::BUCKET_ANIMATEUR => 'Animateur',
            SectionRosterEntry::BUCKET_INTENDANT => 'Intendant',
            default => 'Animé',
        };

        return new MemberExportRow(
            memberId: $entry->memberId,
            memberYearId: $entry->memberYearId,
            deskId: (string) $memberYearRow['desk_id'],
            scoutYearLabel: $scoutYearLabel,
            firstName: $this->encryption->decrypt($memberYearRow['first_name_encrypted']),
            lastName: $this->encryption->decrypt($memberYearRow['last_name_encrypted']),
            totem: !empty($memberYearRow['totem_encrypted']) ? $this->encryption->decrypt($memberYearRow['totem_encrypted']) : null,
            quali: !empty($memberYearRow['quali_encrypted']) ? $this->encryption->decrypt($memberYearRow['quali_encrypted']) : null,
            gender: !empty($memberYearRow['gender_encrypted']) ? $this->encryption->decrypt($memberYearRow['gender_encrypted']) : null,
            birthDate: !empty($memberYearRow['birth_date_encrypted']) ? $this->encryption->decrypt($memberYearRow['birth_date_encrypted']) : null,
            emails: $emails,
            phone: !empty($memberYearRow['phone_encrypted']) ? $this->encryption->decrypt($memberYearRow['phone_encrypted']) : null,
            mobile: !empty($memberYearRow['mobile_encrypted']) ? $this->encryption->decrypt($memberYearRow['mobile_encrypted']) : null,
            street: $addressRow !== null && !empty($addressRow['street_encrypted']) ? $this->encryption->decrypt($addressRow['street_encrypted']) : null,
            number: $addressRow !== null && !empty($addressRow['number_encrypted']) ? $this->encryption->decrypt($addressRow['number_encrypted']) : null,
            box: $addressRow !== null && !empty($addressRow['box_encrypted']) ? $this->encryption->decrypt($addressRow['box_encrypted']) : null,
            postalCode: $addressRow !== null && !empty($addressRow['postal_code_encrypted']) ? $this->encryption->decrypt($addressRow['postal_code_encrypted']) : null,
            city: $addressRow !== null && !empty($addressRow['city_encrypted']) ? $this->encryption->decrypt($addressRow['city_encrypted']) : null,
            country: $addressRow !== null && !empty($addressRow['country_encrypted']) ? $this->encryption->decrypt($addressRow['country_encrypted']) : null,
            sectionName: $section['name'] ?? $section['desk_code'] ?? null,
            sectionCode: $section['desk_code'] ?? null,
            branchName: $section['branch_name'] ?? null,
            roleBucketLabel: $bucketLabel,
            functionLabels: $functionLabels,
            isActive: (bool) $memberYearRow['is_active'],
            scoutYearOffset: (int) $memberYearRow['scout_year_offset'],
            formationLevel: $memberYearRow['formation_level'] !== null ? (string) $memberYearRow['formation_level'] : null,
            supplementaryInsurance: $memberYearRow['supplementary_insurance'] !== null ? (string) $memberYearRow['supplementary_insurance'] : null,
            leaving: (bool) $memberYearRow['leaving'],
            leavingComment: !empty($memberYearRow['leaving_comment_encrypted']) ? $this->encryption->decrypt($memberYearRow['leaving_comment_encrypted']) : null,
            handicap: !empty($memberYearRow['handicap_encrypted']) ? $this->encryption->decrypt($memberYearRow['handicap_encrypted']) : null,
            movementStatusLabel: $movement->status->label(),
            previousSectionName: $previousSection['name'] ?? $previousSection['desk_code'] ?? null,
            previousBranchName: $previousSection['branch_name'] ?? null
        );
    }
}
