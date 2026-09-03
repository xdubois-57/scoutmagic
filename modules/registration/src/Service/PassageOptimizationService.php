<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;

/**
 * « Optimiser la répartition » (roadmap IT-18, spec §14 and §15): who goes
 * into which section of the branch they are arriving in.
 *
 * **Synchronous, in the answer to the request.** No scheduled task, no
 * polling, no waiting banner, no button disabled while something churns.
 * That is a constraint on the ALGORITHM, not a wish about the UI: if a
 * distribution needed a background job, the algorithm would be the thing
 * to simplify. A greedy construction followed by pair swaps over a few
 * dozen people finishes in milliseconds, and a strict budget guarantees an
 * answer whatever the roster size — over budget it returns the best
 * solution found so far, never an error.
 *
 * **It never touches a line that already carries a section.** There is no
 * notion of "who assigned this" anywhere in this module, deliberately (the
 * reset button has none either), so "chosen by hand" is read the only way
 * it can be read without inventing one: a line with a section is kept, a
 * line without one is placed. A chief who wants everything reconsidered
 * presses « Réinitialiser » first — which is exactly what that button is
 * for.
 *
 * **Two methods, one algorithm.** « Respecter les souhaits » is the same
 * search with the balance limits switched off; « Souhaits et équilibre »
 * is the same search with them on. One code path, so the two can never
 * disagree about what a wish is worth.
 *
 * **The score is lexicographic, in the order §14 fixes**: the section a
 * family explicitly asked for first, then siblings together, then friend
 * wishes. Lexicographic and not weighted: weights would let two friend
 * wishes outvote a family's explicit request, which is not what "par ordre
 * lexicographique décroissant" says.
 *
 * **What is deliberately NOT in the score:** the girls/boys mix (it stays
 * displayed and unoptimised, spec §14), a friend wish that is only text, a
 * wish the AI suggested and nobody confirmed, and a negative wish
 * (« surtout pas avec X »), which stays free text for a human whatever
 * channel it arrived through.
 *
 * **Determinism.** Fixed seed, and an LCG of our own rather than
 * `mt_rand()`/`shuffle()`: the global generator is shared with whatever
 * else ran in the same request, so seeding it is not enough to make a run
 * reproducible. Three runs of the same input give the same output, which
 * is what makes this testable at all.
 */
class PassageOptimizationService
{
    public const METHOD_WISHES = 'wishes';
    public const METHOD_BALANCED = 'balanced';

    public const SETTING_MAX_IMBALANCE = 'passage_max_section_imbalance_percent';
    public const SETTING_KEEP_SIBLINGS = 'passage_keep_siblings_together';

    /**
     * Wall-clock budget for the local search, in seconds.
     *
     * Small on purpose. The greedy construction alone is already a decent
     * distribution; the search only polishes it, and a chief pressing a
     * button is owed an answer now, not a better answer later.
     */
    private const SEARCH_BUDGET_SECONDS = 1.0;

    private const RESTARTS = 8;
    private const SEED = 20260301;

    public function __construct(
        private PassageService $passageService,
        private ProjectedPopulationService $projectedPopulation,
        private RegistrationRequestRepository $requestRepository,
        private ReenrollmentRepository $reenrollmentRepository,
        private PassageNoteRepository $passageNoteRepository,
        private SectionTransferRepository $transferRepository,
        private MemberYearRepository $memberYearRepository,
        private SettingService $settingService,
        private \PDO $pdo
    ) {
    }

    /**
     * What the dialog says before anybody presses anything: how many lines
     * are already settled, and how many are still to place.
     *
     * @param array<int, array<string, mixed>> $newRegistrations
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     * @return array{kept: int, to_place: int}
     */
    public function counts(array $newRegistrations, array $branchChanges): array
    {
        $kept = 0;
        $toPlace = 0;

        foreach ($newRegistrations as $row) {
            if ($row['sections_in_branch'] === []) {
                continue;
            }
            $row['request']->intendedSectionId !== null ? $kept++ : $toPlace++;
        }
        foreach ($branchChanges as $group) {
            foreach ($group['members'] as $member) {
                if ($member['destination_options'] === []) {
                    continue;
                }
                $member['destination_section_id'] !== null ? $kept++ : $toPlace++;
            }
        }

        return ['kept' => $kept, 'to_place' => $toPlace];
    }

