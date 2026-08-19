<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ConfigDangerController;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The config page's two destructive actions. They had no test file at all,
 * despite being the only endpoints in the module that delete movements
 * outright.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ConfigDangerControllerTest extends TestCase
{
    private \PDO $pdo;
    private ConfigDangerController $controller;
    private TransactionRepository $transactionRepository;
    private BalanceCheckpointRepository $checkpointRepository;
    private AttachmentRepository $attachmentRepository;
    private TransactionAttachmentRepository $associations;
    private int $accountId;
    private int $otherAccountId;
    private int $fiscalYearId;
    private int $fileId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $this->attachmentRepository = new AttachmentRepository($this->pdo, $encryption);
        $this->associations = new TransactionAttachmentRepository($this->pdo);

        $this->controller = new ConfigDangerController(
            new Environment(new ArrayLoader([])),
            $this->transactionRepository,
            $this->checkpointRepository,
            $this->attachmentRepository,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Autre', 'bank')");
        $this->otherAccountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id) VALUES ('p/f.pdf', 'f.pdf', 'application/pdf', 1, 'intendant', 'finance')");
        $this->fileId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testDeleteMovementsRemovesOnlyTheTargetAccountsData(): void
    {
        $this->createTransaction($this->accountId);
        $this->createTransaction($this->accountId);
        $keptId = $this->createTransaction($this->otherAccountId);
        $this->checkpointRepository->create($this->accountId, '2026-10-01', 100.0, BalanceCheckpoint::SOURCE_IMPORT);
        $this->checkpointRepository->create($this->otherAccountId, '2026-10-01', 250.0, BalanceCheckpoint::SOURCE_IMPORT);

        $response = $this->controller->execute($this->jsonRequest([
            'action' => 'delete_movements',
            'account_id' => $this->accountId,
            'confirmation_text' => 'SUPPRIMER',
        ]), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->transactionRepository->findByAccountId($this->accountId));
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->otherAccountId));
        $this->assertNotNull($this->transactionRepository->findById($keptId));
        $this->assertFalse($this->checkpointRepository->hasAnyForAccount($this->accountId));
        $this->assertTrue($this->checkpointRepository->hasAnyForAccount($this->otherAccountId));
    }

    /**
     * @dataProvider wrongConfirmations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongConfirmations')]
    public function testDeleteMovementsRefusesAWrongConfirmationText(string $confirmation): void
    {
        $this->createTransaction($this->accountId);

        $response = $this->controller->execute($this->jsonRequest([
            'action' => 'delete_movements',
            'account_id' => $this->accountId,
            'confirmation_text' => $confirmation,
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->accountId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrongConfirmations(): array
    {
        return [
            'empty' => [''],
            'lowercase' => ['supprimer'],
            'padded' => [' SUPPRIMER '],
            'something else' => ['OUI'],
        ];
    }

    public function testDeleteMovementsRequiresAnAccount(): void
    {
        $this->createTransaction($this->accountId);

        $response = $this->controller->execute($this->jsonRequest([
            'action' => 'delete_movements',
            'confirmation_text' => 'SUPPRIMER',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->accountId));
    }

    public function testDeleteReceiptsArchivesThemAndDropsTheirAssociations(): void
    {
        $transactionId = $this->createTransaction($this->accountId);
        $attachmentId = $this->attachmentRepository->create($this->accountId, $this->fileId, 'application/pdf', 'r.pdf', null, null, null, null);
        $this->associations->associate($transactionId, $attachmentId);

        $response = $this->controller->execute($this->jsonRequest([
            'action' => 'delete_receipts',
            'confirmation_text' => 'SUPPRIMER',
        ]), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('archived', $this->attachmentRepository->findById($attachmentId)->status);
        $this->assertSame([], $this->associations->findAttachmentIdsForTransaction($transactionId));
        // Archived, never physically deleted — proof of an expense survives.
        $this->assertNotNull($this->attachmentRepository->findById($attachmentId));
    }

    public function testDeleteReceiptsLeavesMovementsAlone(): void
    {
        $transactionId = $this->createTransaction($this->accountId);

        $this->controller->execute($this->jsonRequest([
            'action' => 'delete_receipts',
            'confirmation_text' => 'SUPPRIMER',
        ]), []);

        $this->assertNotNull($this->transactionRepository->findById($transactionId));
    }

    public function testAnUnknownActionIsRejected(): void
    {
        $response = $this->controller->execute($this->jsonRequest([
            'action' => 'drop_everything',
            'confirmation_text' => 'SUPPRIMER',
        ]), []);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAnInvalidCsrfTokenIsRejectedBeforeAnythingElse(): void
    {
        $this->createTransaction($this->accountId);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/danger', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode([
            'action' => 'delete_movements',
            'account_id' => $this->accountId,
            'confirmation_text' => 'SUPPRIMER',
            '_csrf_token' => str_repeat('f', 64),
        ]));

        $response = $this->controller->execute($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertCount(1, $this->transactionRepository->findByAccountId($this->accountId));
    }

    public function testAMalformedBodyIsRejected(): void
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/danger', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn('not json');

        $this->assertSame(400, $this->controller->execute($request, [])->getStatusCode());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $data['_csrf_token'] = $token;

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/config/finance/danger', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode($data));
        return $request;
    }

    private function createTransaction(int $accountId): int
    {
        return $this->transactionRepository->create(
            $accountId, $this->fiscalYearId, null, '2026-10-01', 'Mouvement', -10.0, null, null, 'manual', null
        );
    }
}
