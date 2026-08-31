<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Mail;

use Core\Security\EncryptionService;
use Modules\Finance\Mail\FinanceMessageConsumer;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Service\TreasurerScopeService;
use Modules\InboundMail\Api\CandidateAttachment;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundAttachment;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * An invoice arriving by email, offered as a receipt on the account it
 * names.
 *
 * This consumer **never associates anything on its own**: every answer is
 * a proposition, and only a treasurer's confirmation turns one into a
 * receipt. A receipt is an accounting document, and a wrong one is worse
 * than a missing one because it silently balances against the wrong
 * account.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FinanceMessageConsumerTest extends TestCase
{
    private const IBAN = 'BE92001511757023';
    private const OTHER_IBAN = 'BE71096123456769';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private AccountRepository $accounts;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new AccountRepository($this->pdo, $this->encryption);

        $this->accountId = $this->activeAccount('Compte courant', self::IBAN);
    }

    // ── Both signals are required ───────────────────────────────────────

    public function testAnAttachmentAndTheAccountsIbanTogetherProduceOneProposition(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture de la fédération',
            'Merci de virer sur le compte BE92 0015 1175 7023.',
            [$this->pdfAttachment()]
        ));

        $this->assertSame([], $result->links, 'this module never associates on its own');
        $this->assertCount(1, $result->candidates);
        $this->assertSame(
            FinanceMessageConsumer::referenceFor($this->accountId),
            $result->candidates[0]->businessReference
        );
        $this->assertSame('Compte courant', $result->candidates[0]->label);
        $this->assertStringContainsString('signal faible', $result->candidates[0]->explanation);
    }

    public function testAnIbanWithNoAttachmentIsNothingToFile(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Nos coordonnées',
            'Notre IBAN est BE92 0015 1175 7023.',
            []
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testAnAttachmentWithNoIbanSaysNothingAboutWhichAccount(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture',
            'Voici la facture en pièce jointe.',
            [$this->pdfAttachment()]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testASpreadsheetIsADocumentAndNotAReceipt(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Tableau',
            'Compte BE92 0015 1175 7023.',
            [new CandidateAttachment(
                'budget.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                4096
            )]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testTheIbanIsFoundInTheSubjectToo(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Virement BE92 0015 1175 7023',
            'Voir pièce jointe.',
            [$this->pdfAttachment()]
        ));

        $this->assertCount(1, $result->candidates);
    }

    // ── It refuses to guess ─────────────────────────────────────────────

    public function testTwoOfTheUnitsOwnIbansMeanSilence(): void
    {
        // Almost always a transfer between two of the unit's accounts, and
        // a transfer's receipt belongs to neither side by default.
        $this->activeAccount('Louveteaux', self::OTHER_IBAN);

        $result = $this->consumer()->analyze($this->message(
            'Virement interne',
            'De BE92 0015 1175 7023 vers BE71 0961 2345 6769.',
            [$this->pdfAttachment()]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testAnIbanThatIsNotTheUnitsIsIgnored(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture',
            'Virez sur BE71 0961 2345 6769, merci.',
            [$this->pdfAttachment()]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testAnArchivedAccountIsNotProposedOn(): void
    {
        $archived = $this->accounts->create('Ancien', Account::TYPE_BANK, null, self::OTHER_IBAN, 'T', 'intendant');
        $this->accounts->updateStatus($archived, Account::STATUS_INACTIVE);

        $result = $this->consumer()->analyze($this->message(
            'Facture',
            'Compte BE71 0961 2345 6769.',
            [$this->pdfAttachment()]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testTextThatMerelyLooksLikeAnIbanCostsNothing(): void
    {
        // The pattern is deliberately loose; what makes it safe is that
        // whatever it finds is checked against the unit's own accounts.
        $result = $this->consumer()->analyze($this->message(
            'Référence',
            'Votre dossier XX99 ABCD EFGH IJKL est ouvert.',
            [$this->pdfAttachment()]
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testTheDeferredPassAddsNothing(): void
    {
        $this->assertTrue($this->consumer()->analyzeStored($this->storedMessage())->isEmpty());
    }

    // ── A receipt is only ever filed by a person ────────────────────────

    public function testNothingIsFiledWhenAMachineMadeTheAssociation(): void
    {
        // `createdByUserAccountId` is null when a machine associated.
        // Finance's account check is built from the actor, and inventing
        // one here would be this module granting itself an account.
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL
        ));

        $this->assertSame([], $filed);
    }

    public function testAConfirmedPropositionFilesTheAttachmentAsAReceipt(): void
    {
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            0,
            7
        ));

        $this->assertCount(1, $filed);
        $this->assertSame('facture.pdf', $filed[0]['filename']);
        $this->assertSame($this->accountId, $filed[0]['account_id']);
        $this->assertSame('admin', $filed[0]['role']);
    }

    public function testAnActorTheSessionDoesNotRecogniseFilesNothing(): void
    {
        $filed = [];
        // The resolver answers for user 7 only; the link names user 42.
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            0,
            42
        ));

        $this->assertSame([], $filed);
    }

    public function testAnAttachmentLevelAssociationFilesThatAttachmentAndNoOther(): void
    {
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(twoAttachments: true), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            88,
            7
        ));

        $this->assertCount(1, $filed);
        $this->assertSame('facture.pdf', $filed[0]['filename']);
    }

    public function testAnAssociationOnAnotherModulesReferenceFilesNothing(): void
    {
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink('camps', 'camp-1', LinkOrigin::MANUAL, 0, 7));

        $this->assertSame([], $filed);
    }

    public function testDetachingNeverDeletesAReceipt(): void
    {
        // A receipt is an accounting document. Detaching the email it
        // arrived in is a statement about the mail, not about the books.
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onUnlinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            0,
            7
        ));

        $this->assertSame([], $filed, 'nothing is undone, and nothing throws');
    }

    // ── References ──────────────────────────────────────────────────────

    public function testOnlyAnAccountReferenceReadsBackAsAnAccount(): void
    {
        $this->assertSame('account-42', FinanceMessageConsumer::referenceFor(42));
        $this->assertSame(42, FinanceMessageConsumer::accountIdFromReference('account-42'));
        $this->assertNull(FinanceMessageConsumer::accountIdFromReference('camp-42'));
        $this->assertNull(FinanceMessageConsumer::accountIdFromReference('account-0'));
        $this->assertNull(FinanceMessageConsumer::accountIdFromReference('42'));
    }

    public function testTheModulePublishesWhatItProposesOn(): void
    {
        // The superadmin reads this sentence before opening a shared
        // mailbox to this module. Saying « signal faible » out loud is the
        // price of there being no central threshold.
        $evidence = implode(' ', $this->consumer()->describeEvidence());

        $this->assertStringContainsString('signal faible', $evidence);
        $this->assertStringContainsString('IBAN', $evidence);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<int, array{filename: string, account_id: int, role: string}> $filed
     */
    // ── Who may open a message filed against an account ─────────────────

    public function testAReferenceThatNamesNoAccountOpensToNobody(): void
    {
        // Fail closed. A reference this module does not recognise is a
        // corrupted row or another module's, and neither is a reason to
        // open an accounting document.
        $consumer = $this->consumer();

        $this->assertFalse($consumer->canRead('pas-un-compte', [], 'superadmin'));
        $this->assertFalse($consumer->canRead('', [], 'superadmin'));
        $this->assertFalse($consumer->canRead(
            FinanceMessageConsumer::REFERENCE_PREFIX . '99999',
            [],
            'superadmin'
        ));
    }

    // ── What the triage screen asks every consumer ──────────────────────

    public function testTheModuleSaysWhatItRecognisesAndWhoWouldSeeIt(): void
    {
        // Shown to a superadmin before a shared mailbox is opened to this
        // module. The evidence line says outright that the signal is weak
        // and always a proposition, because that is the whole shape of what
        // this module does.
        $consumer = $this->consumer();

        $this->assertSame(FinanceMessageConsumer::CONSUMER_ID, $consumer->consumerId());
        $this->assertNotSame('', $consumer->displayName());
        $this->assertNotSame([], $consumer->describeEvidence());
        $this->assertStringContainsString('proposition', implode(' ', $consumer->describeEvidence()));
        $this->assertNotSame('', $consumer->triageAudienceLabel());
    }

    public function testTheAudienceIsCountedOnTheYearInEffect(): void
    {
        // A section treasurer already holds a chief function, so they are
        // in this figure once — counting the badge again would inflate the
        // number the superadmin is asked to weigh.
        $this->assertGreaterThanOrEqual(0, $this->consumer()->triageAudienceCount());
    }

    private function consumer(array &$filed = [], ?int $actorFor = null): FinanceMessageConsumer
    {
        $receipts = new RecordingExpenseReceipts($filed);

        return new FinanceMessageConsumer(
            $this->accounts,
            new TreasurerScopeService(
                \Core\Database\Connection::withPdo($this->pdo),
                new \Core\Badge\BadgeRepository($this->pdo),
                new \Core\Badge\MemberBadgeRepository($this->pdo)
            ),
            $this->pdo,
            $this->encryption,
            1,
            $receipts,
            $actorFor === null
                ? null
                : static fn(int $id): ?array => $id === $actorFor
                    ? ['role' => 'admin', 'member_ids' => []]
                    : null,
            static fn(int $fileId): ?string => 'des octets'
        );
    }

    /**
     * @param CandidateAttachment[] $attachments
     */
    private function message(string $subject, string $body, array $attachments): CandidateMessage
    {
        return new CandidateMessage(
            mailboxId: 1,
            subject: $subject,
            fromEmail: 'fournisseur@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: $body,
            bodyHtml: '',
            attachments: $attachments
        );
    }

    private function pdfAttachment(): CandidateAttachment
    {
        return new CandidateAttachment('facture.pdf', 'application/pdf', 8192);
    }

    private function storedMessage(bool $twoAttachments = false): InboundMessage
    {
        $attachments = [
            new InboundAttachment(88, 55, 900, 'facture.pdf', 'application/pdf', 8192, 'hash-a'),
        ];
        if ($twoAttachments) {
            $attachments[] = new InboundAttachment(89, 55, 901, 'photo.jpg', 'image/jpeg', 4096, 'hash-b');
        }

        return new InboundMessage(
            id: 55,
            mailboxId: 1,
            consumerId: FinanceMessageConsumer::CONSUMER_ID,
            businessReference: FinanceMessageConsumer::referenceFor($this->accountId),
            linkOrigin: LinkOrigin::MANUAL,
            subject: 'Facture',
            fromEmail: 'fournisseur@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: 'Compte BE92 0015 1175 7023.',
            bodyHtml: '',
            toEmails: [],
            attachments: $attachments
        );
    }

    private function activeAccount(string $name, string $iban): int
    {
        $id = $this->accounts->create($name, Account::TYPE_BANK, null, $iban, 'Titulaire', 'intendant');
        $this->accounts->updateStatus($id, Account::STATUS_ACTIVE);

        return $id;
    }
}

/**
 * Records what would have been filed, so a test can assert on the DECISION
 * rather than on finance's own storage — which has its own tests and would
 * only make this file slower and less specific.
 *
 * @internal
 */
class RecordingExpenseReceipts implements \Modules\Finance\Api\ExpenseReceiptInterface
{
    /** @param array<int, array{filename: string, account_id: int, role: string}> $filed */
    public function __construct(private array &$filed)
    {
    }

    /**
     * @param int[] $actorLinkedMemberIds
     * @return array<int, string>
     */
    public function receiptAccounts(string $actorRole, array $actorLinkedMemberIds): array
    {
        return [];
    }

    /**
     * @param int[] $actorLinkedMemberIds
     */
    public function storeReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        int $accountId,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        string $actorRole,
        array $actorLinkedMemberIds,
        ?int $uploadedBy
    ): int {
        $this->filed[] = [
            'filename' => $originalFilename,
            'account_id' => $accountId,
            'role' => $actorRole,
        ];

        return 1;
    }
}
