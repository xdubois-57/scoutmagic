<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Member\MemberYearService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Repository\AgeBracket;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\QueueCandidate;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;

/**
 * The recommendation Oui / Peut-être / Non on each pending request
 * (issue #843, D3 and D5) — staff only, never shown to a family.
 *
 * It is computed **per slot** — branch × year in branch, the only unit a
 * capacity exists for — as a queue:
 *
 * 1. the requests matching a « Toujours Oui » criterion first, by date of
 *    filing; they are always Oui and may take the slot past its capacity;
 * 2. then the others, sorted lexicographically by the « Classement »
 *    criteria in the order chosen, then by date of filing;
 * 3. each request is judged on the occupancy BEFORE it — the slot's own
 *    (Service\SlotService: projected animés + accepted requests), plus
 *    every request ahead of it counted as accepted — read through the same
 *    two thresholds as the public level (SlotMath::levelForOccupancy()).
 *    The number of places is the limit that bites at the end: the locked
 *    line after « Classement » in the « Priorités » box.
 *
 * « Fratrie déjà dans l'unité » counts a declared sibling only while they
 * are still in the unit — an active enrolment in the current scout year or
 * in the year the request targets
 * (RegistrationRequestRepository::findPresentSiblingMemberIdsForRequests()).
 * The screens that show the siblings keep showing every declared one.
 *
 * Left out of the queue, with an explanation and no level: a possible
 * duplicate — the same child (`name_dob_blind_index`) filed twice in the
 * same year — except the one request of that child that stays (its
 * oldest live one, or the one already accepted or encoded:
 * keptRequestByChild()); and a birth year outside every age bracket.
 *
 * Nothing at all is computed while waitlists are not managed, nor for a
 * past scout year, which is consultation only (R8). The result is
 * deterministic: every order ends on the date of filing, then the id.
 *
 * The explanations are built from the criteria actually evaluated, with
 * the wording of the mock-up's `recommend()` — the chantier's acceptance
 * criteria quote them word for word.
 *
 * @phpstan-type QueueSlotShape array{
 *   age_branch_id: int, year_in_branch: int, label: string, capacity: ?int, occupied: int
 * }
 * @phpstan-type QueueFactsShape array{
 *   siblings: array<int, array<int>>, previous_no_room: array<string, string>
 * }
 */
class RecommendationService
{
    public const EXPLANATION_OUT_OF_BRACKETS =
        "Pas de recommandation : l'année de naissance est hors des tranches d'âge de l'unité.";

    public function __construct(
        private RegistrationRequestRepository $requestRepository,
        private SlotService $slotService,
        private AgeBracketRepository $ageBracketRepository,
        private ScoutYearService $scoutYearService,
        private ScoutYearResolver $scoutYearResolver,
        private PriorityOrderService $priorityOrderService
    ) {
    }

    /**
     * Whether recommendations are computed at all for $scoutYearId: waitlists
     * are managed, and the year is the current one or a later one.
     */
    public function isAvailableForYear(int $scoutYearId): bool
    {
        return $this->resolveLiveYear($scoutYearId) !== null;
    }

    /**
     * The recommendation of every pending request of $scoutYearId, keyed by
     * request id: the queued ones first, slot by slot in branch order and in
     * queue order within a slot, then the ones left out of the queue, in
     * order of filing. Empty when isAvailableForYear() is false.
     *
     * @return array<int, Recommendation>
     */
    public function forYear(int $scoutYearId): array
    {
        $year = $this->resolveLiveYear($scoutYearId);
        if ($year === null) {
            return [];
        }

        // In order of filing (the repository's own ORDER BY).
        $candidates = $this->requestRepository->findQueueCandidatesForYear($scoutYearId);
        $keptByChild = self::keptRequestByChild($candidates);

        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel((string) $year['label']),
            $this->slotService->referenceMonthDay()
        );

