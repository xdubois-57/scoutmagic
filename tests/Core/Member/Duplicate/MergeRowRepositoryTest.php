<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Member\Duplicate\MergeRowRepository;
use PHPUnit\Framework\TestCase;

/** Issue #1462: the two statements most merge listeners need, and their collision rules. */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MergeRowRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MergeRowRepository $rows;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            member_id INTEGER NOT NULL,
            year_id INTEGER NOT NULL,
            answered_at TEXT NULL,
            note TEXT NULL
        )');
        $this->pdo->exec('CREATE UNIQUE INDEX uq_answers ON answers (member_id, year_id)');
        $this->rows = new MergeRowRepository($this->pdo);
    }

    private function answer(int $member, int $year, ?string $at, string $note): void
    {
        $this->pdo->prepare('INSERT INTO answers (member_id, year_id, answered_at, note) VALUES (?, ?, ?, ?)')
            ->execute([$member, $year, $at, $note]);
    }

    /** @return array<int, string> year => note, for $member */
    private function notesOf(int $member): array
    {
        $stmt = $this->pdo->prepare('SELECT year_id, note FROM answers WHERE member_id = ? ORDER BY year_id');
        $stmt->execute([$member]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    public function testTheLatestRowWinsACollisionAndTheRestMoves(): void
    {
        $this->answer(1, 2025, '2025-10-01', 'gardée ancienne');
        $this->answer(2, 2025, '2025-11-01', 'absorbée récente');
        $this->answer(1, 2026, '2026-03-01', 'gardée récente');
        $this->answer(2, 2026, '2026-01-01', 'absorbée ancienne');
        $this->answer(2, 2027, null, 'absorbée seule');

        $result = $this->rows->repointUnique('answers', 'member_id', ['year_id'], 'answered_at', 1, 2);

        $this->assertSame(['handled' => 3, 'collisions' => 2], $result);
        $this->assertSame(
            [2025 => 'absorbée récente', 2026 => 'gardée récente', 2027 => 'absorbée seule'],
            $this->notesOf(1)
        );
        $this->assertSame([], $this->notesOf(2));
    }

    public function testKeptWinsWhenTheRuleSaysSo(): void
    {
        $this->answer(1, 2025, '2025-10-01', 'gardée');
        $this->answer(2, 2025, '2025-11-01', 'absorbée');

        $this->rows->repointUnique('answers', 'member_id', ['year_id'], 'kept', 1, 2);

        $this->assertSame([2025 => 'gardée'], $this->notesOf(1));
    }

    public function testAPlainRepointMovesEveryRow(): void
    {
        $this->answer(2, 2025, null, 'a');

        $this->assertSame(1, $this->rows->repoint('answers', 'member_id', 1, 2));
        $this->assertSame(1, $this->rows->count('answers', 'member_id', 1));
    }

    public function testANameThatIsNoIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rows->repoint('answers; DROP TABLE answers', 'member_id', 1, 2);
    }
}
