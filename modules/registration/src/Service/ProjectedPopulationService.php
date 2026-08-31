<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Member\SectionService;
use Modules\Registration\Api\ProjectedPerson;
use Modules\Registration\Api\ProjectedPopulationProvider;
use Modules\Registration\Api\ProjectedRecipient;
use Modules\Registration\Api\ProjectedSectionTotals;
use Modules\Registration\Repository\ProjectedMemberEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The implementation behind `Api\ProjectedPopulationProvider` — a façade
 * over `ForecastService`, which keeps every decision it already made.
 *
 * **It computes nothing of its own.** Who is in the projection, where they
 * land, what counts as certain, how a member held back by
 * `scout_year_offset` ages: all of that stays in `ForecastService`, whose
 * two invariants are regression-tested. This class turns its rows into the
 * `Api` types and resolves the addresses. A second implementation of the
 * projection is the one thing that would make the interface harmful rather
 * than useful.
 *
 * The Prévisions page keeps calling `ForecastService` directly. Routing it
 * through here would make the page a consumer of its own module's public
 * contract, which buys nothing and adds a layer to read through.
 */
class ProjectedPopulationService implements ProjectedPopulationProvider
{
    public function __construct(
        private ForecastService $forecastService,
        private SlotService $slotService,
        private ScoutYearService $scoutYearService,
        private SectionService $sectionService,
        private RegistrationRequestRepository $requestRepository,
        private ProjectedMemberEmailRepository $memberEmailRepository
    ) {
    }

    /**
     * @return array<int, ProjectedPerson>
     */
    public function projectedPopulation(int $targetScoutYearId): array
    {
        return array_map(
            static fn (array $row): ProjectedPerson => new ProjectedPerson(
                memberId: $row['member_id'],
                registrationRequestId: $row['request_id'],
                sectionId: $row['section_id'],
                yearInBranch: $row['year_in_branch'],
                gender: $row['gender'],
                certain: $row['certain'],
                origin: $row['origin'],
            ),
            $this->rows($targetScoutYearId)
        );
    }

    /**
     * @return array<int, ProjectedSectionTotals>
     */
    public function projectedSectionTotals(int $targetScoutYearId): array
    {
        // includeHidden, for the same reason ForecastService does it: a
        // hidden-but-active section is an ordinary destination, and
        // dropping it here would move its members into no section at all
        // rather than into a section the consumer chooses not to show.
        $labels = [];
        foreach ($this->sectionService->getAllWithBranches(true) as $section) {
            $labels[(int) $section['id']] = $section['name'] ?? $section['desk_code'];
        }

        /** @var array<int, array{total: int, certain: int, hypothesis: int, ranks: array<int, int>, gender: array{male: int, female: int, other: int, total: int}}> $totals */
        $totals = [];
        foreach ($this->rows($targetScoutYearId) as $row) {
            $sectionId = $row['section_id'];
            // Unassigned people, and people pointing at a section that no
            // longer exists, are counted by nobody here — they belong to
            // the unit's total, not to a section's. A consumer that needs
            // them asks projectedPopulation() and looks for a null
            // sectionId, which is what that null is for.
            if ($sectionId === null || !isset($labels[$sectionId])) {
                continue;
            }

            $totals[$sectionId] ??= [
                'total' => 0,
                'certain' => 0,
                'hypothesis' => 0,
                'ranks' => [],
                'gender' => ['male' => 0, 'female' => 0, 'other' => 0, 'total' => 0],
            ];

            $totals[$sectionId]['total']++;
            $row['certain'] ? $totals[$sectionId]['certain']++ : $totals[$sectionId]['hypothesis']++;
            $totals[$sectionId]['gender'][$row['gender']]++;
            $totals[$sectionId]['gender']['total']++;

            if ($row['year_in_branch'] !== null) {
                $totals[$sectionId]['ranks'][$row['year_in_branch']] =
                    ($totals[$sectionId]['ranks'][$row['year_in_branch']] ?? 0) + 1;
            }
        }

        // Iterated over the SECTION list, not over the tally: the order is
        // then the unit's own section order (branch, then desk code —
        // SectionService::getAllWithBranches()), the same order the
        // Prévisions page lists them in, rather than whichever section the
        // first projected row happened to land in. A consumer rendering
        // this straight out gets a list that reads like the site's.
        $result = [];
        foreach (array_keys($labels) as $sectionId) {
            if (!isset($totals[$sectionId])) {
                continue;
            }

            $counts = $totals[$sectionId];
            ksort($counts['ranks']);
            $result[] = new ProjectedSectionTotals(
                sectionId: $sectionId,
                label: $labels[$sectionId],
                total: $counts['total'],
                certainTotal: $counts['certain'],
                hypothesisTotal: $counts['hypothesis'],
                byYearInBranch: $counts['ranks'],
                gender: $counts['gender'],
            );
        }

        return $result;
    }

