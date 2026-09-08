<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Config\ScoutYearService;
use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\AnimeHistoryEntry;
use Modules\Presences\Service\AnimeMonth;
use Modules\Presences\Service\PresenceAnimeService;
use Modules\Presences\Service\PresenceRegisterService;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * The page one reads before phoning a family — and the boundary in front
 * of it, which is the same one as everywhere else in this module: an
 * animé of a section this account does not staff is not found at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceAnimeServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceRepository $repository;
    private PresenceAnimeService $service;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private string $year;
    /** @var array<string, int> */
    private array $animes = [];
    /** @var array<int, int> */
    private array $calendars = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->repository = new PresenceRepository($this->pdo, $this->encryption);

        [$label, $start] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear(
            $this->pdo,
            $label,
            (new \DateTimeImmutable('-1 year'))->format('Y-m-d'),
            (new \DateTimeImmutable('+1 year'))->format('Y-m-d')
        );
        $this->year = substr($start, 0, 4);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->sectionA = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->sectionB = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');

        $calendar = PresencesTestHelper::calendarService($this->pdo, $this->encryption);
        $sectionService = PresencesTestHelper::sectionService($this->pdo, $this->encryption);
        $authorization = PresencesTestHelper::authorization($this->pdo, $this->encryption);
        $registerService = new PresenceRegisterService(
            $calendar,
            new ScoutYearService($this->pdo),
            $sectionService,
            $this->repository
        );

        $this->service = new PresenceAnimeService(
            $authorization,
            PresencesTestHelper::sheetService($this->pdo, $this->encryption, $calendar),
            $registerService,
            $this->repository
        );

        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->sectionA, 'akela@test.be'
        );
    }

    public function testTheRateArrivesBesideTheSectionsOwnAverage(): void
    {
        $first = $this->event($this->sectionA, '09-13');
        $second = $this->event($this->sectionA, '09-20');
        $this->anime('Basile', 'Hargot', $this->sectionA);
        $this->anime('Dounia', 'Ayoute', $this->sectionA);
        $this->repository->saveStatus($first, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($first, $this->animes['Ayoute'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($second, $this->animes['Hargot'], PresenceStatus::ABSENT, null);
        $this->repository->saveStatus($second, $this->animes['Ayoute'], PresenceStatus::PRESENT, null);

        $profile = $this->service->buildProfile(
            $this->animes['Hargot'], 'akela@test.be', 'chief', $this->scoutYearId
        );

        $this->assertNotNull($profile);
        $this->assertSame(50, $profile->rate);
        $this->assertSame(75, $profile->sectionAverageRate);
        $this->assertSame(1, $profile->present);
        $this->assertSame(2, $profile->pointedEvents);
    }

    public function testAnAnimeOfAnotherSectionIsNotFound(): void
    {
        $this->event($this->sectionB, '09-13');
        $this->anime('Jeanne', 'Vanloqueren', $this->sectionB);

        $this->assertNull($this->service->buildProfile(
            $this->animes['Vanloqueren'], 'akela@test.be', 'chief', $this->scoutYearId
        ));
    }

    public function testAUnitChiefReachesEveryAnime(): void
    {
        $this->event($this->sectionB, '09-13');
        $this->anime('Jeanne', 'Vanloqueren', $this->sectionB);

        $this->assertNotNull($this->service->buildProfile(
            $this->animes['Vanloqueren'], 'cu@test.be', 'admin', $this->scoutYearId
        ));
    }

    public function testAnAnimateurIsNotAnAnime(): void
    {
        $this->event($this->sectionA, '09-13');
        $animateur = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Baloo', 'Martin', 'chief', $this->sectionA
        );

        $this->assertNull($this->service->buildProfile(
            $animateur['memberId'], 'akela@test.be', 'chief', $this->scoutYearId
        ));
    }

    public function testNobodyIsNotFound(): void
    {
        $this->assertNull($this->service->buildProfile(
            999_999, 'akela@test.be', 'chief', $this->scoutYearId
        ));
    }

    public function testTheHistoryIsMostRecentFirstAndCarriesTheComments(): void
    {
        $first = $this->event($this->sectionA, '09-13');
        $second = $this->event($this->sectionA, '09-20', 'Fête de Noël');
        $this->anime('Basile', 'Hargot', $this->sectionA);
        $this->repository->saveStatus($first, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($second, $this->animes['Hargot'], PresenceStatus::EXCUSED, null);
        $this->repository->saveComment($second, $this->animes['Hargot'], 'Rendez-vous chez le médecin.', null);

        $profile = $this->service->buildProfile(
            $this->animes['Hargot'], 'akela@test.be', 'chief', $this->scoutYearId
        );

        $this->assertNotNull($profile);
        $this->assertSame(
            [$second, $first],
            array_map(static fn (AnimeHistoryEntry $entry): int => $entry->eventId, $profile->history)
        );
        $this->assertSame('Rendez-vous chez le médecin.', $profile->history[0]->comment);
        $this->assertSame(PresenceStatus::EXCUSED, $profile->history[0]->status);
    }

    /**
     * A gap in the history is a fact about the section. Hiding an evening
     * nobody pointed would make a run of missing Saturdays read as a run
     * of absences.
     */
    public function testAnUnpointedEveningStillAppearsInTheHistory(): void
    {
        $this->event($this->sectionA, '09-13');
        $this->anime('Basile', 'Hargot', $this->sectionA);

        $profile = $this->service->buildProfile(
            $this->animes['Hargot'], 'akela@test.be', 'chief', $this->scoutYearId
        );

        $this->assertNotNull($profile);
        $this->assertCount(1, $profile->history);
        $this->assertSame(PresenceStatus::UNSET, $profile->history[0]->status);
    }

    public function testTheHistoryIsCappedAndSaysSo(): void
    {
        $this->anime('Basile', 'Hargot', $this->sectionA);
        for ($day = 1; $day <= PresenceAnimeService::HISTORY_LIMIT + 2; $day++) {
            $this->event($this->sectionA, sprintf('09-%02d', $day));
        }

        $profile = $this->service->buildProfile(
            $this->animes['Hargot'], 'akela@test.be', 'chief', $this->scoutYearId
        );

        $this->assertNotNull($profile);
        $this->assertCount(PresenceAnimeService::HISTORY_LIMIT, $profile->history);
        $this->assertTrue($profile->historyTruncated);
    }

    public function testTheMonthsHoldOnlyMonthsThatWerePointed(): void
    {
        $september = $this->event($this->sectionA, '09-13');
        $this->event($this->sectionA, '10-04');
        $november = $this->event($this->sectionA, '11-08');
        $this->anime('Basile', 'Hargot', $this->sectionA);
        $this->repository->saveStatus($september, $this->animes['Hargot'], PresenceStatus::PRESENT, null);
        $this->repository->saveStatus($november, $this->animes['Hargot'], PresenceStatus::ABSENT, null);

        $profile = $this->service->buildProfile(
            $this->animes['Hargot'], 'akela@test.be', 'chief', $this->scoutYearId
        );

        $this->assertNotNull($profile);
        // October held an evening, but nobody pointed it — a month drawn
        // at 0 % would invent an absence out of a forgotten Saturday.
        $this->assertSame(
            [$this->year . '-09', $this->year . '-11'],
            array_map(static fn (AnimeMonth $month): string => $month->month, $profile->months)
        );
        $this->assertSame([100, 0], array_map(
            static fn (AnimeMonth $month): int => $month->rate,
            $profile->months
        ));
        $this->assertSame('Sep', $profile->months[0]->label);
    }

    private function event(int $sectionId, string $monthDay, string $title = 'Réunion'): int
    {
        $this->calendars[$sectionId] ??= PresencesTestHelper::createSectionCalendar($this->pdo, $sectionId);

        return PresencesTestHelper::createEvent(
            $this->pdo, $this->calendars[$sectionId], $title, $this->year . '-' . $monthDay
        );
    }

    private function anime(string $firstName, string $lastName, int $sectionId): void
    {
        $this->animes[$lastName] = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId, $firstName, $lastName, 'animated', $sectionId
        )['memberId'];
    }
}
