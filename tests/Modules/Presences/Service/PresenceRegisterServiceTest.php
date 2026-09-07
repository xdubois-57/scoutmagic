<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Config\ScoutYearService;
use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceRegisterService;
use Modules\Presences\Service\RegisterAnime;
use Modules\Presences\Service\RegisterEvent;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * The register's arithmetic, and the two honesty rules it rests on: an
 * evening nobody pointed is counted nowhere, and « excusé » is an absence.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceRegisterServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceRepository $repository;
    private PresenceRegisterService $service;
    private int $scoutYearId;
    private int $sectionId;
    private int $otherSectionId;
    private string $year;
    /** @var array<string, int> */
    private array $animes = [];
    /** @var array<int, int> section id => its calendar, created on first use */
    private array $calendars = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->repository = new PresenceRepository($this->pdo, $this->encryption);

        // The scout year label is the current one (Tests\DatabaseTestHelper
        // says why a hard-coded one turns red every 1 September), but its
        // window is deliberately widened to a year either side: several
        // tests below place an evening « seven days ago » or « in a week »
        // to exercise the « Prochain évènement » shortcut, and a real
        // 1 September–31 August window would push those outside it in the
        // fortnight around the boundary.
        [$label, $start] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear(
            $this->pdo,
            $label,
            (new \DateTimeImmutable('-1 year'))->format('Y-m-d'),
            (new \DateTimeImmutable('+1 year'))->format('Y-m-d')
        );
        $this->year = substr($start, 0, 4);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->sectionId = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->otherSectionId = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');

        $this->service = new PresenceRegisterService(
            PresencesTestHelper::calendarService($this->pdo, $this->encryption),
            new ScoutYearService($this->pdo),
            PresencesTestHelper::sectionService($this->pdo, $this->encryption),
            $this->repository
        );
    }

    public function testTheRegisterHoldsTheSectionsOwnEveningsOnly(): void
    {
        $mine = $this->event($this->sectionId, '09-13');
        $this->event($this->otherSectionId, '09-13');

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame([$mine], array_map(
            static fn (RegisterEvent $event): int => $event->eventId,
            $register->events
        ));
    }

    public function testAnEveningNobodyPointedIsListedAndCountedNowhere(): void
    {
        $pointed = $this->event($this->sectionId, '09-13');
        $untouched = $this->event($this->sectionId, '09-20');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');
        $this->repository->saveStatus($pointed, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($pointed, $this->animes['Ayoute'], PresenceStatus::PRESENT, null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertCount(2, $register->events);
        $this->assertSame(1, $register->pointedEventCount);
        // 100 %, not 50 % — the untouched evening is not an evening at 0 %.
        $this->assertSame(100, $register->averageRate);
        $this->assertSame(
            [$pointed],
            array_map(static fn (RegisterEvent $event): int => $event->eventId, $register->chartEvents())
        );
        $this->assertFalse(
            array_values(array_filter(
                $register->events,
                static fn (RegisterEvent $event): bool => $event->eventId === $untouched
            ))[0]->pointed
        );
    }

    public function testACommentAloneIsNotSomebodyHavingPointedTheEvening(): void
    {
        $event = $this->event($this->sectionId, '09-13');
        $this->anime('Basile', 'Hargot');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::UNSET, null);
        $this->repository->saveComment($event, $this->animes['Hargot'], 'Sa maman a prévenu.', null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame(0, $register->pointedEventCount);
        $this->assertFalse($register->events[0]->pointed);
    }

    public function testAnExcusedAnimeIsAbsentFromTheRate(): void
    {
        $event = $this->event($this->sectionId, '09-13');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($event, $this->animes['Ayoute'], PresenceStatus::EXCUSED, null);
        $this->repository->saveComment($event, $this->animes['Ayoute'], 'Malade.', null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame(50, $register->events[0]->rate);
        $this->assertSame(1, $register->events[0]->present);
        $this->assertSame(1, $register->events[0]->excused);
    }

    public function testTheRateDividesByTheWholeRosterSoAMissingAnimeShows(): void
    {
        $event = $this->event($this->sectionId, '09-13');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');
        $this->anime('Hugo', 'Lejeune');
        $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($event, $this->animes['Ayoute'], PresenceStatus::ABSENT, null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        // One of three present, and the animé nobody answered for is
        // reported as such rather than folded into the absents.
        $this->assertSame(33, $register->events[0]->rate);
        $this->assertSame(1, $register->events[0]->notRecorded);
    }

    public function testTheBestAndWeakestDatesAreThePointedOnes(): void
    {
        $strong = $this->event($this->sectionId, '09-13');
        $weak = $this->event($this->sectionId, '09-20');
        $this->event($this->sectionId, '09-27');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');
        $this->repository->saveStatus($strong, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($strong, $this->animes['Ayoute'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($weak, $this->animes['Hargot'], PresenceStatus::ABSENT, null);
        $this->repository->saveStatus($weak, $this->animes['Ayoute'], PresenceStatus::ABSENT, null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame($strong, $register->best?->eventId);
        $this->assertSame($weak, $register->worst?->eventId);
    }

    public function testNothingPointedMeansNoBestDateRatherThanAnArbitraryOne(): void
    {
        $this->event($this->sectionId, '09-13');
        $this->anime('Basile', 'Hargot');

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertNull($register->best);
        $this->assertNull($register->worst);
        $this->assertSame(0, $register->averageRate);
    }

    public function testTheAnimesAreRankedLeastPresentFirst(): void
    {
        $first = $this->event($this->sectionId, '09-13');
        $second = $this->event($this->sectionId, '09-20');
        $this->anime('Basile', 'Hargot');
        $this->anime('Dounia', 'Ayoute');
        foreach ([$first, $second] as $event) {
            $this->repository->saveStatus($event, $this->animes['Ayoute'], PresenceStatus::PRESENT, null);
            $this->repository->saveStatus($event, $this->animes['Hargot'], PresenceStatus::ABSENT, null);
        }

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame(
            ['Hargot', 'Ayoute'],
            array_map(static fn (RegisterAnime $anime): string => $anime->lastName, $register->animes)
        );
        $this->assertSame(0, $register->animes[0]->rate);
        $this->assertSame(100, $register->animes[1]->rate);
        $this->assertSame(2, $register->animes[1]->consideredEvents);
    }

    public function testLeastPresentIsCappedAtWhatWasAskedFor(): void
    {
        $event = $this->event($this->sectionId, '09-13');
        foreach (['Ayoute', 'Biernaux', 'Hargot', 'Lejeune', 'Meese', 'Vanloqueren'] as $name) {
            $this->anime('Prénom', $name);
            $this->repository->saveStatus($event, $this->animes[$name], PresenceStatus::ABSENT, null);
        }

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertCount(5, $register->leastPresent(5));
        $this->assertCount(6, $register->leastPresent(10));
    }

    public function testTheShortcutOpensTheFirstEveningStillToCome(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->eventOn($this->sectionId, (new \DateTimeImmutable('-7 days'))->format('Y-m-d'));
        $soon = $this->eventOn($this->sectionId, $today);
        $this->eventOn($this->sectionId, (new \DateTimeImmutable('+7 days'))->format('Y-m-d'));

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame($soon, $register->nextEvent?->eventId);
    }

    public function testWithNothingLeftToComeTheShortcutOffersTheUnfinishedEvening(): void
    {
        $this->anime('Basile', 'Hargot');
        $done = $this->eventOn($this->sectionId, (new \DateTimeImmutable('-14 days'))->format('Y-m-d'));
        $unfinished = $this->eventOn($this->sectionId, (new \DateTimeImmutable('-7 days'))->format('Y-m-d'));
        $this->repository->saveStatus($done, $this->animes['Hargot'], PresenceStatus::PRESENT, null);

        $register = $this->service->buildRegister($this->sectionId, $this->scoutYearId);

        $this->assertSame($unfinished, $register->nextEvent?->eventId);
        $this->assertSame(1, $register->nextEventPending);
    }

    public function testAFinishedYearOffersNoShortcutAtAll(): void
    {
        $this->anime('Basile', 'Hargot');
        $done = $this->eventOn($this->sectionId, (new \DateTimeImmutable('-7 days'))->format('Y-m-d'));
        $this->repository->saveStatus($done, $this->animes['Hargot'], PresenceStatus::PRESENT, null);

        $this->assertNull($this->service->buildRegister($this->sectionId, $this->scoutYearId)->nextEvent);
    }

    public function testAnUnknownScoutYearHoldsNoEveningsRatherThanEveryOne(): void
    {
        $this->event($this->sectionId, '09-13');

        $this->assertSame([], $this->service->sectionEvents($this->sectionId, 999_999));
    }

    public function testTheEmptyQueryAlreadyAnswers(): void
    {
        foreach (['09-13', '09-20', '09-27', '10-04'] as $day) {
            $this->event($this->sectionId, $day);
        }
        $this->anime('Basile', 'Hargot');

        $results = $this->service->search($this->sectionId, $this->scoutYearId, '');

        $this->assertCount(3, $results['events']);
        // Most recent first: the evening somebody looks for is almost
        // always the last one, not September's.
        $this->assertSame($this->year . '-10-04', $results['events'][0]->startDate);
        $this->assertCount(1, $results['animes']);
    }

    public function testAnAnimeIsFoundByNameWhateverTheAccentsAndTheCase(): void
    {
        $this->event($this->sectionId, '09-13');
        $this->anime('Ismaïl', 'Maftah');
        $this->anime('Basile', 'Hargot');

        $results = $this->service->search($this->sectionId, $this->scoutYearId, 'ISMAIL');

        $this->assertCount(1, $results['animes']);
        $this->assertSame('Maftah', $results['animes'][0]->lastName);
    }

    public function testAnEveningIsFoundByItsTitleOrByTheDateAsItIsShown(): void
    {
        $this->event($this->sectionId, '09-13', 'Fête de Noël');

        $this->assertCount(1, $this->service->search($this->sectionId, $this->scoutYearId, 'noel')['events']);
        $this->assertCount(1, $this->service->search($this->sectionId, $this->scoutYearId, '13/09')['events']);
        $this->assertCount(1, $this->service->search($this->sectionId, $this->scoutYearId, '09-13')['events']);
        $this->assertCount(0, $this->service->search($this->sectionId, $this->scoutYearId, 'camp')['events']);
    }

    public function testSearchNeverReachesOutsideTheSectionItWasAskedAbout(): void
    {
        $this->event($this->otherSectionId, '09-13', 'Réunion des Louveteaux 2');
        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Jeanne', 'Vanloqueren', 'animated', $this->otherSectionId
        );

        $results = $this->service->search($this->sectionId, $this->scoutYearId, '');

        $this->assertSame([], $results['events']);
        $this->assertSame([], $results['animes']);
    }

    private function event(int $sectionId, string $monthDay, string $title = 'Réunion'): int
    {
        return $this->eventOn($sectionId, $this->year . '-' . $monthDay, $title);
    }

    private function eventOn(int $sectionId, string $date, string $title = 'Réunion'): int
    {
        $this->calendars[$sectionId] ??= PresencesTestHelper::createSectionCalendar($this->pdo, $sectionId);

        return PresencesTestHelper::createEvent($this->pdo, $this->calendars[$sectionId], $title, $date);
    }

    private function anime(string $firstName, string $lastName): void
    {
        $this->animes[$lastName] = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId, $firstName, $lastName, 'animated', $this->sectionId
        )['memberId'];
    }
}
