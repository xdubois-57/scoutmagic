<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Movement\MemberMovementClassifierService;
use Core\Member\Movement\MemberMovementResult;
use Core\Member\Movement\MemberMovementStatus;
use Core\Security\EncryptionService;
use Core\Service\TextNormalizerService;

/**
 * Assembles the section-roster page's data: for a set of sections and a
 * scout year, every member split into three buckets (animateurs,
 * intendants, animés), with every known contact and a year-over-year
 * movement classification — all built from a small, fixed number of
 * batched queries, never one query per member (ARCHITECTURE.md's N+1 rule).
 */
final class SectionRosterService
{
    public function __construct(
        private SectionRosterRepository $repository,
        private EncryptionService $encryption,
        private MemberEmailRepository $memberEmailRepository,
        private MemberMovementClassifierService $movementClassifier
    ) {
    }

    /**
     * @param int[] $sectionIds
     * @return array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[], animes: MemberRosterRow[]}>
     *         keyed by section_id — a section with no member in a given
     *         bucket simply has an empty array there, never a missing key
     */
    public function buildRoster(array $sectionIds, int $scoutYearId): array
    {
        $empty = [SectionRosterEntry::BUCKET_ANIMATEUR => [], SectionRosterEntry::BUCKET_INTENDANT => [], SectionRosterEntry::BUCKET_ANIME => []];
        $bySection = array_fill_keys(array_map('intval', $sectionIds), $empty);

        $entries = $this->repository->findRosterEntries($sectionIds, $scoutYearId);
        if ($entries === []) {
            return $this->mapBuckets($bySection);
        }

        $memberYearIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberYearId, $entries)));
        $memberIds = array_values(array_unique(array_map(fn(SectionRosterEntry $e) => $e->memberId, $entries)));

        $memberYearRows = $this->repository->findMemberYearRows($memberYearIds);
        $validEmailsByMember = $this->memberEmailRepository->findValidByMemberIds($memberIds);

        $currentRoster = array_map(fn(SectionRosterEntry $e) => [
            'member_id' => $e->memberId,
            'section_id' => $e->sectionId,
            'age_branch_id' => $e->ageBranchId,
        ], $entries);
        $movementByMemberId = $this->movementClassifier->classifyBatch($scoutYearId, $currentRoster);

        foreach ($entries as $entry) {
            $row = $this->buildRow($entry, $memberYearRows[$entry->memberYearId] ?? null, $validEmailsByMember[$entry->memberId] ?? [], $movementByMemberId[$entry->memberId] ?? new MemberMovementResult(MemberMovementStatus::UNKNOWN));
            if ($row === null) {
                continue;
            }
            $bySection[$entry->sectionId][$entry->bucket][] = $row;
        }

        foreach ($bySection as $sectionId => $buckets) {
            foreach ($buckets as $bucket => $rows) {
                usort($rows, fn(MemberRosterRow $a, MemberRosterRow $b) => strcasecmp(
                    $a->lastName . ' ' . $a->firstName,
                    $b->lastName . ' ' . $b->firstName
                ));
                $bySection[$sectionId][$bucket] = $rows;
            }
        }

        return $this->mapBuckets($bySection);
    }

    /**
     * @param array<string, mixed>|null $memberYearRow
     * @param MemberEmail[] $validSecondaryEmails
     */
    private function buildRow(SectionRosterEntry $entry, ?array $memberYearRow, array $validSecondaryEmails, MemberMovementResult $movement): ?MemberRosterRow
    {
        if ($memberYearRow === null) {
            return null;
        }

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
            $phones[] = ['label' => 'Téléphone', 'value' => TextNormalizerService::normalizePhone($this->encryption->decrypt($memberYearRow['phone_encrypted'], 'member_years.phone'))];
        }
        if (!empty($memberYearRow['mobile_encrypted'])) {
            $phones[] = ['label' => 'GSM', 'value' => TextNormalizerService::normalizePhone($this->encryption->decrypt($memberYearRow['mobile_encrypted'], 'member_years.mobile'))];
        }

        return new MemberRosterRow(
            memberYearId: $entry->memberYearId,
            memberId: $entry->memberId,
            firstName: $this->encryption->decrypt($memberYearRow['first_name_encrypted'], 'member_years.first_name'),
            lastName: $this->encryption->decrypt($memberYearRow['last_name_encrypted'], 'member_years.last_name'),
            totem: !empty($memberYearRow['totem_encrypted']) ? $this->encryption->decrypt($memberYearRow['totem_encrypted'], 'member_years.totem') : null,
            functionLabel: $entry->functionLabel,
            bucket: $entry->bucket,
            emails: $emails,
            phones: $phones,
            movement: $movement
        );
    }

    /**
     * @param array<int, array<string, MemberRosterRow[]>> $bySection
     * @return array<int, array{animateurs: MemberRosterRow[], intendants: MemberRosterRow[], animes: MemberRosterRow[]}>
     */
    private function mapBuckets(array $bySection): array
    {
        $result = [];
        foreach ($bySection as $sectionId => $buckets) {
            $result[$sectionId] = [
                'animateurs' => $buckets[SectionRosterEntry::BUCKET_ANIMATEUR] ?? [],
                'intendants' => $buckets[SectionRosterEntry::BUCKET_INTENDANT] ?? [],
                'animes' => $buckets[SectionRosterEntry::BUCKET_ANIME] ?? [],
            ];
        }
        return $result;
    }
}
