<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * Turns UnitBlueprint's tables and ScenarioCatalog's named cases into three
 * years of people.
 *
 * Two populations, built in that order:
 *
 *   1. **The scenario people** (`T0001`-`T0033`, ScenarioPeople), written out
 *      by hand. Each exists to make one named behaviour observable, and each
 *      one's Tiers is pinned so a test can name it.
 *   2. **The filler**, from `T0101` up. Nobody asserts on an individual filler
 *      member; they exist so the unit has the size and the age pyramid of a
 *      real one, and so section counts, branch transitions and household
 *      grouping have volume behind them.
 *
 * The filler is grown year by year rather than drawn independently per year:
 * everyone ages by one, their branch is recomputed from their age exactly as
 * MemberYearService::getEffectiveAge() will, and only then are departures and
 * arrivals applied to reach the declared headcount. That is what makes
 * continuity real rather than staged — a Louveteau of 11 in A1 is an Éclaireur
 * in A2 because of arithmetic, not because a table said so.
 *
 * Scenario members count against the headcount rather than sitting on top of
 * it: they are members of the unit, not extras.
 */
final class PopulationBuilder
{

    /** @var array<string, Person> */
    private array $people = [];

    /**
     * Live state of every filler member, keyed by Tiers. Scenario members are
     * deliberately absent: they are never aged, churned or trimmed, because
     * their whole point is to do exactly what ScenarioPeople says they do.
     *
     * `unitFunction` is set on unit-level members only — which of the three
     * unit functions this person holds, fixed at creation and carried
     * forward so nobody changes job title between two years by accident.
     *
     * @var array<string, array{birthYear: int, track: string, kind: string, section: ?string, active: bool, unitFunction?: string}>
     */
    private array $filler = [];

    private int $nextFillerNumber = ScenarioCatalog::FILLER_FIRST_ID;

    /** How many unit-level members have been created, for the rotation below. */
    private int $unitStaffCreated = 0;

    public function __construct(
        private readonly Rng $rng,
        private readonly PersonFactory $factory,
    ) {
    }

    /**
     * @return array<string, Person> keyed by Tiers, in Tiers order
     */
    public function build(): array
    {
        (new ScenarioPeople($this->rng, $this->factory))->addTo($this->people);

        foreach (UnitBlueprint::YEARS as $index => $year) {
            if ($index > 0) {
                $this->ageFiller($year);
            }
            $this->fillToHeadcount($year);
            $this->promoteSectionRoles($year);
        }

        ksort($this->people);

        return $this->people;
    }

    // ------------------------------------------------------------ year loops

    /**
     * Carry every filler member into the next year: one year older, branch
     * recomputed from that age, section reassigned, and some of them gone.
     */
    private function ageFiller(string $year): void
    {
        $reference = UnitBlueprint::referenceYear($year);

        foreach (array_keys($this->filler) as $tiers) {
            if (!$this->filler[$tiers]['active']) {
                continue;
            }

            if ($this->rng->chance(UnitBlueprint::CHURN_PERCENT)) {
                $this->filler[$tiers]['active'] = false;
                continue;
            }

            match ($this->filler[$tiers]['kind']) {
                'anime' => $this->carryAnime($tiers, $year, $reference),
                'cadre' => $this->carryCadre($tiers, $year),
                default => $this->carryUnitStaff($tiers, $year),
            };
        }
    }

    private function carryAnime(string $tiers, string $year, int $reference): void
    {
        $state = $this->filler[$tiers];
        $branch = $this->branchForAge($reference - $state['birthYear'], $state['track']);

        // Aged out of the last branch their track offers — they leave, which
        // is what actually happens to a Routier turning 22.
        $section = $branch !== null ? $this->sectionFor($branch, $year, $state['section']) : null;
        if ($branch === null || $section === null) {
            $this->filler[$tiers]['active'] = false;
            return;
        }

        $this->filler[$tiers]['section'] = $section;
        $this->appendAnimeYear($tiers, $year, $section, $branch);
    }

