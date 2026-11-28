<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupMemberRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: carryModeratorBinding() reads the
 * two memberships FOR UPDATE inside the merge's transaction, so a
 * revocation of the absorbed identity's grant cannot commit between the
 * read and the copy — it waits for the merge. Shown with a second
 * connection whose revocation has to wait.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ModeratorBindingMergeLockOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testARevocationWaitsForTheMergeThatReadTheGrant(): void
    {
        $pdo = $this->productionEngine();
        $pdo->exec("INSERT INTO members (id, desk_id) VALUES (10, 'ML-KEPT'), (20, 'ML-MERGED'), (30, 'ML-OTHER')");
        // The locks are what is under test; the group and the accounts the
        // memberships hang from are not, so the keys those would need are
        // not checked on this connection.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec(
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator, moderator_user_account_id)
             VALUES (1, 10, 1, NULL), (1, 20, 1, 7), (1, 30, 1, 8)'
        );

        $other = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $pdo->beginTransaction();
        try {
            $this->assertSame(1, (new GroupMemberRepository($pdo))->carryModeratorBinding(10, 20));

            try {
                (new GroupMemberRepository($other))->demoteUnlessLastModerator(1, 20);
                $this->fail('the grant the merge read was revoked while the merge still held it');
            } catch (\PDOException $e) {
                $this->assertSame('HY000', $e->getCode());
                $this->assertStringContainsString('1205', $e->getMessage(), 'a lock wait, nothing else');
            }
        } finally {
            $pdo->rollBack();
        }
    }
}
