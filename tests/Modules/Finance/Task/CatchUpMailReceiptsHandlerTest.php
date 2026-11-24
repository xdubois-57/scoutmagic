<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Task\CatchUpMailReceiptsHandler;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The mail already associated with an account whose receipt was never
 * filed (#1342 §A, §E, §F) is caught up once, by the real store, and the
 * chain ends when nothing is left (decision 9).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CatchUpMailReceiptsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private AttachmentRepository $attachments;
    private int $accountId;

    /** @var InboundMessage[] what the courrier says is filed under each reference */
    private array $mail = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_catchup_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->attachments = new AttachmentRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1)");
        $this->pdo->exec(
            "INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description, editable)
             VALUES ('finance', '" . CatchUpMailReceiptsHandler::DONE_SETTING . "', '', '', 'text', 'x', 'x', 0)"
        );

        $accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accounts->create('Louveteaux', Account::TYPE_BANK, null, null, null, 'intendant');
        $accounts->updateStatus($this->accountId, Account::STATUS_ACTIVE);
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

    public function testAnAssociationWhoseReceiptWasNeverFiledHasItFiledOnItsAccount(): void
    {
        $this->linked(LinkOrigin::IBAN);

        $this->runTask();

        $filed = $this->attachments->findActiveOrdered();
        $this->assertCount(1, $filed);
        $this->assertSame($this->accountId, $filed[0]->accountId);
        $this->assertSame(1, $this->pendingChains(), 'work was found: the chain goes on');
    }

    /** The run after the last one finds nothing: the history is caught up, and the chain ends. */
    public function testTheChainEndsOnceNothingIsLeft(): void
    {
        $this->linked(LinkOrigin::IBAN);

        $this->runTask();
        $this->pdo->exec('DELETE FROM scheduled_actions');
        $this->runTask();

        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'nothing filed twice');
        $this->assertSame(0, $this->pendingChains());
        $this->assertNotSame('', $this->settings()->get(CatchUpMailReceiptsHandler::DONE_SETTING, 'finance', ''));

        $scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        CatchUpMailReceiptsHandler::bootstrap($scheduler, $this->settings());
        $this->assertSame(0, $this->pendingChains(), 'a caught-up history is not seeded again');
    }

    /** A person's association files under their own right — never by the unattended door. */
    public function testAPersonsAssociationIsLeftAlone(): void
    {
        $this->linked(LinkOrigin::MANUAL, byUserAccountId: 7);

        $this->runTask();

        $this->assertSame([], $this->attachments->findActiveOrdered());
    }

    /** A receipt somebody deleted is not brought back by the catch-up. */
    public function testADeletedReceiptIsNotBroughtBack(): void
    {
        $fileId = $this->linked(LinkOrigin::IBAN);
        $this->runTask();
        $this->attachments->archive($this->attachments->findActiveOrdered()[0]->id);
        $this->pdo->exec('DELETE FROM finance_receipt_mail_sources');

        $this->mail = [];
        $this->linked(LinkOrigin::IBAN, fileId: $fileId);
        $this->runTask();

        $this->assertSame([], $this->attachments->findActiveOrdered());
    }

    /**
     * Finance refusing the receipt — here its account was archived since —
     * is said once in the journal and remembered: the walk still ends.
     */
    public function testARefusalIsSaidOnceAndTheWalkStillEnds(): void
    {
        $this->linked(LinkOrigin::IBAN);
        (new AccountRepository($this->pdo, $this->encryption))->updateStatus($this->accountId, Account::STATUS_INACTIVE);

        $this->runTask();
        $this->pdo->exec('DELETE FROM scheduled_actions');
        $this->runTask();

        $this->assertSame([], $this->attachments->findActiveOrdered());
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'inbound_receipt_not_filed'")->fetchColumn()
        );
        $this->assertNotSame('', $this->settings()->get(CatchUpMailReceiptsHandler::DONE_SETTING, 'finance', ''));
    }

    /**
     * A receipt filed before finance kept a hash cannot be recognised by its
     * bytes: same name and type is enough for the catch-up to leave it be.
     */
    public function testAReceiptFiledBeforeTheHashIsRecognisedByItsName(): void
    {
        $this->pdo->prepare(
            'INSERT INTO finance_attachments (account_id, file_id, mime_type, original_filename, status, content_hash)
             VALUES (?, 1, ?, ?, ?, NULL)'
        )->execute([$this->accountId, 'application/pdf', 'facture.pdf', 'active']);
        $this->linked(LinkOrigin::IBAN);

        $this->runTask();

        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'the old one, and no second');
    }

    /**
     * Only unreadable files left is not an empty history: the walk does not
     * declare itself done, and says why — for a few runs. Then it leaves
     * the file, so one gone for good neither holds the walk open for ever
     * nor takes the place of readable ones behind it.
     */
    public function testAnUnreadableFileIsTriedAFewTimesThenLeft(): void
    {
        $this->linked(LinkOrigin::IBAN, fileId: 99999);

        foreach (range(1, \Modules\Finance\Repository\ReceiptMailSourceRepository::MAX_UNREADABLE_TRIES) as $try) {
            $this->runTask();
            $this->assertSame('', $this->settings()->get(CatchUpMailReceiptsHandler::DONE_SETTING, 'finance', ''), "try {$try}: not done");
            $this->pdo->exec('DELETE FROM scheduled_actions');
        }
        $this->runTask();

        $this->assertNotSame('', $this->settings()->get(CatchUpMailReceiptsHandler::DONE_SETTING, 'finance', ''));
        $this->assertSame(
            \Modules\Finance\Repository\ReceiptMailSourceRepository::MAX_UNREADABLE_TRIES,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'inbound_receipt_unreadable'")->fetchColumn()
        );
    }

    /** No courrier module: nothing to catch up yet, and not « done » either. */
    public function testWithoutTheCourrierTheChainWaits(): void
    {
        $this->runTask(withInboundMail: false);

        $this->assertSame(1, $this->pendingChains());
        $this->assertSame('', $this->settings()->get(CatchUpMailReceiptsHandler::DONE_SETTING, 'finance', ''));
    }

    private function linked(LinkOrigin $origin, ?int $byUserAccountId = null, ?int $fileId = null): int
    {
        $fileId ??= $this->inboundPdf();
        $size = (int) ((new FileRepository($this->pdo))->findById($fileId)?->sizeBytes ?? 0);
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            $origin,
            0,
            $byUserAccountId
        );

        $this->mail[] = new InboundMessage(
            id: 60,
            mailboxId: 1,
            consumerId: FinanceMessageConsumer::CONSUMER_ID,
            businessReference: $link->businessReference,
            linkOrigin: $origin,
            subject: 'Facture',
            fromEmail: 'fournisseur@example.be',
            fromName: null,
            messageId: 'e@f',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2026-09-01 07:40:00'),
            bodyText: 'Ci-joint.',
            bodyHtml: '',
            toEmails: [],
            attachments: [new InboundAttachment(95, 60, $fileId, 'facture.pdf', 'application/pdf', $size, 'hash-e')],
            links: [$link]
        );

        return $fileId;
    }

    private function inboundPdf(): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'inbound-mail-');
        $this->assertIsString($temporary);
        copy(dirname(__DIR__, 3) . '/fixtures/pdf/text_receipt.pdf', $temporary);

        return (new UploadHandler(new FileRepository($this->pdo), $this->storagePath))->handle(
            ['tmp_name' => $temporary, 'name' => 'facture.pdf', 'size' => (int) filesize($temporary), 'error' => UPLOAD_ERR_OK],
            'inbound_mail/attachments',
            ['application/pdf'],
            5 * 1024 * 1024,
            'intendant',
            'inbound_mail'
        );
    }

    private function runTask(bool $withInboundMail = true): void
    {
        $mail = &$this->mail;
        $inboundMail = $this->createStub(InboundMailInterface::class);
        $inboundMail->method('findForReference')->willReturnCallback(
            static function (string $consumerId, string $reference) use (&$mail): array {
                return array_values(array_filter(
                    $mail,
                    static fn(InboundMessage $message): bool => $message->linksFor($consumerId) !== []
                        && $message->linksFor($consumerId)[0]->businessReference === $reference
                ));
            }
        );

        $moduleManager = $this->createStub(\Core\Module\ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn(['finance', 'inbound_mail']);
        $capabilities = new \Core\Scheduler\TaskCapabilities($moduleManager);
        if ($withInboundMail) {
            $capabilities->register(InboundMailInterface::class, 'inbound_mail', static fn (): object => $inboundMail);
        }

        (new CatchUpMailReceiptsHandler())->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings(),
            $this->createStub(UserAccountRepository::class),
            $this->storagePath,
            null,
            $capabilities
        ));
    }

    private function settings(): SettingService
    {
        return new SettingService(new SettingRepository($this->pdo));
    }

    private function pendingChains(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'finance' AND task_key = ? AND status = 'pending'"
        );
        $stmt->execute([CatchUpMailReceiptsHandler::TASK_KEY]);

        return (int) $stmt->fetchColumn();
    }
}