    private function carryCadre(string $tiers, string $year): void
    {
        $section = $this->filler[$tiers]['section'];
        if ($section === null) {
            $this->filler[$tiers]['active'] = false;
            return;
        }

        // An animateur moves section now and then; that is scenario 9 in the
        // small, happening to nobody in particular.
        if ($this->rng->chance(15)) {
            $branch = UnitBlueprint::SECTIONS[$section]['branch'];
            $section = $this->sectionFor($branch, $year, null) ?? $section;
        }

        if (!in_array($section, UnitBlueprint::sectionsIn($year), true)) {
            $this->filler[$tiers]['active'] = false;
            return;
        }

        $this->filler[$tiers]['section'] = $section;
        $this->appendCadreYear($tiers, $year, $section);
    }

    private function carryUnitStaff(string $tiers, string $year): void
    {
        $this->appendUnitStaffYear($tiers, $year);
    }

    /** Drop whoever is over the declared headcount, then recruit up to it. */
    private function fillToHeadcount(string $year): void
    {
        foreach (UnitBlueprint::HEADCOUNT[$year] as $handle => [$animes, $cadres]) {
            $branch = UnitBlueprint::SECTIONS[$handle]['branch'];

            $this->trimTo($year, $handle, 'anime', $animes);
            for ($i = $this->countAnimes($year, $handle); $i < $animes; $i++) {
                $this->createAnime($year, $handle, $branch);
            }

            $this->trimTo($year, $handle, 'cadre', $cadres);
            for ($i = $this->countLeaders($year, $handle); $i < $cadres; $i++) {
                $this->createCadre($year, $handle);
            }
        }

        $target = UnitBlueprint::UNIT_STAFF_SIZE[$year];
        $this->trimTo($year, null, 'unitstaff', $target);
        for ($i = $this->countUnitStaff($year); $i < $target; $i++) {
            $this->createUnitStaff($year);
        }
    }

    /**
     * Remove the surplus of a slot, newest member first — a unit that shrinks
     * loses its most recent recruits before its long-standing ones. Only
     * filler is ever trimmed.
     */
    private function trimTo(string $year, ?string $handle, string $kind, int $target): void
    {
        $members = $this->fillerHolding($year, $handle, $kind);
        $held = match ($kind) {
            'anime' => $this->countAnimes($year, $handle),
            'cadre' => $this->countLeaders($year, $handle),
            default => $this->countUnitStaff($year),
        };

        for ($i = count($members) - 1; $i >= 0 && $held > $target; $i--) {
            $tiers = $members[$i];
            unset($this->people[$tiers]->years[$year]);
            $this->filler[$tiers]['active'] = false;
            $held--;
        }
    }

    /**
     * Give each section the roles a real one carries, once its slots are
     * filled for the year.
     *
     * **Exactly one `Animateur responsable` per section per year.** That is
     * the section's designated responsable — what
     * Core\Module\SectionResponsableProvider answers with, what the public
     * Sections page names, what the member page shows beside a postal
     * address, and what the trombinoscope highlights. The dataset carried no
     * such function at all, so every one of those surfaces read `null` and
     * none of them was exercised by anything. More than one would be a unit
     * unable to say who is in charge, so the rule is exactly one.
     *
     * **`Intendant` and the two `Candidat …` functions** then go to the
     * spare leader of the next sections in turn, one each, so the three land
     * in three different sections rather than stacking in the first one. A
     * section with a single leader keeps them as its responsable and
     * contributes no spare.
     *
     * Deterministic throughout: sections in blueprint order, leaders in Tiers
     * order, so the same seed produces the same people.
     */
    private function promoteSectionRoles(string $year): void
    {
        $spare = [];

        foreach (array_keys(UnitBlueprint::HEADCOUNT[$year]) as $handle) {
            $leaders = $this->fillerHolding($year, $handle, 'cadre');
            sort($leaders);

            if ($leaders === []) {
                continue;
            }

            $this->rewriteSectionFunction($leaders[0], $year, 'Animateur responsable');

            if (isset($leaders[1])) {
                $spare[] = $leaders[1];
            }
        }

        foreach (['Intendant', 'Candidat intendant', 'Candidat animateur'] as $index => $code) {
            if (isset($spare[$index])) {
                $this->rewriteSectionFunction($spare[$index], $year, $code);
            }
        }
    }

