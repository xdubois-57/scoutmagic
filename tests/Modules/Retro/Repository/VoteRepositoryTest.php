<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Repository;

use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Repository\VoteRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class VoteRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private VoteRepository $repository;
    private int $boardId;
    private int $commentId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $this->repository = new VoteRepository($this->pdo);

        $boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->boardId = $boardRepository->create(
            'Board', '2026-07-01', null, 'tok', null, true, 'budget', 5, true, 'cookie', 140, '7d', null, null
        );
        $commentRepository = new CommentRepository($this->pdo);
        $this->commentId = $commentRepository->create($this->boardId, 'good', 'Text');
    }

    public function testCreateAndFindByCommentAndVoter(): void
    {
        $this->repository->create($this->commentId, $this->boardId, 'hash1', 'boardhash1', 1);

        $vote = $this->repository->findByCommentAndVoter($this->commentId, 'hash1');

        $this->assertNotNull($vote);
        $this->assertSame(1, $vote->weight);
    }

    public function testUniqueConstraintRejectsADuplicateVoterHashOnTheSameComment(): void
    {
        $this->repository->create($this->commentId, $this->boardId, 'hash1', 'boardhash1', 1);

        $this->expectException(\PDOException::class);
        $this->repository->create($this->commentId, $this->boardId, 'hash1', 'boardhash1', 1);
    }

    public function testUpdateWeight(): void
    {
        $id = $this->repository->create($this->commentId, $this->boardId, 'hash1', 'boardhash1', 1);

        $this->repository->updateWeight($id, 3);

        $this->assertSame(3, $this->repository->findByCommentAndVoter($this->commentId, 'hash1')->weight);
    }

    public function testDelete(): void
    {
        $id = $this->repository->create($this->commentId, $this->boardId, 'hash1', 'boardhash1', 1);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findByCommentAndVoter($this->commentId, 'hash1'));
    }

    public function testSumWeightForComment(): void
    {
        $this->repository->create($this->commentId, $this->boardId, 'hashA', 'boardhashA', 2);
        $this->repository->create($this->commentId, $this->boardId, 'hashB', 'boardhashB', 3);

        $this->assertSame(5, $this->repository->sumWeightForComment($this->commentId));
    }

    public function testSumWeightForVoterOnBoardSumsAcrossDifferentComments(): void
    {
        $commentRepository = new CommentRepository($this->pdo);
        $otherCommentId = $commentRepository->create($this->boardId, 'improve', 'Other');

        // Same voter_board_hash (same voter, same board) but two DIFFERENT
        // comments, hence two different voter_hash values — this is exactly
        // why voter_board_hash exists (see schema.sql/VoteService docblocks).
        $this->repository->create($this->commentId, $this->boardId, 'hash-for-comment-1', 'shared-board-hash', 2);
        $this->repository->create($otherCommentId, $this->boardId, 'hash-for-comment-2', 'shared-board-hash', 1);

        $this->assertSame(3, $this->repository->sumWeightForVoterOnBoard($this->boardId, 'shared-board-hash'));
    }

    public function testSumWeightForVoterOnBoardIgnoresOtherVoters(): void
    {
        $this->repository->create($this->commentId, $this->boardId, 'hashA', 'my-board-hash', 4);
        $this->repository->create($this->commentId, $this->boardId, 'hashB', 'someone-elses-board-hash', 4);

        $this->assertSame(4, $this->repository->sumWeightForVoterOnBoard($this->boardId, 'my-board-hash'));
    }
}
