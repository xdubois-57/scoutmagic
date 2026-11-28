<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: repointNotesOfMergedMembers() is
 * an UPDATE with subqueries whose rowCount() decides whether the boot
 * repair journals anything — rows CHANGED on MySQL, MATCHED on SQLite
 * (AGENTS.md § Database).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MemberMergeRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /**
     * The premise first (AGENTS.md § Database): this engine reports the
     * rows an UPDATE CHANGED, not the rows it matched — which is what makes
     * a second, idle pass of the repair answer 0 and journal nothing.
     */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $pdo = $this->productionEngine();
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['NM-PROBE']);
        $member = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO member_notes (member_id, body) VALUES (?, ?)')->execute([$member, 'x']);

        $update = $pdo->prepare('UPDATE member_notes SET member_id = ? WHERE member_id = ?');
        $update->execute([$member, $member]);

        $this->assertSame(
            0,
            $update->rowCount(),
            'if this ever reports 1, the engine counts matched rows and the idle-pass assertion below proves nothing.'
        );
    }

    public function testNotesOfAlreadyMergedIdentitiesAreRepointedOnceAndCountedTruthfully(): void
    {
        $pdo = $this->productionEngine();
        $repository = new MemberMergeRepository(
            $pdo,
            new DuplicateMemberRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))
        );
        $insertMember = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $insertMember->execute(['NM-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $insertMember->execute(['NM-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        $insertMember->execute(['NM-OTHER']);
        $other = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE members SET merged_into_member_id = ? WHERE id = ?')->execute([$kept, $merged]);
        $note = $pdo->prepare('INSERT INTO member_notes (member_id, body) VALUES (?, ?)');
        $note->execute([$merged, 'x']);
        $note->execute([$merged, 'y']);
        $note->execute([$other, 'z']);

        $this->assertSame(2, $repository->repointNotesOfMergedMembers());
        $this->assertSame(0, $repository->repointNotesOfMergedMembers());

        $count = $pdo->prepare('SELECT COUNT(*) FROM member_notes WHERE member_id = ?');
        $count->execute([$kept]);
        $this->assertSame(2, (int) $count->fetchColumn());
        $count->execute([$other]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }
}
