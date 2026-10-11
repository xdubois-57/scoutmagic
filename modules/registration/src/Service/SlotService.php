<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Member\MemberYearService;
use Modules\Registration\Repository\AgeBracket;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\PassageRosterRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;

/**
 * Orchestrates Service\SlotMath against real data: configured brackets and
 * capacities, the module's one projection of next year's animés
 * (Service\ForecastService, the same rows the Prévisions page counts), and
 * the accepted requests — into the occupancy of each slot and the level the
 * two thresholds give it.
 *
 * **A slot with no recorded capacity is UNLIMITED, never full.** The
 * database says so with a NULL (schema.sql's own comment on
 * `registration_slot_capacities`), and the whole point of carrying `?int`
 * through this class rather than defaulting to `0` on read is that
 * `$capacities[...] ?? 0` — the shape this code used to have — announces
 * every unconfigured branch as "complet" the moment the column is allowed
 * to be empty. `0` still means something, and something else: a branch a
 * chief deliberately closed.
 */
class SlotService
{
    /**
     * The capacity a slot gets when it has none at all — genuinely
     * WRITTEN to the database by seedMissingCapacities(), not just shown
     * in an empty box, so a chief editing it afterwards edits a real
     * stored number like any other.
     */
    public const DEFAULT_CAPACITY = 15;

    /**
     * « Oui jusqu'à … % de places occupées » and « Peut-être jusqu'à … %
     * de places occupées », as module.json declares them — the defaults
     * here and there are the same numbers.
     */
    public const SETTING_THRESHOLD_YES = 'registration_threshold_yes_percent';
    public const SETTING_THRESHOLD_MAYBE = 'registration_threshold_maybe_percent';
    public const DEFAULT_YES_PERCENT = 70;
    public const DEFAULT_MAYBE_PERCENT = 100;

    public function __construct(
        private PassageRosterRepository $roster,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private AgeBracketRepository $ageBracketRepository,
        private SlotCapacityRepository $slotCapacityRepository,
        private RegistrationRequestRepository $requestRepository,
        private ForecastService $forecastService,
        private ReenrollmentRepository $reenrollmentRepository
    ) {
    }

    public function referenceMonthDay(): string
    {
        $value = (string) $this->settingService->get('registration_reference_date', 'registration', '12-31');

        return preg_match('/^\d{2}-\d{2}$/', $value) === 1 ? $value : '12-31';
    }

    /**
     * Gives every configured slot (branch × year-in-branch) that has NO
     * row at all a real, stored capacity of DEFAULT_CAPACITY. Returns how
     * many rows it created.
     *
     * The default is written to the database rather than merely rendered
     * into an empty box, so that what a chief sees on the management page
     * is what the availability computation and the public page are
     * actually using — a displayed-only default is a number nobody can
     * trust, since clearing the box and saving would have produced a
     * different behaviour than never touching it.
     *
     * It is deliberately a *seed*, not a repair: a slot whose row exists
     * is never rewritten, whatever it holds. An empty box a chief saved on
     * purpose stored NULL ("pas de limite") and must stay NULL — a
     * re-seed would silently overrule that decision on the next page load.
     * A branch created later (a new `age_branches` row) is seeded the
     * first time the page is opened after it appears, which is the same
     * rule applied to a slot that genuinely has no capacity yet.
     */
    public function seedMissingCapacities(): int
    {
        $seeded = 0;
        foreach ($this->ageBracketRepository->findAllOrdered() as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                if ($this->slotCapacityRepository->insertIfMissing(
                    $bracket->ageBranchId,
                    $yearInBranch,
                    self::DEFAULT_CAPACITY
                )) {
                    $seeded++;
                }
            }
        }

