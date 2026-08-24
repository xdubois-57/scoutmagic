<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\SupervisionCalculator;
use Modules\Leadership\Service\TrainingService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

class TrainingServiceTest extends TestCase
{
    private const SCOUT_YEAR_ID = 7;
    private const SCOUT_YEAR_LABEL = '2025-2026';
    private const PREVIOUS_YEAR_ID = 6;

    /** Reference year of 2025-2026 is 2025, so a 17-year-old is a last-year pionnier. */
    private const LAST_YEAR_PIONNIER_BIRTH = '2008-04-01';
    private const FIRST_YEAR_PIONNIER_BIRTH = '2009-04-01';

    /**
     * @param list<int> $previousAnimators
     * @param list<array<string, mixed>> $branchMembers
     * @param array<int, int> $headcounts
     * @param array<string, int> $formationLevels
     */
    private function service(
        array $previousAnimators = [],
        array $branchMembers = [],
        array $headcounts = [],
        array $formationLevels = [],
        ?SectionService $sectionService = null
    ): TrainingService {
        $repository = $this->createStub(LeadershipRepository::class);
        $repository->method('findMemberIdsWithAnimationFunction')->willReturn($previousAnimators);
        $repository->method('findMembersInBranchSections')->willReturn($branchMembers);
        $repository->method('countAnimesBySection')->willReturn($headcounts);
        $repository->method('countFormationLevels')->willReturn($formationLevels);

        if ($sectionService === null) {
            $sectionService = $this->createStub(SectionService::class);
            $sectionService->method('getAllWithBranches')->willReturn([]);
        }

        return new TrainingService(
            $repository,
            $sectionService,
            new MemberYearService(),
            new SupervisionCalculator()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function branchMember(array $overrides = []): array
    {
        return array_merge([
            'member_id' => 1,
            'member_year_id' => 10,
            'first_name' => 'Alix',
            'last_name' => 'Martin',
            'totem' => null,
            'birth_date' => self::LAST_YEAR_PIONNIER_BIRTH,
            'scout_year_offset' => 0,
            'section_id' => 4,
            'section_name' => 'Pionniers',
        ], $overrides);
    }

    // --- "à convaincre de commencer" -----------------------------------

    public function testListsPionniersInTheirLastBranchYear(): void
    {
        $service = $this->service(branchMembers: [
            $this->branchMember(['member_id' => 1, 'first_name' => 'Dernière']),
            $this->branchMember([
                'member_id' => 2,
                'first_name' => 'Première',
                'birth_date' => self::FIRST_YEAR_PIONNIER_BIRTH,
            ]),
        ]);

        $lines = $service->toConvince([], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, null);

        $this->assertCount(1, $lines);
        $this->assertSame('Dernière Martin', $lines[0]->fullName);
        $this->assertStringContainsString('Dernière année chez les pionniers', (string) $lines[0]->note);
    }

    /**
     * The branch-year comes from MemberYearService::getEffectiveAge(), so a
     * chief's scout-year offset moves somebody in and out of the list
     * exactly as it does everywhere else on the site.
     */
    public function testTheScoutYearOffsetIsRespected(): void
    {
        $service = $this->service(branchMembers: [
            $this->branchMember([
                'birth_date' => self::FIRST_YEAR_PIONNIER_BIRTH,
                'scout_year_offset' => 1,
            ]),
        ]);

        $this->assertCount(1, $service->toConvince([], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, null));
    }

    /**
     * The section and the effective age have to agree on the branch.
     *
     * A member sitting in a Pionniers section whose age still places them
     * among the Éclaireurs is in the last year of *that* branch — 4e année
     * éclaireurs — not of this one. Before this was checked, they were
     * listed with the note "Dernière année chez les pionniers" printed
     * directly beside the words "4e année éclaireurs": a contradiction on
     * one row, and the wrong year to raise the training in.
     */
    public function testAnEclaireurInAPionnierSectionIsNotALastYearPionnier(): void
    {
        // Reference year 2025, born 2010 → age 15 → 4th and last year of
        // Éclaireurs, while the section period says Pionniers.
        $service = $this->service(branchMembers: [
            $this->branchMember(['birth_date' => '2010-04-01']),
        ]);

        $this->assertSame([], $service->toConvince([], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, null));
    }

    public function testAMemberWithNoBirthDateIsNeverGuessedIntoTheList(): void
    {
        $service = $this->service(branchMembers: [$this->branchMember(['birth_date' => null])]);

        $this->assertSame([], $service->toConvince([], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, null));
    }

    /**
     * The trap this rule exists for: member_functions.start_date restarts
     * whenever somebody changes section, so a veteran who moved from
     * Louveteaux to Éclaireurs carries a brand-new start date. The
     * comparison is against the previous year's animation functions
     * instead, keyed on the persistent member id.
     */
    public function testAVeteranWhoChangedSectionIsNotAFirstYearAnimator(): void
    {
        $veteran = LeadershipTestHelper::staffRow([
            'memberId' => 42,
            'firstName' => 'Vétéran',
            // A start date from this September — exactly what would have
            // fooled a start_date-based rule.
            'functionStartDate' => '2025-09-01',
        ]);

        $service = $this->service(previousAnimators: [42]);

        $this->assertSame(
            [],
            $service->toConvince([$veteran], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, self::PREVIOUS_YEAR_ID)
        );
    }

    public function testListsAnAnimatorWithNoAnimationFunctionLastYear(): void
    {
        $newcomer = LeadershipTestHelper::staffRow(['memberId' => 99, 'firstName' => 'Nouvelle']);

        $service = $this->service(previousAnimators: [42]);
        $lines = $service->toConvince(
            [$newcomer],
            self::SCOUT_YEAR_ID,
            self::SCOUT_YEAR_LABEL,
            self::PREVIOUS_YEAR_ID
        );

        $this->assertCount(1, $lines);
        $this->assertStringContainsString("Première année d'animation", (string) $lines[0]->note);
    }

    public function testStewardsAreNotFirstYearAnimators(): void
    {
        $steward = LeadershipTestHelper::staffRow(['memberId' => 99, 'functionRole' => 'intendant']);

        $service = $this->service(previousAnimators: []);

        $this->assertSame(
            [],
            $service->toConvince([$steward], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, self::PREVIOUS_YEAR_ID)
        );
    }

    /**
     * With one imported year there is nothing to compare against, so the
     * first-year half is not computed at all — the page says so rather
     * than showing an empty list that would read as "nobody".
     */
    public function testWithNoPreviousYearTheFirstYearHalfIsSkipped(): void
    {
        $newcomer = LeadershipTestHelper::staffRow(['memberId' => 99]);

        $service = $this->service(previousAnimators: [42]);

        $this->assertSame([], $service->toConvince([$newcomer], self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, null));
    }

    public function testAnAnimatorWithTwoFunctionsIsListedOnce(): void
    {
        $rows = [
            LeadershipTestHelper::staffRow(['memberId' => 99, 'memberFunctionId' => 1]),
            LeadershipTestHelper::staffRow(['memberId' => 99, 'memberFunctionId' => 2]),
        ];

        $lines = $this->service()->toConvince($rows, self::SCOUT_YEAR_ID, self::SCOUT_YEAR_LABEL, self::PREVIOUS_YEAR_ID);

        $this->assertCount(1, $lines);
    }

    // --- "parcours à terminer" ------------------------------------------

    public function testToFinishKeepsOnlyTheStepsBetweenT1AndT3(): void
    {
        $rows = [
            LeadershipTestHelper::staffRow(['memberId' => 1, 'lastName' => 'Sansrien', 'formationLevel' => null]),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'lastName' => 'Premiere', 'formationLevel' => 'T1']),
            LeadershipTestHelper::staffRow(['memberId' => 3, 'lastName' => 'Troisieme', 'formationLevel' => 'T3']),
            LeadershipTestHelper::staffRow(['memberId' => 4, 'lastName' => 'Brevetee', 'formationLevel' => 'Brevet']),
            LeadershipTestHelper::staffRow(['memberId' => 5, 'lastName' => 'Inconnue', 'formationLevel' => 'Zorglub']),
        ];

        $lines = $this->service()->toFinish($rows, new FormationLevelResolver());

        $this->assertSame(
            ['Camille Troisieme', 'Camille Premiere'],
            array_map(static fn ($l) => $l->fullName, $lines),
            'Sorted by step reached, descending.'
        );
    }

