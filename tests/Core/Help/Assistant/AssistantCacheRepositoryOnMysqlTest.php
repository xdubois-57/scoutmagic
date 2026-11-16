<?php

declare(strict_types=1);

namespace Tests\Core\Help\Assistant;

use Core\Help\Assistant\AssistantCacheRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The cache's write race, against the engine an installation runs.
 *
 * `store()` tells « another request cached this question first » from a
 * real failure by the SQLSTATE of the refusal alone. That code is the
 * engine's to decide, and SQLite reports a trigger's refusal under the
 * same 23000 as a duplicate key, so the distinction is only honest here:
 * a duplicate key is 23000 on MariaDB and MySQL, a `SIGNAL` is 45000.
 */
#[Group('database')]
final class AssistantCacheRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private AssistantCacheRepository $cache;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->cache = new AssistantCacheRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS refuse_cache');
        }
    }

    /** Two people asking the same question at once: the second write is a no-op, the first answer stays. */
    public function testASecondWriteForTheSameQuestionIsANoOpAndKeepsTheFirstAnswer(): void
    {
        $this->cache->store('empreinte', 'Première réponse.', ['a']);
        $this->cache->store('empreinte', 'Seconde réponse.', ['b']);

        $this->assertSame(['answer' => 'Première réponse.', 'topic_ids' => ['a']], $this->cache->find('empreinte'));
        $count = $this->pdo->query('SELECT COUNT(*) FROM help_assistant_cache')->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    /** Any other refusal is a real failure, and it belongs to the caller. */
    public function testAnyOtherRefusalReachesTheCaller(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_cache BEFORE INSERT ON help_assistant_cache FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'cache refused'"
        );

        try {
            $this->cache->store('empreinte', 'Réponse.', []);
            $this->fail('A refusal that is not a duplicate must not be swallowed.');
        } catch (\PDOException $e) {
            $this->assertSame('45000', $e->getCode());
        }

        $this->assertNull($this->cache->find('empreinte'));
    }
}