        /** @var array<int, Recommendation> $leftOut */
        $leftOut = [];
        /** @var array<string, array<int, QueueCandidate>> $queuedBySlot */
        $queuedBySlot = [];
        foreach ($candidates as $candidate) {
            if ($candidate->status !== RegistrationRequest::STATUS_PENDING) {
                continue;
            }
            $slot = SlotMath::slotForBirthDate($brackets, (string) $candidate->birthDate, $referenceYear);
            if ($slot === null) {
                $leftOut[$candidate->id] = new Recommendation(
                    $candidate->id,
                    null,
                    self::EXPLANATION_OUT_OF_BRACKETS
                );
                continue;
            }
            $kept = $keptByChild[$candidate->nameDobBlindIndex];
            if ($kept->id !== $candidate->id) {
                $leftOut[$candidate->id] = new Recommendation(
                    $candidate->id,
                    null,
                    "Pas de recommandation tant que le doublon possible de la demande #{$kept->id} "
                        . "n'est pas réglé.",
                    duplicateOfRequestId: $kept->id
                );
                continue;
            }
            $queuedBySlot[$slot['age_branch_id'] . ':' . $slot['year_in_branch']][] = $candidate;
        }

        if ($queuedBySlot === []) {
            return $leftOut;
        }

        $queued = $this->evaluateQueues($queuedBySlot, $brackets, $year);

