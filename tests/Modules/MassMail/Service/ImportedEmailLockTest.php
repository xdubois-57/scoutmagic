<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\ImportedEmailRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\ImportedEmailFixture;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * The read-only lock of an email imported from a former site (decision
 * D11), on the server: whatever a page shows or hides, the service refuses
 * to edit it, delete it, send it, resend one of its messages — which would
 * REALLY send it — or touch its attachments.
 *
 * Nothing of the data_migration module is built here, on purpose: the lock
 * is mass_mail's own column (`imported_at`), and a site that disabled the
 * migration module after its migration is exactly this harness.
 */
#[Group('database')]
final class ImportedEmailLockTest extends TestCase
{
    private \PDO $pdo;
    private MassMailService $service;
    private string $storage;
    private int $emailId;
    private int $acceptedId;
    private int $unknownId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->storage = sys_get_temp_dir() . '/mm_lock_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $encryption = ImportedEmailFixture::encryption();

        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');

        $this->service = new MassMailService(
            new EmailRepository($this->pdo),
            new DeliveryRepository($this->pdo, $encryption),
            new TargetRepository($this->pdo),
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $this->createStub(MailingListService::class),
            $this->createStub(MemberService::class),
            $this->createStub(MemberEmailService::class),
            $this->createStub(SectionService::class),
            $mail,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            $this->createStub(ScoutYearService::class),
            $this->createStub(ImportJournalRepository::class),
            $this->storage,
            $this->createStub(AudienceRepository::class),
            new MemberResolutionRepository($this->pdo, $encryption),
            $this->createStub(SuppressedAddressRepository::class),
            new MergeRenderer(),
            null,
            new ImportedEmailRepository($this->pdo, $encryption)
        );

        ImportedEmailFixture::staffSection($this->pdo);
        $pdf = ImportedEmailFixture::pdf();
        try {
            $this->emailId = ImportedEmailFixture::service($this->pdo, $this->storage)->import(
                ImportedEmailFixture::email(
                    'c-lock',
                    null,
                    [],
                    [new \Modules\MassMail\Api\ImportedAttachment($pdf, 'programme.pdf')],
                )
            )->emailId;
        } finally {
            @unlink($pdf);
        }
        $this->acceptedId = $this->deliveryId(Delivery::STATUS_SENT);
        $this->unknownId = $this->deliveryId(Delivery::STATUS_UNKNOWN);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testResendingAnAcceptedMessageIsRefusedAndNothingIsQueued(): void
    {
        $this->assertRefused(fn() => $this->service->resendDelivery($this->acceptedId, null), 'renvoyé');

        $this->assertSame(Delivery::STATUS_SENT, $this->deliveryStatus($this->acceptedId));
        $this->assertSame(Email::STATUS_SENT, $this->service->findById($this->emailId)?->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn());
    }

    public function testResendingAMessageOfUnknownFateIsRefused(): void
    {
        $this->assertRefused(fn() => $this->service->resendDelivery($this->unknownId, null), 'renvoyé');

        $this->assertSame(Delivery::STATUS_UNKNOWN, $this->deliveryStatus($this->unknownId));
    }

    public function testEditingIsRefused(): void
    {
        $this->assertRefused(fn() => $this->service->updateDraft(
            $this->emailId,
            'Autre sujet',
            '<p>Autre texte</p>',
            ImportedEmailFixture::staffSection($this->pdo),
            Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS,
            null,
            null,
            [1],
            new SenderAuthorization(true, [], null)
        ), 'modifié');

        $this->assertSame("Infos camp d'été 2024", $this->service->findById($this->emailId)?->subject);
    }

    public function testDeletingIsRefusedAndTheRowStays(): void
    {
        $this->assertRefused(fn() => $this->service->deleteUnsent($this->emailId, null), 'supprimé');

        $this->assertNotNull($this->service->findById($this->emailId));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_attachments')->fetchColumn());
    }

