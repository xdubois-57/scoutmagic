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
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);

        $record = $this->repository->find(10, $this->memberA);

        $this->assertNotNull($record);
        $this->assertSame(PresenceStatus::PRESENT, $record->status);
        $this->assertNull($record->comment);
    }

    public function testASecondTapCorrectsRatherThanAddsARow(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::ABSENT, null);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn()
        );
        $this->assertSame(PresenceStatus::ABSENT, $this->repository->find(10, $this->memberA)?->status);
    }

    public function testAStateChangeKeepsTheCommentBesideIt(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);
        $this->repository->saveComment(10, $this->memberA, 'Malade.', null);
        // Nothing carries the comment across: saveStatus() writes its own
        // column and no other, which is the guarantee, not a convention
        // the caller has to remember.
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::ABSENT, null);

        $this->assertSame('Malade.', $this->repository->find(10, $this->memberA)?->comment);
    }

    public function testTheCommentIsUnreadableInTheDatabase(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);
        $this->repository->saveComment(10, $this->memberA, 'Chez son père ce week-end.', null);

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
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);
        $this->repository->saveComment(10, $this->memberA, 'Malade.', null);
        $stored = (string) $this->pdo->query('SELECT comment_encrypted FROM presences_records')->fetchColumn();

        $this->expectException(\Core\Security\DecryptionException::class);
        $this->encryption->decrypt($stored, 'member_years.first_name');
    }

    public function testNonRenseigneWithNothingBesideItStoresNoRow(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::UNSET, null);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn());
        $this->assertNull($this->repository->find(10, $this->memberA));
    }

    public function testTakingAStateBackRemovesTheRowItCreated(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::UNSET, null);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn());
    }

    public function testACommentAloneKeepsARowEvenWithoutAState(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::UNSET, null);
        $this->repository->saveComment(10, $this->memberA, 'Sa maman a prévenu.', null);

        $record = $this->repository->find(10, $this->memberA);
        $this->assertNotNull($record);
        $this->assertSame(PresenceStatus::UNSET, $record->status);
        $this->assertSame('Sa maman a prévenu.', $record->comment);
    }

    public function testAnEmptyCommentIsTheSameAsNoComment(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveComment(10, $this->memberA, '   ', null);

        $this->assertNull($this->repository->find(10, $this->memberA)?->comment);
    }

    public function testOneEventComesBackKeyedByMember(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(10, $this->memberB, PresenceStatus::ABSENT, null);
        $this->repository->saveStatus(11, $this->memberA, PresenceStatus::EXCUSED, null);

        $sheet = $this->repository->findByEvent(10);

        $this->assertSame([$this->memberA, $this->memberB], array_keys($sheet));
        $this->assertSame(PresenceStatus::PRESENT, $sheet[$this->memberA]->status);
    }

    public function testManyEventsComeBackKeyedByEventThenMember(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(11, $this->memberB, PresenceStatus::ABSENT, null);

        $register = $this->repository->findByEvents([10, 11, 12]);

        // Every asked-for event is a key, including the one nobody has
        // opened: a caller must not have to tell "no key" from "no rows".
        $this->assertSame([10, 11, 12], array_keys($register));
        $this->assertSame([], $register[12]);
        $this->assertSame(PresenceStatus::PRESENT, $register[10][$this->memberA]->status);
    }

    public function testOneAnimesHistoryComesBackKeyedByEvent(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(11, $this->memberA, PresenceStatus::ABSENT, null);
        $this->repository->saveStatus(11, $this->memberB, PresenceStatus::PRESENT, null);

        $history = $this->repository->findByMemberAndEvents($this->memberA, [10, 11]);

        $this->assertSame([10, 11], array_keys($history));
        $this->assertSame(PresenceStatus::ABSENT, $history[11]->status);
    }

    public function testTheCountsAreAggregatedWithoutDecryptingAnything(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveComment(10, $this->memberA, 'Un commentaire.', null);
        $this->repository->saveStatus(10, $this->memberB, PresenceStatus::PRESENT, null);
        $this->repository->saveStatus(11, $this->memberA, PresenceStatus::ABSENT, null);

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
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);
        $this->repository->saveComment(10, $this->memberA, 'Malade.', null);
        $this->repository->saveStatus(10, $this->memberB, PresenceStatus::PRESENT, null);
        $this->repository->saveComment(10, $this->memberB, 'Reparti plus tôt.', null);

        $this->assertSame(1, $this->repository->eraseComments([$this->memberA]));

        $this->assertNull($this->repository->find(10, $this->memberA)?->comment);
        $this->assertSame(PresenceStatus::EXCUSED, $this->repository->find(10, $this->memberA)?->status);
        $this->assertSame('Reparti plus tôt.', $this->repository->find(10, $this->memberB)?->comment);
    }

    /**
     * The race the unique index used to report as an error: two
     * animateurs tapping the same animé for the first time in the same
     * second. A read-then-INSERT has both of them find no row; the upsert
     * has no such window, so the second tap is recorded rather than
     * refused.
     */
    public function testTwoFirstWritesForTheSameAnimeDoNotCollide(): void
    {
        $second = new PresenceRepository($this->pdo, $this->encryption);

        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $second->saveStatus(10, $this->memberA, PresenceStatus::ABSENT, null);

        $this->assertSame(PresenceStatus::ABSENT, $this->repository->find(10, $this->memberA)?->status);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM presences_records')->fetchColumn()
        );
    }

    /**
     * The lost update the two column-scoped writes exist to prevent: a
     * state saved from a page that loaded before somebody else's comment
     * must not carry that page's stale idea of the comment back.
     */
    public function testAStateNeverCarriesAStaleCommentBack(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        // The other animateur, on their own screen.
        $this->repository->saveComment(10, $this->memberA, 'Sa maman vient de prévenir.', null);
        // This screen still shows no comment, and only sends a state.
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::EXCUSED, null);

        $record = $this->repository->find(10, $this->memberA);
        $this->assertSame(PresenceStatus::EXCUSED, $record?->status);
        $this->assertSame('Sa maman vient de prévenir.', $record?->comment);
    }

    public function testACommentNeverCarriesAStaleStateBack(): void
    {
        $this->repository->saveComment(10, $this->memberA, 'Rendez-vous médical.', null);
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::ABSENT, null);
        $this->repository->saveComment(10, $this->memberA, 'Rendez-vous médical, prévenu jeudi.', null);

        $record = $this->repository->find(10, $this->memberA);
        $this->assertSame(PresenceStatus::ABSENT, $record?->status);
        $this->assertSame('Rendez-vous médical, prévenu jeudi.', $record?->comment);
    }

    public function testDeletingAnEventTakesEveryRowOfItAndNoOther(): void
    {
        $this->repository->saveStatus(10, $this->memberA, PresenceStatus::PRESENT, null);
        $this->repository->saveComment(10, $this->memberA, 'Malade.', null);
        $this->repository->saveStatus(10, $this->memberB, PresenceStatus::ABSENT, null);
        $this->repository->saveStatus(11, $this->memberA, PresenceStatus::PRESENT, null);

        $this->repository->deleteByEvent(10);

        $this->assertSame([], $this->repository->findByEvent(10));
        $this->assertCount(1, $this->repository->findByEvent(11));
    }
}