        return $queued + $leftOut;
    }

    /**
     * Which request of each child stays: a child filed twice in the same
     * year is one child, and only one of its live requests may be judged.
     * It is the oldest one — unless the child already holds a place, by an
     * accepted or encoded request, which is then the one kept whatever the
     * filing order: a pending request of a child already accepted is a
     * duplicate to settle, never a second place to recommend.
     *
     * @param array<QueueCandidate> $candidates in order of filing
     * @return array<string, QueueCandidate> child key => the request kept
     */
    private static function keptRequestByChild(array $candidates): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            $current = $kept[$candidate->nameDobBlindIndex] ?? null;
            if ($current === null
                || ($current->status === RegistrationRequest::STATUS_PENDING
                    && $candidate->status !== RegistrationRequest::STATUS_PENDING)
            ) {
                $kept[$candidate->nameDobBlindIndex] = $candidate;
            }
        }

        return $kept;
    }

    /**
     * The recommendation of one request, or null when it has none to have:
     * not pending, or nothing is computed for its year.
     *
     * It computes the request's whole year, which a queue needs: a page
     * showing several requests calls forYear() once instead.
     */
    public function forRequest(RegistrationRequest $request): ?Recommendation
    {
        if ($request->status !== RegistrationRequest::STATUS_PENDING) {
            return null;
        }

        return $this->forYear($request->scoutYearId)[$request->id] ?? null;
    }

    /**
     * @param array<string, array<int, QueueCandidate>> $queuedBySlot
     * @param array<AgeBracket> $brackets
     * @param array{id: int, label: string, start_date: string, end_date: string} $year
     * @return array<int, Recommendation>
     */
    private function evaluateQueues(array $queuedBySlot, array $brackets, array $year): array
    {
        $order = $this->priorityOrderService->current();
        $evaluated = array_merge($order->always(), $order->rank());

        $queuedIds = [];
        $childKeys = [];
        foreach ($queuedBySlot as $slotCandidates) {
            foreach ($slotCandidates as $candidate) {
                $queuedIds[] = $candidate->id;
                $childKeys[] = $candidate->nameDobBlindIndex;
            }
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        // Each criterion is read only when it is evaluated: a disabled one
        // costs no query, and cannot leak into an explanation. A declared
        // sibling counts only while still in the unit — an active enrolment
        // in the current year or in the year the request targets — not
        // because the family named them once (the maintainer's decision).
        $siblings = in_array(PriorityOrder::CRITERION_SIBLING, $evaluated, true)
            ? $this->requestRepository->findPresentSiblingMemberIdsForRequests($queuedIds, (int) $publicYear['id'])
            : [];
        $previousNoRoom = in_array(PriorityOrder::CRITERION_PREVIOUS_NO_ROOM, $evaluated, true)
            ? $this->requestRepository->findNoRoomRefusalYearLabels($childKeys, (string) $year['start_date'])
            : [];

        $facts = ['siblings' => $siblings, 'previous_no_room' => $previousNoRoom];

        $slotRows = [];
        foreach ($this->slotService->capacityBreakdownForYear(
            (int) $year['id'],
            (string) $year['label'],
            (int) $publicYear['id']
        ) as $row) {
            $slotRows[$row['age_branch_id'] . ':' . $row['year_in_branch']] = $row;
        }
        $thresholds = $this->slotService->levelThresholds();

        $recommendations = [];
        foreach ($brackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $slotKey = $bracket->ageBranchId . ':' . $yearInBranch;
                if (!isset($queuedBySlot[$slotKey])) {
                    continue;
                }
                $slot = [
                    'age_branch_id' => $bracket->ageBranchId,
                    'year_in_branch' => $yearInBranch,
                    'label' => self::slotLabel($bracket, $yearInBranch),
                    'capacity' => $slotRows[$slotKey]['capacity'] ?? null,
                    'occupied' => $slotRows[$slotKey]['occupied'] ?? 0,
                ];
                foreach ($this->queueForSlot($queuedBySlot[$slotKey], $slot, $order, $facts, $thresholds) as $entry) {
                    $recommendations[$entry->requestId] = $entry;
                }
            }
        }

        return $recommendations;
    }

    /**
     * One slot's queue (D5): « Toujours Oui » by date of filing, then the
     * others in the order of the « Classement » criteria, each judged on the
     * occupancy before it.
     *
     * @param array<int, QueueCandidate> $candidates
     * @param QueueSlotShape $slot
     * @param QueueFactsShape $facts
     * @param array{yes: int, maybe: int} $thresholds
     * @return array<int, Recommendation> in queue order
     */
    private function queueForSlot(
        array $candidates,
        array $slot,
        PriorityOrder $order,
        array $facts,
        array $thresholds
    ): array {
        $always = [];
        $ranked = [];
        foreach ($candidates as $candidate) {
            if (self::matchesAny($candidate, $order->always(), $facts)) {
                $always[] = $candidate;
            } else {
                $ranked[] = $candidate;
            }
        }

        usort($always, [QueueCandidate::class, 'compareFiling']);
        usort($ranked, static function (QueueCandidate $a, QueueCandidate $b) use ($order, $facts): int {
            foreach ($order->rank() as $criterion) {
                $comparison = $criterion === PriorityOrder::CRITERION_ARRIVAL
                    ? $a->receivedAt->getTimestamp() <=> $b->receivedAt->getTimestamp()
                    // A request the criterion favours comes first.
                    : (int) self::matches($b, $criterion, $facts) <=> (int) self::matches($a, $criterion, $facts);
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return QueueCandidate::compareFiling($a, $b);
        });

        $entries = [];
        $occupied = $slot['occupied'];
        $position = 0;
        foreach ($always as $candidate) {
            $entries[] = new Recommendation(
                $candidate->id,
                SlotMath::LEVEL_YES,
                $this->explainAlways($candidate, $slot, $occupied, $order, $facts),
                Recommendation::KIND_ALWAYS,
                $slot['age_branch_id'],
                $slot['year_in_branch'],
                $slot['label'],
                ++$position,
                null,
                null,
                $occupied,
                $slot['capacity'],
                receivedAt: $candidate->receivedAt
            );
            $occupied++;
        }

        $rankedCount = count($ranked);
        foreach ($ranked as $index => $candidate) {
            $level = SlotMath::levelForOccupancy(
                $slot['capacity'],
                $occupied,
                $thresholds['yes'],
                $thresholds['maybe']
            );
            $entries[] = new Recommendation(
                $candidate->id,
                $level,
                $this->explainRanked(
                    $candidate,
                    $slot,
                    ['level' => $level, 'rank' => $index + 1, 'of' => $rankedCount, 'before' => $occupied],
                    $thresholds,
                    $order,
                    $facts
                ),
                Recommendation::KIND_RANK,
                $slot['age_branch_id'],
                $slot['year_in_branch'],
                $slot['label'],
                ++$position,
                $index + 1,
                $rankedCount,
                $occupied,
                $slot['capacity'],
                receivedAt: $candidate->receivedAt
            );
            $occupied++;
        }

        return $entries;
    }

    /**
     * « Oui — règle « Toujours Oui » : … . », naming the « Toujours Oui »
     * criteria the request matches, in the order of that zone, plus the
     * warning when accepting it takes the slot past its capacity.
     *
     * @param QueueSlotShape $slot
     * @param QueueFactsShape $facts
     */
    private function explainAlways(
        QueueCandidate $candidate,
        array $slot,
        int $occupiedBefore,
        PriorityOrder $order,
        array $facts
    ): string {
        $matched = [];
        foreach ($order->always() as $criterion) {
            if (!self::matches($candidate, $criterion, $facts)) {
                continue;
            }
            $matched[] = match ($criterion) {
                PriorityOrder::CRITERION_SIBLING => "fratrie déjà présente dans l'unité",
                default => 'déjà demandée en ' . self::previousNoRoomYear($candidate, $facts)
                    . ' et restée sans place',
            };
        }

        $text = 'Oui — règle « Toujours Oui » : ' . implode(', ', $matched) . '.';
        if ($slot['capacity'] === 0) {
            $text .= " Attention : {$slot['label']} est fermée aux inscriptions (capacité 0), "
                . 'cette acceptation dépasse la capacité.';
        } elseif ($slot['capacity'] !== null && $occupiedBefore >= $slot['capacity']) {
            $text .= " Attention : {$slot['label']} est déjà complète, cette acceptation dépasse la capacité.";
        }

        return $text;
    }

    /**
     * A ranked request's explanation: its rank, the occupancy before it
     * against the threshold that decided, the capacity warning, and the
     * « Classement » criteria that favoured it, in the order of that zone.
     *
     * @param QueueSlotShape $slot
     * @param array{level: string, rank: int, of: int, before: int} $place
     * @param array{yes: int, maybe: int} $thresholds
     * @param QueueFactsShape $facts
     */
    private function explainRanked(
        QueueCandidate $candidate,
        array $slot,
        array $place,
        array $thresholds,
        PriorityOrder $order,
        array $facts
    ): string {
        $label = $slot['label'];
        $capacity = $slot['capacity'];
        if ($capacity === null) {
            return "Oui — aucune limite n'est fixée pour {$label}.";
        }
        if ($capacity === 0) {
            return "Non — {$label} est fermée aux inscriptions (capacité 0).";
        }

        $rank = $place['rank'];
        $level = $place['level'];
        // The threshold the occupancy is said to be beyond, if any — the
        // percentage shown must never read as within it.
        $exceeded = match ($level) {
            SlotMath::LEVEL_MAYBE => $thresholds['yes'],
            SlotMath::LEVEL_NO => $thresholds['maybe'],
            default => null,
        };
        $lead = $rank . ($rank === 1 ? 're' : 'e') . " demande classée sur {$place['of']} pour {$label}. "
            . 'Avant elle, ' . self::placesBefore($place['before'], $capacity, $exceeded);
        $full = $place['before'] >= $capacity;

        $text = match (true) {
            $level === SlotMath::LEVEL_YES => "Oui — {$lead}, dans la limite du « Oui » ({$thresholds['yes']} %).",
            $level === SlotMath::LEVEL_MAYBE => "Peut-être — {$lead}, au-delà de la limite du « Oui » "
                . "({$thresholds['yes']} %).",
            $full && $thresholds['maybe'] <= 100 => "Non — {$label} serait complète avant elle.",
            default => "Non — {$lead}, au-delà de la limite du « Peut-être » ({$thresholds['maybe']} %).",
        };
        if ($level !== SlotMath::LEVEL_NO && $full) {
            $text .= ' Accepter cette demande dépasse la capacité.';
        }

        $favoured = [];
        foreach ($order->rank() as $criterion) {
            if (!self::matches($candidate, $criterion, $facts)) {
                continue;
            }
            $favoured[] = match ($criterion) {
                PriorityOrder::CRITERION_SIBLING => "fratrie dans l'unité",
                default => 'ancienne demande restée sans place ('
                    . self::previousNoRoomYear($candidate, $facts) . ')',
            };
        }
        if ($favoured !== []) {
            $text .= ' Classement favorisé par : ' . implode(', ', $favoured) . '.';
        }

        return $text;
    }

    /**
     * Whether $candidate matches one criterion. « Ordre d'arrivée » matches
     * nobody: it orders requests, it does not single one out.
     *
     * @param QueueFactsShape $facts
     */
    private static function matches(QueueCandidate $candidate, string $criterion, array $facts): bool
    {
        return match ($criterion) {
            PriorityOrder::CRITERION_SIBLING => ($facts['siblings'][$candidate->id] ?? []) !== [],
            PriorityOrder::CRITERION_PREVIOUS_NO_ROOM =>
                isset($facts['previous_no_room'][$candidate->nameDobBlindIndex]),
            default => false,
        };
    }

    /**
     * @param array<int, string> $criteria
     * @param QueueFactsShape $facts
     */
    private static function matchesAny(QueueCandidate $candidate, array $criteria, array $facts): bool
    {
        foreach ($criteria as $criterion) {
            if (self::matches($candidate, $criterion, $facts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param QueueFactsShape $facts
     */
    private static function previousNoRoomYear(QueueCandidate $candidate, array $facts): string
    {
        return $facts['previous_no_room'][$candidate->nameDobBlindIndex] ?? '';
    }

    /**
     * « 8 places sur 15 seraient occupées (53 %) », agreed in number — « 1
     * place sur 15 serait occupée », « aucune place sur 15 ne serait
     * occupée ».
     *
     * The percentage is rounded half up, in integers — except where that
     * would hide the very comparison the explanation reports: 19 places of
     * 27 are 70.37 %, beyond « Oui jusqu'à 70 % », and « (70 %), au-delà de
     * la limite du « Oui » (70 %) » would read as a contradiction. When the
     * occupancy is beyond $exceededThreshold and the rounded figure is not,
     * it is given to the tenth above instead — « (70,4 %) ».
     */
    private static function placesBefore(int $occupied, int $capacity, ?int $exceededThreshold = null): string
    {
        $percent = (string) intdiv(200 * $occupied + $capacity, 2 * $capacity);
        if ($exceededThreshold !== null && (int) $percent <= $exceededThreshold) {
            $tenths = intdiv(1000 * $occupied + $capacity - 1, $capacity);
            $percent = intdiv($tenths, 10) . ',' . ($tenths % 10);
        }

        return match (true) {
            $occupied === 0 => "aucune place sur {$capacity} ne serait occupée ({$percent} %)",
            $occupied === 1 => "1 place sur {$capacity} serait occupée ({$percent} %)",
            default => "{$occupied} places sur {$capacity} seraient occupées ({$percent} %)",
        };
    }

    /**
     * « Louveteaux · 1re année » — how a slot is named in an explanation.
     */
    public static function slotLabel(AgeBracket $bracket, int $yearInBranch): string
    {
        return $bracket->branchLabel . ' · ' . $yearInBranch . ($yearInBranch === 1 ? 're' : 'e') . ' année';
    }

    /**
     * The year when recommendations are computed for it, null otherwise.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    private function resolveLiveYear(int $scoutYearId): ?array
    {
        if (!$this->slotService->waitlistEnabled()) {
            return null;
        }
        $year = $this->scoutYearService->findById($scoutYearId);
        if ($year === null) {
            return null;
        }
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        return $year['start_date'] < $publicYear['start_date'] ? null : $year;
    }
}
