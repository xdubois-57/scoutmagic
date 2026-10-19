<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Modules\Registration\Api\MissingAcceptedRequest;
use Modules\Registration\Api\PassageMismatch;
use Modules\Registration\Api\PassageWithoutDestination;
use Modules\Registration\Api\PreparedImportGaps;
use Modules\Registration\Api\ReenrollmentCampaignState;
use Modules\Registration\Api\RegistrationRequestCounts;
use Modules\Registration\Api\ScoutYearMember;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use Modules\Registration\Repository\ImportedMemberRepository;
use Modules\Registration\Repository\PassageRosterRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;

/**
 * The module's implementation of its own Api\ScoutYearRegistrationReviewProvider
 * (ARCHITECTURE.md §7.5) — wired nullable by the composition root, only
 * when this module is enabled.
 *
 * A façade, and deliberately one that decides nothing new: the campaign's
 * state is ReenrollmentCampaignService's, the passages are PassageService's
 * own list (the Passage page's, to the unit), and whether an accepted
 * request's child is in an import is answered with the very key
 * ReconciliationService links on — the request's stored name-and-birth-date
 * blind index against ImportedMemberRepository's index of the year. A
 * second reconciliation, however close, would one day disagree with the
 * one that actually turns requests into members.
 */
class ScoutYearRegistrationReviewService implements ScoutYearRegistrationReviewProvider
{
    public function __construct(
        private ReenrollmentCampaignService $campaign,
        private PassageService $passageService,
        private PassageRosterRepository $roster,
        private SectionTransferRepository $transfers,
        private RegistrationRequestRepository $requests,
        private ImportedMemberRepository $importedMembers,
        private ScoutYearService $scoutYearService
    ) {
    }

    public function reenrollmentCampaign(int $targetYearId, ?\DateTimeImmutable $now = null): ?ReenrollmentCampaignState
    {
        $target = $this->scoutYearService->findById($targetYearId);
        if ($target === null) {
            return null;
        }

        $state = $this->campaign->stateFor($target['label'], $now ?? new \DateTimeImmutable());
        if ($state === null) {
            return null;
        }

        return new ReenrollmentCampaignState($state['state'], $target['label'], $state['opens'], $state['closes']);
    }

    public function requestCounts(int $targetYearId): RegistrationRequestCounts
    {
        $counts = $this->requests->countWaitingForYear($targetYearId);

        return new RegistrationRequestCounts(
            $counts['pending'],
            $counts['accepted'],
            $counts['accepted_without_intended_section']
        );
    }

    public function announcedLeavers(int $targetYearId): array
    {
        $previous = $this->previousYear($targetYearId);
        if ($previous === null) {
            return [];
        }

        return array_map(
            static fn(array $row): ScoutYearMember => new ScoutYearMember(
                $row['member_id'],
                $row['member_year_id'],
                $row['name'],
                $row['section_id']
            ),
            $this->roster->findLeavingAnimes($previous['id'])
        );
    }

    public function passagesWithoutDestination(int $targetYearId): array
    {
        $previous = $this->previousYear($targetYearId);
        if ($previous === null) {
            return [];
        }

        $passages = [];
        $branchChanges = $this->passageService->getBranchChanges($previous['id'], $previous['label'], $targetYearId);
        foreach ($branchChanges as $key => $group) {
            foreach ($group['members'] as $member) {
                if ($member['destination_section_id'] !== null) {
                    continue;
                }
                $passages[] = new PassageWithoutDestination(
                    $member['member_id'],
                    $member['name'],
                    // getBranchChanges() groups by the current section's id,
                    // and by 'none' for an animé with no section.
                    is_numeric($key) ? (int) $key : null,
                    $member['branch_year_label'],
                    count($member['destination_options'])
                );
            }
        }

        return $passages;
    }

    public function preparedImportGaps(int $targetYearId): PreparedImportGaps
    {
        return new PreparedImportGaps(
            $this->leaversStillActive($targetYearId),
            $this->passagesElsewhere($targetYearId),
            $this->acceptedRequestsMissing($targetYearId)
        );
    }

    /**
     * @return list<ScoutYearMember>
     */
    private function leaversStillActive(int $targetYearId): array
    {
        $leavers = $this->announcedLeavers($targetYearId);
        if ($leavers === []) {
            return [];
        }

        $active = $this->roster->findActiveRowsWithSection(
            array_map(static fn(ScoutYearMember $leaver): int => $leaver->memberId, $leavers),
            $targetYearId
        );

        $stillActive = [];
        foreach ($leavers as $leaver) {
            $row = $active[$leaver->memberId] ?? null;
            if ($row === null) {
                continue;
            }
            $stillActive[] = new ScoutYearMember(
                $leaver->memberId,
                $row['member_year_id'],
                $leaver->name,
                $row['section_id']
            );
        }

        return $stillActive;
    }

