<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpenseReceiptService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * A receipt that arrives by email actually lands in the receipts screen.
 *
 * Every other test on this path uses a recording double for finance's
 * storage, so all of them passed while nothing was ever filed: the bug was
 * between the consumer's decision and the bytes on disk, which is exactly
 * the seam a double removes.
 *
 * **The bug.** An inbound-mail attachment is written by UploadHandler,
 * unencrypted; the composition roots read it back through
 * EncryptedFileStorageService::retrieve(), which handed plaintext to
 * decrypt(). It threw, the caller's catch turned the throw into « pas
 * d'octets », and the receipt was never created — silently, on every
 * message, while the courrier screen cheerfully showed the association.
 *
 * So this file wires the REAL services end to end, and reads the file the
 * way production does.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class UnattendedReceiptFilingTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private FileRepository $files;
    private AttachmentRepository $attachments;
    private AccountRepository $accounts;
    private StoredFileReader $reader;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_unattended_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->files = new FileRepository($this->pdo);
        $this->attachments = new AttachmentRepository($this->pdo, $this->encryption);
        $this->accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->reader = new StoredFileReader(
            $this->files,
            new EncryptedFileStorageService($this->files, $this->encryption, $this->storagePath),
            $this->storagePath
        );

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1)");
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->storagePath);
    }

    public function testAReceiptWithNoAccountLandsInTheSortingPile(): void
    {
        $fileId = $this->inboundAttachment();

        $this->consumer()->onLinked(
            $this->message($fileId),
            new MessageLink(
                FinanceMessageConsumer::CONSUMER_ID,
                FinanceMessageConsumer::REFERENCE_UNKNOWN,
                LinkOrigin::ATTACHMENT
            )
        );

        $filed = $this->attachments->findActiveOrdered();
        $this->assertCount(1, $filed, 'the receipt must exist — this is what the treasurer came to look for');
        $this->assertNull($filed[0]->accountId, 'and with no account: it is the sorting pile');
        $this->assertSame('image/png', $filed[0]->mimeType);
    }

    public function testTheFiledReceiptIsReadableAfterwards(): void
    {
        $this->consumer()->onLinked(
            $this->message($this->inboundAttachment()),
            new MessageLink(
                FinanceMessageConsumer::CONSUMER_ID,
                FinanceMessageConsumer::REFERENCE_UNKNOWN,
                LinkOrigin::ATTACHMENT
            )
        );

        // Filing garbage would satisfy the test above. The receipt is
        // stored ENCRYPTED by finance, so reading it back is what proves
        // the bytes survived the trip from an unencrypted inbound file.
        $filed = $this->attachments->findActiveOrdered()[0];
        $content = (new EncryptedFileStorageService($this->files, $this->encryption, $this->storagePath))
            ->retrieve($filed->fileId);

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($content, 0, 8));
    }

    public function testAReceiptResolvedToAnAccountLandsOnThatAccount(): void
    {
        $accountId = $this->accounts->create('Louveteaux', \Modules\Finance\Repository\Account::TYPE_BANK, null, null, null, 'intendant');
        $this->accounts->updateStatus($accountId, \Modules\Finance\Repository\Account::STATUS_ACTIVE);

        $this->consumer()->onLinked(
            $this->message($this->inboundAttachment()),
            new MessageLink(
                FinanceMessageConsumer::CONSUMER_ID,
                FinanceMessageConsumer::referenceFor($accountId),
                LinkOrigin::SENDER
            )
        );

        $filed = $this->attachments->findActiveOrdered();
        $this->assertCount(1, $filed);
        $this->assertSame($accountId, $filed[0]->accountId);
    }

    private function consumer(): FinanceMessageConsumer
    {
        $receiptService = new ReceiptService(
            $this->attachments,
            $this->accounts,
            new TransactionAttachmentRepository($this->pdo),
            new EncryptedFileStorageService($this->files, $this->encryption, $this->storagePath),
            new TransactionRepository($this->pdo, $this->encryption)
        );

        return new FinanceMessageConsumer(
            $this->accounts,
            new TreasurerScopeService(
                Connection::withPdo($this->pdo),
                new BadgeRepository($this->pdo),
                new MemberBadgeRepository($this->pdo)
            ),
            $this->pdo,
            $this->encryption,
            1,
            new ExpenseReceiptService(
                $this->accounts,
                new TreasurerScopeService(
                    Connection::withPdo($this->pdo),
                    new BadgeRepository($this->pdo),
                    new MemberBadgeRepository($this->pdo)
                ),
                $receiptService,
                1
            ),
            null,
            // The production closure, verbatim in shape: whatever the
            // composition roots install has to be able to read a file the
            // inbound-mail sync wrote.
            fn(int $fileId): ?string => $this->reader->read($fileId)
        );
    }

    /**
     * An attachment stored exactly as MailboxSyncService stores one —
     * through UploadHandler, therefore unencrypted.
     */
    private function inboundAttachment(): int
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertIsString($bytes);

        $temporary = tempnam(sys_get_temp_dir(), 'inbound-mail-');
        $this->assertIsString($temporary);
        file_put_contents($temporary, $bytes);

        return (new UploadHandler($this->files, $this->storagePath))->handle(
            ['tmp_name' => $temporary, 'name' => 'ticket.png', 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK],
            'inbound_mail/attachments',
            ['image/png'],
            5 * 1024 * 1024,
            'intendant',
            'inbound_mail'
        );
    }

    private function message(int $fileId): InboundMessage
    {
        return new InboundMessage(
            id: 55,
            mailboxId: 1,
            consumerId: FinanceMessageConsumer::CONSUMER_ID,
            businessReference: FinanceMessageConsumer::REFERENCE_UNKNOWN,
            linkOrigin: LinkOrigin::ATTACHMENT,
            subject: 'Mes dépenses pour le barbecue',
            fromEmail: 'anna@example.be',
            fromName: 'Anna',
            messageId: 'a@b',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2026-09-01 07:40:00'),
            bodyText: '',
            bodyHtml: '',
            toEmails: ['finances@unite.be'],
            attachments: [new InboundAttachment(88, 55, $fileId, 'ticket.png', 'image/png', 120, 'hash-a')]
        );
    }
}
