<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceSheetService;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * The one door every screen and every write of this module goes through:
 * an event id in, a sheet this account may touch out, or nothing.
 *
 * The three refusals — no such event, an event that is not a section's,
 * a section this account does not staff — are asserted to answer
 * IDENTICALLY, because a caller able to tell them apart could map out
 * which ids exist and which sections hold which evenings.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceSheetServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceSheetService $service;
    private int $scoutYearId;
    private int $louveteaux1;
    private int $louveteaux2;
    private int $eventLou1;
    private int $eventLou2;
    private int $eventAnimateurs;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $this->scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);

        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $this->louveteaux1 = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');
        $this->louveteaux2 = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU2', 'Louveteaux 2');

        $this->eventLou1 = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->louveteaux1),
            'Réunion',
            substr($start, 0, 4) . '-09-13'
        );
        $this->eventLou2 = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSectionCalendar($this->pdo, $this->louveteaux2),
            'Réunion',
            substr($start, 0, 4) . '-09-13'
        );
        $this->eventAnimateurs = PresencesTestHelper::createEvent(
            $this->pdo,
            PresencesTestHelper::createSupplementaryCalendar($this->pdo, 'Animateurs'),
            'Réunion de staff',
            substr($start, 0, 4) . '-09-14'
        );

        $this->service = PresencesTestHelper::sheetService(
            $this->pdo,
            $this->encryption,
            PresencesTestHelper::calendarService($this->pdo, $this->encryption)
        );

        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Akéla', 'Dupont', 'chief', $this->louveteaux1, 'akela@test.be'
        );
    }

    public function testAnAnimateurGetsTheirOwnSectionsSheet(): void
    {
        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);

        $this->assertNotNull($sheet);
        $this->assertSame($this->louveteaux1, $sheet->sectionId);
        $this->assertSame('Louveteaux 1', $sheet->sectionLabel);
        $this->assertSame('Réunion', $sheet->title);
    }

    public function testAnAnimateurIsRefusedAnotherSectionsSheet(): void
    {
        $this->assertNull(
            $this->service->buildSheet($this->eventLou2, 'akela@test.be', 'chief', $this->scoutYearId)
        );
        $this->assertNull(
            $this->service->resolveEvent($this->eventLou2, 'akela@test.be', 'chief', $this->scoutYearId)
        );
    }

    public function testAnEventOnASupplementaryCalendarOpensNoSheetForAnybody(): void
    {
        // Not even for a chef d'unité, who staffs every section: the
        // « Animateurs » calendar belongs to no section, so there are no
        // animés to point.
        $this->assertNull(
            $this->service->buildSheet($this->eventAnimateurs, 'akela@test.be', 'chief', $this->scoutYearId)
        );
        $this->assertNull(
            $this->service->buildSheet($this->eventAnimateurs, 'cu@test.be', 'admin', $this->scoutYearId)
        );
    }

    public function testAnUnknownEventAnswersExactlyLikeARefusedOne(): void
    {
        $this->assertSame(
            $this->service->buildSheet(999_999, 'akela@test.be', 'chief', $this->scoutYearId),
            $this->service->buildSheet($this->eventLou2, 'akela@test.be', 'chief', $this->scoutYearId)
        );
    }

    public function testAUnitChiefGetsEverySectionsSheet(): void
    {
        $this->assertNotNull(
            $this->service->buildSheet($this->eventLou1, 'cu@test.be', 'admin', $this->scoutYearId)
        );
        $this->assertNotNull(
            $this->service->buildSheet($this->eventLou2, 'cu@test.be', 'admin', $this->scoutYearId)
        );
    }

    public function testAnIdentifiedVisitorGetsNothing(): void
    {
        $this->assertNull(
            $this->service->buildSheet($this->eventLou1, 'parent@test.be', 'identified', $this->scoutYearId)
        );
    }

    public function testTheSheetListsOnlyTheSectionsAnimesAlphabetically(): void
    {
        $this->seedAnime('Basile', 'Hargot', $this->louveteaux1);
        $this->seedAnime('Isao', 'de Cooman', $this->louveteaux1);
        $this->seedAnime('Dounia', 'Ayoute', $this->louveteaux1);
        // The other section's animé, and an intendant of this one: both
        // are out — a sheet lists animés of THIS section and nothing else.
        $this->seedAnime('Jeanne', 'Vanloqueren', $this->louveteaux2);
        PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId,
            'Marc', 'Intendant', 'intendant', $this->louveteaux1
        );

        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);

        $this->assertNotNull($sheet);
        $this->assertSame(
            ['Ayoute', 'de Cooman', 'Hargot'],
            array_map(static fn ($line): string => $line->lastName, $sheet->lines)
        );
    }

    public function testAnAnimeWithNoRowIsNonRenseigne(): void
    {
        $anime = $this->seedAnime('Basile', 'Hargot', $this->louveteaux1);

        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);
        $this->assertNotNull($sheet);
        $this->assertSame(PresenceStatus::UNSET, $sheet->lines[0]->status);
        $this->assertNull($sheet->lines[0]->comment);

        $repository = new PresenceRepository($this->pdo, $this->encryption);
        $repository->saveStatus($this->eventLou1, $anime['memberId'], PresenceStatus::EXCUSED, null);
        $repository->saveComment($this->eventLou1, $anime['memberId'], 'Mariage de sa cousine.', null);

        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);
        $this->assertNotNull($sheet);
        $this->assertSame(PresenceStatus::EXCUSED, $sheet->lines[0]->status);
        $this->assertSame('Mariage de sa cousine.', $sheet->lines[0]->comment);
    }

    public function testTheCountersCoverTheFourStatesEvenWhenNobodyHasBeenPointed(): void
    {
        $this->seedAnime('Basile', 'Hargot', $this->louveteaux1);
        $this->seedAnime('Dounia', 'Ayoute', $this->louveteaux1);

        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);

        $this->assertNotNull($sheet);
        $this->assertSame(
            ['present' => 0, 'excused' => 0, 'absent' => 0, 'unset' => 2],
            $sheet->counts()
        );
        $this->assertSame(2, $sheet->total());
    }

    /**
     * The composition read is the section's TODAY, not a snapshot taken
     * when the event was created — somebody who joined in November shows
     * up on October's sheet as « non renseigné ».
     */
    public function testAnAnimeAddedAfterTheEventAppearsOnItsSheet(): void
    {
        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);
        $this->assertNotNull($sheet);
        $this->assertSame([], $sheet->lines);

        $this->seedAnime('Hugo', 'Lejeune', $this->louveteaux1);

        $sheet = $this->service->buildSheet($this->eventLou1, 'akela@test.be', 'chief', $this->scoutYearId);
        $this->assertNotNull($sheet);
        $this->assertCount(1, $sheet->lines);
        $this->assertSame(PresenceStatus::UNSET, $sheet->lines[0]->status);
    }

    public function testAnimeMemberIdsAreTheSectionsOwn(): void
    {
        $here = $this->seedAnime('Basile', 'Hargot', $this->louveteaux1);
        $elsewhere = $this->seedAnime('Jeanne', 'Vanloqueren', $this->louveteaux2);

        $ids = $this->service->animeMemberIds($this->louveteaux1, $this->scoutYearId);

        $this->assertContains($here['memberId'], $ids);
        $this->assertNotContains($elsewhere['memberId'], $ids);
    }

    /**
     * @return array{memberId: int, memberYearId: int}
     */
    private function seedAnime(string $firstName, string $lastName, int $sectionId): array
    {
        return PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $this->scoutYearId, $firstName, $lastName, 'animated', $sectionId
        );
    }
}
