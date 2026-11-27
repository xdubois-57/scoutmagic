<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Core\Security\EncryptionService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * `FormResponseRepository::lockReceivableLink()` (issue #1525), on the
 * engine whose snapshots it reads past.
 *
 * An edit decides whether to raise a receivable from that link. Inside the
 * edit's REPEATABLE READ transaction a plain read returns the snapshot of
 * its first read, and a competing edit's committed link is invisible: two
 * receivables would be raised for one response. The `FOR UPDATE` read
 * sees the latest committed row, and holds it. SQLite has neither.
 */
#[Group('database')]
final class FormResponseRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private FormResponseRepository $responses;
    private int $responseId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responses = new FormResponseRepository($this->pdo, $encryption);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', str_repeat('a', 64)]);
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = (new ArticleRepository($this->pdo))->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $authorId);
        $formId = (new FormRepository($this->pdo))->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null
        );
        $this->responseId = $this->responses->create($formId, null, null, 'a@test.com', [], null, null);
    }

    /** The premise: were this SQLite, there would be no snapshot to read past. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testTheLinkACompetingEditCommittedIsSeenInsideAnOlderSnapshot(): void
    {
        $this->pdo->beginTransaction();
        try {
            // The edit's first read fixes its snapshot.
            $this->assertNull($this->responses->findById($this->responseId)?->receivableId);

            $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $other = self::productionEngineConnection($database)->getPdo();
            $other->prepare('UPDATE news_form_responses SET receivable_id = 77, structured_communication = ? WHERE id = ?')
                ->execute(['+++090/9337/55493+++', $this->responseId]);

            // The premise of the fix: a plain read still answers the snapshot.
            $this->assertNull(
                $this->responses->findById($this->responseId)?->receivableId,
                'this engine shows the edit a snapshot, which the locking read must see past'
            );

            $this->assertSame(
                ['receivable_id' => 77, 'structured_communication' => '+++090/9337/55493+++'],
                $this->responses->lockReceivableLink($this->responseId)
            );
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testTheRowStaysHeldUntilTheEditEnds(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->responses->lockReceivableLink($this->responseId);

            $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $other = self::productionEngineConnection($database)->getPdo();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = false;
            try {
                $other->beginTransaction();
                $other->prepare('SELECT id FROM news_form_responses WHERE id = ? FOR UPDATE')->execute([$this->responseId]);
            } catch (\PDOException $e) {
                $blocked = str_contains(strtolower($e->getMessage()), 'lock');
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }

            $this->assertTrue($blocked, 'a second edit can still decide on the link at the same time');
        } finally {
            $this->pdo->rollBack();
        }
    }
}
