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

    /**
     * Review of #1374, in two processes: another request holds the token's row
     * and rolls back AFTER the link has lapsed. The claim that waited on that
     * lock must be judged by the time it gets the row, not by the time it
     * started waiting — or it consumes an expired link and the password it
     * guards is set after expiry.
     */
    public function testAClaimThatWaitedOnTheRowIsJudgedAtTheTimeItGetsIt(): void
    {
        $pdo = $this->productionEngine();
        $repository = new PasswordResetRepository($pdo);
        $id = $repository->create(str_repeat('f', 64), 'hash', new \DateTimeImmutable('+2 seconds'));

        $other = $this->processHoldingTheRowPastExpiry($pdo, $id);
        try {
            $this->assertFalse(
                $repository->markUsed($id),
                'the link lapsed while this claim waited for the row: it must not be consumed'
            );
        } finally {
            $this->assertSame("ROLLED BACK\n", stream_get_contents($other['out']));
            proc_close($other['process']);
        }
        $this->assertFalse($repository->findById($id)?->used);
    }

    /**
     * A second PHP process locking the token's row, keeping it for four
     * seconds — past the token's two-second life — then rolling back.
     * Returned once it holds the lock, so the caller's next gesture is the one
     * that waits.
     *
     * @return array{process: resource, out: resource}
     */
    private function processHoldingTheRowPastExpiry(\PDO $pdo, int $id): array
    {
        $credentials = self::productionEngineCredentials();
        $code = <<<'PHP'
            require 'vendor/autoload.php';
            [, $host, $port, $database, $user, $password, $id] = $argv;
            $pdo = (new Core\Database\Connection($host, (int) $port, $database, $user, $password))->getPdo();
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM password_reset_tokens WHERE id = ? FOR UPDATE')->execute([(int) $id]);
            fwrite(STDOUT, "LOCKED\n");
            fflush(STDOUT);
            sleep(4);
            $pdo->rollBack();
            fwrite(STDOUT, "ROLLED BACK\n");
            PHP;

        $process = proc_open(
            [
                PHP_BINARY, '-r', $code, '--',
                $credentials['host'], (string) $credentials['port'],
                (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
                $credentials['user'], $credentials['password'], (string) $id,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3)
        );
        $this->assertIsResource($process);

        $line = fgets($pipes[1]);
        if ($line !== "LOCKED\n") {
            $this->fail('the second process did not take the row: ' . $line . stream_get_contents($pipes[2]));
        }

        return ['process' => $process, 'out' => $pipes[1]];
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
