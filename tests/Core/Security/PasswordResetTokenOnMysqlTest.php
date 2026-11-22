<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\PasswordResetRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1257, on the engine production runs. Claiming a reset token turns
 * on `rowCount()`, which MySQL answers with the rows CHANGED and SQLite
 * with the rows FOUND (AGENTS.md § Database): a claim that read the wrong
 * one would let two requests each believe they consumed the link.
 */
#[Group('database')]
final class PasswordResetTokenOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testOnlyTheFirstOfTwoClaimsOnTheSameTokenWins(): void
    {
        $pdo = $this->productionEngine();
        $first = new PasswordResetRepository($pdo);
        $second = new PasswordResetRepository($pdo);
        $id = $first->create(str_repeat('a', 64), 'hash', new \DateTimeImmutable('+60 minutes'));

        $this->assertTrue($first->markUsed($id));
        $this->assertFalse($second->markUsed($id), 'The second request must be told it came too late.');
        $this->assertTrue($second->findById($id)?->used);
    }

    public function testAnExpiredTokenCannotBeClaimedAndAnUnknownOneNeither(): void
    {
        $repository = new PasswordResetRepository($this->productionEngine());
        $expired = $repository->create(str_repeat('b', 64), 'hash', new \DateTimeImmutable('-1 minute'));

        $this->assertFalse($repository->markUsed($expired));
        $this->assertFalse($repository->markUsed($expired + 1000));
        $this->assertFalse($repository->findById($expired)?->used);
    }

    public function testThePurgeRemovesSpentTokensPastTheirRateLimitHourAndKeepsTheRest(): void
    {
        $pdo = $this->productionEngine();
        $repository = new PasswordResetRepository($pdo);
        $insert = $pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at, used, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $at = static fn(string $when): string => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s');
        $insert->execute([str_repeat('c', 64), 'h', $at('-2 hours'), 1, $at('-3 hours')]);
        $insert->execute([str_repeat('c', 64), 'h', $at('-2 hours'), 0, $at('-3 hours')]);
        $insert->execute([str_repeat('c', 64), 'h', $at('+50 minutes'), 1, $at('-10 minutes')]);
        $insert->execute([str_repeat('c', 64), 'h', $at('+55 minutes'), 0, $at('-5 minutes')]);

        $this->assertSame(2, $repository->deleteExpired());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }
}
