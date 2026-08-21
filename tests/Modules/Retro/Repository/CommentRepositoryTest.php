<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Repository;

use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CommentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CommentRepository $repository;
    private int $boardId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);
        $this->repository = new CommentRepository($this->pdo);

        $boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->boardId = $boardRepository->create(
            'Board', '2026-07-01', null, 'tok', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, null
        );
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create($this->boardId, 'good', 'Super ambiance !');

        $comment = $this->repository->findById($id);

        $this->assertNotNull($comment);
        $this->assertSame('good', $comment->columnKey);
        $this->assertSame('Super ambiance !', $comment->body);
        $this->assertSame(0, $comment->votes);
        $this->assertFalse($comment->hidden);
    }

    public function testFindByBoardIdOrdersOldestFirst(): void
    {
        $first = $this->repository->create($this->boardId, 'good', 'First');
        $second = $this->repository->create($this->boardId, 'improve', 'Second');

        $comments = $this->repository->findByBoardId($this->boardId);

        $this->assertCount(2, $comments);
        $this->assertSame($first, $comments[0]->id);
        $this->assertSame($second, $comments[1]->id);
    }

    public function testSetHidden(): void
    {
        $id = $this->repository->create($this->boardId, 'good', 'Text');

        $this->repository->setHidden($id, true);

        $this->assertTrue($this->repository->findById($id)->hidden);
    }

    public function testSetVotes(): void
    {
        $id = $this->repository->create($this->boardId, 'good', 'Text');

        $this->repository->setVotes($id, 7);

        $this->assertSame(7, $this->repository->findById($id)->votes);
    }
}
