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
        return $this->buildRows($this->rosterRepository->findRosterEntries($sectionIds, $scoutYearId), $scoutYearId);
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

        $covered = [];
        foreach ($entries as $entry) {
            $covered[$entry->memberYearId] = true;
        }
        $uncoveredIds = array_values(array_filter($memberYearIds, fn(int $id) => !isset($covered[$id])));
        if ($uncoveredIds !== []) {
            foreach ($this->rosterRepository->findMemberYearRows($uncoveredIds) as $memberYearId => $row) {
                $entries[] = new SectionRosterEntry(
                    sectionId: 0,
                    ageBranchId: 0,
                    memberYearId: (int) $memberYearId,
                    memberId: (int) $row['member_id'],
                    bucket: SectionRosterEntry::BUCKET_ANIME,
                    functionLabel: '',
                    isMainFunction: false
                );
            }
        }

        return $this->buildRows($entries, $scoutYearId);
    }

    /**
     * @param SectionRosterEntry[] $entries
     * @return MemberExportRow[]
     */
    private function buildRows(array $entries, int $scoutYearId): array
    {
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

        // Synthetic sectionless entries (buildForMemberYears()) carry
        // sectionId 0 — never fed to the classifier, whose input is a real
        // (section, branch) placement; they fall back to UNKNOWN below.
        $currentRoster = array_map(fn(SectionRosterEntry $e) => [
            'member_id' => $e->memberId,
            'section_id' => $e->sectionId,
            'age_branch_id' => $e->ageBranchId,
        ], array_values(array_filter($entries, fn(SectionRosterEntry $e) => $e->sectionId > 0)));
        $movementByMemberId = $this->movementClassifier->classifyBatch($scoutYearId, $currentRoster);

        $sectionIdsNeeded = array_values(array_filter(array_unique(array_merge(
            array_map(fn(SectionRosterEntry $e) => $e->sectionId, $entries),
            array_filter(array_map(fn(MemberMovementResult $r) => $r->previousSectionId, $movementByMemberId))
        ))));
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

        usort($rows,
            fn(
                MemberExportRow $a,
                MemberExportRow $b
            ) => [$a->sectionName, $a->lastName, $a->firstName] <=> [$b->sectionName, $b->lastName, $b->firstName]
            );

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
            $emails[] = $this->encryption->decrypt($memberYearRow['email_encrypted'], 'member_years.email');
        }
        foreach ($validSecondaryEmails as $secondary) {
            if (!in_array($secondary->email, $emails, true)) {
                $emails[] = $secondary->email;
            }
        }

        $phones = [];
        if (!empty($memberYearRow['phone_encrypted'])) {
            $phones[] = 'Téléphone : ' . TextNormalizerService::normalizePhone($this->encryption->decrypt(
                $memberYearRow['phone_encrypted'],
                'member_years.phone'
            ));
        }
        if (!empty($memberYearRow['mobile_encrypted'])) {
            $phones[] = 'GSM : ' . TextNormalizerService::normalizePhone($this->encryption->decrypt(
                $memberYearRow['mobile_encrypted'],
                'member_years.mobile'
            ));
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
            deskId: (string) $memberYearRow['desk_id'],
            scoutYearLabel: $scoutYearLabel,
            firstName: $this->encryption->decrypt($memberYearRow['first_name_encrypted'], 'member_years.first_name'),
            lastName: $this->encryption->decrypt($memberYearRow['last_name_encrypted'], 'member_years.last_name'),
            totem: !empty($memberYearRow['totem_encrypted'])
                ? $this->encryption->decrypt($memberYearRow['totem_encrypted'], 'member_years.totem')
                : null,
            quali: !empty($memberYearRow['quali_encrypted'])
                ? $this->encryption->decrypt($memberYearRow['quali_encrypted'], 'member_years.quali')
                : null,
            gender: !empty($memberYearRow['gender_encrypted'])
                ? $this->encryption->decrypt($memberYearRow['gender_encrypted'], 'member_years.gender')
                : null,
            birthDate: !empty($memberYearRow['birth_date_encrypted'])
                ? $this->encryption->decrypt($memberYearRow['birth_date_encrypted'], 'member_years.birth_date')
                : null,
            emails: $emails,
            phones: $phones,
            street: $addressRow !== null && !empty($addressRow['street_encrypted'])
                ? $this->encryption->decrypt($addressRow['street_encrypted'], 'member_addresses.street')
                : null,
            number: $addressRow !== null && !empty($addressRow['number_encrypted'])
                ? $this->encryption->decrypt($addressRow['number_encrypted'], 'member_addresses.number')
                : null,
            box: $addressRow !== null && !empty($addressRow['box_encrypted'])
                ? $this->encryption->decrypt($addressRow['box_encrypted'], 'member_addresses.box')
                : null,
            postalCode: $addressRow !== null && !empty($addressRow['postal_code_encrypted'])
                ? $this->encryption->decrypt($addressRow['postal_code_encrypted'], 'member_addresses.postal_code')
                : null,
            city: $addressRow !== null && !empty($addressRow['city_encrypted'])
                ? $this->encryption->decrypt($addressRow['city_encrypted'], 'member_addresses.city')
                : null,
            country: $addressRow !== null && !empty($addressRow['country_encrypted'])
                ? $this->encryption->decrypt($addressRow['country_encrypted'], 'member_addresses.country')
                : null,
            sectionName: $section['name'] ?? $section['desk_code'] ?? null,
            sectionCode: $section['desk_code'] ?? null,
            branchName: $section['branch_name'] ?? null,
            roleBucketLabel: $bucketLabel,
            functionLabels: $functionLabels,
            isActive: (bool) $memberYearRow['is_active'],
            scoutYearOffset: (int) $memberYearRow['scout_year_offset'],
            formationLevel: $memberYearRow['formation_level'] !== null
                ? (string) $memberYearRow['formation_level']
                : null,
            supplementaryInsurance: $memberYearRow['supplementary_insurance'] !== null
                ? (string) $memberYearRow['supplementary_insurance']
                : null,
            leaving: (bool) $memberYearRow['leaving'],
            leavingComment: !empty($memberYearRow['leaving_comment_encrypted']) ? $this->encryption->decrypt(
                $memberYearRow['leaving_comment_encrypted'],
                'member_years.leaving_comment'
            ) : null,
            handicap: !empty($memberYearRow['handicap_encrypted'])
                ? $this->encryption->decrypt($memberYearRow['handicap_encrypted'], 'member_years.handicap')
                : null,
            movementStatusLabel: $movement->status->label(),
            previousSectionName: $previousSection['name'] ?? $previousSection['desk_code'] ?? null,
            previousBranchName: $previousSection['branch_name'] ?? null
        );
    }
}