    /**
     * @return array<int, ProjectedRecipient>
     */
    public function reachableRecipients(int $targetScoutYearId): array
    {
        $rows = $this->rows($targetScoutYearId);

        $memberIds = [];
        foreach ($rows as $row) {
            if ($row['member_id'] !== null) {
                $memberIds[] = $row['member_id'];
            }
        }

        $currentYearId = $this->currentYearIdFor($targetScoutYearId);
        $memberEmails = $currentYearId !== null
            ? $this->memberEmailRepository->findEmails($memberIds, $currentYearId, $targetScoutYearId)
            : [];

        // One decryption pass for the requests too, rather than one lookup
        // per projected row: the accepted set for a year is small, and the
        // repository already hydrates the address.
        $requestEmails = [];
        foreach ($this->requestRepository->findAcceptedForYear($targetScoutYearId) as $request) {
            $email = trim($request->email);
            if ($email !== '') {
                $requestEmails[$request->id] = $email;
            }
        }

        $recipients = [];
        $seen = [];
        foreach ($rows as $row) {
            $email = $row['member_id'] !== null
                ? ($memberEmails[$row['member_id']] ?? null)
                : ($requestEmails[$row['request_id']] ?? null);

            if ($email === null) {
                continue;
            }

            // One entry per person, not per address: a family whose two
            // children share one address is two people the caller is
            // writing about, and de-duplicating the address here would
            // silently drop one of them. The guard is on the identity.
            $key = $row['member_id'] !== null ? 'm' . $row['member_id'] : 'r' . $row['request_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $recipients[] = new ProjectedRecipient(
                memberId: $row['member_id'],
                registrationRequestId: $row['request_id'],
                email: $email,
            );
        }

        return $recipients;
    }

    /**
     * @return array<int, array{member_id: ?int, request_id: ?int, section_id: ?int, year_in_branch: ?int, gender: string, birth_year: ?int, certain: bool, origin: string}>
     */
    private function rows(int $targetScoutYearId): array
    {
        $targetYear = $this->scoutYearService->findById($targetScoutYearId);
        if ($targetYear === null) {
            return [];
        }

        $currentLabel = ScoutYearService::previousLabel((string) $targetYear['label']);
        $currentYear = $this->scoutYearService->findByLabel($currentLabel);
        if ($currentYear === null) {
            // A target year whose predecessor was never created has nothing
            // to project FROM: no continuing members, no passages. Returning
            // nothing beats inventing a current year, which would silently
            // project against the wrong roster.
            return [];
        }

        return $this->forecastService->projectedRows(
            (int) $currentYear['id'],
            (string) $currentYear['label'],
            $targetScoutYearId,
            (string) $targetYear['label'],
            $this->slotService->referenceMonthDay()
        );
    }

    private function currentYearIdFor(int $targetScoutYearId): ?int
    {
        $targetYear = $this->scoutYearService->findById($targetScoutYearId);
        if ($targetYear === null) {
            return null;
        }

        $currentYear = $this->scoutYearService->findByLabel(
            ScoutYearService::previousLabel((string) $targetYear['label'])
        );

        return $currentYear !== null ? (int) $currentYear['id'] : null;
    }
}
