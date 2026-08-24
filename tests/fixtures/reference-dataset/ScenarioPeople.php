<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The members that carry the named scenarios of ScenarioCatalog.
 *
 * Written out by hand, one method per scenario, because each one is a claim
 * about how the application behaves and a generator that produced them "by
 * chance" would be asserting nothing. The filler population (PopulationBuilder)
 * is drawn; these are stated.
 *
 * Birth years are chosen so that the branch each member lands in falls out of
 * the arithmetic MemberYearService::getEffectiveAge() will do — reference year
 * (the scout year's START year) minus birth year — rather than being declared.
 * A2 = A1 + 1 everywhere, so a boundary crossing is a birth year, not a rule.
 */
final class ScenarioPeople
{
    private const A1 = '2024-2025';
    private const A2 = '2025-2026';
    private const A3 = '2026-2027';

    public function __construct(
        private readonly Rng $rng,
        private readonly PersonFactory $factory,
    ) {
    }

    /**
     * @param array<string, Person> $people
     */
    public function addTo(array &$people): void
    {
        foreach ($this->build() as $person) {
            $people[$person->deskId] = $person;
        }
    }

    /**
     * @return list<Person>
     */
    private function build(): array
    {
        return [
            ...$this->simpleContinuity(),
            ...$this->branchCrossings(),
            ...$this->sectionChangesWithinBranch(),
            ...$this->departure(),
            ...$this->returnAfterGap(),
            ...$this->lateArrivals(),
            ...$this->totemEarned(),
            ...$this->pioneerBecomesLeader(),
            ...$this->leaderChangesSection(),
            ...$this->leaderBecomesUnitChief(),
            ...$this->quartermaster(),
            ...$this->twoFunctions(),
            ...$this->candidateFunction(),
            ...$this->brandNewFunction(),
            ...$this->siblingsGrowingTo3(),
            ...$this->siblingsLosingOne(),
            ...$this->twoAddresses(),
            ...$this->sparseMember(),
            ...$this->handicapAndInsurance(),
            ...$this->awkwardNames(),
            ...$this->feeChange(),
        ];
    }

    // ------------------------------------------------------------ scenario 1

    /** @return list<Person> */
    private function simpleContinuity(): array
    {
        // Born 2015 → 9, 10, 11: three Louveteaux years in the same section,
        // year-in-branch 2 then 3 then 4.
        $person = $this->factory->make('T0001', 2015, 'Louveteaux');
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $person->years[$year] = $this->animeYear('lou1', $year, totem: null);
        }

        return [$person];
    }

    // ------------------------------------------------------------ scenario 2

    /** @return list<Person> */
    private function branchCrossings(): array
    {
        // One member per frontier, each sitting on the last year of their
        // branch in A1 so the crossing happens in A2 and holds in A3.
        $crossings = [
            ['T0002', 2017, ['bal1', 'lou1', 'lou1']],  // 7, 8, 9    Baladins → Louveteaux
            ['T0003', 2013, ['lou2', 'ecl1', 'ecl1']],  // 11, 12, 13 Louveteaux → Éclaireurs
            ['T0004', 2009, ['ecl1', 'pio1', 'pio1']],  // 15, 16, 17 Éclaireurs → Pionniers
            ['T0005', 2007, ['pio1', 'rou1', 'rou1']],  // 17, 18, 19 Pionniers → Route
        ];

        $people = [];
        foreach ($crossings as [$tiers, $birthYear, $sections]) {
            $person = $this->factory->make($tiers, $birthYear, 'Éclaireurs');
            foreach ([self::A1, self::A2, self::A3] as $index => $year) {
                $person->years[$year] = $this->animeYear($sections[$index], $year, totem: $this->totemFrom($sections[$index]));
            }
            $people[] = $person;
        }

        return $people;
    }

    // ------------------------------------------------------------ scenario 3

    /** @return list<Person> */
    private function sectionChangesWithinBranch(): array
    {
        // T0006 moves between the two Baladins sections in A2 — which is also
        // the year the second one first exists (scenario 16). Aged 8 in A3 he
        // necessarily leaves the branch: Baladins is only two years long, so
        // the same-branch move is observable on A1→A2 and nowhere else.
        $baladin = $this->factory->make('T0006', 2018, 'Baladins');
        $baladin->years[self::A1] = $this->animeYear('bal1', self::A1, totem: null);
        $baladin->years[self::A2] = $this->animeYear('bal2', self::A2, totem: null);
        $baladin->years[self::A3] = $this->animeYear('lou1', self::A3, totem: null);

        // T0007 has four Louveteaux years to play with, so the move is clean:
        // same branch, different section, twice in a row.
        $louveteau = $this->factory->make('T0007', 2015, 'Louveteaux');
        $louveteau->years[self::A1] = $this->animeYear('lou1', self::A1, totem: null);
        $louveteau->years[self::A2] = $this->animeYear('lou2', self::A2, totem: null);
        $louveteau->years[self::A3] = $this->animeYear('lou2', self::A3, totem: null);

        return [$baladin, $louveteau];
    }

    // ------------------------------------------------------------ scenario 4

    /** @return list<Person> */
    private function departure(): array
    {
        $person = $this->factory->make('T0008', 2011, 'Éclaireurs');
        $person->years[self::A1] = $this->animeYear('ecl1', self::A1, totem: $this->rng->pick(UnitBlueprint::TOTEMS));

        return [$person];
    }

    // ------------------------------------------------------------ scenario 5

    /** @return list<Person> */
    private function returnAfterGap(): array
    {
        // Present, gone, back. The builder gives this member a
        // scout_year_offset in A1 (extras, IT-06); A3's row must inherit it
        // from A1 by start_date, since A2 does not exist to inherit from.
        $person = $this->factory->make('T0009', 2013, 'Louveteaux');
        $person->years[self::A1] = $this->animeYear('lou1', self::A1, totem: null);
        $person->years[self::A3] = $this->animeYear('ecl1', self::A3, totem: $this->rng->pick(UnitBlueprint::TOTEMS));

        return [$person];
    }

    // ------------------------------------------------------------ scenario 6

    /** @return list<Person> */
    private function lateArrivals(): array
    {
        $inA2 = $this->factory->make('T0010', 2016, 'Louveteaux');
        $inA2->years[self::A2] = $this->animeYear('lou1', self::A2, totem: null);
        $inA2->years[self::A3] = $this->animeYear('lou1', self::A3, totem: null);

        $inA3 = $this->factory->make('T0011', 2018, 'Louveteaux');
        $inA3->years[self::A3] = $this->animeYear('lou2', self::A3, totem: null);

        return [$inA2, $inA3];
    }

    // ------------------------------------------------------------ scenario 7

    /** @return list<Person> */
    private function totemEarned(): array
    {
        $totem = $this->rng->pick(UnitBlueprint::TOTEMS);
        $quali = $this->rng->pick(UnitBlueprint::QUALIS);

        $person = $this->factory->make('T0012', 2011, 'Éclaireurs');
        $person->years[self::A1] = $this->animeYear('ecl1', self::A1, totem: null);
        $person->years[self::A2] = $this->animeYear('ecl1', self::A2, totem: $totem, quali: $quali);
        $person->years[self::A3] = $this->animeYear('ecl1', self::A3, totem: $totem, quali: $quali);

        return [$person];
    }

    // ------------------------------------------------------------ scenario 8

    /** @return list<Person> */
    private function pioneerBecomesLeader(): array
    {
        // 16, 17, 18: two Pionniers years then, rather than Route, a Baladins
        // staff. His photo file keeps its pionnier_ prefix and follows him —
        // the prefix is an initial assignment, never an identity.
        $person = $this->factory->make('T0013', 2008, 'Pionniers');
        $person->years[self::A1] = $this->animeYear('pio1', self::A1, totem: $this->rng->pick(UnitBlueprint::TOTEMS));
        $person->years[self::A2] = $this->animeYear('pio1', self::A2, totem: $person->years[self::A1]->totem);
        $person->years[self::A3] = new PersonYear(
            functions: [$this->sectionFunction('Animateur', 'bal1', self::A3, true)],
            feeCode: UnitBlueprint::FEE_CODES['cadre'],
            totem: $person->years[self::A1]->totem,
            quali: $person->years[self::A1]->quali,
            formationLevel: 'Formation de base',
        );

        return [$person];
    }

    // ------------------------------------------------------------ scenario 9

    /** @return list<Person> */
    private function leaderChangesSection(): array
    {
        $person = $this->factory->make('T0014', 2001, null);
        $person->years[self::A1] = $this->cadreYear('Animateur', 'lou1', self::A1);
        $person->years[self::A2] = $this->cadreYear('Animateur', 'lou2', self::A2);
        $person->years[self::A3] = $this->cadreYear('Animateur', 'lou2', self::A3);

        return [$person];
    }

    // ----------------------------------------------------------- scenario 10

    /** @return list<Person> */
    private function leaderBecomesUnitChief(): array
    {
        // Section animateur, then a unit-level function with no Section at
        // all. Staff d'U membership only appears once Config Desk confirms
        // "Chef d'unité" as role=admin — never from the CSV.
        $person = $this->factory->make('T0015', 1996, null);
        $person->years[self::A1] = $this->cadreYear('Animateur', 'ecl1', self::A1);
        $person->years[self::A2] = $this->unitYear('Chef d\'unité');
        $person->years[self::A3] = $this->unitYear('Chef d\'unité');

        return [$person];
    }

    // ----------------------------------------------------------- scenario 11

    /** @return list<Person> */
    private function quartermaster(): array
    {
        $person = $this->factory->make('T0016', 1979, null);
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $person->years[$year] = $this->unitYear('Intendant d\'unité');
        }

        return [$person];
    }

    // ----------------------------------------------------------- scenario 12

    /** @return list<Person> */
    private function twoFunctions(): array
    {
        // Two functions, one main. Functions are NOT deduplicated the way
        // addresses are, so this member produces two rows per address.
        $person = $this->factory->make('T0017', 1999, null);
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $person->years[$year] = new PersonYear(
                functions: [
                    $this->sectionFunction('Animateur', 'pio1', $year, true),
                    $this->unitFunction('Trésorier d\'unité', false),
                ],
                feeCode: UnitBlueprint::FEE_CODES['cadre'],
                totem: 'Sittelle',
                quali: 'Posé',
                formationLevel: 'Formation avancée',
            );
        }

        return [$person];
    }

    // ----------------------------------------------------------- scenario 13

    /** @return list<Person> */
    private function candidateFunction(): array
    {
        // Desk marks an incomplete animateur "candidat". Nothing consumes the
        // word today; the future Encadrement module will, and the test can
        // only check the label survives the import intact.
        $person = $this->factory->make('T0018', 2005, null);
        $person->years[self::A2] = $this->cadreYear('Animateur candidat', 'ecl1', self::A2);
        $person->years[self::A3] = $this->cadreYear('Animateur', 'ecl1', self::A3);

        return [$person];
    }

    // ----------------------------------------------------------- scenario 14

    /** @return list<Person> */
    private function brandNewFunction(): array
    {
        // A function nobody has ever imported, appearing in the last year:
        // created as role='identified', confirmed=false, and counted in
        // ImportResult::$newFunctionsCount.
        $person = $this->factory->make('T0019', 1990, null);
        $person->years[self::A1] = $this->cadreYear('Animateur', 'rou1', self::A1);
        $person->years[self::A2] = $this->cadreYear('Animateur', 'rou1', self::A2);
        $person->years[self::A3] = $this->unitYear('Accompagnateur d\'unité');

        return [$person];
    }

    // ----------------------------------------------------------- scenario 17

    /** @return list<Person> */
    private function siblingsGrowingTo3(): array
    {
        // One parent email, one postal address, so the three of them share an
        // address blind index — that is what FeeEstimationService counts as a
        // household, through AddressNormalizer.
        $lastName = 'Delvaux';
        $address = $this->factory->makeAddress('Domicile');
        $email = $this->factory->makeHouseholdEmail($lastName);

        $eldest = $this->factory->make('T0020', 2012, 'Louveteaux', $lastName, $address, $email);
        $eldest->years[self::A1] = $this->animeYear('lou1', self::A1, totem: null);
        $eldest->years[self::A2] = $this->animeYear('ecl1', self::A2, totem: $this->rng->pick(UnitBlueprint::TOTEMS));
        $eldest->years[self::A3] = $this->animeYear('ecl1', self::A3, totem: $eldest->years[self::A2]->totem);

        $middle = $this->factory->make('T0021', 2015, 'Louveteaux', $lastName, $address, $email);
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $middle->years[$year] = $this->animeYear('lou1', $year, totem: null);
        }

        // The youngest turns six and joins in A2 — the household goes from 2
        // to 3 without either of the others changing.
        $youngest = $this->factory->make('T0022', 2019, 'Baladins', $lastName, $address, $email);
        $youngest->years[self::A2] = $this->animeYear('bal1', self::A2, totem: null);
        $youngest->years[self::A3] = $this->animeYear('bal1', self::A3, totem: null);

        return [$eldest, $middle, $youngest];
    }

    // ----------------------------------------------------------- scenario 18

    /** @return list<Person> */
    private function siblingsLosingOne(): array
    {
        $lastName = 'Poncelet';
        $address = $this->factory->makeAddress('Domicile');
        $email = $this->factory->makeHouseholdEmail($lastName);

        $first = $this->factory->make('T0023', 2014, 'Louveteaux', $lastName, $address, $email);
        $second = $this->factory->make('T0024', 2016, 'Louveteaux', $lastName, $address, $email);
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $first->years[$year] = $this->animeYear('lou2', $year, totem: null);
            $second->years[$year] = $this->animeYear('lou2', $year, totem: null);
        }

        // Present in A1 only: the household drops from 3 to 2.
        $leaver = $this->factory->make('T0025', 2010, 'Éclaireurs', $lastName, $address, $email);
        $leaver->years[self::A1] = $this->animeYear('ecl1', self::A1, totem: $this->rng->pick(UnitBlueprint::TOTEMS));

        return [$first, $second, $leaver];
    }

    // ----------------------------------------------------------- scenario 19

    /** @return list<Person> */
    private function twoAddresses(): array
    {
        $person = new Person(
            deskId: 'T0026',
            lastName: 'Hallet',
            firstName: 'Margaux',
            gender: 'F',
            birthDate: $this->factory->makeBirthDate(2012),
            phone: PersonFactory::landline(26),
            mobile: PersonFactory::mobile(26),
            email: $this->factory->makeEmail('Margaux', 'Hallet'),
            addresses: [
                $this->factory->makeAddress('Domicile'),
                $this->factory->makeAddress('Adresse secondaire'),
            ],
        );
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $person->years[$year] = $this->animeYear('ecl1', $year, totem: null);
        }

        return [$person];
    }

    // ----------------------------------------------------------- scenario 20

    /** @return list<Person> */
    private function sparseMember(): array
    {
        // No email (so no user account is created for him), no birth date (so
        // no effective age and no branch can be derived), no landline.
        $person = new Person(
            deskId: 'T0027',
            lastName: 'Wathelet',
            firstName: 'Corentin',
            gender: 'M',
            birthDate: null,
            phone: null,
            mobile: PersonFactory::mobile(27),
            email: null,
            addresses: [$this->factory->makeAddress('Domicile')],
        );
        foreach ([self::A1, self::A2, self::A3] as $year) {
            $person->years[$year] = $this->animeYear('pio1', $year, totem: null);
        }

        return [$person];
    }

    // ----------------------------------------------------------- scenario 21

    /** @return list<Person> */
    private function handicapAndInsurance(): array
    {
        // Handicap is health data — a GDPR special category, encrypted at rest
        // by DeskImportService rather than stored in clear like the insurance
        // label next to it.
        $person = $this->factory->make(
            'T0028',
            2011,
            'Éclaireurs',
            handicap: 'true',
            insurance: 'Assurance rapatriement',
        );
        // Iama in A1 and A2, Éclaireurs in A3 — he is 13, 14 then 15, so the
        // move is age-legal, and it has to happen: Iama Horizon is emptied in
        // A3 on purpose (scenario 15), and a member left behind in it would
        // quietly cancel that scenario.
        $person->years[self::A1] = $this->animeYear('iam1', self::A1, totem: null);
        $person->years[self::A2] = $this->animeYear('iam1', self::A2, totem: null);
        $person->years[self::A3] = $this->animeYear('ecl1', self::A3, totem: null);

        return [$person];
    }

    // ----------------------------------------------------------- scenario 22

    /** @return list<Person> */
    private function awkwardNames(): array
    {
        $specimens = [
            ['T0029', 'Étienne', 2013, 'lou1'],
            ['T0030', "N'Diaye", 2011, 'ecl1'],
            ['T0031', 'Van der Meulen', 2009, 'ecl1'],
            ['T0032', 'Dubois-Lefèvre', 2008, 'pio1'],
        ];

        $people = [];
        foreach ($specimens as [$tiers, $lastName, $birthYear, $handle]) {
            $person = $this->factory->make($tiers, $birthYear, 'Éclaireurs', $lastName);
            foreach ([self::A1, self::A2, self::A3] as $year) {
                $person->years[$year] = $this->animeYear($handle, $year, totem: null);
            }
            $people[] = $person;
        }

        return $people;
    }

    // ----------------------------------------------------------- scenario 23

    /** @return list<Person> */
    private function feeChange(): array
    {
        $person = $this->factory->make('T0033', 2014, 'Louveteaux');
        $person->years[self::A1] = $this->animeYear('lou2', self::A1, totem: null, feeCode: UnitBlueprint::FEE_CODES['anime']);
        $person->years[self::A2] = $this->animeYear('lou2', self::A2, totem: null, feeCode: UnitBlueprint::FEE_CODES['anime_reduit']);
        $person->years[self::A3] = $this->animeYear('lou2', self::A3, totem: null, feeCode: UnitBlueprint::FEE_CODES['anime_reduit']);

        return [$person];
    }

    // --------------------------------------------------------------- helpers

    private function animeYear(
        string $handle,
        string $year,
        ?string $totem,
        ?string $quali = null,
        ?string $feeCode = null,
    ): PersonYear {
        $branch = UnitBlueprint::SECTIONS[$handle]['branch'];

        return new PersonYear(
            functions: [$this->sectionFunction('Animé', $handle, $year, true)],
            feeCode: $feeCode ?? UnitBlueprint::FEE_CODES['anime'],
            totem: $totem,
            quali: $totem !== null ? ($quali ?? $this->rng->pick(UnitBlueprint::QUALIS)) : null,
            patrol: match ($branch) {
                'Baladins', 'Louveteaux' => $this->rng->pick(UnitBlueprint::PATROLS_YOUNG),
                'Éclaireurs' => $this->rng->pick(UnitBlueprint::PATROLS_TEEN),
                default => null,
            },
        );
    }

    private function cadreYear(string $code, string $handle, string $year): PersonYear
    {
        return new PersonYear(
            functions: [$this->sectionFunction($code, $handle, $year, true)],
            feeCode: UnitBlueprint::FEE_CODES['cadre'],
            totem: $this->rng->pick(UnitBlueprint::TOTEMS),
            quali: $this->rng->pick(UnitBlueprint::QUALIS),
            formationLevel: $this->rng->pick(UnitBlueprint::FORMATION_LEVELS),
        );
    }

    private function unitYear(string $code): PersonYear
    {
        return new PersonYear(
            functions: [$this->unitFunction($code, true)],
            feeCode: UnitBlueprint::FEE_CODES['cadre'],
            totem: $this->rng->pick(UnitBlueprint::TOTEMS),
            quali: $this->rng->pick(UnitBlueprint::QUALIS),
            formationLevel: 'Formation avancée',
        );
    }

    private function totemFrom(string $handle): ?string
    {
        $branch = UnitBlueprint::SECTIONS[$handle]['branch'];

        return in_array($branch, ['Éclaireurs', 'Pionniers', 'Route', 'Iama'], true)
            ? $this->rng->pick(UnitBlueprint::TOTEMS)
            : null;
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
}