    public function testToFinishNamesTheNextStep(): void
    {
        $rows = [LeadershipTestHelper::staffRow(['formationLevel' => 'T2'])];

        $lines = $this->service()->toFinish($rows, new FormationLevelResolver());

        $this->assertStringContainsString('étape suivante : T3', (string) $lines[0]->note);
    }

    public function testToFinishHonoursAnAdminMapping(): void
    {
        $rows = [LeadershipTestHelper::staffRow(['formationLevel' => 'Module transversal 4'])];
        $resolver = new FormationLevelResolver([
            FormationLevelResolver::keyFor('Module transversal 4') => FormationStep::T2->value,
        ]);

        $this->assertCount(1, $this->service()->toFinish($rows, $resolver));
    }

    public function testToFinishIgnoresStewards(): void
    {
        $rows = [LeadershipTestHelper::staffRow(['functionRole' => 'intendant', 'formationLevel' => 'T2'])];

        $this->assertSame([], $this->service()->toFinish($rows, new FormationLevelResolver()));
    }

    // --- section situations ---------------------------------------------

    public function testSectionSituationsCombineHeadcountsAndResolvedLevels(): void
    {
        $sectionService = $this->createStub(SectionService::class);
        $sectionService->method('getAllWithBranches')->willReturn([
            [
                'id' => 5, 'desk_code' => 'LOUV', 'name' => 'Louveteaux', 'email' => null,
                'age_branch_id' => 2, 'branch_name' => 'Louveteaux', 'branch_sort_order' => 20,
                'is_visible' => true, 'is_active' => true, 'color' => null,
            ],
        ]);

        $service = $this->service(headcounts: [5 => 24], sectionService: $sectionService);

        $rows = $service->sectionSituations([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'sectionId' => 5, 'formationLevel' => 'Brevet']),
            LeadershipTestHelper::staffRow(['memberId' => 2, 'sectionId' => 5, 'formationLevel' => 'T1']),
        ], self::SCOUT_YEAR_ID, new FormationLevelResolver());

        $this->assertCount(1, $rows);
        $this->assertSame('Louveteaux', $rows[0]['section_name']);
        $this->assertSame(24, $rows[0]['situation']->headcount);
        $this->assertSame(2, $rows[0]['situation']->animatorCount);
        $this->assertSame(1, $rows[0]['situation']->brevetCount);
        $this->assertTrue($rows[0]['situation']->satisfied);
    }

    public function testTwoFunctionsInOneSectionCountAsOneAnimator(): void
    {
        $sectionService = $this->createStub(SectionService::class);
        $sectionService->method('getAllWithBranches')->willReturn([
            [
                'id' => 5, 'desk_code' => 'LOUV', 'name' => 'Louveteaux', 'email' => null,
                'age_branch_id' => 2, 'branch_name' => 'Louveteaux', 'branch_sort_order' => 20,
                'is_visible' => true, 'is_active' => true, 'color' => null,
            ],
        ]);

        $service = $this->service(headcounts: [5 => 10], sectionService: $sectionService);

        $rows = $service->sectionSituations([
            LeadershipTestHelper::staffRow(['memberId' => 1, 'memberFunctionId' => 1, 'sectionId' => 5]),
            LeadershipTestHelper::staffRow(['memberId' => 1, 'memberFunctionId' => 2, 'sectionId' => 5]),
        ], self::SCOUT_YEAR_ID, new FormationLevelResolver());

        $this->assertSame(1, $rows[0]['situation']->animatorCount);
    }

    // --- the mapping block ----------------------------------------------

    public function testUnresolvedLevelsListsOnlyWhatTheSiteCannotRead(): void
    {
        $service = $this->service(formationLevels: [
            'T2' => 3,
            'Zorglub' => 1,
            'Module transversal 4' => 2,
        ]);

        $rows = $service->unresolvedLevels(self::SCOUT_YEAR_ID, new FormationLevelResolver());

        $this->assertSame(
            [
                ['raw_value' => 'Module transversal 4', 'holders' => 2],
                ['raw_value' => 'Zorglub', 'holders' => 1],
            ],
            $rows
        );
    }

    public function testAMappedValueLeavesTheUnresolvedList(): void
    {
        $service = $this->service(formationLevels: ['Zorglub' => 1]);
        $resolver = new FormationLevelResolver([
            FormationLevelResolver::keyFor('Zorglub') => FormationStep::T1->value,
        ]);

        $this->assertSame([], $service->unresolvedLevels(self::SCOUT_YEAR_ID, $resolver));
    }

    public function testDecidedLevelsReportsHowManyPeopleStillUseEachMapping(): void
    {
        $service = $this->service(formationLevels: ['ZORGLUB' => 4]);

        $rows = $service->decidedLevels(
            [
                ['raw_value' => 'Zorglub', 'step' => 't1'],
                ['raw_value' => 'Wording Desk a cessé d\'exporter', 'step' => 'brevet'],
            ],
            self::SCOUT_YEAR_ID
        );

        // Matched through the folded key, so a case difference between the
        // stored decision and this year's data still counts.
        $this->assertSame(4, $rows[0]['holders']);
        $this->assertSame('T1', $rows[0]['step_label']);
        $this->assertSame(0, $rows[1]['holders']);
    }

    public function testDecidedLevelsSkipsAnUnparseableStoredStep(): void
    {
        $service = $this->service();

        $rows = $service->decidedLevels([['raw_value' => 'X', 'step' => 'pas-une-etape']], self::SCOUT_YEAR_ID);

        $this->assertSame([], $rows);
    }
}
