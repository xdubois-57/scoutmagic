<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Review of #1374, on the engine production runs: a revocation stamp never
 * moves back. The writes of `sessions_valid_from` take the later of the old
 * stamp and the new one with a portable `CASE`, whose comparison and
 * assignment of a DATETIME column are what the engine decides — the in-memory
 * SQLite database of UserAccountRepositoryTest cannot show them.
 */
#[Group('database')]
final class UserAccountStampOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private function repository(\PDO $pdo): UserAccountRepository
    {
        return new UserAccountRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
    }

    public function testAStampDatedAheadSurvivesEveryLaterWrite(): void
    {
        $pdo = $this->productionEngine();
        $repo = $this->repository($pdo);
        $account = $repo->create('stamp@test.com');
        $ahead = date('Y-m-d H:i:s', time() + 3600);
        $set = $pdo->prepare('UPDATE user_accounts SET sessions_valid_from = ? WHERE id = ?');
        $set->execute([$ahead, $account->id]);

        $repo->updatePasswordHash($account->id, password_hash('x', PASSWORD_DEFAULT));
        $repo->revokeSessions($account->id);
        $repo->clearPassword($account->id);
        $repo->deactivate($account->id);

        $this->assertSame($ahead, $repo->findById($account->id)?->sessionsValidFrom?->format('Y-m-d H:i:s'));
    }

    public function testAStampInThePastIsReplacedByTheNewOne(): void
    {
        $pdo = $this->productionEngine();
        $repo = $this->repository($pdo);
        $account = $repo->create('past@test.com');
        $set = $pdo->prepare('UPDATE user_accounts SET sessions_valid_from = ? WHERE id = ?');
        $set->execute([date('Y-m-d H:i:s', time() - 3600), $account->id]);

        $repo->updatePasswordHash($account->id, password_hash('x', PASSWORD_DEFAULT));

        $stamp = $repo->findById($account->id)?->sessionsValidFrom;
        $this->assertNotNull($stamp);
        $this->assertEqualsWithDelta(time(), $stamp->getTimestamp(), 5);
    }

    public function testAnAccountThatWasNeverRevokedGetsItsFirstStamp(): void
    {
        $pdo = $this->productionEngine();
        $repo = $this->repository($pdo);
        $account = $repo->create('never@test.com');
        $set = $pdo->prepare('UPDATE user_accounts SET sessions_valid_from = NULL WHERE id = ?');
        $set->execute([$account->id]);

        $repo->revokeSessions($account->id);

        $this->assertNotNull($repo->findById($account->id)?->sessionsValidFrom);
    }
}