    /**
     * Restate one person's section function under a different FONCTION,
     * keeping its branch, section, dates and main flag — the person did not
     * move, their job title is being named properly.
     *
     * PersonYear and FunctionAssignment are both readonly, so this rebuilds
     * rather than mutates.
     */
    private function rewriteSectionFunction(string $tiers, string $year, string $code): void
    {
        $personYear = $this->people[$tiers]->years[$year];
        $functions = $personYear->functions;
        $existing = $functions[0];

        $functions[0] = new FunctionAssignment(
            functionCode: $code,
            branch: $existing->branch,
            section: $existing->section,
            ignoredSectionCode: $existing->ignoredSectionCode,
            startDate: $existing->startDate,
            endDate: $existing->endDate,
            mandateEnd: $existing->mandateEnd,
            isMain: $existing->isMain,
        );

        $this->people[$tiers]->years[$year] = new PersonYear(
            functions: $functions,
            feeCode: $personYear->feeCode,
            totem: $personYear->totem,
            quali: $personYear->quali,
            patrol: $personYear->patrol,
            formationLevel: $personYear->formationLevel,
        );
    }

    // ------------------------------------------------------- filler creation

    private function createAnime(string $year, string $handle, string $branch): void
    {
        $reference = UnitBlueprint::referenceYear($year);
        [$minAge, $maxAge] = UnitBlueprint::BRANCH_AGES[$branch];

        // A newcomer joins at the bottom of the bracket far more often than at
        // the top: a unit recruits six-year-olds, not fifteen-year-olds.
        $age = $this->rng->chance(60) ? $minAge : $this->rng->int($minAge, $maxAge);
        $birthYear = $reference - $age;

        $tiers = $this->nextFillerTiers();
        $this->people[$tiers] = $this->factory->make($tiers, $birthYear, $branch);
        $this->filler[$tiers] = [
            'birthYear' => $birthYear,
            'track' => $branch === 'Iama' ? 'iama' : 'canonical',
            'kind' => 'anime',
            'section' => $handle,
            'active' => true,
        ];

        $this->appendAnimeYear($tiers, $year, $handle, $branch);
    }

    private function createCadre(string $year, string $handle): void
    {
        $birthYear = UnitBlueprint::referenceYear($year) - $this->rng->int(19, 38);
        $tiers = $this->nextFillerTiers();
        $this->people[$tiers] = $this->factory->make($tiers, $birthYear, null);
        $this->filler[$tiers] = [
            'birthYear' => $birthYear,
            'track' => 'cadre',
            'kind' => 'cadre',
            'section' => $handle,
            'active' => true,
        ];

        $this->appendCadreYear($tiers, $year, $handle);
    }

    /**
     * A unit-level member. The three unit functions are handed out in turn
     * rather than all being the same one: UNIT_STAFF_SIZE is four or five a
     * year, so a strict rotation guarantees at least one of each — which is
     * what makes « Staff d'U » a staff rather than four copies of one job
     * title, and what gives the roster on the public Contact page and the
     * leadership module's Équipiers page something to show.
     *
     * The function is stored in the filler state and carried forward
     * unchanged: somebody does not become a different kind of unit staffer
     * between two years by accident.
     */
    private function createUnitStaff(string $year): void
    {
        $birthYear = UnitBlueprint::referenceYear($year) - $this->rng->int(24, 45);
        $tiers = $this->nextFillerTiers();
        $this->people[$tiers] = $this->factory->make($tiers, $birthYear, null);
        $unitFunctions = UnitBlueprint::UNIT_LEVEL_FUNCTIONS;
        $this->filler[$tiers] = [
            'birthYear' => $birthYear,
            'track' => 'cadre',
            'kind' => 'unitstaff',
            'section' => null,
            'active' => true,
            'unitFunction' => $unitFunctions[$this->unitStaffCreated % count($unitFunctions)],
        ];
        $this->unitStaffCreated++;

        $this->appendUnitStaffYear($tiers, $year);
    }