    /**
     * Compute a distribution. Writes nothing.
     *
     * @param array<int, array<string, mixed>> $newRegistrations
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     */
    public function plan(
        array $newRegistrations,
        array $branchChanges,
        int $publicYearId,
        int $targetYearId,
        string $method
    ): PassageOptimizationOutcome {
        $people = $this->collectPeople($newRegistrations, $branchChanges, $publicYearId, $targetYearId);
        $counts = $this->counts($newRegistrations, $branchChanges);

        if ($people['to_place'] === []) {
            return new PassageOptimizationOutcome([], [], $counts['kept'], 0);
        }

        $limit = $method === self::METHOD_WISHES ? null : $this->maxImbalancePercent();

        $memberDestinations = [];
        $requestSections = [];
        $warnings = [];

        foreach ($people['by_branch'] as $branch) {
            $solution = $this->solveBranch($branch, $limit);

            foreach ($solution['assignment'] as $index => $sectionId) {
                $person = $branch['people'][$index];
                if ($person['kind'] === 'member') {
                    $memberDestinations[$person['id']] = $sectionId;
                } else {
                    $requestSections[$person['id']] = $sectionId;
                }
            }
            if ($solution['warning'] !== null) {
                $warnings[] = $solution['warning'];
            }
        }

        return new PassageOptimizationOutcome(
            $memberDestinations,
            $requestSections,
            $counts['kept'],
            count($memberDestinations) + count($requestSections),
            $warnings
        );
    }

