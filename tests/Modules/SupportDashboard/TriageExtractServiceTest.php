<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;
use Modules\SupportDashboard\Service\TriageExtractBuilder;
use Modules\SupportDashboard\Service\TriageExtractResult;
use Modules\SupportDashboard\Service\TriageExtractService;
use Modules\SupportDashboard\Service\TriageTokenService;
use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The route the GitHub triage calls (ARCHITECTURE.md §8.49sexies): one
 * credential, one identical refusal, and a reference bound to the first
 * issue that cites it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TriageExtractServiceTest extends TestCase
{
    private \PDO $pdo;
    private TriageExtractService $service;
    private TriageTokenService $tokens;
    private SupportTicketRepository $tickets;
    private EncryptedFileStorageService $storage;
    private string $storagePath;
    private string $token;
    private string $reference;
    private int $ticketId;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $settingRepository = new SettingRepository($this->pdo);
        $settingRepository->insert(
            TriageTokenService::MODULE_ID,
            TriageTokenService::SETTING_KEY,
            '',
            'secret',
            'Jeton',
            'Empreinte.',
            null,
            null,
            false,
            0
        );

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-triage-extract-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);

        $files = new FileRepository($this->pdo);
        $this->storage = new EncryptedFileStorageService($files, $encryption, $this->storagePath);
        $this->tickets = new SupportTicketRepository($this->pdo, $encryption);
        $this->tokens = new TriageTokenService(new SettingService($settingRepository), $journal);
        $this->token = $this->tokens->issue();

        $installationId = (new SupportInstallationRepository($this->pdo))->register(
            'unite-de-test',
            password_hash('secret', PASSWORD_DEFAULT),
            '{}',
            []
        );
        $this->reference = $this->tickets->create(
            $installationId,
            TicketCategory::of('desk_import'),
            "L'import Desk s'arrête à mi-parcours.",
            'chef@unite.be',
            '1.0.41',
            '8.4.0'
        );
        $this->ticketId = (int) $this->tickets->findByReference($this->reference)['id'];

        $this->service = new TriageExtractService(
            $this->tokens,
            $this->tickets,
            new StoredFileReader($files, $this->storage, $this->storagePath),
            new TriageExtractBuilder(),
            $journal
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->storagePath);
    }

    public function testAValidCallIsServedTheExtractAndBindsTheIssue(): void
    {
        $this->attachArchive();

        $result = $this->serve();

        $this->assertTrue($result->accepted, (string) $result->rejectionReason);
        $this->assertSame($this->reference, $result->reference);
        $this->assertStringStartsWith('PK', $result->bytes, 'the extract is a zip');
        $this->assertStringNotContainsString('chef@unite.be', $result->bytes);

        $ticket = $this->tickets->find($this->ticketId);
        $this->assertSame(181, $ticket['github_issue_number']);
        $this->assertNotNull($ticket['github_issue_linked_at']);

        $this->assertSame(
            [['event_type' => 'support_triage_extract_served', 'level' => 'security']],
            $this->journal('support_triage_extract_served')
        );
    }

    public function testTheSameIssueIsServedAgain(): void
    {
        $this->attachArchive();
        $this->serve();

        $this->assertTrue($this->serve()->accepted, 'a re-triage of the same issue asks with the same number');
    }

    public function testAReferenceAlreadyCitedByAnotherIssueIsRefused(): void
    {
        $this->attachArchive();
        $this->serve(issue: 181);

        $result = $this->serve(issue: 182);

        $this->assertFalse($result->accepted);
        $this->assertSame(TriageExtractResult::REJECT_ISSUE_MISMATCH, $result->rejectionReason);
        $this->assertSame(181, $this->tickets->find($this->ticketId)['github_issue_number'], 'never re-pointed');
    }

    public function testAWrongOrMissingTokenIsRejectedBeforeAnythingIsLookedAt(): void
    {
        $this->attachArchive();

        $wrong = $this->serve(token: strrev($this->token));
        $missing = $this->serve(token: '');

        $this->assertSame(TriageExtractResult::REJECT_UNAUTHENTICATED, $wrong->rejectionReason);
        $this->assertSame(TriageExtractResult::REJECT_UNAUTHENTICATED, $missing->rejectionReason);
        $this->assertNull($this->tickets->find($this->ticketId)['github_issue_number']);
        $this->assertCount(2, $this->journal('support_triage_extract_unauthenticated'));
    }

    public function testARevokedTokenIsRejected(): void
    {
        $this->attachArchive();
        $this->tokens->revoke();

        $this->assertSame(TriageExtractResult::REJECT_UNAUTHENTICATED, $this->serve()->rejectionReason);
    }

    public function testCleartextTransportIsRefused(): void
    {
        $this->attachArchive();

        $this->assertSame(TriageExtractResult::REJECT_INSECURE_TRANSPORT, $this->serve(https: false)->rejectionReason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedBodies(): array
    {
        return [
            'empty' => [''],
            'not json' => ['issue 181'],
            'no number' => ['{"github_issue_number":"181"}'],
            'zero' => ['{"github_issue_number":0}'],
            'negative' => ['{"github_issue_number":-3}'],
            'oversized' => ['{"github_issue_number":181,"padding":"' . str_repeat('x', 5000) . '"}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedBodies')]
    public function testABodyThatNamesNoIssueIsRefused(string $body): void
    {
        $this->attachArchive();

        $result = $this->service->serve(
            $this->reference,
            $body,
            'Bearer ' . $this->token,
            '203.0.113.1',
            true,
            new \DateTimeImmutable()
        );

        $this->assertSame(TriageExtractResult::REJECT_MALFORMED, $result->rejectionReason);
    }

    public function testAnUnknownOrMalformedReferenceIsRefused(): void
    {
        $this->attachArchive();

        $this->assertSame(TriageExtractResult::REJECT_UNKNOWN_REFERENCE, $this->serve(reference: 'SUP-ZZZZZZ')->rejectionReason);
        $this->assertSame(TriageExtractResult::REJECT_UNKNOWN_REFERENCE, $this->serve(reference: '../etc/passwd')->rejectionReason);
        $this->assertSame(TriageExtractResult::REJECT_UNKNOWN_REFERENCE, $this->serve(reference: strtolower($this->reference))->rejectionReason);
    }

    public function testATicketWithoutAnArchiveIsRefusedButStillBound(): void
    {
        $result = $this->serve();

        $this->assertSame(TriageExtractResult::REJECT_NO_ARCHIVE, $result->rejectionReason);
        // The link is the reporter's claim on their own reference, and
        // it holds whether or not an archive ever arrived.
        $this->assertSame(181, $this->tickets->find($this->ticketId)['github_issue_number']);
    }

    public function testAPurgedArchiveIsRefusedTheSameWay(): void
    {
        $fileId = $this->attachArchive();
        $this->storage->delete($fileId);
        $this->tickets->detachArchive($this->ticketId);

        $this->assertSame(TriageExtractResult::REJECT_NO_ARCHIVE, $this->serve()->rejectionReason);
    }

    public function testTheJournalNamesTheReferenceAndTheIssueAndNothingOfTheContent(): void
    {
        $this->attachArchive();
        $this->serve();

        $rows = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'support_triage_extract_served'"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(1, $rows);
        $context = json_decode((string) $rows[0], true);
        $this->assertSame($this->reference, $context['ticket_reference']);
        $this->assertSame(181, $context['github_issue_number']);
        $this->assertArrayHasKey('bytes', $context);
        $this->assertStringNotContainsString('203.0.113.7', (string) $rows[0]);
        $this->assertStringNotContainsString('chef@unite.be', (string) $rows[0]);
    }

    private function serve(
        ?string $token = null,
        int $issue = 181,
        ?string $reference = null,
        bool $https = true
    ): TriageExtractResult {
        $token ??= $this->token;

        return $this->service->serve(
            $reference ?? $this->reference,
            (string) json_encode(['github_issue_number' => $issue]),
            $token === '' ? '' : 'Bearer ' . $token,
            '203.0.113.1',
            $https,
            new \DateTimeImmutable('2026-09-06 10:00:00')
        );
    }

    private function attachArchive(): int
    {
        $path = tempnam(sys_get_temp_dir(), 'sm-triage-svc-');
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('logs/error.log', "[06-Sep-2026] PHP Fatal error at 203.0.113.7\n");
        $zip->addFromString('statistics.json', '{}');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $fileId = $this->storage->store($bytes, 'application/zip', 'support.zip', 'support-tickets', 'superadmin');
        $this->tickets->attachArchive($this->ticketId, $fileId);

        return $fileId;
    }

    /**
     * @return list<array{event_type: string, level: string}>
     */
    private function journal(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT event_type, level FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute([$type]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