    // --------------------------------------------------------- year building

    private function appendAnimeYear(string $tiers, string $year, string $handle, string $branch): void
    {
        $person = $this->people[$tiers];
        $previous = $this->previousYearOf($person, $year);

        // A totem is earned once and kept. Éclaireurs and above carry one;
        // Baladins and Louveteaux do not.
        $earnsTotem = in_array($branch, ['Éclaireurs', 'Pionniers', 'Route', 'Iama'], true);
        $totem = $previous->totem ?? ($earnsTotem && $this->rng->chance(75) ? $this->rng->pick(UnitBlueprint::TOTEMS) : null);
        $quali = $totem !== null ? ($previous->quali ?? $this->rng->pick(UnitBlueprint::QUALIS)) : null;

        $person->years[$year] = new PersonYear(
            functions: [$this->sectionFunction('Animé', $handle, $year, true)],
            feeCode: $previous->feeCode
                ?? ($this->rng->chance(12) ? UnitBlueprint::FEE_CODES['anime_reduit'] : UnitBlueprint::FEE_CODES['anime']),
            totem: $totem,
            quali: $quali,
            patrol: match ($branch) {
                'Baladins', 'Louveteaux' => $this->rng->pick(UnitBlueprint::PATROLS_YOUNG),
                'Éclaireurs' => $this->rng->pick(UnitBlueprint::PATROLS_TEEN),
                default => null,
            },
        );
    }

    private function appendCadreYear(string $tiers, string $year, string $handle): void
    {
        $person = $this->people[$tiers];
        $previous = $this->previousYearOf($person, $year);

        $person->years[$year] = new PersonYear(
            functions: [$this->sectionFunction('Animateur', $handle, $year, true)],
            feeCode: UnitBlueprint::FEE_CODES['cadre'],
            totem: $previous->totem ?? $this->rng->pick(UnitBlueprint::TOTEMS),
            quali: $previous->quali ?? $this->rng->pick(UnitBlueprint::QUALIS),
            formationLevel: $previous->formationLevel ?? $this->rng->pick(UnitBlueprint::FORMATION_LEVELS),
        );
    }

    private function appendUnitStaffYear(string $tiers, string $year): void
    {
        $person = $this->people[$tiers];
        $previous = $this->previousYearOf($person, $year);

        // "Staff d'U" is never a Section value in a Desk export — the section
        // is synthesised by UnitStaffSectionService from the admin role, and
        // that role is only known once Config Desk confirms the function.
        $person->years[$year] = new PersonYear(
            functions: [$this->unitFunction($this->filler[$tiers]['unitFunction'] ?? UnitBlueprint::UNIT_LEVEL_FUNCTIONS[0], true)],
            feeCode: UnitBlueprint::FEE_CODES['cadre'],
            totem: $previous->totem ?? $this->rng->pick(UnitBlueprint::TOTEMS),
            quali: $previous->quali ?? $this->rng->pick(UnitBlueprint::QUALIS),
            formationLevel: $previous->formationLevel ?? 'Formation avancée',
        );
    }

