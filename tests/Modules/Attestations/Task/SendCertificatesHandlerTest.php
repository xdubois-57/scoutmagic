<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Service\BatchDistributionService;
use Modules\Attestations\Task\SendCertificatesHandler;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\DeliveryState;
use Modules\Attestations\Value\MatchState;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * The scheduled half of the send.
 *
 * What matters here is the chain: one slice, then a successor carrying the
 * same batch — a task that wakes up without its batch id is a distribution
 * that stops halfway, with half the families told and half not.
 *
 * The transport is a recorder, so the real `MailService` and the real
 * `MemberDocumentMailer` are exercised (the attachment is really built) and
 * nothing reaches the network.
 */
#[Group('database')]
class SendCertificatesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private EncryptionService $encryption;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private EncryptedFileStorageService $fileStorage;
    private int $scoutYearId;

    /** A transport that records instead of delivering. */
    private object $transport;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->storageRoot = AttestationsTestHelper::createStorageRoot();
        mkdir($this->storageRoot . '/keys', 0755, true);
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);

        $this->encryption = AttestationsTestHelper::encryption();
        $connection = Connection::withPdo($this->pdo);
        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $this->encryption);
        $this->fileStorage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $this->encryption,
            $this->storageRoot
        );

        $this->transport = new class implements MailTransportInterface {
            /** @var list<PHPMailer> */
            public array $delivered = [];

            public function deliver(PHPMailer $mail): void
            {
                $this->delivered[] = $mail;
            }
        };
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    private function context(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            new MailService(
                'local',
                'unite@exemple.be',
                'Unité',
                'EX',
                new DkimManager($this->storageRoot . '/keys'),
                's1',
                transport: $this->transport
            ),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->storageRoot
        );
    }

    /**
     * A published batch whose lines all await delivery, with one member
     * (and one address) per line.
     */
    private function seedPublishedBatch(int $lineCount): int
    {
        $batchId = $this->batches->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            $lineCount * 2,
            2,
            $lineCount,
            1
        );

        for ($position = 1; $position <= $lineCount; $position++) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['D' . $position]);
            $memberId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId,
                $this->scoutYearId,
                $this->encryption->encrypt('Prenom' . $position, 'member_years.first_name'),
                $this->encryption->encrypt('NOM' . $position, 'member_years.last_name'),
                $this->encryption->encrypt('famille' . $position . '@example.org', 'member_years.email'),
            ]);

            $fileId = $this->fileStorage->store(
                '%PDF-1.4 fake',
                'application/pdf',
                'attestation.pdf',
                'attestations/documents',
                'admin',
                'attestations',
                null,
                $memberId
            );

            $this->lines->create(
                $batchId,
                $position,
                $position * 2 - 1,
                $position * 2,
                'NOM' . $position . ' Prenom' . $position,
                $memberId,
                MatchState::Matched,
                $fileId
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE attestation_batches SET status = ?, published_at = ?, distribution_started_at = ? WHERE id = ?'
        );
        $stmt->execute([
            BatchStatus::Published->value,
            '2026-02-11 09:00:00',
            '2026-02-11 09:05:00',
            $batchId,
        ]);

        return $batchId;
    }

    /** @return list<array<string, mixed>> */
    private function pendingTasks(): array
    {
        $rows = $this->pdo
            ->query("SELECT * FROM scheduled_actions WHERE status = 'pending'")
            ->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function testTheHandlerSendsThroughTheRealMailerWithTheCertificateAttached(): void
    {
        $batchId = $this->seedPublishedBatch(1);

        (new SendCertificatesHandler())->handle(['batch_id' => $batchId], $this->context());

        $this->assertCount(1, $this->transport->delivered);
        $mail = $this->transport->delivered[0];
        $this->assertSame('famille1@example.org', $mail->getToAddresses()[0][0]);
        // The site's short name prefixes every outgoing subject.
        $this->assertStringEndsWith('Attestation fiscale 2025', $mail->Subject);
        $this->assertCount(1, $mail->getAttachments());
    }

    /**
     * The successor carries the batch id. Without it the next slice wakes
     * up, finds nothing to do, and the families in the second half are
     * never told.
     */
    public function testAFullSliceReArmsItselfCarryingTheBatch(): void
    {
        $batchId = $this->seedPublishedBatch(BatchDistributionService::SLICE_SIZE + 3);

        (new SendCertificatesHandler())->handle(['batch_id' => $batchId], $this->context());

        $tasks = $this->pendingTasks();
        $this->assertCount(1, $tasks);
        $this->assertSame('attestations', $tasks[0]['module_id']);
        $this->assertSame('send_batch', $tasks[0]['task_key']);
        $this->assertSame((string) $batchId, (string) $tasks[0]['reference']);
        $this->assertSame(
            ['batch_id' => $batchId],
            json_decode((string) $tasks[0]['payload'], true)
        );
    }

    /** One slice is one slice: the rest waits for the successor. */
    public function testOnlyOneSliceGoesOutPerRun(): void
    {
        $batchId = $this->seedPublishedBatch(BatchDistributionService::SLICE_SIZE + 3);

        (new SendCertificatesHandler())->handle(['batch_id' => $batchId], $this->context());

        $this->assertCount(BatchDistributionService::SLICE_SIZE, $this->transport->delivered);
        $counts = $this->lines->countByDeliveryState($batchId);
        $this->assertSame(3, $counts[DeliveryState::Pending->value] ?? 0);
    }

    /**
     * The run that finds nothing left closes the batch instead of arming a
     * successor — a chain that re-arms itself on an empty slice never ends.
     */
    public function testTheChainStopsWhenNothingIsLeftToSend(): void
    {
        $batchId = $this->seedPublishedBatch(2);
        $handler = new SendCertificatesHandler();

        $handler->handle(['batch_id' => $batchId], $this->context());
        // Stand in for the scheduler having run the successor.
        $this->pdo->exec("UPDATE scheduled_actions SET status = 'done'");

        $handler->handle(['batch_id' => $batchId], $this->context());

        $this->assertSame([], $this->pendingTasks());
        $batch = $this->batches->findById($batchId);
        $this->assertNotNull($batch);
        $this->assertNotNull($batch->notifiedAt);
    }

    /**
     * A payload that lost its batch id is a bug elsewhere, not a crash
     * here: the task ends quietly rather than taking the scheduler tick
     * down with it.
     */
    public function testAPayloadWithoutABatchIdDoesNothing(): void
    {
        $this->seedPublishedBatch(1);

        (new SendCertificatesHandler())->handle([], $this->context());

        $this->assertSame([], $this->transport->delivered);
        $this->assertSame([], $this->pendingTasks());
    }

    /** The message signs off with the unit's own name, not a placeholder. */
    public function testTheMessageSignsOffWithTheSiteName(): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (NULL, ?, ?, ?, ?, ?)'
        )->execute(['site_name', '25e Saint-Vincent', 'text', 'Nom du site', '']);
        $batchId = $this->seedPublishedBatch(1);

        (new SendCertificatesHandler())->handle(['batch_id' => $batchId], $this->context());

        $this->assertStringContainsString('25e Saint-Vincent', $this->transport->delivered[0]->Body);
    }
}
