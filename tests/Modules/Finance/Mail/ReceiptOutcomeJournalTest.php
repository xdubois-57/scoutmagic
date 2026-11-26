<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Core\Badge\BadgeRepository;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\Finance\Mail\MailReceiptTrace;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpenseReceiptService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\AttachmentFate;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * What became of each attachment is said in the journal, and on the message
 * page (#1406).
 *
 * Before it, a receipt filed in « Compte inconnu », one finance already held
 * and a document that does not read like a receipt all left the same
 * journal: nothing. The real store is wired end to end, as in
 * `UnattendedReceiptFilingTest`, because the decision to skip is made
 * against what the store really holds.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ReceiptOutcomeJournalTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private FileRepository $files;
    private AttachmentRepository $attachments;
    private AccountRepository $accounts;
    private StoredFileReader $reader;
    private JournalRepository $journalRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_outcome_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->files = new FileRepository($this->pdo);
        $this->attachments = new AttachmentRepository($this->pdo, $this->encryption);
        $this->accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->journalRepository = new JournalRepository($this->pdo);
        $this->reader = new StoredFileReader(
            $this->files,
            new EncryptedFileStorageService($this->files, $this->encryption, $this->storagePath),
            $this->storagePath
        );

        $this->pdo->exec(
            "INSERT INTO scout_years (id, label, start_date, end_date, is_current)
             VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1)"
        );
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

    // ── The journal, from the deferred pass ──────────────────────────

    public function testAReceiptFiledInTheUnknownAccountIsSaid(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');

        $result = $this->consumer()->analyzeStored($this->generalMessage($fileId));

        $this->assertSame(['filed_unknown_account'], $this->reasons());
        $this->assertTrue($result->isEmpty(), 'the message is still not linked');
        $this->assertSame(1, $result->filed, 'but the pass can count what it filed');
        $this->assertStringContainsString('« Compte inconnu »', $this->entries()[0]['description']);
        $this->assertSame($this->attachments->findActiveOrdered()[0]->id, $this->contexts()[0]['receipt_id']);
    }

    public function testAnAttachmentAlreadyThroughFinanceIsSaidAndNotFiledAgain(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer();
        $consumer->analyzeStored($message);

        $again = $consumer->analyzeStored($message);

        $this->assertSame(['filed_unknown_account', 'already_through'], $this->reasons());
        $this->assertSame(0, $again->filed);
        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'the behaviour did not change');
    }

    public function testBytesFinanceAlreadyHoldsAreSaidAndNotFiledAgain(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $consumer->analyzeStored($this->generalMessage($fileId));

        // The same document, in a NEW message: its attachment was never
        // through finance, its bytes are.
        $second = $consumer->analyzeStored($this->generalMessage($fileId, messageId: 57, attachmentId: 91));

        $this->assertSame(['filed_unknown_account', 'already_held'], $this->reasons());
        $this->assertSame(0, $second->filed);
        $this->assertCount(1, $this->attachments->findActiveOrdered());
        $this->assertSame(57, $this->contexts()[1]['message_id']);
    }

    public function testADocumentThatDoesNotReadLikeAReceiptIsSaid(): void
    {
        $result = $this->consumer()->analyzeStored(
            $this->generalMessage($this->inboundPdf('camp_booking_contract.pdf'))
        );

        $this->assertSame(['not_a_receipt'], $this->reasons());
        $this->assertSame(0, $result->filed);
        $this->assertSame([], $this->attachments->findActiveOrdered());
    }

    // ── The journal, from the association made on arrival ────────────

    public function testAnAssociationFilingAnAlreadyHeldDocumentSaysSo(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::ATTACHMENT
        );

        $consumer->onLinked($this->generalMessage($fileId), $link);
        $consumer->onLinked($this->generalMessage($fileId, messageId: 58, attachmentId: 92), $link);

        $this->assertSame(['filed_unknown_account', 'already_held'], $this->reasons());
        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'no second receipt');
    }

    public function testAnAttachmentAlreadyThroughFinanceIsSaidOnAnAssociationToo(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer();
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::ATTACHMENT
        );

        $consumer->onLinked($message, $link);
        $consumer->onLinked($message, $link);

        $this->assertSame(['filed_unknown_account', 'already_through'], $this->reasons());
    }

    public function testAPersonWhoCannotBeResolvedIntoARoleIsSaid(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::MANUAL,
            0,
            77
        );

        $this->consumer()->onLinked($message, $link);

        $this->assertSame(['person_unresolved'], $this->reasons());
        $this->assertSame([], $this->attachments->findActiveOrdered(), 'nothing is filed in that name');
    }

    public function testTheJournalNamesNoSenderNoSubjectAndNoFile(): void
    {
        $this->consumer()->analyzeStored($this->generalMessage($this->inboundPdf('text_receipt.pdf')));

        $entry = $this->entries()[0];
        $everything = $entry['description'] . ' ' . $entry['context'];

        $this->assertSame('finance', $entry['category']);
        $this->assertSame('info', $entry['level']);
        $this->assertStringNotContainsString('inconnu@example.be', $everything);
        $this->assertStringNotContainsString('Courses', $everything);
        $this->assertStringNotContainsString('facture.pdf', $everything);
        $this->assertSame(
            ['attachment_id', 'message_id', 'reason', 'receipt_id'],
            $this->sortedKeys($this->contexts()[0])
        );
    }

    public function testAJournalThatCannotBeWrittenNeverFailsTheFiling(): void
    {
        $this->pdo->exec('DROP TABLE event_log');

        $this->consumer()->analyzeStored($this->generalMessage($this->inboundPdf('text_receipt.pdf')));

        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'the receipt exists all the same');
    }

    // ── The message page: what became of each attachment ─────────────

    public function testAFiledReceiptIsReportedAsCreatedWithALinkForWhoMaySeeIt(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer();
        $consumer->analyzeStored($message);

        $fates = $consumer->attachmentFates($message, $this->unknownLink(), 'admin', []);

        $this->assertCount(1, $fates);
        $this->assertSame(AttachmentFate::CREATED, $fates[0]->state);
        $this->assertSame('Reçu créé', $fates[0]->label);
        $this->assertSame('/finance/receipts?account_id=unassigned', $fates[0]->url);
        $this->assertSame('Compte inconnu', $fates[0]->targetLabel);
    }

    public function testWithoutTheRightToSeeTheAccountTheStateIsToldAndNothingElse(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer();
        $consumer->analyzeStored($message);

        $fates = $consumer->attachmentFates($message, $this->unknownLink(), 'identified', []);

        $this->assertSame(AttachmentFate::CREATED, $fates[0]->state);
        $this->assertNull($fates[0]->url, 'no link to a pile the viewer may not open');
        $this->assertNull($fates[0]->targetLabel, 'and no name for it');
    }

    public function testAnAccountTheViewerMaySeeIsNamedAndLinked(): void
    {
        $accountId = $this->accounts->create('Louveteaux', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->accounts->updateStatus($accountId, Account::STATUS_ACTIVE);
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer();
        $consumer->analyzeStored($message);
        $this->receiptService()->reassign($this->attachments->findActiveOrdered()[0]->id, $accountId);

        $fates = $consumer->attachmentFates($message, $this->unknownLink(), 'admin', []);

        $this->assertSame('/finance/receipts?account_id=' . $accountId, $fates[0]->url);
        $this->assertSame('Louveteaux', $fates[0]->targetLabel);
    }

    public function testAnIdenticalReceiptAlreadyInFinanceIsReportedAsAlreadyPresent(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $consumer->onLinked($this->generalMessage($fileId), $this->unknownLink());
        $second = $this->generalMessage($fileId, messageId: 58, attachmentId: 92);
        $consumer->onLinked($second, $this->unknownLink());

        $fates = $consumer->attachmentFates($second, $this->unknownLink(), 'admin', []);

        $this->assertSame(AttachmentFate::ALREADY_PRESENT, $fates[0]->state);
        $this->assertSame('Reçu déjà présent dans Finance', $fates[0]->label);
        $this->assertSame('/finance/receipts?account_id=unassigned', $fates[0]->url);
    }

    public function testAReceiptCreatedFromThisAttachmentAndDeletedSinceIsNotCalledIdentical(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $message = $this->generalMessage($fileId);
        $consumer->onLinked($message, $this->unknownLink());
        $this->receiptService()->delete($this->attachments->findActiveOrdered()[0]->id);

        $fates = $consumer->attachmentFates($message, $this->unknownLink(), 'admin', []);

        $this->assertSame(AttachmentFate::REMOVED, $fates[0]->state, 'there was never a duplicate');
        $this->assertSame('Reçu supprimé dans Finance — non recréé', $fates[0]->label);
        $this->assertNull($fates[0]->url, 'an archived receipt is on no screen');
    }

    public function testAnIdenticalReceiptDeletedBeforeIsReportedAsNotRecreated(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $consumer->onLinked($this->generalMessage($fileId), $this->unknownLink());
        $this->receiptService()->delete($this->attachments->findActiveOrdered()[0]->id);

        // A second attachment of the SAME message with the same bytes: the
        // receipt that held them is archived and came from this message, so
        // it is not brought back (decision 3) — and it is an identical one.
        $second = $this->generalMessage($fileId, messageId: 56, attachmentId: 92);
        $consumer->onLinked($second, $this->unknownLink());

        $fates = $consumer->attachmentFates($second, $this->unknownLink(), 'admin', []);

        $this->assertSame(AttachmentFate::DELETED, $fates[0]->state);
        $this->assertSame('Reçu identique supprimé dans Finance — non recréé', $fates[0]->label);
        $this->assertSame([], $this->attachments->findActiveOrdered(), 'and nothing came back');
    }

    /**
     * A receipt a treasurer confirmed that the store refused. The catch-up
     * skips every association a person made, so nothing else would ever
     * write the row: it read « pas encore traité » for ever.
     */
    public function testAReceiptFinanceRefusedForAPersonIsReportedAsRefusedNotAsPending(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer(
            new \Tests\Modules\Finance\Mail\RefusingExpenseReceipts(),
            static fn(int $userAccountId): array => ['role' => 'admin', 'member_ids' => []]
        );
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::MANUAL,
            0,
            77
        );

        $consumer->onLinked($message, $link);
        $fates = $consumer->attachmentFates($message, $link, 'admin', []);

        $this->assertSame([], $this->attachments->findActiveOrdered(), 'nothing was filed');
        $this->assertSame(AttachmentFate::REFUSED, $fates[0]->state);
        $this->assertSame('Reçu refusé par Finance — voir le journal', $fates[0]->label);
    }

    public function testAPersonWhoCannotBeResolvedIsReportedAsRefusedNotAsPending(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $link = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::MANUAL,
            0,
            77
        );
        $consumer = $this->consumer();

        $consumer->onLinked($message, $link);

        $this->assertSame(
            AttachmentFate::REFUSED,
            $consumer->attachmentFates($message, $link, 'admin', [])[0]->state
        );
    }

    /**
     * A person's refusal says nothing about the document: it must not count
     * as « through finance », or a machine-made link on the same attachment
     * would lose its chance to file for good.
     */
    public function testARefusalForAPersonDoesNotSettleTheAttachment(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $personLink = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::MANUAL,
            0,
            77
        );
        $this->consumer(
            new \Tests\Modules\Finance\Mail\RefusingExpenseReceipts(),
            static fn(int $userAccountId): array => ['role' => 'admin', 'member_ids' => []]
        )->onLinked($message, $personLink);

        // A machine-made link on the same attachment, afterwards.
        $this->consumer()->onLinked($message, $this->unknownLink());

        $this->assertCount(1, $this->attachments->findActiveOrdered(), 'the receipt is filed after all');
        $this->assertSame(
            AttachmentFate::CREATED,
            $this->consumer()->attachmentFates($message, $this->unknownLink(), 'admin', [])[0]->state,
            'and the refusal gave way to the filing'
        );
    }

    public function testARefusalForAPersonNeverReplacesWhatIsAlreadyKnown(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $this->consumer(readFile: static fn(int $fileId): ?string => null)
            ->onLinked($message, $this->unknownLink());
        $personLink = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::MANUAL,
            0,
            77
        );

        // The person cannot be resolved: a refusal, written over nothing.
        $consumer = $this->consumer();
        $consumer->onLinked($message, $personLink);

        $this->assertSame(
            AttachmentFate::UNREADABLE,
            $consumer->attachmentFates($message, $personLink, 'admin', [])[0]->state,
            'the retryable « illisible » stays what the page says'
        );
    }

    public function testAnAttachmentWhoseFileCannotBeReadIsReportedAsUnreadableNotAsPending(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $consumer = $this->consumer(readFile: static fn(int $fileId): ?string => null);

        $consumer->onLinked($message, $this->unknownLink());

        $this->assertSame(
            AttachmentFate::UNREADABLE,
            $consumer->attachmentFates($message, $this->unknownLink(), 'admin', [])[0]->state
        );
        $this->assertSame([], $this->attachments->findActiveOrdered());
    }

    public function testAnUnreadableFileDoesNotCountAsThroughFinanceSoALaterPassReadsItAgain(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));
        $this->consumer(readFile: static fn(int $fileId): ?string => null)
            ->onLinked($message, $this->unknownLink());

        // The file is readable now: the same association files it, which is
        // what keeps « illisible » from becoming a verdict.
        $this->consumer()->onLinked($message, $this->unknownLink());

        $this->assertCount(1, $this->attachments->findActiveOrdered());
    }

    /**
     * Message A's receipt is deleted, message B brings the same document
     * back as a new receipt, then message C carries it again. C's held
     * receipt is the live one, not the oldest: the page must not say
     * « supprimé » next to a receipt that exists.
     */
    public function testAHeldDocumentPointsAtTheLiveReceiptNotTheOldestDeletedOne(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $consumer = $this->consumer();
        $consumer->onLinked($this->generalMessage($fileId), $this->unknownLink());
        $this->receiptService()->delete($this->attachments->findActiveOrdered()[0]->id);
        $consumer->onLinked($this->generalMessage($fileId, messageId: 57, attachmentId: 91), $this->unknownLink());
        $third = $this->generalMessage($fileId, messageId: 58, attachmentId: 92);

        $consumer->onLinked($third, $this->unknownLink());

        $live = $this->attachments->findActiveOrdered();
        $this->assertCount(1, $live, 'one live receipt, and nothing was filed a third time');
        $fates = $consumer->attachmentFates($third, $this->unknownLink(), 'admin', []);
        $this->assertSame(AttachmentFate::ALREADY_PRESENT, $fates[0]->state);
        $this->assertSame('/finance/receipts?account_id=unassigned', $fates[0]->url);
        $this->assertSame(
            $live[0]->id,
            $this->contexts()[2]['receipt_id'],
            'and the journal names the live receipt'
        );
    }

    /**
     * Two message-level links to Finance cover the same attachment, and the
     * memory of where it went is one row. The receipt on record may be the
     * other link's: neither link names an account, rather than one naming
     * the wrong one.
     */
    public function testTwoLinksCoveringOneAttachmentNameNoAccountForEither(): void
    {
        $accountId = $this->accounts->create('Louveteaux', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->accounts->updateStatus($accountId, Account::STATUS_ACTIVE);
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $unknown = $this->unknownLink();
        $onAccount = new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($accountId),
            LinkOrigin::MANUAL
        );
        $message = $this->generalMessage($fileId, links: [$unknown, $onAccount]);
        $consumer = $this->consumer();
        $consumer->onLinked($message, $unknown);

        foreach ([$unknown, $onAccount] as $link) {
            $fates = $consumer->attachmentFates($message, $link, 'admin', []);

            $this->assertSame(AttachmentFate::CREATED, $fates[0]->state, 'a receipt was created from it');
            $this->assertNull($fates[0]->url, 'but which link it belongs to is not known');
            $this->assertNull($fates[0]->targetLabel);
        }
    }

    public function testAnAttachmentOnlyOneLinkCoversStillNamesItsAccount(): void
    {
        $message = $this->generalMessage(
            $this->inboundPdf('text_receipt.pdf'),
            links: [$this->unknownLink()]
        );
        $consumer = $this->consumer();
        $consumer->onLinked($message, $this->unknownLink());

        $fates = $consumer->attachmentFates($message, $this->unknownLink(), 'admin', []);

        $this->assertSame('/finance/receipts?account_id=unassigned', $fates[0]->url);
    }

    public function testAnAttachmentFinanceHasNotLookedAtIsPending(): void
    {
        $message = $this->generalMessage($this->inboundPdf('text_receipt.pdf'));

        $fates = $this->consumer()->attachmentFates($message, $this->unknownLink(), 'admin', []);

        $this->assertSame(AttachmentFate::PENDING, $fates[0]->state);
        $this->assertNull($fates[0]->url);
    }

    public function testOnlyTheAttachmentAnAttachmentLevelLinkCoversIsReported(): void
    {
        $fileId = $this->inboundPdf('text_receipt.pdf');
        $message = $this->generalMessage($fileId);

        $fates = $this->consumer()->attachmentFates(
            $message,
            new MessageLink(
                FinanceMessageConsumer::CONSUMER_ID,
                FinanceMessageConsumer::REFERENCE_UNKNOWN,
                LinkOrigin::ATTACHMENT,
                999
            ),
            'admin',
            []
        );

        $this->assertSame([], $fates, 'attachment 999 is not in this message');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function unknownLink(): MessageLink
    {
        return new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::ATTACHMENT
        );
    }

    /** @return array<int, array<string, mixed>> oldest first */
    private function entries(): array
    {
        $rows = array_values(array_filter(
            $this->journalRepository->search(),
            static fn(array $row): bool => $row['event_type'] === MailReceiptTrace::EVENT
        ));

        // By id, not by time: two lines written in the same second have no
        // order a timestamp could give them.
        usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function contexts(): array
    {
        return array_map(
            static fn(array $row): array => json_decode((string) $row['context'], true),
            $this->entries()
        );
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return array_map(static fn(array $context): string => $context['reason'], $this->contexts());
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function sortedKeys(array $context): array
    {
        $keys = array_keys($context);
        sort($keys);

        return $keys;
    }

    private function receiptService(): ReceiptService
    {
        return new ReceiptService(
            $this->attachments,
            $this->accounts,
            new TransactionAttachmentRepository($this->pdo),
            new EncryptedFileStorageService($this->files, $this->encryption, $this->storagePath),
            new TransactionRepository($this->pdo, $this->encryption)
        );
    }

    /**
     * @param (\Closure(int): ?array{role: string, member_ids: int[]})|null $resolveActor
     * @param (\Closure(int): ?string)|null $readFile
     */
    private function consumer(
        ?\Modules\Finance\Api\ExpenseReceiptInterface $receipts = null,
        ?\Closure $resolveActor = null,
        ?\Closure $readFile = null
    ): FinanceMessageConsumer {
        $scope = new TreasurerScopeService(Connection::withPdo($this->pdo), new BadgeRepository($this->pdo));

        return new FinanceMessageConsumer(
            $this->accounts,
            $scope,
            $this->pdo,
            $this->encryption,
            1,
            $receipts ?? new ExpenseReceiptService($this->accounts, $scope, $this->receiptService(), 1),
            $resolveActor,
            $readFile ?? fn(int $fileId): ?string => $this->reader->read($fileId),
            null,
            null,
            null,
            null,
            new \Core\Mail\AttachmentTextReader($this->reader),
            null,
            new MailReceiptTrace(new JournalService($this->journalRepository))
        );
    }

    /** A fixture PDF stored as the sync stores an attachment: through UploadHandler. */
    private function inboundPdf(string $fixture): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'inbound-mail-');
        $this->assertIsString($temporary);
        copy(dirname(__DIR__, 3) . '/fixtures/pdf/' . $fixture, $temporary);

        return (new UploadHandler($this->files, $this->storagePath))->handle(
            [
                'tmp_name' => $temporary,
                'name' => $fixture,
                'size' => (int) filesize($temporary),
                'error' => UPLOAD_ERR_OK,
            ],
            'inbound_mail/attachments',
            ['application/pdf'],
            5 * 1024 * 1024,
            'intendant',
            'inbound_mail'
        );
    }

    /** A general-address message nothing in its covering e-mail places. */
    /** @param MessageLink[] $links */
    private function generalMessage(
        int $fileId,
        int $messageId = 56,
        int $attachmentId = 90,
        array $links = []
    ): InboundMessage {
        $size = (int) ($this->files->findById($fileId)?->sizeBytes ?? 0);

        return new InboundMessage(
            id: $messageId,
            mailboxId: 1,
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::MANUAL,
            subject: 'Courses',
            fromEmail: 'inconnu@example.be',
            fromName: null,
            messageId: 'c@d',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2026-09-01 07:40:00'),
            bodyText: 'Bonjour, voici le ticket.',
            bodyHtml: '',
            toEmails: [],
            attachments: [
                new InboundAttachment($attachmentId, $messageId, $fileId, 'facture.pdf', 'application/pdf', $size, 'hash-c'),
            ],
            links: $links
        );
    }
}