    private function sectionFunction(string $code, string $handle, string $year, bool $isMain): FunctionAssignment
    {
        $section = UnitBlueprint::SECTIONS[$handle];

        return new FunctionAssignment(
            functionCode: $code,
            branch: $section['branch'],
            section: $section['name'],
            ignoredSectionCode: $section['ignoredCode'],
            startDate: UnitBlueprint::startDate($year),
            endDate: null,
            mandateEnd: null,
            isMain: $isMain,
        );
    }

    private function unitFunction(string $code, bool $isMain): FunctionAssignment
    {
        return new FunctionAssignment(
            functionCode: $code,
            branch: null,
            section: null,
            ignoredSectionCode: null,
            startDate: null,
            endDate: null,
            mandateEnd: null,
            isMain: $isMain,
        );
    }

    // --------------------------------------------------------------- counting

    private function countAnimes(string $year, ?string $handle): int
    {
        return $this->count($year, $handle, fn (string $code): bool => $code === 'Animé');
    }

    private function countLeaders(string $year, ?string $handle): int
    {
        return $this->count($year, $handle, fn (string $code): bool => in_array($code, UnitBlueprint::SECTION_STAFF_FUNCTIONS, true));
    }

    private function countUnitStaff(string $year): int
    {
        return $this->count(
            $year,
            null,
            fn (string $code): bool => in_array($code, UnitBlueprint::UNIT_LEVEL_FUNCTIONS, true)
        );
    }

    /**
     * @param callable(string): bool $matchesCode
     */
    private function count(string $year, ?string $handle, callable $matchesCode): int
    {
        $wanted = $handle !== null ? UnitBlueprint::SECTIONS[$handle]['name'] : null;
        $count = 0;

        foreach ($this->people as $person) {
            $personYear = $person->years[$year] ?? null;
            if ($personYear === null) {
                continue;
            }

            foreach ($personYear->functions as $function) {
                if ($function->section === $wanted && $matchesCode($function->functionCode)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string> Tiers of the filler members holding a slot this
     *                      year, oldest first
     */
    private function fillerHolding(string $year, ?string $handle, string $kind): array
    {
        $found = [];
        foreach ($this->filler as $tiers => $state) {
            if ($state['kind'] !== $kind || $state['section'] !== $handle) {
                continue;
            }
            if (isset($this->people[$tiers]->years[$year])) {
                $found[] = $tiers;
            }
        }

        return $found;
    }

    // --------------------------------------------------------------- helpers

    private function previousYearOf(Person $person, string $year): ?PersonYear
    {
        $index = (int) array_search($year, UnitBlueprint::YEARS, true);
        for ($i = $index - 1; $i >= 0; $i--) {
            $candidate = UnitBlueprint::YEARS[$i];
            if (isset($person->years[$candidate])) {
                return $person->years[$candidate];
            }
        }

        return null;
    }

    private function branchForAge(int $age, string $track): ?string
    {
        if ($track === 'iama') {
            [$min, $max] = UnitBlueprint::BRANCH_AGES['Iama'];

            return $age >= $min && $age <= $max ? 'Iama' : null;
        }

        foreach (['Baladins', 'Louveteaux', 'Éclaireurs', 'Pionniers', 'Route'] as $branch) {
            [$min, $max] = UnitBlueprint::BRANCH_AGES[$branch];
            if ($age >= $min && $age <= $max) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * The section of $branch a member lands in: the one they were already in
     * when it still exists, otherwise the least-filled one of that branch —
     * which is what a staff actually does when it splits a section.
     */
    private function sectionFor(string $branch, string $year, ?string $current): ?string
    {
        $candidates = UnitBlueprint::sectionsOfBranchIn($branch, $year);
        if ($candidates === []) {
            return null;
        }

        if ($current !== null && in_array($current, $candidates, true)) {
            return $current;
        }

        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($candidates as $handle) {
            $count = $this->countAnimes($year, $handle);
            if ($count < $bestCount) {
                $best = $handle;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function nextFillerTiers(): string
    {
        return sprintf('T%04d', $this->nextFillerNumber++);
    }
}
