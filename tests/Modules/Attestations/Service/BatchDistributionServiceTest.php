<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Service;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberDocumentMailer;
use Core\Member\MemberEmailRepository;
use Core\Import\MemberYearRepository;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\BatchDistributionService;
use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;
use Modules\Attestations\Value\DeliveryState;
use Modules\Attestations\Value\MatchState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;

/**
 * Sending a published batch out, one slice at a time.
 *
 * The mailer is replaced by a recorder — what is under test is which
 * address each certificate went to, what happens to the ones that cannot be
 * sent, and the fact that a failure is never retried. `MailService` itself
 * is exercised by its own tests; putting a real SMTP round trip here would
 * test the network.
 */
#[Group('database')]
class BatchDistributionServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storageRoot;
    private BatchRepository $batches;
    private BatchLineRepository $lines;
    private RecordingDocumentMailer $mailer;
    private BatchDistributionService $service;
    private int $scoutYearId;
    private int $batchId;

    /** @var array<string, int> */
    private array $memberIds = [];

    /** @var array<string, int> */
    private array $lineIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->storageRoot = AttestationsTestHelper::createStorageRoot();
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $files = new FileRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService($files, $encryption, $this->storageRoot);

        $this->batches = new BatchRepository($connection);
        $this->lines = new BatchLineRepository($connection, $encryption);
        $this->mailer = new RecordingDocumentMailer($fileStorage, $this->storageRoot);

        $this->service = new BatchDistributionService(
            $this->batches,
            $this->lines,
            new MemberNameRepository($connection, $encryption),
            $this->mailer,
            new MemberAccountResolver(
                new MemberYearRepository($this->pdo),
                new MemberEmailRepository($this->pdo, $encryption),
                new \Core\Security\UserAccountRepository($this->pdo, $encryption),
                $encryption
            ),
            new JournalService(new JournalRepository($this->pdo)),
            null
        );

        $this->seedPublishedBatch($fileStorage, $encryption);
    }

    protected function tearDown(): void
    {
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    /**
     * Three published certificates: two members with an address, and one
     * the site holds no address for.
     */
    private function seedPublishedBatch(
        EncryptedFileStorageService $fileStorage,
        \Core\Security\EncryptionService $encryption
    ): void {
        foreach ([
            ['margaux', 'Margaux', 'Vandenbrande', 'famille.vandenbrande@example.org'],
            ['sacha', 'Sacha', 'Meunier', 'meunier@example.org'],
            ['sans', 'Camille', 'Delacroix', null],
        ] as [$key, $first, $last, $email]) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute(['D' . $key]);
            $memberId = (int) $this->pdo->lastInsertId();
            $this->memberIds[$key] = $memberId;

            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years
                 (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId,
                $this->scoutYearId,
                $encryption->encrypt($first, 'member_years.first_name'),
                $encryption->encrypt($last, 'member_years.last_name'),
                $email === null ? null : $encryption->encrypt($email, 'member_years.email'),
                $email === null ? null : $encryption->blindIndex(strtolower($email), 'email'),
            ]);
        }

        $this->batchId = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Tax, 'Attestation fiscale 2025', 6, 2, 3, 1
        );

        $position = 0;
        foreach (['margaux', 'sacha', 'sans'] as $key) {
            $position++;
            $fileId = $fileStorage->store(
                '%PDF-1.4 fake',
                'application/pdf',
                'attestation.pdf',
                'attestations/documents',
                'admin',
                'attestations',
                null,
                $this->memberIds[$key]
            );
            $this->lineIds[$key] = $this->lines->create(
                $this->batchId,
                $position,
                $position * 2 - 1,
                $position * 2,
                'NOM Prenom',
                $this->memberIds[$key],
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
            $this->batchId,
        ]);
    }

    public function testEachCertificateGoesToItsOwnFamilysAddress(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $this->assertSame(
            [
                'famille.vandenbrande@example.org' => 'Attestation fiscale 2025',
                'meunier@example.org' => 'Attestation fiscale 2025',
            ],
            $this->mailer->sentByAddress
        );
    }

    /** Each family receives ITS certificate, never a neighbour's. */
    public function testEachFamilyGetsItsOwnCertificate(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $margaux = $this->lines->findById($this->lineIds['margaux']);
        $sacha = $this->lines->findById($this->lineIds['sacha']);
        $this->assertNotNull($margaux);
        $this->assertNotNull($sacha);

        $this->assertSame($margaux->fileId, $this->mailer->fileIdByAddress['famille.vandenbrande@example.org']);
        $this->assertSame($sacha->fileId, $this->mailer->fileIdByAddress['meunier@example.org']);
    }

    public function testASentLineIsStampedAndSettled(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $line = $this->lines->findById($this->lineIds['margaux']);
        $this->assertNotNull($line);
        $this->assertSame(DeliveryState::Sent, $line->deliveryState);
        $this->assertNotNull($line->sentAt);
    }

    /**
     * A member the site holds no address for is settled rather than left
     * pending: a line nothing can be done about must not make the slice
     * loop for ever. The screen counts it and says what it means.
     */
    public function testAMemberWithNoAddressIsRecordedRatherThanRetriedForEver(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $line = $this->lines->findById($this->lineIds['sans']);
        $this->assertNotNull($line);
        $this->assertSame(DeliveryState::NoAddress, $line->deliveryState);
        $this->assertNull($line->sentAt);
        $this->assertTrue($line->deliveryState->isSettled());
    }

    /**
     * A transport failure cannot tell « never left » from « left, and then
     * the connection dropped », and a certificate delivered twice is worse
     * than one delivered once. The line is marked and never tried again.
     */
    public function testARefusedSendIsRecordedAndNeverRetried(): void
    {
        $this->mailer->failFor = 'meunier@example.org';

        $this->service->sendSlice($this->batchId, 'Unité de test');

        $line = $this->lines->findById($this->lineIds['sacha']);
        $this->assertNotNull($line);
        $this->assertSame(DeliveryState::Failed, $line->deliveryState);

        // A second slice finds nothing left to attempt.
        $this->mailer->sentByAddress = [];
        $this->service->sendSlice($this->batchId, 'Unité de test');
        $this->assertSame([], $this->mailer->sentByAddress);
    }

    public function testTheSliceReportsWhetherWorkRemains(): void
    {
        $this->assertTrue($this->service->sendSlice($this->batchId, 'Unité de test'));
        // Everything is settled now, so the next call finishes the batch.
        $this->assertFalse($this->service->sendSlice($this->batchId, 'Unité de test'));
    }

    public function testTheBatchIsMarkedNotifiedOnceEveryLineIsSettled(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $batch = $this->batches->findById($this->batchId);
        $this->assertNotNull($batch);
        $this->assertNotNull($batch->notifiedAt);
        $this->assertFalse($batch->isDistributing());
    }

    public function testANotifiedBatchIsNeverSentAgain(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $this->mailer->sentByAddress = [];
        $this->assertFalse($this->service->sendSlice($this->batchId, 'Unité de test'));
        $this->assertSame([], $this->mailer->sentByAddress);
    }

    public function testABatchThatWasNeverPublishedSendsNothing(): void
    {
        $draft = $this->batches->create(
            $this->scoutYearId, AttestationCategory::Other, 'Brouillon', 2, 2, 1, null
        );

        $this->assertFalse($this->service->sendSlice($draft, 'Unité de test'));
        $this->assertSame([], $this->mailer->sentByAddress);
    }

    /**
     * **One notification per account, never one per document.** A parent of
     * three children would otherwise get three in a row, which is exactly
     * how people learn to swipe this kind of message away unread.
     */
    public function testTwoChildrenBehindOneAccountProduceOneNotification(): void
    {
        $encryption = AttestationsTestHelper::encryption();
        $shared = 'parent@example.org';

        // Both children reachable at the same family address, and one login
        // behind it.
        $this->pdo->prepare('UPDATE member_years SET email_encrypted = ?, email_blind_index = ? WHERE member_id IN (?, ?)')
            ->execute([
                $encryption->encrypt($shared, 'member_years.email'),
                $encryption->blindIndex($shared, 'email'),
                $this->memberIds['margaux'],
                $this->memberIds['sacha'],
            ]);
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $encryption);
        $accounts->create($shared);

        $service = $this->serviceWithNotifications();
        $service->sendSlice($this->batchId, 'Unité de test');
        $service->sendSlice($this->batchId, 'Unité de test');

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()
        );
    }

    /** The push lands on a lock screen, so it names nobody. */
    public function testTheNotificationCarriesTheBatchLabelAndNoName(): void
    {
        $encryption = AttestationsTestHelper::encryption();
        (new \Core\Security\UserAccountRepository($this->pdo, $encryption))
            ->create('famille.vandenbrande@example.org');

        $service = $this->serviceWithNotifications();
        $service->sendSlice($this->batchId, 'Unité de test');
        $service->sendSlice($this->batchId, 'Unité de test');

        $row = $this->pdo->query('SELECT * FROM notifications')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('attestations.published', $row['type_id']);

        $body = $encryption->decrypt((string) $row['body'], 'notifications.body');
        $this->assertSame('Attestation fiscale 2025', $body);
        $this->assertStringNotContainsString('Margaux', $body);
    }

    /**
     * The same service, wired to a real notification service — the dispatch
     * path is what these two tests are about, so it is not a double.
     */
    private function serviceWithNotifications(): BatchDistributionService
    {
        $encryption = AttestationsTestHelper::encryption();
        $connection = Connection::withPdo($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $encryption);

        $notifications = new \Core\Notification\NotificationService(
            new \Core\Notification\NotificationRepository($this->pdo, $encryption),
            new \Core\Notification\PushSubscriptionRepository($this->pdo, $encryption),
            new \Core\Notification\NotificationPreferenceRepository($this->pdo),
            null,
            $settings,
            $journal,
            \Core\Scheduler\SchedulerService::forPdo($this->pdo),
            $accounts
        );
        $notifications->registerModuleTypes('attestations', [[
            'id' => BatchDistributionService::NOTIFICATION_TYPE,
            'label' => 'Attestation disponible',
            'description' => 'Un document nominatif vous a été envoyé.',
            'group' => 'Attestations',
            'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off'],
        ]]);

        return new BatchDistributionService(
            $this->batches,
            $this->lines,
            new MemberNameRepository($connection, $encryption),
            $this->mailer,
            new MemberAccountResolver(
                new MemberYearRepository($this->pdo),
                new MemberEmailRepository($this->pdo, $encryption),
                $accounts,
                $encryption
            ),
            $journal,
            $notifications
        );
    }

    /** Counts and identifiers only — never an address, never a name. */
    public function testTheJournalNamesNobody(): void
    {
        $this->service->sendSlice($this->batchId, 'Unité de test');

        $rows = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'attestation_slice_sent'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('2 envoyée(s), 1 sans adresse', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('@', (string) $rows[0]['description']);
        $this->assertStringNotContainsString('@', (string) $rows[0]['context']);
    }
}

/**
 * A mailer that records rather than sends. It extends the real one so the
 * production constructor signature stays under test, and overrides the one
 * method that would reach the network.
 */
class RecordingDocumentMailer extends MemberDocumentMailer
{
    /** @var array<string, string> address => subject */
    public array $sentByAddress = [];

    /** @var array<string, int> address => file id */
    public array $fileIdByAddress = [];

    public ?string $failFor = null;

    public function __construct(EncryptedFileStorageService $fileStorage, string $storagePath)
    {
        parent::__construct(
            // Never reached: send() is overridden below.
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new \Core\Mail\DkimManager($storagePath . '/keys'), 's1'),
            $fileStorage,
            $storagePath
        );
    }

    public function send(string $title, int $fileId, string $toAddress, string $unitName): void
    {
        if ($this->failFor === $toAddress) {
            throw new \RuntimeException('SMTP said no.');
        }

        $this->sentByAddress[$toAddress] = $title;
        $this->fileIdByAddress[$toAddress] = $fileId;
    }
}
