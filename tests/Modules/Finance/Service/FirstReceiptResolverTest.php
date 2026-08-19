<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Service\FirstReceiptResolver;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The bulk "first receipt per movement" lookup behind every
 * Service\MovementPresenter call site. It had no test file of its own.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FirstReceiptResolverTest extends TestCase
{
    private \PDO $pdo;
    private TransactionAttachmentRepository $associations;
    private AttachmentRepository $attachmentRepository;
    private FirstReceiptResolver $resolver;
    private int $accountId;
    private int $fiscalYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->associations = new TransactionAttachmentRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->resolver = new FirstReceiptResolver($this->associations, $this->attachmentRepository);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id) VALUES ('p/f.pdf', 'f.pdf', 'application/pdf', 1, 'intendant', 'finance')");
        $this->fileId = (int) $this->pdo->lastInsertId();
    }

    public function testResolveReturnsEmptyForNoMovements(): void
    {
        $this->assertSame([], $this->resolver->resolve([]));
    }

    public function testResolveReturnsEmptyWhenNoMovementHasAReceipt(): void
    {
        $this->assertSame([], $this->resolver->resolve([$this->createTransaction(), $this->createTransaction()]));
    }

    public function testResolveReturnsTheLowestIdReceiptPerMovement(): void
    {
        $movementId = $this->createTransaction();
        $first = $this->createAttachment('premier.pdf');
        $second = $this->createAttachment('second.pdf');
        $this->associations->associate($movementId, $second);
        $this->associations->associate($movementId, $first);

        $resolved = $this->resolver->resolve([$movementId]);

        $this->assertArrayHasKey($movementId, $resolved);
        $this->assertSame($first, $resolved[$movementId]->id);
        $this->assertSame('premier.pdf', $resolved[$movementId]->originalFilename);
    }

    public function testResolveHandlesSeveralMovementsAtOnceAndSkipsTheBareOnes(): void
    {
        $withReceipt = $this->createTransaction();
        $alsoWithReceipt = $this->createTransaction();
        $withoutReceipt = $this->createTransaction();
        $a1 = $this->createAttachment('a.pdf');
        $a2 = $this->createAttachment('b.pdf');
        $this->associations->associate($withReceipt, $a1);
        $this->associations->associate($alsoWithReceipt, $a2);

        $resolved = $this->resolver->resolve([$withReceipt, $alsoWithReceipt, $withoutReceipt]);

        $this->assertSame($a1, $resolved[$withReceipt]->id);
        $this->assertSame($a2, $resolved[$alsoWithReceipt]->id);
        $this->assertArrayNotHasKey($withoutReceipt, $resolved);
    }

    /**
     * An archived receipt still resolves — the movements page shows the
     * proof that was attached, whatever its current status.
     */
    public function testResolveStillReturnsAnArchivedReceipt(): void
    {
        $movementId = $this->createTransaction();
        $attachmentId = $this->createAttachment('archive.pdf');
        $this->associations->associate($movementId, $attachmentId);
        $this->attachmentRepository->archive($attachmentId);

        $resolved = $this->resolver->resolve([$movementId]);

        $this->assertSame($attachmentId, $resolved[$movementId]->id);
    }

    private function createTransaction(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_transactions (account_id, fiscal_year_id, transaction_date, label, amount, source)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->accountId, $this->fiscalYearId, '2026-10-01', 'x', -10.0, 'manual']);
        return (int) $this->pdo->lastInsertId();
    }

    private function createAttachment(string $filename): int
    {
        return $this->attachmentRepository->create(
            $this->accountId, $this->fileId, 'application/pdf', $filename, null, null, null, null
        );
    }
}
