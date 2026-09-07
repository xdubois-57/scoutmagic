<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Member\AddressNormalizer;
use Core\Member\HouseholdFeeCategory;

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

    /** @var array<string, true> Tiers of the hand-written scenario members */
    private array $scenarioTiers = [];

    /**
     * Every home the filler has opened, oldest first. Kept for the whole
     * build rather than per year, which is the point: a member created in A2
     * moves in with a family built in A1, and that home then changes size
     * between two years on its own.
     *
     * The scenario fratries are deliberately absent — ScenarioPeople owns
     * theirs, and a filler member moving in with the Delvaux would change the
     * household sizes the import test pins.
     *
     * @var list<Household>
     */
    private array $households = [];

    /**
     * How many cadres of each gender still have to be created for every
     * portrait of the photo lot to find a holder. See {@see nextCadreGender()}.
     *
     * @var array<string, int>
     */
    private array $cadreGenderQuota = [];

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
        // Whoever ScenarioPeople wrote by hand: designateSectionLeads() must
        // never touch them, since their functions are what the scenarios are.
        $this->scenarioTiers = array_fill_keys(array_keys($this->people), true);
        $this->cadreGenderQuota = $this->cadreGenderQuotaLeftByScenarios();

        foreach (UnitBlueprint::YEARS as $index => $year) {
            if ($index > 0) {
                $this->ageFiller($year);
            }
            $this->fillToHeadcount($year);
        }

        ksort($this->people);
        $this->designateSectionLeads();
        $this->designateSectionSpecialists();
        $this->assignHouseholdTariffs();
        $this->staleTheOldestTariffOfSomeHouseholds();

        return $this->people;
    }

    /**
     * Give every member the Desk cotisation type their household size
     * calls for — the last word on the "Tarif" column, for everyone.
     *
     * Desk offers three types and no others (UnitBlueprint::FEE_CODES),
     * and which one a member carries is not a property of that member: it
     * is a property of how many members live at their address. So it
     * cannot be decided while a person is being built, when the rest of
     * their household may not exist yet — hence a post-pass, the same
     * shape and for the same kind of reason as designateSectionLeads()
     * above, and drawing nothing from the Rng for the same reason too.
     *
     * The grouping goes through `Core\Member\AddressNormalizer`, the very
     * class the site uses to decide who shares a household, rather than a
     * comparison written here: a dataset whose households the application
     * would group differently would be quietly testing nothing on the
     * « Justesse des tarifs » screen.
     *
     * Only the **Domicile** address counts. A member with a second address
     * belongs to two households as far as the site is concerned
     * (`Core\Member\Household\Household`, which says in as many words
     * that which of the two the federation bills on is not something the
     * site knows) — but a unit encoding the tariff picks one, and it is
     * the home one.
     *
     * This is what makes a household that changes size change tariff:
     * scenarios 17 and 18 (siblings arriving and leaving) now move between
     * couple and famille on their own, and scenario 23 is a household
     * going from one member to two.
     */
    private function assignHouseholdTariffs(): void
    {
        foreach (UnitBlueprint::YEARS as $year) {
            $sizes = array_map(
                static fn (array $members): int => count($members),
                $this->groupHouseholds($year),
            );

            foreach ($this->people as $person) {
                $personYear = $person->years[$year] ?? null;
                if ($personYear === null) {
                    continue;
                }
                $key = self::householdKey($person);
                // No usable address is not "a household of one" — it is
                // "the site cannot say", the distinction HouseholdService
                // draws. The tariff already on the year stands.
                $feeCode = $key === ''
                    ? $personYear->feeCode
                    : UnitBlueprint::FEE_CODES[HouseholdFeeCategory::fromHouseholdSize($sizes[$key])->value];

                $person->years[$year] = new PersonYear(
                    functions: $personYear->functions,
                    feeCode: $feeCode,
                    totem: $personYear->totem,
                    quali: $personYear->quali,
                    patrol: $personYear->patrol,
                    formationLevel: $personYear->formationLevel,
                );
            }
        }
    }

    /**
     * Let a few homes a year keep a tariff nobody re-encoded — issue #201.
     *
     * `assignHouseholdTariffs()` above is a perfect clerk: every member comes
     * out carrying exactly the tariff their household size implies. A unit is
     * not, and « Justesse des tarifs »
     * (`Modules\Fees\Service\FeeAccuracyService`) exists precisely for the
     * one that forgot — a third child arrived, the home became famille, and
     * the eldest stayed on couple because nobody went back to their row. With
     * a flawless export that screen's « à corriger » tab and its banner are
     * empty forever, and a demonstration instance shows half a feature.
     *
     * So the eldest of a few homes keeps the tariff of the home as it was one
     * member ago. It is a **real Desk code** in every case (#194 is about the
     * fourth code Desk does not offer, and stays fixed), it is always genuinely
     * wrong for the size, and it is applied per year — the same home can be
     * right in A1 and stale in A2, which is what the screen is read for.
     *
     * Chosen in normalized-address order over homes holding no scenario
     * member: the hand-written fratries are what ReferenceDatasetImportTest
     * pins tariff by tariff, and a pass that could reach them would make those
     * assertions depend on this one. A post-pass like the two above it, and
     * like them it draws nothing from the Rng — the dataset must not move
     * because this number changed.
     */
    private function staleTheOldestTariffOfSomeHouseholds(): void
    {
        foreach (UnitBlueprint::YEARS as $year) {
            $eligible = [];

            foreach ($this->groupHouseholds($year) as $key => $members) {
                if (count($members) < 2) {
                    continue;
                }
                foreach ($members as $tiers) {
                    if (isset($this->scenarioTiers[$tiers])) {
                        continue 2;
                    }
                }
                $eligible[$key] = $members;
            }

            ksort($eligible);
            $stalled = 0;

            foreach ($eligible as $members) {
                if ($stalled >= UnitBlueprint::STALE_TARIFF_HOUSEHOLDS_PER_YEAR) {
                    break;
                }

                // The eldest of the home: the row that was already correct
                // before the newcomer, and therefore the one nobody thought
                // to open again.
                $forgotten = $members[0];
                $this->rewriteFeeCode(
                    $forgotten,
                    $year,
                    count($members) >= 3 ? UnitBlueprint::FEE_CODES['couple'] : UnitBlueprint::FEE_CODES['normal'],
                );
                $stalled++;
            }
        }
    }

    /**
     * Who lives with whom in one year, keyed by normalized home address, each
     * list in Tiers order — `$this->people` has been ksorted by the time any
     * of the post-passes runs.
     *
     * @return array<string, list<string>>
     */
    private function groupHouseholds(string $year): array
    {
        $groups = [];

        foreach ($this->people as $tiers => $person) {
            if (!$person->isPresentIn($year)) {
                continue;
            }
            $key = self::householdKey($person);
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $tiers;
        }

        return $groups;
    }

    /** Swap one year's tariff on one member, leaving the rest of the year alone. */
    private function rewriteFeeCode(string $tiers, string $year, string $feeCode): void
    {
        $personYear = $this->people[$tiers]->years[$year] ?? null;
        if ($personYear === null) {
            return;
        }

        $this->people[$tiers]->years[$year] = new PersonYear(
            functions: $personYear->functions,
            feeCode: $feeCode,
            totem: $personYear->totem,
            quali: $personYear->quali,
            patrol: $personYear->patrol,
            formationLevel: $personYear->formationLevel,
        );
    }

    /**
     * The normalized home address, which is what the site groups a
     * household by. Empty when the person has no Domicile row at all,
     * which this generator never produces but which costs one check to
     * refuse to guess about.
     */
    private static function householdKey(Person $person): string
    {
        foreach ($person->addresses as $address) {
            if ($address->type !== 'Domicile') {
                continue;
            }

            return AddressNormalizer::normalize(
                $address->street,
                $address->number,
                $address->box,
                $address->postalCode
            );
        }

        return '';
    }

    /**
     * Promote exactly one cadre per section per year to `Chef de section`.
     *
     * The trombinoscope's "responsable" is whoever holds a function flagged
     * `is_lead` (Modules\Trombinoscope\Repository\FunctionFlagsRepository).
     * With every animateur carrying the same FONCTION there is nothing to
     * flag that would not flag the whole staff, and TrombinoscopeService then
     * promotes whichever row came back first — a "responsable" decided by the
     * query planner. One designated holder per section per year is what makes
     * that page mean something.
     *
     * Deliberately a POST-PASS over the finished population rather than a
     * choice made while building it, and it draws nothing from the Rng: the
     * generated files are compared byte for byte, so a new random call here
     * would shift every subsequent draw and rewrite the whole dataset.
     *
     * Scenario members are skipped. `T0013` becoming an `Animateur` in A3 and
     * `T0018` losing "candidat" are pinned by ReferenceDatasetImportTest —
     * promoting one of them would be a silent change to a named behaviour.
     * The filler cadres are numerous enough everywhere (three at the very
     * least, per UnitBlueprint::HEADCOUNT) that one is always available, and
     * the generator fails loudly rather than leaving a section headless.
     */
    private function designateSectionLeads(): void
    {
        foreach (UnitBlueprint::YEARS as $year) {
            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                if (UnitBlueprint::HEADCOUNT[$year][$handle][1] === 0) {
                    continue;
                }
                if (!$this->promoteOne($year, $handle, UnitBlueprint::SECTION_LEAD_FUNCTION)) {
                    throw new \RuntimeException(
                        "Aucun cadre disponible pour porter « " . UnitBlueprint::SECTION_LEAD_FUNCTION
                        . " » dans {$handle} en {$year}."
                    );
                }
            }
        }
    }

    /**
     * Restate one filler `Animateur` of a section under another FONCTION —
     * the person did not move, their post is being named properly.
     *
     * @return bool whether a cadre was found and promoted
     */
    private function promoteOne(string $year, string $handle, string $code): bool
    {
        $sectionName = UnitBlueprint::SECTIONS[$handle]['name'];

        foreach ($this->people as $tiers => $person) {
            if (isset($this->scenarioTiers[$tiers])) {
                continue;
            }
            $personYear = $person->years[$year] ?? null;
            if ($personYear === null) {
                continue;
            }

            foreach ($personYear->functions as $index => $function) {
                if ($function->functionCode !== 'Animateur' || $function->section !== $sectionName) {
                    continue;
                }

                $functions = $personYear->functions;
                $functions[$index] = new FunctionAssignment(
                    functionCode: $code,
                    branch: $function->branch,
                    section: $function->section,
                    ignoredSectionCode: $function->ignoredSectionCode,
                    startDate: $function->startDate,
                    endDate: $function->endDate,
                    mandateEnd: $function->mandateEnd,
                    isMain: $function->isMain,
                );

                $person->years[$year] = new PersonYear(
                    functions: $functions,
                    feeCode: $personYear->feeCode,
                    totem: $personYear->totem,
                    quali: $personYear->quali,
                    patrol: $personYear->patrol,
                    formationLevel: $personYear->formationLevel,
                );

                return true;
            }
        }

        return false;
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
     * The rest of a section's real vocabulary: an `Intendant`, and one of
     * each `Candidat …`.
     *
     * A unit does not staff its sections with animateurs alone, and a
     * dataset that says so cannot exercise the intendant role
     * (`Modules\Finance`'s account visibility asks for it) nor
     * CandidateDetector, which the leadership module reads on every staff
     * page. One each, spread over the first sections that still have a
     * spare cadre, so the three do not stack in one section.
     *
     * Runs AFTER designateSectionLeads() and on the same terms: a post-pass
     * over the finished population, drawing nothing from the Rng, never
     * touching a scenario member. Promoting only rows still reading
     * `Animateur` is what keeps it from taking the section's lead back.
     *
     * Unlike a missing lead, a missing specialist is not fatal: a small year
     * can genuinely run out of spare cadres, and the dataset is still
     * coherent without the third one. ReferenceDatasetImportTest asserts
     * what must be there.
     */
    private function designateSectionSpecialists(): void
    {
        $wanted = ['Intendant', 'Candidat intendant', 'Candidat animateur'];

        foreach (UnitBlueprint::YEARS as $year) {
            $index = 0;

            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                if ($index >= count($wanted)) {
                    break;
                }
                if (UnitBlueprint::HEADCOUNT[$year][$handle][1] < 2) {
                    continue;
                }

                if ($this->promoteOne($year, $handle, $wanted[$index])) {
                    $index++;
                }
            }
        }
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
        $household = $this->homeFor($year, $branch);
        $this->people[$tiers] = $this->factory->make(
            $tiers,
            $birthYear,
            $branch,
            $household?->lastName,
            $household?->address,
            $household?->email,
            $household?->secondAddress,
        );
        $this->moveIn($household, $tiers);
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
        // Only ever joins a home, never founds one: a home nobody but cadres
        // may join is a flatshare, and the mixed home this exists for is an
        // animateur moving in ON a family that is already there.
        $household = $this->joinOpenHome($year, UnitBlueprint::HOUSEHOLD_CADRE_JOIN_PERCENT);
        $this->people[$tiers] = $this->factory->make(
            $tiers,
            $birthYear,
            null,
            $household?->lastName,
            $household?->address,
            $household?->email,
            $household?->secondAddress,
            forcedGender: $this->nextCadreGender(),
        );
        $this->moveIn($household, $tiers);
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
        $this->people[$tiers] = $this->factory->make($tiers, $birthYear, null, forcedGender: $this->nextCadreGender());
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

    // ---------------------------------------------------------------- homes

    /**
     * Where this new animé lives: with a family that already has room, or in
     * a home of their own that later arrivals may join, or nowhere in
     * particular. Issue #201.
     *
     * Drawn BEFORE the person is built, because a home decides four of the
     * things PersonFactory would otherwise draw — surname, address, second
     * address, mailbox — and a person cannot be moved into one afterwards
     * without contradicting what was already written on their row.
     */
    private function homeFor(string $year, ?string $branch): ?Household
    {
        $joined = $this->joinOpenHome($year, UnitBlueprint::HOUSEHOLD_JOIN_PERCENT);
        if ($joined !== null) {
            return $joined;
        }

        if (!$this->rng->chance(UnitBlueprint::HOUSEHOLD_FOUND_PERCENT)) {
            return null;
        }

        return $this->foundHome($branch);
    }

    /**
     * Pick a home that still has room and somebody living in it this year,
     * or nothing.
     *
     * "Somebody living in it this year" is what keeps a newcomer from moving
     * into a house whose whole family left two years ago: the address would
     * be reused by strangers, and the fratrie the tariff is about would be a
     * coincidence of the generator rather than a family.
     */
    private function joinOpenHome(string $year, int $joinPercent): ?Household
    {
        $open = [];
        foreach ($this->households as $household) {
            if (!$household->isFull() && $this->isOccupiedIn($household, $year)) {
                $open[] = $household;
            }
        }

        if ($open === [] || !$this->rng->chance($joinPercent)) {
            return null;
        }

        return $this->rng->pick($open);
    }

    /**
     * Open a home: a surname, an address, the second address its children
     * will all carry, and one parent mailbox for the lot.
     *
     * The mailbox is the reason this is a draw of its own rather than a
     * by-product of the first member. `DeskImportService::ensureUserAccount()`
     * keys an account on the email blind index, so one mailbox over three
     * children is one parent account linked to three members — the commonest
     * shape on the public site, and one this dataset did not have outside the
     * two hand-written fratries.
     */
    private function foundHome(?string $branch): Household
    {
        $lastName = $this->factory->nextLastName();
        $address = $this->factory->makeAddress('Domicile');
        $secondAddress = $this->rng->chance(PersonFactory::secondAddressChance($branch))
            ? $this->factory->makeAddress('Adresse secondaire')
            : null;

        $household = new Household(
            lastName: $lastName,
            address: $address,
            email: $this->factory->makeHouseholdEmail($lastName),
            targetSize: $this->rng->pick(UnitBlueprint::HOUSEHOLD_TARGET_SIZES),
            secondAddress: $secondAddress,
        );
        $this->households[] = $household;

        return $household;
    }

    private function moveIn(?Household $household, string $tiers): void
    {
        if ($household === null) {
            return;
        }

        $household->members[] = $tiers;
    }

    /** Whether anybody assigned to this home is a member of the unit in $year. */
    private function isOccupiedIn(Household $household, string $year): bool
    {
        foreach ($household->members as $tiers) {
            if (($this->people[$tiers] ?? null)?->isPresentIn($year) === true) {
                return true;
            }
        }

        return false;
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
            // Provisional: assignHouseholdTariffs() has the last word, once
            // every member of every household exists.
            feeCode: UnitBlueprint::FEE_CODES['normal'],
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
            // Provisional, like every other tariff here — and an animateur
            // gets one of the same three types as anybody else (issue #194).
            feeCode: UnitBlueprint::FEE_CODES['normal'],
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
            feeCode: UnitBlueprint::FEE_CODES['normal'],
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

    /**
     * The gender the next filler cadre must have, or null to let the
     * ordinary draw decide.
     *
     * **The photo lot and the cadre population are one constraint, and it
     * used to be met by luck.** `PhotoLot::INDIVIDUAL_GENDERS` declares a
     * gender for each of the forty individual portraits, and
     * `PhotoAssigner` gives every one of them to a distinct cadre of that
     * gender — refusing loudly, and producing no dataset at all, when it
     * runs out. The unit has almost exactly forty cadres, so the split had
     * to match the lot's within nothing, and it did only because
     * `PersonFactory::GENDER_F_PERCENT` happened to land there on this
     * seed. Removing a single unrelated `Rng` draw elsewhere (the fee code
     * of an animé year, issue #194) moved the whole stream two cadres off
     * and the generator stopped working — which is how a constraint that
     * had never been written down was discovered.
     *
     * So it is written down here instead: as long as the lot still needs a
     * gender, the next cadre has it, the scarcer one first. Once both
     * quotas are met — the unit has more cadres than the lot has portraits
     * — the ordinary draw resumes, which is what keeps the staff from
     * being an exact copy of the lot's own balance.
     *
     * This decides the gender of a CADRE only. Animés, who are the bulk of
     * the population and the whole of scenario 24's gender balance, are
     * untouched.
     */
    private function nextCadreGender(): ?string
    {
        $needed = array_filter($this->cadreGenderQuota, static fn(int $left): bool => $left > 0);
        if ($needed === []) {
            return null;
        }

        // Whichever gender the lot still needs MOST, so a lot asking for 24
        // M and 16 F interleaves the two rather than producing 24 men
        // followed by 16 women. `arsort` keeps the keys, and a tie resolves
        // on the first of them, so this never depends on hash order.
        arsort($needed);
        $gender = (string) array_key_first($needed);
        $this->cadreGenderQuota[$gender]--;

        return $gender;
    }

    /**
     * What the photo lot still needs once the hand-written scenario cadres
     * — whose genders are theirs and not negotiable — have been counted.
     *
     * @return array<string, int>
     */
    private function cadreGenderQuotaLeftByScenarios(): array
    {
        $quota = ['F' => 0, 'M' => 0];
        foreach (PhotoLot::INDIVIDUAL_GENDERS as $gender) {
            $quota[$gender]++;
        }

        foreach ($this->people as $person) {
            if (!self::everHoldsACadreFunction($person)) {
                continue;
            }
            if (isset($quota[$person->gender]) && $quota[$person->gender] > 0) {
                $quota[$person->gender]--;
            }
        }

        return $quota;
    }

    /**
     * Whether this person ever holds a function `PhotoAssigner` counts as
     * a cadre — the same `chief`/`admin` test it applies, so the supply
     * counted here is the supply it will draw from.
     */
    private static function everHoldsACadreFunction(Person $person): bool
    {
        foreach ($person->years as $personYear) {
            foreach ($personYear->functions as $function) {
                $role = UnitBlueprint::FUNCTIONS[$function->functionCode] ?? 'identified';
                if ($role === 'chief' || $role === 'admin') {
                    return true;
                }
            }
        }

        return false;
    }

    private function nextFillerTiers(): string
    {
        return sprintf('T%04d', $this->nextFillerNumber++);
    }
}