        return $seeded;
    }

    /**
     * The list of birth years per branch for the target scout year — the
     * public page's "which year was my child born" section. Ordered by
     * canonical branch order, each branch listing one birth year per
     * configured year-in-branch (oldest year-in-branch first).
     *
     * @return array<int, array{branch_label: string, birth_years: array<int>}> keyed by age_branch_id
     */
    public function birthYearsByBranch(string $targetScoutYearLabel): array
    {
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->referenceMonthDay()
        );

        $result = [];
        foreach ($brackets as $bracket) {
            $years = [];
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $years[] = SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear);
            }
            $result[$bracket->ageBranchId] = [
                'branch_label' => $bracket->branchLabel,
                'birth_years' => $years,
            ];
        }

        return $result;
    }

    /**
     * The two thresholds every level of the module is read through —
     * « Oui jusqu'à X % » and « Peut-être jusqu'à Y % » of the places
     * occupied (`registration_threshold_yes_percent` /
     * `registration_threshold_maybe_percent`).
     *
     * A stored pair that is not usable — a value outside 1–120, or Oui not
     * strictly below Peut-être, which Configuration > Réglages can save
     * since it validates each key on its own — falls back to the manifest
     * defaults as a PAIR. Mixing one stored value with one default could
     * produce exactly the inverted pair the validation exists to refuse.
     * storedThresholdsAreUnusable() is what lets the capacity box say so
     * rather than show 70 / 100 as if a chief had chosen them.
     *
     * @return array{yes: int, maybe: int}
     */
    public function levelThresholds(): array
    {
        return $this->storedThresholds()
            ?? ['yes' => self::DEFAULT_YES_PERCENT, 'maybe' => self::DEFAULT_MAYBE_PERCENT];
    }

    public function storedThresholdsAreUnusable(): bool
    {
        return $this->storedThresholds() === null;
    }

    /**
     * @return array{yes: int, maybe: int}|null null when the stored pair is unusable
     */
    private function storedThresholds(): ?array
    {
        $yes = SlotMath::parseThresholdPercent($this->settingService->get(
            self::SETTING_THRESHOLD_YES,
            'registration',
            (string) self::DEFAULT_YES_PERCENT
        ));
        $maybe = SlotMath::parseThresholdPercent($this->settingService->get(
            self::SETTING_THRESHOLD_MAYBE,
            'registration',
            (string) self::DEFAULT_MAYBE_PERCENT
        ));

        return $yes !== null && $maybe !== null && SlotMath::isValidThresholdPair($yes, $maybe)
            ? ['yes' => $yes, 'maybe' => $maybe]
            : null;
    }

    /**
     * The public page's three baskets of birth years — only meaningful
     * when waitlists are managed (the caller decides whether to show this
     * at all). Keys are SlotMath's levels: `yes` reads « Disponible »,
     * `maybe` « Limitée », `no` « Complet ».
     *
     * @return array<string, array<int>> level => birth years (deduplicated, sorted)
     */
    public function publicLevelsByBirthYear(
        int $targetScoutYearId,
        string $targetScoutYearLabel,
        int $currentScoutYearId
    ): array
    {
        $levels = [SlotMath::LEVEL_YES => [], SlotMath::LEVEL_MAYBE => [], SlotMath::LEVEL_NO => []];

        $rows = $this->capacityBreakdownForYear($targetScoutYearId, $targetScoutYearLabel, $currentScoutYearId);
        foreach ($rows as $row) {
            $levels[$row['level']][] = $row['birth_year'];
        }

        foreach ($levels as $level => $years) {
            $years = array_values(array_unique($years));
            sort($years);
            $levels[$level] = $years;
        }

        return $levels;
    }

    /**
     * Per-birth-year slot info for the public page's birth-date preview:
     * which (branch, year-in-branch) a birth year falls into, and its
     * public level — reuses birthYearsByBranch()'s own bracket iteration,
     * and takes the levels publicLevelsByBirthYear() already computed
     * rather than computing them again: the projection behind them is the
     * expensive part of the page, and a page anybody can open must not run
     * it twice. Deliberately never exposes the raw capacity/projected/
     * accepted counts capacityBreakdownForYear() carries
     * (Controller\RegistrationConfigController's staff-only "Vérification
     * des capacités" table) — a visitor gets only the same « Disponible » /
     * « Limitée » / « Complet » the birth-year grid already shows, never
     * the numbers behind it.
     *
     * `age_branch_id` is here for the « Section souhaitée » list, which
     * has to keep only the sections of the branch the birth date lands
     * in. It is the SAME identity
     * Controller\PublicRegistrationController::resolveDesiredSectionId()
     * validates the submitted section against, and that is the point of
     * shipping the id rather than matching on `branch_label`: two readings
     * of one relation that could disagree would let the form offer a
     * section the server then drops. It identifies a branch and nothing
     * else — the same value the page already prints on every
     * `<option data-branch-id>` — so it exposes nothing a visitor cannot
     * already read in the markup.
     *
     * @param array<string, array<int>> $publicLevels publicLevelsByBirthYear()'s
     *        answer, or [] when waitlists are not managed (every level null)
     * @return array<int, array{
     *   birth_year: int, age_branch_id: int, branch_label: string, year_in_branch: int, level: ?string
     * }>
     */
    public function birthYearSlotsForPublic(string $targetScoutYearLabel, array $publicLevels): array
    {
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->referenceMonthDay()
        );

        $levelByBirthYear = [];
        foreach ($publicLevels as $level => $years) {
            foreach ($years as $year) {
                $levelByBirthYear[$year] = $level;
            }
        }

        $rows = [];
        foreach ($brackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $birthYear = SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear);
                $rows[] = [
                    'birth_year' => $birthYear,
                    'age_branch_id' => $bracket->ageBranchId,
                    'branch_label' => $bracket->branchLabel,
                    'year_in_branch' => $yearInBranch,
                    'level' => $levelByBirthYear[$birthYear] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * The capacity check's own row set (module spec: "capacité, effectif
     * projeté, demandes acceptées, restant, et le niveau tel qu'il apparaît
     * au public") — the numbers publicLevelsByBirthYear() is computed from,
     * returned per slot rather than bucketed into birth years, so a chief
     * can verify the public level isn't a black box.
     *
     * - `projected` — the children Service\ForecastService expects in this
     *   slot next year: Desk's own rows for that year when it already has
     *   them, otherwise the current animés projected forward through
     *   `scout_year_offset`, those changing branch included. A child whose
     *   family has not answered the reenrollment yet is counted as staying;
     *   only a departure (`member_years.leaving`, set by the family's answer
     *   or by the staff) takes them out. `silent` is how many of
     *   `projected` are such unanswered children (« dont N sans réponse »).
     * - `accepted` — the accepted requests, encoded in Desk or not: each
     *   occupies ONE place, never two and never zero (R1, see
     *   occupancyBySlot()).
     * - `occupied` = `projected` + `accepted`, the number the level reads.
     *
     * `capacity` and `remaining` are nullable together: a slot with no
     * recorded capacity has no remaining count, and the table renders that
     * as "sans limite" rather than as a zero. Its level is always Oui.
     *
     * @return array<int, array{
     *   age_branch_id: int, branch_label: string, branch_sort_order: int, year_in_branch: int,
     *   birth_year: int, capacity: ?int, projected: int, silent: int, accepted: int, occupied: int,
     *   remaining: ?int, level: string
     * }>
     */
    public function capacityBreakdownForYear(
        int $targetScoutYearId,
        string $targetScoutYearLabel,
        int $currentScoutYearId
    ): array
    {
        $thresholds = $this->levelThresholds();
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $capacities = $this->slotCapacityRepository->findAllAsMap();
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
            $this->referenceMonthDay()
        );

        $occupancy = $this->occupancyBySlot($targetScoutYearId, $targetScoutYearLabel, $currentScoutYearId, $brackets);

        $rows = [];
        foreach ($brackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $slotKey = $bracket->ageBranchId . ':' . $yearInBranch;
                $capacity = $capacities[$bracket->ageBranchId][$yearInBranch] ?? null;
                $counts = $occupancy[$slotKey] ?? ['projected' => 0, 'silent' => 0, 'accepted' => 0];
                $occupied = $counts['projected'] + $counts['accepted'];

                $rows[] = [
                    'age_branch_id' => $bracket->ageBranchId,
                    'branch_label' => $bracket->branchLabel,
                    'branch_sort_order' => $bracket->branchSortOrder,
                    'year_in_branch' => $yearInBranch,
                    'birth_year' => SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear),
                    'capacity' => $capacity,
                    'projected' => $counts['projected'],
                    'silent' => $counts['silent'],
                    'accepted' => $counts['accepted'],
                    'occupied' => $occupied,
                    'remaining' => $capacity === null ? null : $capacity - $occupied,
                    'level' => SlotMath::levelForOccupancy(
                        $capacity,
                        $occupied,
                        $thresholds['yes'],
                        $thresholds['maybe']
                    ),
                ];
            }
        }

        return $rows;
    }

    /**
     * Who occupies each slot next year, from the module's ONE projection
     * (Service\ForecastService::projectedRows() — the same rows the
     * Prévisions page and Api\ProjectedPopulationProvider count). Nothing
     * here computes an age: every row already carries the branch and
     * year-in-branch ForecastService derived through
     * MemberYearService::getEffectiveAge(), `scout_year_offset` included.
     *
     * The rule this method owns is R1 — **an accepted request occupies one
     * place, never two, never zero, whether or not it is encoded in Desk**:
     *
     * - accepted, not encoded: ForecastService's registration row, counted
     *   in `accepted`;
     * - encoded, and the projection holds the member it was linked to (the
     *   target year's Desk row, or the current-year row projected forward):
     *   that member's row, moved from `projected` to `accepted` — the same
     *   child, counted once, in the column that says why the place is taken;
     * - encoded, and the projection does not hold that member (a manual
     *   link to a member last seen in an earlier year, or a target-year
     *   Desk row the animé roster does not read — no section yet, say):
     *   the request's own birth date, in `accepted`.
     *
     * The one thing that takes an encoded request out is Desk saying the
     * child is not there next year: a target-year row that is inactive or
     * marked leaving.
     *
     * @param array<AgeBracket> $brackets
     * @return array<string, array{projected: int, silent: int, accepted: int}> "{ageBranchId}:{yearInBranch}" => counts
     */
    private function occupancyBySlot(
        int $targetScoutYearId,
        string $targetScoutYearLabel,
        int $currentScoutYearId,
        array $brackets
    ): array {
        $referenceMonthDay = $this->referenceMonthDay();
        $rows = $this->forecastService->projectedRows(
            $currentScoutYearId,
            $this->scoutYearLabel($currentScoutYearId),
            $targetScoutYearId,
            $targetScoutYearLabel,
            $referenceMonthDay
        );

        $encodedByMemberId = [];
        foreach ($this->requestRepository->findEncodedForYear($targetScoutYearId) as $request) {
            if ($request->linkedMemberId !== null) {
                $encodedByMemberId[$request->linkedMemberId] = $request;
            }
        }
        $answered = array_fill_keys($this->reenrollmentRepository->answeredMemberIds($targetScoutYearId), true);

        /** @var array<string, array{projected: int, silent: int, accepted: int}> $occupancy */
        $occupancy = [];

        $placedMemberIds = [];
        foreach ($rows as $row) {
            $bracket = $row['branch_sort_order'] !== null
                ? $this->findBracketBySortOrder($brackets, $row['branch_sort_order'])
                : null;
            if ($bracket === null || $row['year_in_branch'] === null) {
                continue;
            }
            $key = $bracket->ageBranchId . ':' . $row['year_in_branch'];
            $memberId = $row['member_id'];

            if ($row['request_id'] !== null) {
                self::tally($occupancy, $key, 'accepted');
            } elseif ($memberId !== null && isset($encodedByMemberId[$memberId])) {
                $placedMemberIds[$memberId] = true;
                self::tally($occupancy, $key, 'accepted');
            } else {
                self::tally($occupancy, $key, 'projected');
                // Desk's own target-year rows are facts, not a guess about
                // who stays: only a projected current-year animé can be
                // « sans réponse ».
                if (!$row['certain'] && $memberId !== null && !isset($answered[$memberId])) {
                    self::tally($occupancy, $key, 'silent');
                }
            }
        }

        $unplaced = array_diff_key($encodedByMemberId, $placedMemberIds);
        if ($unplaced !== []) {
            $deskTargetYear = $this->roster->findStayingStates(array_keys($unplaced), $targetScoutYearId);
            $referenceYear = SlotMath::referenceCalendarYear(
                MemberYearService::referenceYearFromScoutYearLabel($targetScoutYearLabel),
                $referenceMonthDay
            );
            foreach ($unplaced as $memberId => $request) {
                if (($deskTargetYear[$memberId] ?? true) === false) {
                    continue;
                }
                $slot = SlotMath::slotForBirthDate($brackets, $request->birthDate, $referenceYear);
                if ($slot !== null) {
                    self::tally($occupancy, $slot['age_branch_id'] . ':' . $slot['year_in_branch'], 'accepted');
                }
            }
        }

        return $occupancy;
    }

    /**
     * @param array<string, array{projected: int, silent: int, accepted: int}> $occupancy
     * @param 'projected'|'silent'|'accepted' $column
     */
    private static function tally(array &$occupancy, string $slotKey, string $column): void
    {
        $occupancy[$slotKey] ??= ['projected' => 0, 'silent' => 0, 'accepted' => 0];
        $occupancy[$slotKey][$column]++;
    }

    /**
     * @param array<AgeBracket> $brackets
     */
    private function findBracketBySortOrder(array $brackets, int $sortOrder): ?AgeBracket
    {
        foreach ($brackets as $bracket) {
            if ($bracket->branchSortOrder === $sortOrder) {
                return $bracket;
            }
        }

        return null;
    }

    private function scoutYearLabel(int $scoutYearId): string
    {
        return $this->scoutYearService->findById($scoutYearId)['label'] ?? '';
    }
}