    /**
     * Even forced to 'draft' — a status an imported email never has — the
     * repository's own DELETE refuses it: the lock is the column.
     */
    public function testTheRepositoryRefusesToDeleteAnImportedEmailWhateverItsStatus(): void
    {
        $this->pdo->exec("UPDATE mass_mail_emails SET status = 'draft' WHERE id = {$this->emailId}");

        $this->assertFalse((new EmailRepository($this->pdo))->deleteIfNotStarted($this->emailId));
        $this->assertRefused(fn() => $this->service->deleteUnsent($this->emailId, null), 'supprimé');
        $this->assertRefused(fn() => $this->service->moveToTest($this->emailId, null), 'envoyé');
        $this->assertNotNull($this->service->findById($this->emailId));
    }

    public function testAddingAnAttachmentIsRefusedBeforeAnyFileIsStored(): void
    {
        $this->assertRefused(fn() => $this->service->assertAcceptsAttachments($this->emailId), 'modifié');
        $this->assertRefused(fn() => $this->service->addAttachment($this->emailId, 1), 'modifié');

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_attachments')->fetchColumn());
    }

    public function testRemovingAnAttachmentIsRefused(): void
    {
        $attachmentId = (int) $this->pdo->query('SELECT id FROM mass_mail_attachments')->fetchColumn();

        $this->assertRefused(fn() => $this->service->removeAttachment($attachmentId), 'modifié');

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_attachments')->fetchColumn());
    }

    public function testNoStatusTransitionNorTestSendIsAccepted(): void
    {
        $this->assertRefused(fn() => $this->service->moveToTest($this->emailId, null), 'envoyé');
        $this->assertRefused(fn() => $this->service->backToDraft($this->emailId, null), 'modifié');
        $this->assertRefused(fn() => $this->service->startSending($this->emailId, null), 'envoyé');
        $this->assertRefused(fn() => $this->service->sendTestEmail($this->emailId, 'chef@exemple.test'), 'envoyé');
    }

    /**
     * The lock reads the column and nothing else: an imported row the
     * migration module never touched — no correspondence table at all — is
     * as locked as one it wrote.
     */
    public function testTheLockIsTheColumnNotTheMigrationModule(): void
    {
        $this->assertFalse(
            (bool) $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'data_migration%'",
            )->fetchColumn(),
            'this harness must not hold anything of data_migration'
        );
        $email = $this->service->findById($this->emailId);
        $this->assertTrue($email?->isImported());

        $this->pdo->exec("UPDATE mass_mail_emails SET imported_at = NULL WHERE id = {$this->emailId}");
        $this->assertFalse($this->service->findById($this->emailId)?->isImported(), 'the lock is that column');
    }

    public function testTheOriginalSenderIsReadBackForTheScreens(): void
    {
        $this->assertSame(
            ['address' => 'eclaireurs@exemple.test', 'name' => 'Éclaireurs 99e Exempleville'],
            $this->service->getOriginalSenders([$this->emailId])[$this->emailId] ?? null
        );
    }

    public function testTheCountsNameTheMessagesOfUnknownFate(): void
    {
        $this->assertSame(
            ['pending' => 0, 'sent' => 1, 'error' => 1, 'unknown' => 1, 'total' => 3],
            $this->service->getStatusCounts($this->emailId)
        );
    }

    private function assertRefused(callable $action, string $verb): void
    {
        try {
            $action();
        } catch (MassMailException $e) {
            $this->assertStringContainsString('importé de l\'ancien site', $e->getMessage());
            $this->assertStringContainsString($verb, $e->getMessage());

            return;
        }
        $this->fail('An imported email was not refused.');
    }

    private function deliveryId(string $status): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mass_mail_deliveries WHERE email_id = ? AND status = ?');
        $stmt->execute([$this->emailId, $status]);

        return (int) $stmt->fetchColumn();
    }

    private function deliveryStatus(int $deliveryId): string
    {
        return (string) $this->pdo->query(
            "SELECT status FROM mass_mail_deliveries WHERE id = {$deliveryId}",
        )->fetchColumn();
    }
}
