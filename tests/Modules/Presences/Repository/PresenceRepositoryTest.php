<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Repository;

use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Value\PresenceStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Presences\PresencesTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PresenceRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PresenceRepository $repository;
    private int $memberA;
    private int $memberB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        PresencesTestHelper::createTables($this->pdo);
        $this->encryption = PresencesTestHelper::encryption();
        $this->repository = new PresenceRepository($this->pdo, $this->encryption);

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $scoutYearId = PresencesTestHelper::createScoutYear($this->pdo, $label, $start, $end);
        $branch = PresencesTestHelper::createBranch($this->pdo, 'LOU', 'Louveteaux', 20);
        $section = PresencesTestHelper::createSection($this->pdo, $branch, 'LOU1', 'Louveteaux 1');

        $this->memberA = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $scoutYearId, 'Basile', 'Hargot', 'animated', $section
        )['memberId'];
        $this->memberB = PresencesTestHelper::createMember(
            $this->pdo, $this->encryption, $scoutYearId, 'Dounia', 'Ayoute', 'animated', $section
        )['memberId'];
    }

    public function testARecordedStateComesBackAsWritten(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);

        $record = $this->repository->find(10, $this->memberA);

        $this->assertNotNull($record);
        $this->assertSame(PresenceStatus::PRESENT, $record->status);
        $this->assertNull($record->comment);
    }

    public function testASecondTapCorrectsRatherThanAddsARow(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);
        $this->repository->save(10, $this->memberA, PresenceStatus::ABSENT, null, null);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn()
        );
        $this->assertSame(PresenceStatus::ABSENT, $this->repository->find(10, $this->memberA)?->status);
    }

    public function testAStateChangeKeepsTheCommentBesideIt(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::EXCUSED, 'Malade.', null);
        $this->repository->save(
            10,
            $this->memberA,
            PresenceStatus::ABSENT,
            $this->repository->find(10, $this->memberA)?->comment,
            null
        );

        $this->assertSame('Malade.', $this->repository->find(10, $this->memberA)?->comment);
    }

    public function testTheCommentIsUnreadableInTheDatabase(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::EXCUSED, 'Chez son père ce week-end.', null);

        $stored = (string) $this->pdo->query('SELECT comment_encrypted FROM presences_records')->fetchColumn();

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('père', $stored);
        $this->assertStringNotContainsString('week-end', $stored);
        $this->assertSame(
            'Chez son père ce week-end.',
            $this->repository->find(10, $this->memberA)?->comment
        );
    }

    /**
     * The AES-GCM context binds the ciphertext to its column: a blob
     * lifted out of another encrypted column does not silently decrypt
     * into a comment.
     */
    public function testACommentDoesNotDecryptUnderAnotherColumnsContext(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::EXCUSED, 'Malade.', null);
        $stored = (string) $this->pdo->query('SELECT comment_encrypted FROM presences_records')->fetchColumn();

        $this->expectException(\Core\Security\DecryptionException::class);
        $this->encryption->decrypt($stored, 'member_years.first_name');
    }

    public function testNonRenseigneWithNothingBesideItStoresNoRow(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::UNSET, null, null);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn());
        $this->assertNull($this->repository->find(10, $this->memberA));
    }

    public function testTakingAStateBackRemovesTheRowItCreated(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);
        $this->repository->save(10, $this->memberA, PresenceStatus::UNSET, null, null);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn());
    }

    public function testACommentAloneKeepsARowEvenWithoutAState(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::UNSET, 'Sa maman a prévenu.', null);

        $record = $this->repository->find(10, $this->memberA);
        $this->assertNotNull($record);
        $this->assertSame(PresenceStatus::UNSET, $record->status);
        $this->assertSame('Sa maman a prévenu.', $record->comment);
    }

    public function testAnEmptyCommentIsTheSameAsNoComment(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, '   ', null);

        $this->assertNull($this->repository->find(10, $this->memberA)?->comment);
    }

    public function testOneEventComesBackKeyedByMember(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);
        $this->repository->save(10, $this->memberB, PresenceStatus::ABSENT, null, null);
        $this->repository->save(11, $this->memberA, PresenceStatus::EXCUSED, null, null);

        $sheet = $this->repository->findByEvent(10);

        $this->assertSame([$this->memberA, $this->memberB], array_keys($sheet));
        $this->assertSame(PresenceStatus::PRESENT, $sheet[$this->memberA]->status);
    }

    public function testManyEventsComeBackKeyedByEventThenMember(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);
        $this->repository->save(11, $this->memberB, PresenceStatus::ABSENT, null, null);

        $register = $this->repository->findByEvents([10, 11, 12]);

        // Every asked-for event is a key, including the one nobody has
        // opened: a caller must not have to tell "no key" from "no rows".
        $this->assertSame([10, 11, 12], array_keys($register));
        $this->assertSame([], $register[12]);
        $this->assertSame(PresenceStatus::PRESENT, $register[10][$this->memberA]->status);
    }

    public function testOneAnimesHistoryComesBackKeyedByEvent(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, null, null);
        $this->repository->save(11, $this->memberA, PresenceStatus::ABSENT, null, null);
        $this->repository->save(11, $this->memberB, PresenceStatus::PRESENT, null, null);

        $history = $this->repository->findByMemberAndEvents($this->memberA, [10, 11]);

        $this->assertSame([10, 11], array_keys($history));
        $this->assertSame(PresenceStatus::ABSENT, $history[11]->status);
    }

    public function testTheCountsAreAggregatedWithoutDecryptingAnything(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::PRESENT, 'Un commentaire.', null);
        $this->repository->save(10, $this->memberB, PresenceStatus::PRESENT, null, null);
        $this->repository->save(11, $this->memberA, PresenceStatus::ABSENT, null, null);

        $counts = $this->repository->countByStatusForEvents([10, 11, 12]);

        $this->assertSame(['present' => 2], $counts[10]);
        $this->assertSame(['absent' => 1], $counts[11]);
        $this->assertSame([], $counts[12]);
    }

    public function testAnEmptyIdListAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->repository->findByEvents([]));
        $this->assertSame([], $this->repository->findByMemberAndEvents($this->memberA, []));
        $this->assertSame([], $this->repository->countByStatusForEvents([]));
        $this->assertSame(0, $this->repository->eraseComments([]));
    }

    public function testErasingCommentsLeavesTheStatesStanding(): void
    {
        $this->repository->save(10, $this->memberA, PresenceStatus::EXCUSED, 'Malade.', null);
        $this->repository->save(10, $this->memberB, PresenceStatus::PRESENT, 'Reparti plus tôt.', null);

        $this->assertSame(1, $this->repository->eraseComments([$this->memberA]));

        $this->assertNull($this->repository->find(10, $this->memberA)?->comment);
        $this->assertSame(PresenceStatus::EXCUSED, $this->repository->find(10, $this->memberA)?->status);
        $this->assertSame('Reparti plus tôt.', $this->repository->find(10, $this->memberB)?->comment);
    }
}
