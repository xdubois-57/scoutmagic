<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\ScoutYear\Step\Check\DeparturesStepCheck;
use Core\ScoutYear\Step\StepCheck;
use Modules\Registration\Api\ReenrollmentCampaignState;
use Modules\Registration\Api\ScoutYearMember;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 1 and its hard block (chantier « Année scoute » §2.3): the mark is
 * refused while the target year's campaign is scheduled or running, with
 * one sentence per case; a campaign that has started and is no longer
 * open — by its date or by hand — lets it through; a reopening after the
 * mark is a point to check; no campaign at all is a point, never a refusal.
 */
class DeparturesStepCheckTest extends TestCase
{
    private const REFUSAL_END = " L'étape pourra être marquée une fois la campagne terminée.";

    /**
     * @param list<ScoutYearMember> $leavers
     */
    private function check(
        ?ReenrollmentCampaignState $campaign,
        bool $marked = false,
        array $leavers = []
    ): StepCheck {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('reenrollmentCampaign')->willReturn($campaign);
        $review->method('announcedLeavers')->willReturn($leavers);

        $marks = $this->createStub(ScoutYearTransitionStepRepository::class);
        $marks->method('findByYear')->willReturn(
            $marked ? ['departures' => ['done_at' => '2026-07-01 10:00:00', 'done_by' => null]] : []
        );

        $sections = $this->createStub(SectionService::class);
        $sections->method('findByIds')->willReturn([3 => CheckFixtures::sectionRow(3, 'Louveteaux')]);

        return (new DeparturesStepCheck($review, $marks, $sections))->check(CheckFixtures::context(imported: false));
    }

    private static function campaign(string $state, ?string $opens = '2026-05-01', ?string $closes = '2026-06-15'): ReenrollmentCampaignState
    {
        return new ReenrollmentCampaignState($state, '2026-2027', $opens, $closes);
    }

    public function testAScheduledCampaignRefusesTheMarkWithItsOpeningDate(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::NOT_STARTED, '2026-08-01', '2026-08-31'));

        $this->assertSame(
            "La campagne de réinscription 2026-2027 n'a pas encore commencé (ouverture le 01/08/2026)." . self::REFUSAL_END,
            $check->markRefusal
        );
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame('campaign', $check->primaryAction?->id);
    }

    public function testARunningCampaignRefusesTheMarkWithItsClosingDate(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::OPEN));

        $this->assertSame(
            'La campagne de réinscription 2026-2027 est en cours (fermeture le 15/06/2026).' . self::REFUSAL_END,
            $check->markRefusal
        );
        $this->assertSame('Gérer la campagne', $check->primaryAction?->label);
    }

    /** A campaign opened by its switch without dates: the date is left out, never invented. */
    public function testARunningCampaignWithoutDatesStillRefuses(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::OPEN, null, null));

        $this->assertSame('La campagne de réinscription 2026-2027 est en cours.' . self::REFUSAL_END, $check->markRefusal);
    }

    public function testAFinishedCampaignLetsTheStepBeMarked(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::FINISHED));

        $this->assertNull($check->markRefusal);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame('departures', $check->primaryAction?->id);
        $this->assertSame(['state' => 'finished', 'opens_on' => '01/05/2026', 'closes_on' => '15/06/2026'], $check->details['campaign']);
    }

    /**
     * Closed by hand before its closing date: the module says finished
     * (started, no longer open), and that is what counts — not the date.
     */
    public function testACampaignClosedByHandBeforeItsDateIsFinished(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::FINISHED, '2026-05-01', '2026-09-30'));

        $this->assertNull($check->markRefusal);
    }

    public function testAReopenedCampaignAfterTheMarkIsAPointToCheck(): void
    {
        $check = $this->check(self::campaign(ReenrollmentCampaignState::OPEN), marked: true);

        $this->assertSame(['Campagne rouverte après le marquage'], $check->pointsToCheck);
        // The refusal still stands for a new mark request; the engine
        // ignores it for a step that is already done.
        $this->assertNotNull($check->markRefusal);
    }

    public function testNoCampaignConfiguredIsAPointToCheckNeverARefusal(): void
    {
        $check = $this->check(null);

        $this->assertNull($check->markRefusal);
        $this->assertSame(["Aucune campagne de réinscription n'est configurée pour 2026-2027"], $check->pointsToCheck);
        $this->assertContains(
            '/config/reinscription/reglages',
            array_map(static fn($action): ?string => $action->url, $check->actions)
        );
        $this->assertNull($check->details['campaign']);
    }

    public function testTheAnnouncedLeaversAreNamedWithTheirSectionAndSheet(): void
    {
        $check = $this->check(
            self::campaign(ReenrollmentCampaignState::FINISHED),
            leavers: [new ScoutYearMember(7, 70, 'Tom Renard', 3), new ScoutYearMember(8, 80, 'Inès Dupont', null)]
        );

        $this->assertSame([
            ['name' => 'Tom Renard', 'section' => 'Louveteaux', 'url' => '/admin/members/70'],
            ['name' => 'Inès Dupont', 'section' => null, 'url' => '/admin/members/80'],
        ], $check->details['leavers']);
    }

    public function testWithoutTheModuleTheStepOnlyLinksToItsPage(): void
    {
        $check = (new DeparturesStepCheck(
            null,
            $this->createStub(ScoutYearTransitionStepRepository::class),
            $this->createStub(SectionService::class)
        ))->check(CheckFixtures::context());

        $this->assertNull($check->markRefusal);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details);
    }
}