    /**
     * A destination is compared with the section the import gives the same
     * member. A member the import does not hold, or holds without any
     * section, is not a mismatch: there is no other section to point at.
     *
     * @return list<PassageMismatch>
     */
    private function passagesElsewhere(int $targetYearId): array
    {
        $destinations = $this->transfers->findAllForYear($targetYearId);
        if ($destinations === []) {
            return [];
        }

        $imported = $this->roster->findActiveRowsWithSection(array_keys($destinations), $targetYearId);

        $mismatches = [];
        foreach ($destinations as $memberId => $destinationSectionId) {
            $row = $imported[$memberId] ?? null;
            if ($row === null || $row['section_id'] === null || $row['section_id'] === $destinationSectionId) {
                continue;
            }
            $mismatches[] = new PassageMismatch(
                $memberId,
                $row['member_year_id'],
                $row['name'],
                $destinationSectionId,
                $row['section_id']
            );
        }
        usort($mismatches, static fn(PassageMismatch $a, PassageMismatch $b): int => strcasecmp($a->name, $b->name));

        return $mismatches;
    }

    /**
     * The two readings the module already has of "this request's child is
     * in Desk":
     *
     * - an `accepted` request is missing when NO active member of the
     *   target year carries its name-and-birth-date blind index — the key
     *   ReconciliationService links on. One or more members carrying it
     *   means the child is there, even when homonyms made the link too
     *   ambiguous to make automatically: such a request is never listed;
     * - an `encoded` request is missing when the member it was linked to
     *   has no active row in the target year. The link is the fact here.
     *
     * The blind index compares names exactly as the reconciliation
     * normalises them: a child Desk spells differently from the family's
     * form is the case a human links by hand, and reads as missing until
     * then — the same answer « Acceptées, pas encore dans Desk » gives.
     *
     * @return list<MissingAcceptedRequest>
     */
    private function acceptedRequestsMissing(int $targetYearId): array
    {
        $missing = [];

        $accepted = $this->requests->findAcceptedIdsAndBlindIndexForYear($targetYearId);
        if ($accepted !== []) {
            $importedIndexes = [];
            foreach ($this->importedMembers->findNameDobBlindIndexesForYear($targetYearId) as $member) {
                $importedIndexes[$member['name_dob_blind_index']] = true;
            }

            $missingIds = [];
            foreach ($accepted as $row) {
                if (!isset($importedIndexes[$row['name_dob_blind_index']])) {
                    $missingIds[$row['id']] = true;
                }
            }

            if ($missingIds !== []) {
                foreach ($this->requests->findAcceptedForYear($targetYearId) as $request) {
                    if (isset($missingIds[$request->id])) {
                        $missing[] = self::missing($request, MissingAcceptedRequest::STATUS_ACCEPTED);
                    }
                }
            }
        }

        $encoded = array_values(array_filter(
            $this->requests->findEncodedForYear($targetYearId),
            static fn(RegistrationRequest $request): bool => $request->linkedMemberId !== null
        ));
        if ($encoded !== []) {
            $present = $this->roster->findActiveMemberYearIds(
                array_map(static fn(RegistrationRequest $request): int => (int) $request->linkedMemberId, $encoded),
                $targetYearId
            );
            foreach ($encoded as $request) {
                if (!isset($present[(int) $request->linkedMemberId])) {
                    $missing[] = self::missing($request, MissingAcceptedRequest::STATUS_ENCODED);
                }
            }
        }

        usort(
            $missing,
            static fn(MissingAcceptedRequest $a, MissingAcceptedRequest $b): int
                => strcasecmp($a->childName, $b->childName)
        );

        return $missing;
    }

    private static function missing(RegistrationRequest $request, string $status): MissingAcceptedRequest
    {
        return new MissingAcceptedRequest(
            $request->id,
            trim($request->childFirstName . ' ' . $request->childLastName),
            $status,
            $request->intendedSectionId
        );
    }

    /**
     * The scout year before $targetYearId, looked up by label and never
     * created: a year that does not exist has nobody leaving it.
     *
     * @return array{id: int, label: string}|null
     */
    private function previousYear(int $targetYearId): ?array
    {
        $target = $this->scoutYearService->findById($targetYearId);
        if ($target === null) {
            return null;
        }

        $previous = $this->scoutYearService->findByLabel(ScoutYearService::previousLabel($target['label']));

        return $previous !== null ? ['id' => $previous['id'], 'label' => $previous['label']] : null;
    }
}
