<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Repository;

use Modules\Retro\Repository\BoardRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BoardRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $this->repository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }

    private function createBoard(array $overrides = []): int
    {
        $defaults = [
            'title' => 'Camp 2026', 'boardDate' => '2026-07-01', 'calendarEventId' => null,
            'token' => bin2hex(random_bytes(8)), 'shortCode' => null, 'listed' => true,
            'voteMode' => 'unlimited', 'voteBudget' => 5, 'votesVisible' => true,
            'antiDuplicateMode' => 'cookie', 'maxCommentLength' => 140, 'autoCloseDelay' => '7d',
            'autoCloseAt' => null, 'createdBy' => null,
            'closeNotifyEnabled' => true, 'closeNotifyEmail' => null,
        ];
        $p = array_merge($defaults, $overrides);

        return $this->repository->create(
            $p['title'], $p['boardDate'], $p['calendarEventId'], $p['token'], $p['shortCode'], $p['listed'],
            $p['voteMode'], $p['voteBudget'], $p['votesVisible'], $p['antiDuplicateMode'], $p['maxCommentLength'],
            $p['autoCloseDelay'], $p['autoCloseAt'], $p['createdBy'], $p['closeNotifyEnabled'], $p['closeNotifyEmail']
        );
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->createBoard(['title' => 'Weekend']);

        $board = $this->repository->findById($id);

        $this->assertNotNull($board);
        $this->assertSame('Weekend', $board->title);
        $this->assertSame('open', $board->status);
        $this->assertTrue($board->isOpen());
    }

    public function testFindByToken(): void
    {
        $this->createBoard(['token' => 'abc123']);

        $board = $this->repository->findByToken('abc123');

        $this->assertNotNull($board);
        $this->assertSame('abc123', $board->token);
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->repository->findByToken('does-not-exist'));
    }

    public function testTheTokenIsNeverStoredInPlaintext(): void
    {
        // A long-lived bearer credential: any DB read (SQLi, stolen dump)
        // must not yield a working /r/{token} URL. Encrypted at rest, and
        // the blind index is a keyed HMAC — neither column contains the
        // token itself.
        $token = bin2hex(random_bytes(32));
        $id = $this->createBoard(['token' => $token]);

        $row = $this->pdo->query('SELECT token_encrypted, token_blind_index FROM retro_boards WHERE id = ' . $id)->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString($token, (string) $row['token_encrypted']);
        $this->assertNotSame($token, $row['token_blind_index']);
        $this->assertNotSame(hash('sha256', $token), $row['token_blind_index']);

        // Still fully usable: lookup by value, and re-displayable.
        $this->assertSame($token, $this->repository->findByToken($token)?->token);
    }

    public function testFindOpenExcludesClosedBoards(): void
    {
        $openId = $this->createBoard(['token' => 'open1']);
        $closedId = $this->createBoard(['token' => 'closed1']);
        $this->repository->close($closedId);

        $openIds = array_map(fn($b) => $b->id, $this->repository->findOpen());

        $this->assertContains($openId, $openIds);
        $this->assertNotContains($closedId, $openIds);
    }

    public function testCloseSetsStatusAndClosedAt(): void
    {
        $id = $this->createBoard();

        $this->repository->close($id);
        $board = $this->repository->findById($id);

        $this->assertSame('closed', $board->status);
        $this->assertFalse($board->isOpen());
        $this->assertNotNull($board->closedAt);
    }

    public function testUpdateChangesFields(): void
    {
        $id = $this->createBoard(['title' => 'Old title']);

        $this->repository->update($id, 'New title', '2026-08-01', null, false, 'budget', 3, false, 'auth', 160, 'none', null);
        $board = $this->repository->findById($id);

        $this->assertSame('New title', $board->title);
        $this->assertSame('budget', $board->voteMode);
        $this->assertFalse($board->listed);
        $this->assertFalse($board->votesVisible);
        $this->assertSame('auth', $board->antiDuplicateMode);
    }

    public function testCloseNotifyFieldsRoundTripThroughCreateAndUpdate(): void
    {
        $id = $this->createBoard(['closeNotifyEnabled' => true, 'closeNotifyEmail' => 'chief@example.com']);
        $board = $this->repository->findById($id);
        $this->assertTrue($board->closeNotifyEnabled);
        $this->assertSame('chief@example.com', $board->closeNotifyEmail);

        $this->repository->update(
            $id, $board->title, $board->boardDate, null, $board->listed, $board->voteMode, $board->voteBudget,
            $board->votesVisible, $board->antiDuplicateMode, $board->maxCommentLength, $board->autoCloseDelay,
            null, false, null
        );
        $updated = $this->repository->findById($id);
        $this->assertFalse($updated->closeNotifyEnabled);
        $this->assertNull($updated->closeNotifyEmail);
    }

    public function testRegenerateLinkChangesTokenAndInvalidatesOld(): void
    {
        $id = $this->createBoard(['token' => 'old-token']);

        $this->repository->regenerateLink($id, 'new-token', 'sc1');

        $this->assertNull($this->repository->findByToken('old-token'));
        $this->assertNotNull($this->repository->findByToken('new-token'));
    }

    public function testSetAiSummary(): void
    {
        $id = $this->createBoard();

        $this->repository->setAiSummary($id, '- Theme one\n- Theme two');
        $board = $this->repository->findById($id);

        $this->assertSame('- Theme one\n- Theme two', $board->aiSummary);
    }

    public function testResultsRevealedWhenVotesVisible(): void
    {
        $id = $this->createBoard(['votesVisible' => true]);
        $board = $this->repository->findById($id);

        $this->assertTrue($board->resultsRevealed());
    }

    public function testResultsRevealedWhenVotesHiddenButBoardClosed(): void
    {
        $id = $this->createBoard(['votesVisible' => false]);
        $this->repository->close($id);
        $board = $this->repository->findById($id);

        $this->assertTrue($board->resultsRevealed());
    }

    public function testResultsNotRevealedWhenVotesHiddenAndOpen(): void
    {
        $id = $this->createBoard(['votesVisible' => false]);
        $board = $this->repository->findById($id);

        $this->assertFalse($board->resultsRevealed());
    }

    /**
     * The chief list reads the archive capped: only the most recent
     * $limit archived boards, with the true total counted in SQL — the
     * archive grows by one per auto-created retro forever.
     */
    public function testArchivedBoardsAreReadCappedAndCountedApart(): void
    {
        $open = $this->createBoard(['title' => 'Ouverte']);
        $oldArchived = $this->createBoard(['title' => 'Archivée ancienne']);
        $newArchived = $this->createBoard(['title' => 'Archivée récente']);
        $this->pdo->exec("UPDATE retro_boards SET status = 'archived', created_at = '2020-01-01 00:00:00' WHERE id = {$oldArchived}");
        $this->pdo->exec("UPDATE retro_boards SET status = 'archived' WHERE id = {$newArchived}");

        $unarchived = $this->repository->findUnarchivedOrderedByCreated();
        $this->assertSame([$open], array_map(fn($b) => $b->id, $unarchived));

        $recent = $this->repository->findRecentArchived(1);
        $this->assertSame([$newArchived], array_map(fn($b) => $b->id, $recent));

        $this->assertSame(2, $this->repository->countArchived());
    }

}
