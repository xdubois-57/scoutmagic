<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Core\Security\EncryptionService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The recompute of a field's places takes the row lock a submission's
 * increment takes, on the engine that has it (issue #1465, #1546 review):
 * `FOR UPDATE` is emitted on MySQL/MariaDB only, so SQLite never shows
 * that an answer in flight while the editor sets a cap is serialised
 * against the recompute rather than lost between the two.
 */
#[Group('database')]
final class FormFieldRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $fieldId;
    private FormResponseRepository $responses;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', str_repeat('a', 64)]);
        $authorId = (int) $this->pdo->lastInsertId();
        $articleId = (new ArticleRepository($this->pdo))
            ->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
        $formId = (new FormRepository($this->pdo))
            ->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $this->fieldId = (new FormFieldRepository($this->pdo))
            ->create($formId, 0, FormField::TYPE_NUMBER, 'Places', false, null, null, null, null, null);
        $this->responses = new FormResponseRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->responses->create($formId, null, null, 'a@test.com', [$this->fieldId => '30'], null, null);
    }

    public function testTheRecomputeWaitsForASubmissionHoldingTheFieldRowAndThenCountsFromTheAnswers(): void
    {
        $other = self::productionEngineConnection((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn())->getPdo();
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        // What a submission does first: lock the field's row.
        (new FormFieldRepository($other))->usedCapacity($this->fieldId, lockForUpdate: true);

        $repository = new FormFieldRepository($this->pdo);
        // Whether the answers were counted while the row was held: a
        // count read before the lock misses an answer the submission has
        // not committed yet, and its absolute write would then erase that
        // submission's increment — the lock must come FIRST.
        $counting = new class ($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))) extends FormResponseRepository {
            public bool $counted = false;

            public function sumFieldValues(FormField $field, ?int $excludeResponseId = null, bool $lockForUpdate = false): float
            {
                $this->counted = true;

                return parent::sumFieldValues($field, $excludeResponseId, $lockForUpdate);
            }
        };
        try {
            $repository->recomputeUsedCapacity($this->fieldId, $counting);
            $this->fail('The recompute did not wait for the row a submission holds.');
        } catch (\PDOException $e) {
            // Lock wait timeout: the recompute asked for the very lock the
            // submission holds, and wrote nothing meanwhile.
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->rollBack();
        }
        $this->assertFalse($counting->counted, 'the answers were counted before the field row was locked');
        $this->assertFalse($this->pdo->inTransaction(), 'its own transaction is rolled back');

        $this->assertSame(30.0, $repository->recomputeUsedCapacity($this->fieldId, $this->responses));
        $this->assertSame(30.0, $repository->usedCapacity($this->fieldId));
    }
}