    /**
     * Write a plan, all of it or none of it.
     *
     * A distribution half applied is worse than none at all: a chief would
     * be looking at a page where some children moved and some did not, with
     * nothing saying which.
     */
    public function apply(PassageOptimizationOutcome $outcome, int $targetYearId): void
    {
        if ($outcome->total() === 0) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($outcome->memberDestinations as $memberId => $sectionId) {
                $this->transferRepository->setDestination($memberId, $targetYearId, $sectionId);
            }
            foreach ($outcome->requestSections as $requestId => $sectionId) {
                $this->requestRepository->updateIntendedSection($requestId, $sectionId);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Empty every destination of the target year, then put back the ones
     * that were never a choice — a branch with a single section.
     *
     * Deliberately identical to what Task\AutoAssignPassageHandler already
     * does, and with no notion of where an assignment came from: the button
     * says « réinitialiser », and a reset that kept some of the previous
     * answer would be a different word.
     *
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     * @return int how many lines came back with a section on their own
     */
    public function reset(array $branchChanges, int $targetYearId): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->transferRepository->clearAllForYear($targetYearId);
            $this->requestRepository->clearIntendedSectionsForYear($targetYearId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        foreach ($branchChanges as $key => $group) {
            foreach ($group['members'] as $index => $member) {
                $branchChanges[$key]['members'][$index]['destination_section_id'] = null;
            }
        }

        $before = $this->passageService->countSingleOptionDestinationsToAssign($branchChanges);
        $this->passageService->autoAssignSingleOptionDestinations($branchChanges, $targetYearId);

        return $before;
    }

    // ── gathering ────────────────────────────────────────────────────

    /**
     * Everybody this run may place, grouped by the branch they arrive in,
     * with what the score needs to know about each.
     *
     * @param array<int, array<string, mixed>> $newRegistrations
     * @param array<string, array{section_label: string, members: array<int, array<string, mixed>>}> $branchChanges
     * @return array{to_place: array<int, array<string, mixed>>, by_branch: array<int, array<string, mixed>>}
     */
    private function collectPeople(
        array $newRegistrations,
        array $branchChanges,
        int $publicYearId,
        int $targetYearId
    ): array {
        $answers = $this->reenrollmentRepository->findAnswersForYear($targetYearId);
        $staffNotes = $this->passageNoteRepository->findForYear($targetYearId);
        $keepSiblings = $this->keepSiblingsTogether();

        $toPlace = [];

        foreach ($newRegistrations as $row) {
            $request = $row['request'];
            if ($request->intendedSectionId !== null || $row['sections_in_branch'] === []) {
                continue;
            }

            $toPlace[] = [
                'kind' => 'request',
                'id' => $request->id,
                'branch_id' => (int) $row['sections_in_branch'][0]['age_branch_id'],
                'sections' => array_map(static fn(array $s): int => (int) $s['id'], $row['sections_in_branch']),
                'desired_section_id' => $request->desiredSectionId,
                // A new registration arrives wherever its birth date puts
                // it, which is not always the first rank of the branch.
                'first_year' => ($row['slot']['year_in_branch'] ?? 1) === 1,
                'group_key' => null,
                'friends' => [],
            ];
        }

        foreach ($branchChanges as $group) {
            foreach ($group['members'] as $member) {
                if ($member['destination_section_id'] !== null || $member['destination_options'] === []) {
                    continue;
                }

                $memberId = (int) $member['member_id'];
                $answer = $answers[$memberId] ?? null;

                $toPlace[] = [
                    'kind' => 'member',
                    'id' => $memberId,
                    'branch_id' => (int) $member['destination_options'][0]['age_branch_id'],
                    'sections' => array_map(static fn(array $s): int => (int) $s['id'], $member['destination_options']),
                    // The staff's own reading wins over the family's when
                    // there is one (IT-17): it is the later, better
                    // informed statement of the same wish.
                    'desired_section_id' => $staffNotes[$memberId]['preferred_section_id'] ?? $answer?->preferredSectionId,
                    // Somebody changing branch always arrives at its first
                    // rank; that is what "changing branch" means here.
                    'first_year' => true,
                    'group_key' => null,
                    'friends' => $answer === null ? [] : $this->usableFriendMemberIds($answer),
                ];
            }
        }

        if ($keepSiblings) {
            $toPlace = $this->attachSiblingGroups($toPlace, $newRegistrations, $publicYearId);
        }

        return ['to_place' => $toPlace, 'by_branch' => $this->groupByBranch($toPlace, $targetYearId)];
    }

    /**
     * Only the wishes the optimiser is allowed to act on: matched to
     * exactly one member, or disambiguated by a chief (IT-17). Never a raw
     * name, never an AI reading nobody confirmed.
     *
     * @return array<int, int> member ids
     */
    private function usableFriendMemberIds(\Modules\Registration\Repository\ReenrollmentAnswer $answer): array
    {
        $ids = [];
        foreach ($answer->friendWishes as $wish) {
            if ($wish->isUsable() && $wish->matchedMemberId !== null) {
                $ids[] = $wish->matchedMemberId;
            }
        }

        return $ids;
    }

    /**
     * Give everybody who shares a household — or a declared sibling link —
     * the same group key, so the score can count "together" without caring
     * which of the two links produced it.
     *
     * The two links are the ones §14 names and nothing else: the sibling
     * ids declared on a registration request, and the « même adresse »
     * link for branch changes, used exactly as the page already shows it.
     *
     * @param array<int, array<string, mixed>> $people
     * @param array<int, array<string, mixed>> $newRegistrations
     * @return array<int, array<string, mixed>>
     */
    private function attachSiblingGroups(array $people, array $newRegistrations, int $publicYearId): array
    {
        $union = new UnionFind();

        $memberYearIdByMember = $this->memberYearRepository->findIdsByMembersAndYear(
            array_values(array_map(
                static fn(array $p): int => $p['id'],
                array_filter($people, static fn(array $p): bool => $p['kind'] === 'member')
            )),
            $publicYearId
        );
        $memberByMemberYearId = array_flip($memberYearIdByMember);

        foreach ($this->passageService->householdMemberYearIds(
            array_values($memberYearIdByMember),
            $publicYearId
        ) as $memberYearId => $neighbours) {
            $left = $memberByMemberYearId[$memberYearId] ?? null;
            if ($left === null) {
                continue;
            }
            foreach ($neighbours as $neighbourMemberYearId) {
                $right = $memberByMemberYearId[$neighbourMemberYearId] ?? null;
                if ($right !== null) {
                    $union->union('member:' . $left, 'member:' . $right);
                }
            }
        }

        $siblingIds = $this->requestRepository->findSiblingMemberIdsForRequests(
            array_values(array_map(
                static fn(array $row): int => $row['request']->id,
                $newRegistrations
            ))
        );
        foreach ($siblingIds as $requestId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $union->union('request:' . $requestId, 'member:' . $memberId);
            }
        }

        foreach ($people as $index => $person) {
            $people[$index]['group_key'] = $union->find($person['kind'] . ':' . $person['id']);
        }

        return $people;
    }

    /**
     * One problem per arrival branch: sections of different branches are
     * never interchangeable, and balancing across them would be comparing
     * Louveteaux with Éclaireurs.
     *
     * @param array<int, array<string, mixed>> $people
     * @return array<int, array<string, mixed>>
     */
    private function groupByBranch(array $people, int $targetYearId): array
    {
        $base = $this->baseLoads($targetYearId);

        $branches = [];
        foreach ($people as $person) {
            $branchId = $person['branch_id'];
            if (!isset($branches[$branchId])) {
                $branches[$branchId] = ['branch_id' => $branchId, 'people' => [], 'sections' => []];
            }
            $branches[$branchId]['people'][] = $person;
            foreach ($person['sections'] as $sectionId) {
                $branches[$branchId]['sections'][$sectionId] = true;
            }
        }

        $prepared = [];
        foreach ($branches as $branchId => $branch) {
            $sections = array_keys($branch['sections']);
            sort($sections);

            $prepared[] = [
                'branch_id' => $branchId,
                'people' => $branch['people'],
                'sections' => $sections,
                'base_total' => array_combine(
                    $sections,
                    array_map(static fn(int $id): int => $base['total'][$id] ?? 0, $sections)
                ),
                'base_first_year' => array_combine(
                    $sections,
                    array_map(static fn(int $id): int => $base['first_year'][$id] ?? 0, $sections)
                ),
            ];
        }

        // Branch order is the section ids' own, so two runs of the same
        // input walk the branches in the same order.
        usort($prepared, static fn(array $a, array $b): int => $a['sections'][0] <=> $b['sections'][0]);

        return $prepared;
    }

    /**
     * What each section already holds without this run — the module's ONE
     * projection, never a second headcount.
     *
     * People this run is about to place are not in it: an unassigned
     * passage is in no section's projection, which is exactly why the
     * Prévisions page carries a « non attribués » badge for them.
     *
     * @return array{total: array<int, int>, first_year: array<int, int>}
     */
    private function baseLoads(int $targetYearId): array
    {
        $total = [];
        $firstYear = [];
        foreach ($this->projectedPopulation->projectedSectionTotals($targetYearId) as $section) {
            $total[$section->sectionId] = $section->total;
            $firstYear[$section->sectionId] = $section->byYearInBranch[1] ?? 0;
        }

        return ['total' => $total, 'first_year' => $firstYear];
    }

    // ── solving ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $branch
     * @return array{assignment: array<int, int>, warning: ?string}
     */
    private function solveBranch(array $branch, ?int $limitPercent): array
    {
        $random = new DeterministicRandom(self::SEED + $branch['branch_id']);
        $deadline = microtime(true) + self::SEARCH_BUDGET_SECONDS;

        $best = null;
        $bestScore = null;

        for ($restart = 0; $restart < self::RESTARTS; $restart++) {
            $assignment = $this->greedy($branch, $limitPercent, $restart === 0 ? null : $random);
            $assignment = $this->improve($branch, $assignment, $limitPercent, $deadline);

            $score = $this->score($branch, $assignment, $limitPercent);
            if ($bestScore === null || $this->better($score, $bestScore)) {
                $best = $assignment;
                $bestScore = $score;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        }

        // The loop above runs at least once, so $best is a real
        // assignment by construction — RESTARTS is a constant and greedy()
        // always produces one.
        return ['assignment' => $best, 'warning' => $this->warningFor($branch, $best, $limitPercent)];
    }

    /**
     * A first distribution: everybody with an explicit wish first (theirs
     * is the criterion that outranks everything else, so it should not lose
     * a section to somebody who had no opinion), then the rest.
     *
     * @param array<string, mixed> $branch
     * @return array<int, int> person index => section id
     */
    private function greedy(array $branch, ?int $limitPercent, ?DeterministicRandom $random): array
    {
        $order = array_keys($branch['people']);
        usort($order, function (int $a, int $b) use ($branch): int {
            $left = $branch['people'][$a];
            $right = $branch['people'][$b];
            $byWish = (int) ($right['desired_section_id'] !== null) <=> (int) ($left['desired_section_id'] !== null);

            return $byWish !== 0 ? $byWish : $left['id'] <=> $right['id'];
        });

        if ($random !== null) {
            $order = $random->shuffled($order);
        }

        $assignment = [];
        foreach ($order as $index) {
            $person = $branch['people'][$index];
            $bestSection = null;
            $bestScore = null;

            foreach ($person['sections'] as $sectionId) {
                $candidate = $assignment;
                $candidate[$index] = $sectionId;
                $score = $this->score($branch, $candidate, $limitPercent);
                if ($bestScore === null || $this->better($score, $bestScore)) {
                    $bestScore = $score;
                    $bestSection = $sectionId;
                }
            }

            $assignment[$index] = $bestSection ?? $person['sections'][0];
        }

        ksort($assignment);

        return $assignment;
    }

    /**
     * Pair swaps until nothing improves or the budget runs out.
     *
     * @param array<string, mixed> $branch
     * @param array<int, int> $assignment
     * @return array<int, int>
     */
    private function improve(array $branch, array $assignment, ?int $limitPercent, float $deadline): array
    {
        $indexes = array_keys($assignment);
        $current = $this->score($branch, $assignment, $limitPercent);

        $improved = true;
        while ($improved) {
            $improved = false;
            foreach ($indexes as $a) {
                foreach ($indexes as $b) {
                    if ($a >= $b || $assignment[$a] === $assignment[$b]) {
                        continue;
                    }
                    if (microtime(true) >= $deadline) {
                        return $assignment;
                    }
                    // A swap only makes sense when each of the two may
                    // actually sit where the other is.
                    if (!in_array($assignment[$b], $branch['people'][$a]['sections'], true)
                        || !in_array($assignment[$a], $branch['people'][$b]['sections'], true)) {
                        continue;
                    }

                    $candidate = $assignment;
                    [$candidate[$a], $candidate[$b]] = [$candidate[$b], $candidate[$a]];

                    $score = $this->score($branch, $candidate, $limitPercent);
                    if ($this->better($score, $current)) {
                        $assignment = $candidate;
                        $current = $score;
                        $improved = true;
                    }
                }
            }
        }

        return $assignment;
    }

    /**
     * The score, as a tuple compared lexicographically and highest first:
     *
     *   0. how badly the first-year limit is broken (negative — closer to
     *      zero is better). It comes FIRST because §14 makes it the
     *      constraint that wins when the two cannot both hold.
     *   1. how badly the headcount limit is broken (negative).
     *   2. people in the section their family asked for.
     *   3. sibling pairs together.
     *   4. friend-wish pairs together.
     *
     * Putting the two limits in the score rather than refusing solutions
     * that break them is what lets the button always answer: an infeasible
     * branch simply scores negatively on 0 or 1, and the least-bad
     * distribution still comes back.
     *
     * @param array<string, mixed> $branch
     * @param array<int, int> $assignment
     * @return array{int, int, int, int, int}
     */
    private function score(array $branch, array $assignment, ?int $limitPercent): array
    {
        $totals = $branch['base_total'];
        $firstYears = $branch['base_first_year'];
        $wishes = 0;

        foreach ($assignment as $index => $sectionId) {
            $person = $branch['people'][$index];
            $totals[$sectionId] = ($totals[$sectionId] ?? 0) + 1;
            if ($person['first_year']) {
                $firstYears[$sectionId] = ($firstYears[$sectionId] ?? 0) + 1;
            }
            if ($person['desired_section_id'] !== null && $person['desired_section_id'] === $sectionId) {
                $wishes++;
            }
        }

        return [
            $limitPercent === null ? 0 : -$this->excess($firstYears, $limitPercent),
            $limitPercent === null ? 0 : -$this->excess($totals, $limitPercent),
            $wishes,
            $this->pairsTogether($branch, $assignment, 'group'),
            $this->pairsTogether($branch, $assignment, 'friends'),
        ];
    }

    /**
     * By how many percentage points a spread exceeds the limit, 0 when it
     * does not.
     *
     * The spread is `(max - min) / max`, which is the reading the setting's
     * own wording invites — « écart d'effectif de 38 % » between the
     * fullest section and the emptiest — and the one the warning below
     * prints back.
     *
     * @param array<int, int> $loads
     */
    private function excess(array $loads, int $limitPercent): int
    {
        return max(0, $this->spreadPercent($loads) - $limitPercent);
    }

    /**
     * @param array<int, int> $loads
     */
    private function spreadPercent(array $loads): int
    {
        if (count($loads) < 2) {
            return 0;
        }

        $max = max($loads);
        $min = min($loads);
        if ($max <= 0) {
            return 0;
        }

        return (int) round((($max - $min) / $max) * 100);
    }

    /**
     * @param array<string, mixed> $branch
     * @param array<int, int> $assignment
     */
    private function pairsTogether(array $branch, array $assignment, string $link): int
    {
        $pairs = 0;
        $indexes = array_keys($assignment);

        foreach ($indexes as $a) {
            foreach ($indexes as $b) {
                if ($a >= $b || $assignment[$a] !== $assignment[$b]) {
                    continue;
                }
                $left = $branch['people'][$a];
                $right = $branch['people'][$b];

                if ($link === 'group') {
                    if ($left['group_key'] !== null && $left['group_key'] === $right['group_key']) {
                        $pairs++;
                    }
                    continue;
                }

                // A wish counts once whichever of the two expressed it: two
                // children who named each other are one pair kept together,
                // not two.
                $named = ($right['kind'] === 'member' && in_array($right['id'], $left['friends'], true))
                    || ($left['kind'] === 'member' && in_array($left['id'], $right['friends'], true));
                if ($named) {
                    $pairs++;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array{int, int, int, int, int} $candidate
     * @param array{int, int, int, int, int} $reference
     */
    private function better(array $candidate, array $reference): bool
    {
        return $candidate > $reference;
    }

    /**
     * What the page says when a branch could not be balanced.
     *
     * Never a refusal and never silence: §14 requires the result to
     * announce the overshoot in so many words, because a chief reading
     * « 38 % » with no explanation would assume the limit was ignored.
     *
     * @param array<string, mixed> $branch
     * @param array<int, int> $assignment
     */
    private function warningFor(array $branch, array $assignment, ?int $limitPercent): ?string
    {
        if ($limitPercent === null) {
            return null;
        }

        $totals = $branch['base_total'];
        $firstYears = $branch['base_first_year'];
        foreach ($assignment as $index => $sectionId) {
            $totals[$sectionId] = ($totals[$sectionId] ?? 0) + 1;
            if ($branch['people'][$index]['first_year']) {
                $firstYears[$sectionId] = ($firstYears[$sectionId] ?? 0) + 1;
            }
        }

        $firstYearSpread = $this->spreadPercent($firstYears);
        $totalSpread = $this->spreadPercent($totals);

        if ($firstYearSpread > $limitPercent) {
            return sprintf(
                "Écart de premières années de %d %%, au-delà de la limite de %d %% — aucune répartition ne fait mieux "
                . "avec les sections disponibles.",
                $firstYearSpread,
                $limitPercent
            );
        }

        if ($totalSpread > $limitPercent) {
            return sprintf(
                "Écart d'effectif de %d %%, au-delà de la limite de %d %% — nécessaire pour répartir équitablement "
                . "les premières années.",
                $totalSpread,
                $limitPercent
            );
        }

        return null;
    }

    // ── settings ─────────────────────────────────────────────────────

    private function maxImbalancePercent(): int
    {
        $raw = $this->settingService->get(self::SETTING_MAX_IMBALANCE, 'registration', '30');

        return is_numeric($raw) ? max(0, (int) $raw) : 30;
    }

    private function keepSiblingsTogether(): bool
    {
        return (string) $this->settingService->get(self::SETTING_KEEP_SIBLINGS, 'registration', '1') === '1';
    }
}
