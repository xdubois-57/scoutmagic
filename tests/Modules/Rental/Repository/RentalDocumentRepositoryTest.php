<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Document\DocumentType;
use Modules\Rental\Repository\RentalDocumentRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * A document's source text that cannot be written (`saveText()`): the
 * transaction the method opened is closed behind it, and the caller
 * receives the database's own refusal — never a second error about the
 * clean-up.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalDocumentRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalDocumentRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->repository = new RentalDocumentRepository($this->pdo);

        // The first save inserts; only the second one takes the
        // transaction this class is about.
        $this->assertTrue($this->repository->saveText(1, DocumentType::CONTRACT, '<p>Version 1</p>'));
    }

    /**
     * An update the database refuses rolls back the transaction it opened:
     * the connection is not left inside it, and the text is the one before.
     */
    public function testARefusedUpdateLeavesNoTransactionOpenAndTheTextAsItWas(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_text_update BEFORE UPDATE ON rental_booking_document_texts
             BEGIN SELECT RAISE(ABORT, 'texte refusé'); END"
        );

        try {
            $this->repository->saveText(1, DocumentType::CONTRACT, '<p>Version 2</p>');
            $this->fail('a refused update must reach the caller');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('texte refusé', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction(), 'the transaction saveText() opened is left open');
        $this->assertSame('<p>Version 1</p>', $this->repository->findText(1, DocumentType::CONTRACT));
    }

    /**
     * An engine that already rolled the transaction back itself — a
     * deadlock on MySQL, `RAISE(ROLLBACK)` here — leaves nothing for
     * `rollBack()` to do, and it throws. The caller still receives the
     * refusal that happened, not « There is no active transaction ».
     */
    public function testATransactionTheEngineAlreadyRolledBackStillReportsTheOriginalRefusal(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER abandon_text_update BEFORE UPDATE ON rental_booking_document_texts
             BEGIN SELECT RAISE(ROLLBACK, 'transaction abandonnée par le moteur'); END"
        );

        try {
            $this->repository->saveText(1, DocumentType::CONTRACT, '<p>Version 2</p>');
            $this->fail('a refused update must reach the caller');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('transaction abandonnée par le moteur', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame('<p>Version 1</p>', $this->repository->findText(1, DocumentType::CONTRACT));
    }
}
