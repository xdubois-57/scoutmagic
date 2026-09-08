<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Service;

use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceEventLinkRepository;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Service\PresenceEventCleanupService;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * Deleting an evening has to take its sheet with it. No foreign key does
 * that — `presences_records.calendar_event_id` deliberately constrains
 * nothing in another module's table — so if this service does not erase
 * the rows, nothing ever will: every screen starts from an event that no
 * longer exists, so the states and the encrypted comments would sit there
 * unreachable and unerasable.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceEventCleanupServiceTest extends TestCase
{
    private \PDO $pdo;
    private PresenceRepository $repository;
    private PresenceEventLinkRepository $links;
    private PresenceEventCleanupService $service;
    private int $memberA;
    private int $memberB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $encryption = PresencesTestHelper::encryption();
        $this->repository = new PresenceRepository($this->pdo, $encryption);
        $this->links = new PresenceEventLinkRepository($this->pdo);
        $this->service = new PresenceEventCleanupService($this->repository, $this->links);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);
        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $section = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');

        $this->memberA = PresencesTestHelper::createMember(
            $this->pdo, $encryption, $scoutYearId, 'Basile', 'Hargot', 'animated', $section
        )['memberId'];
        $this->memberB = PresencesTestHelper::createMember(
            $this->pdo, $encryption, $scoutYearId, 'Dounia', 'Ayoute', 'animated', $section
        )['memberId'];
    }

    /**
     * The SQL audit skips `tests/`, so a constant `query()` here is not
     * flagged — and a value added to it later would not be either. Every
     * statement goes through the binding path, without exception.
     */
    private function prepared(string $sql): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (string) $stmt->fetchColumn();
    }

    public function testForgettingAnEventErasesItsStatesAndItsComments(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);
        $this->repository->saveComment(10, $this->memberA, 'Malade.', null);
        $this->repository->saveStatus(10, $this->memberB, PresenceStatus::PRESENT, null);

        $this->service->forgetEvent(10);

        $this->assertSame([], $this->repository->findByEvent(10));
        $this->assertSame(
            0,
            (int) $this->prepared('SELECT COUNT(*) FROM presences_records')
        );
    }

    public function testForgettingAnEventDropsTheShortCodeItsSheetWasReachedBy(): void
    {
        $this->links->rememberCode(10, 'K7m2Qa');

        $this->service->forgetEvent(10);

        $this->assertNull($this->links->findCode(10));
    }

    public function testAnotherEveningIsLeftEntirelyAlone(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(11, $this->memberA, PresenceStatus::ABSENT, null);
        $this->links->rememberCode(10, 'K7m2Qa');
        $this->links->rememberCode(11, 'Z3p9Wb');

        $this->service->forgetEvent(10);

        $this->assertCount(1, $this->repository->findByEvent(11));
        $this->assertSame('Z3p9Wb', $this->links->findCode(11));
    }

    public function testAnEveningNobodyEverPointedIsForgottenWithoutComplaint(): void
    {
        $this->service->forgetEvent(999);

        $this->assertSame([], $this->repository->findByEvent(999));
    }

    /**
     * The SQLite mirror has to refuse the codes production refuses, and
     * SQLite enforces nothing from a declared `VARCHAR(16)` — so the bound
     * lives in a CHECK, and this is what keeps it there. Delete the CHECK
     * from `PresencesTestHelper` and only this test says so; every other
     * test in the suite passes either way, which is precisely how a test
     * schema drifts laxer than the server it stands in for.
     */
    public function testTheMirrorRefusesACodeLongerThanProductionAccepts(): void
    {
        $this->expectException(\PDOException::class);

        $this->links->rememberCode(10, str_repeat('K', 17));
    }
}
