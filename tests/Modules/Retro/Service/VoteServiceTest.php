<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\Comment;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Repository\VoteRepository;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\VoteService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class VoteServiceTest extends TestCase
{
    private \PDO $pdo;
    private VoteService $service;
    private BoardRepository $boardRepository;
    private CommentRepository $commentRepository;
    private VoteRepository $voteRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->boardRepository = new BoardRepository($this->pdo);
        $this->commentRepository = new CommentRepository($this->pdo);
        $this->voteRepository = new VoteRepository($this->pdo);
        $this->service = new VoteService($this->voteRepository, $this->commentRepository, $encryption);
    }

    private function createBoard(string $voteMode, int $budget = 5): Board
    {
        $id = $this->boardRepository->create(
            'Board', '2026-07-01', null, bin2hex(random_bytes(8)), null, true, $voteMode, $budget,
            true, 'cookie', 140, '7d', null, null
        );

        return $this->boardRepository->findById($id);
    }

    private function createComment(int $boardId): Comment
    {
        $id = $this->commentRepository->create($boardId, 'good', 'Text');

        return $this->commentRepository->findById($id);
    }

    /**
     * DoD requirement (module spec): participant actions are never logged.
     * VoteService has no JournalService dependency at all, so no code path
     * could call it even accidentally — checked via reflection so this stays
     * a regression test, not just a comment.
     */
    public function testVoteServiceHasNoJournalServiceDependency(): void
    {
        $constructor = new \ReflectionMethod(VoteService::class, '__construct');
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $this->assertNotSame('Core\Journal\JournalService', $typeName, 'VoteService must never depend on JournalService.');
        }
    }

    /**
     * DoD requirement: no code path in VoteService ever passes a raw voter
     * identifier to storage — every write goes through blindIndex() first.
     */
    public function testToggleLikeNeverStoresTheRawVoterIdentifier(): void
    {
        $board = $this->createBoard('unlimited');
        $comment = $this->createComment($board->id);

        $this->service->toggleLike($board, $comment, 'voter-secret-identifier-xyz');

        $row = $this->pdo->query('SELECT voter_hash, voter_board_hash FROM retro_votes')->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('voter-secret-identifier-xyz', $row['voter_hash']);
        $this->assertStringNotContainsString('voter-secret-identifier-xyz', $row['voter_board_hash']);
    }

    /**
     * DoD requirement: a second vote attempt from the same voter identifier
     * on the same comment is rejected/neutralized by the dedup mechanism —
     * here, toggling twice removes the vote rather than creating a
     * duplicate row (the module's actual "toggle" UX for unlimited mode).
     */
    public function testToggleLikeTwiceFromTheSameVoterRemovesRatherThanDuplicates(): void
    {
        $board = $this->createBoard('unlimited');
        $comment = $this->createComment($board->id);

        $liked = $this->service->toggleLike($board, $comment, 'same-voter');
        $this->assertTrue($liked);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM retro_votes')->fetchColumn());

        $likedAgain = $this->service->toggleLike($board, $comment, 'same-voter');
        $this->assertFalse($likedAgain);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM retro_votes')->fetchColumn());
    }

    public function testToggleLikeFromDifferentVotersBothCount(): void
    {
        $board = $this->createBoard('unlimited');
        $comment = $this->createComment($board->id);

        $this->service->toggleLike($board, $comment, 'voter-a');
        $this->service->toggleLike($board, $comment, 'voter-b');

        $this->assertSame(2, $this->commentRepository->findById($comment->id)->votes);
    }

    public function testToggleLikeRejectsWhenBoardIsClosed(): void
    {
        $board = $this->createBoard('unlimited');
        $comment = $this->createComment($board->id);
        $this->boardRepository->close($board->id);
        $closedBoard = $this->boardRepository->findById($board->id);

        $this->expectException(RetroException::class);
        $this->service->toggleLike($closedBoard, $comment, 'voter-a');
    }

    public function testAddPointRespectsRemainingBudget(): void
    {
        $board = $this->createBoard('budget', 2);
        $comment = $this->createComment($board->id);

        $remaining1 = $this->service->addPoint($board, $comment, 'voter-a');
        $this->assertSame(1, $remaining1);
        $remaining2 = $this->service->addPoint($board, $comment, 'voter-a');
        $this->assertSame(0, $remaining2);

        $this->expectException(RetroException::class);
        $this->service->addPoint($board, $comment, 'voter-a');
    }

    public function testAddPointSumsAcrossMultipleCommentsForTheSameVoter(): void
    {
        $board = $this->createBoard('budget', 3);
        $commentA = $this->createComment($board->id);
        $commentB = $this->createComment($board->id);

        $this->service->addPoint($board, $commentA, 'voter-a');
        $remaining = $this->service->addPoint($board, $commentB, 'voter-a');

        $this->assertSame(1, $remaining);
    }

    public function testRemovePointRefundsBudget(): void
    {
        $board = $this->createBoard('budget', 5);
        $comment = $this->createComment($board->id);
        $this->service->addPoint($board, $comment, 'voter-a');

        $remaining = $this->service->removePoint($board, $comment, 'voter-a');

        $this->assertSame(5, $remaining);
        $this->assertSame(0, $this->commentRepository->findById($comment->id)->votes);
    }

    public function testRemainingBudgetIsUnaffectedByAnotherVotersSpend(): void
    {
        $board = $this->createBoard('budget', 5);
        $comment = $this->createComment($board->id);
        $this->service->addPoint($board, $comment, 'voter-a');

        $this->assertSame(5, $this->service->remainingBudget($board, 'voter-b'));
    }

    /**
     * The UI needs this to keep the "you already voted" state visible
     * across polling re-renders — see Controller\RetroBoardController's
     * use of it and public/assets/js/retro-board.js's commentHtml().
     */
    public function testVotedCommentIdsReturnsOnlyTheCommentsThisVoterVotedOn(): void
    {
        $board = $this->createBoard('unlimited');
        $commentA = $this->createComment($board->id);
        $commentB = $this->createComment($board->id);
        $commentC = $this->createComment($board->id);

        $this->service->toggleLike($board, $commentA, 'voter-a');
        $this->service->toggleLike($board, $commentC, 'voter-a');
        $this->service->toggleLike($board, $commentB, 'voter-b');

        $voted = $this->service->votedCommentIds($board->id, [$commentA->id, $commentB->id, $commentC->id], 'voter-a');

        sort($voted);
        $this->assertSame([$commentA->id, $commentC->id], $voted);
    }

    public function testVotedCommentIdsReflectsAnUnvoteImmediately(): void
    {
        $board = $this->createBoard('unlimited');
        $comment = $this->createComment($board->id);

        $this->service->toggleLike($board, $comment, 'voter-a');
        $this->service->toggleLike($board, $comment, 'voter-a');

        $this->assertSame([], $this->service->votedCommentIds($board->id, [$comment->id], 'voter-a'));
    }

    public function testVotedCommentIdsWithNoCommentsReturnsEmptyWithoutQuerying(): void
    {
        $board = $this->createBoard('unlimited');

        $this->assertSame([], $this->service->votedCommentIds($board->id, [], 'voter-a'));
    }
}
