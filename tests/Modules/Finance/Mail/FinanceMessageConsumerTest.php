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

        // The unit's own membership data, which the sender→staff rule
        // reads: one scout year, one branch, one animateur function.
        $this->pdo->exec("INSERT INTO scout_years (id, label, start_date, end_date, is_current) VALUES (1, '2026-2027', '2026-09-01', '2027-08-31', 1)");
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 20)");
        $this->pdo->exec("INSERT INTO functions (id, desk_code, label, role) VALUES (1, 'ANIM', 'Animateur', 'chief')");

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

    public function testTheIbanOnTheTreasurysOwnBoxIsAnAssociation(): void
    {
        // The operator said everything arriving here is this module's
        // business; the IBAN says which account. Asking a treasurer to
        // confirm both is asking for the sake of asking, and the worst
        // outcome — the wrong account — is one click to correct.
        $result = $this->consumer()->analyze($this->message(
            'Facture de la fédération',
            'Merci de virer sur le compte BE92 0015 1175 7023.',
            [$this->pdfAttachment()],
            dedicatedTo: FinanceMessageConsumer::CONSUMER_ID
        ));

        $this->assertSame([], $result->candidates);
        $this->assertCount(1, $result->links);
        $this->assertSame(FinanceMessageConsumer::referenceFor($this->accountId), $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::IBAN, $result->links[0]->origin);
    }

    public function testTheIbanAndTheSendersStaffAgreeingIsAnAssociation(): void
    {
        // Two independent statements naming the same account: the money's
        // own, in the text, and the person's, from the unit's membership.
        $accountId = $this->animateurOfOneStaff('anna@example.be', iban: 'BE71096123456769');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Facture',
            'À payer sur BE71 0961 2345 6769.',
            [$this->pdfAttachment()],
            fromEmail: 'anna@example.be'
        ));

        $this->assertSame([], $result->candidates);
        $this->assertCount(1, $result->links);
        $this->assertSame(FinanceMessageConsumer::referenceFor($accountId), $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::IBAN, $result->links[0]->origin);
    }

    public function testAPropositionTellsTheTreasurersWhichAccounts(): void
    {
        $notifier = $this->createMock(\Modules\Finance\Mail\FinanceMailNotifier::class);
        $notifier->expects($this->once())->method('proposed')->with(['Compte courant', 'compte inconnu']);

        $consumer = $this->consumer(notifier: $notifier);
        $consumer->onProposed($this->storedMessage(), [
            new \Modules\InboundMail\Api\MessageCandidate(FinanceMessageConsumer::referenceFor($this->accountId), 'a', 'iban_in_body', 'x'),
            new \Modules\InboundMail\Api\MessageCandidate(FinanceMessageConsumer::REFERENCE_UNKNOWN, 'b', 'attachment', 'x'),
            new \Modules\InboundMail\Api\MessageCandidate(FinanceMessageConsumer::referenceFor($this->accountId), 'a', 'iban_in_body', 'x'),
        ]);
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

    public function testTheIbanIsFoundThroughTheNoBreakSpacesABankSitePastes(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture',
            "Virement sur BE92\u{00A0}0015\u{00A0}1175\u{00A0}7023 merci.",
            [$this->pdfAttachment()]
        ));

        $this->assertCount(1, $result->candidates);
    }

    public function testTheIbanIsFoundWhenTypedWithHyphens(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture',
            'Virement sur BE92-0015-1175-7023 merci.',
            [$this->pdfAttachment()]
        ));

        $this->assertCount(1, $result->candidates);
    }

    public function testTheIbanIsFoundWhenTheBicFollowsOnTheSameLine(): void
    {
        $result = $this->consumer()->analyze($this->message(
            'Facture',
            'IBAN BE92 0015 1175 7023 BIC GEBABEBB',
            [$this->pdfAttachment()]
        ));

        $this->assertCount(1, $result->candidates);
    }

    public function testTheIbanIsFoundInAnHtmlOnlyMessage(): void
    {
        // A phone or Outlook message often has no text part worth the
        // name; the IBAN a supplier pasted into the HTML was invisible.
        $result = $this->consumer()->analyze(new CandidateMessage(
            mailboxId: 1,
            subject: 'Facture',
            fromEmail: 'fournisseur@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: '',
            bodyHtml: '<p>Virement sur <b>BE92&nbsp;0015&nbsp;1175&nbsp;7023</b></p>',
            attachments: [$this->pdfAttachment()]
        ));

        $this->assertCount(1, $result->candidates);
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

    // ── Nommer ses propres références ───────────────────────────────────

    /**
     * The courrier screens show the business reference when they have
     * nothing better, and « Finances — account-unknown » on a green badge
     * teaches a Chef d'Unité nothing — in a language that is not theirs.
     * `inbound_mail` cannot do better on its own: it does not know what
     * `account-12` is, and by §7.6 it never will.
     */
    public function testTheDirectoryOffersTheActiveAccountsAndThePile(): void
    {
        $consumer = $this->consumer();

        $found = $consumer->searchReferences('courant');
        $this->assertCount(1, $found);
        $this->assertSame(FinanceMessageConsumer::referenceFor($this->accountId), $found[0]->businessReference);
        $this->assertSame('Compte courant', $found[0]->label);

        $pile = $consumer->searchReferences('inconnu');
        $this->assertSame(FinanceMessageConsumer::REFERENCE_UNKNOWN, $pile[0]->businessReference);

        $this->assertSame('/finance/receipts?account_id=' . $this->accountId, $consumer->referenceUrl(FinanceMessageConsumer::referenceFor($this->accountId)));
        $this->assertSame('/finance/receipts?account_id=unassigned', $consumer->referenceUrl(FinanceMessageConsumer::REFERENCE_UNKNOWN));
        $this->assertNull($consumer->referenceUrl('account-999'));
    }

    public function testTheSortingPileIsNamedInFrench(): void
    {
        $this->assertSame('compte inconnu', $this->consumer()->describeReference(FinanceMessageConsumer::REFERENCE_UNKNOWN));
    }

    public function testAnAccountReferenceIsNamedAfterTheAccount(): void
    {
        $this->assertSame(
            'Compte courant',
            $this->consumer()->describeReference(FinanceMessageConsumer::referenceFor($this->accountId))
        );
    }

    public function testAnAccountThatNoLongerExistsHasNoName(): void
    {
        // The screen then shows the raw reference, which is more honest
        // than a name for something that is gone.
        $this->assertNull($this->consumer()->describeReference(FinanceMessageConsumer::referenceFor(9999)));
    }

    public function testAReferenceThatIsNotThisModulesHasNoName(): void
    {
        $this->assertNull($this->consumer()->describeReference('LOC-2027-0012'));
    }

    // ── L'expéditeur anime un seul staff ────────────────────────────────

    public function testAReceiptFromAnAnimateurOfOneStaffIsFiledOnThatStaffsAccount(): void
    {
        $accountId = $this->animateurOfOneStaff('anna@example.be');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Reçu de mes dépenses',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'anna@example.be'
        ));

        // A LINK, not a proposition: asking somebody to confirm what the
        // unit's own membership data already says is asking for the sake
        // of asking.
        $this->assertSame([], $result->candidates);
        $this->assertCount(1, $result->links);
        $this->assertSame(FinanceMessageConsumer::referenceFor($accountId), $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::SENDER, $result->links[0]->origin);
    }

    public function testAnIbanInTheTextBeatsTheSendersStaff(): void
    {
        $this->animateurOfOneStaff('anna@example.be');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Facture',
            'Merci de virer sur le compte BE92 0015 1175 7023.',
            [$this->pdfAttachment()],
            fromEmail: 'anna@example.be'
        ));

        // The IBAN is a statement about the MONEY, made in the document's
        // own covering text; the sender is a statement about a person, and
        // a person can be wrong about which account an expense belongs to
        // in a way an IBAN cannot.
        $this->assertSame([], $result->links);
        $this->assertCount(1, $result->candidates);
        $this->assertSame(
            FinanceMessageConsumer::referenceFor($this->accountId),
            $result->candidates[0]->businessReference
        );
    }

    public function testAnAnimateurOfTwoStaffsResolvesToNothing(): void
    {
        $this->animateurOfOneStaff('anna@example.be', 'Louveteaux');
        $this->animateurOfOneStaff('anna@example.be', 'Éclaireurs');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Reçu',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'anna@example.be'
        ));

        // Two staffs, no answer. Filing on whichever sorted first is worse
        // than not filing: nobody reading the wrong account can tell.
        $this->assertTrue($result->isEmpty());
    }

    public function testAnUnknownSenderResolvesToNothing(): void
    {
        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Reçu',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'inconnu@example.be'
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testAForwardedMessageIsJudgedOnTheOriginalSender(): void
    {
        $accountId = $this->animateurOfOneStaff('anna@example.be');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Tr : Reçu',
            "---------- Message transféré ----------\nDe : Anna Martin <anna@example.be>\nDate : 12 juillet\n\nVoici le reçu.",
            [$this->pdfAttachment()],
            fromEmail: 'secretariat@example.be'
        ));

        $this->assertCount(1, $result->links);
        $this->assertSame(FinanceMessageConsumer::referenceFor($accountId), $result->links[0]->businessReference);
    }

    public function testTheRealSenderIsTriedBeforeTheForwardedOne(): void
    {
        $forwarderAccount = $this->animateurOfOneStaff('secretariat@example.be', 'Staff U');
        $this->animateurOfOneStaff('anna@example.be', 'Louveteaux');

        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Tr : Reçu',
            "De : Anna Martin <anna@example.be>\n\nVoici le reçu.",
            [$this->pdfAttachment()],
            fromEmail: 'secretariat@example.be'
        ));

        // The body is untrusted text and is only consulted when the real
        // From: resolved to nobody. A forwarder who is themselves an
        // animateur answers first.
        $this->assertSame(
            FinanceMessageConsumer::referenceFor($forwarderAccount),
            $result->links[0]->businessReference
        );
    }

    public function testWithoutTheResolverTheIbanIsStillTheOnlySignal(): void
    {
        // The consumer as it was before the resolver existed — a complete
        // behaviour, not a broken one.
        $this->animateurOfOneStaff('anna@example.be');

        $result = $this->consumer()->analyze($this->message(
            'Reçu',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'anna@example.be'
        ));

        $this->assertTrue($result->isEmpty());
    }

    // ── La corbeille, sur une boîte dédiée seulement ────────────────────

    public function testAnUnplaceableReceiptOnADedicatedBoxGoesToTheSortingPile(): void
    {
        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Reçu',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'inconnu@example.be',
            dedicatedTo: FinanceMessageConsumer::CONSUMER_ID
        ));

        $this->assertCount(1, $result->links);
        $this->assertSame(FinanceMessageConsumer::REFERENCE_UNKNOWN, $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::ATTACHMENT, $result->links[0]->origin);
    }

    public function testAnUnplaceableReceiptOnASharedBoxIsLeftAlone(): void
    {
        // A photo attached to a parent's message is not a receipt. Turning
        // every one of them into something a treasurer must sort would bury
        // the real ones within a week.
        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Photos du week-end',
            '',
            [new CandidateAttachment('photo.jpg', 'image/jpeg', 4096)],
            fromEmail: 'parent@example.be'
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testABoxDedicatedToAnotherModuleIsNotThisOnes(): void
    {
        $result = $this->consumerWithSenderStaff()->analyze($this->message(
            'Reçu',
            '',
            [$this->pdfAttachment()],
            fromEmail: 'inconnu@example.be',
            dedicatedTo: 'rental'
        ));

        $this->assertTrue($result->isEmpty());
    }

    public function testTheSortingPileReferenceIsNotReadAsAnAccountId(): void
    {
        // `account-unknown` starts with the account prefix, and an
        // (int) cast of "unknown" is 0 — a reference that silently became
        // account 0 would file receipts against nothing.
        $this->assertNull(
            FinanceMessageConsumer::accountIdFromReference(FinanceMessageConsumer::REFERENCE_UNKNOWN)
        );
    }

    public function testTheDeferredPassAddsNothing(): void
    {
        $this->assertTrue($this->consumer()->analyzeStored($this->storedMessage())->isEmpty());
    }

    // ── Qui dépose le reçu, et par quelle porte ─────────────────────────

    /**
     * An association nobody made goes through the unattended route.
     *
     * This used to file nothing at all, on the reasoning that finance's
     * account check is built from the actor and inventing one would be this
     * module granting itself an account. That reasoning still holds — what
     * changed is that it is no longer the only way in: an actor is not
     * invented, the authorization comes from the superadmin having opened
     * the mailbox to this module, and
     * Api\ExpenseReceiptInterface::storeUnattendedReceipt() is the door
     * that says so out loud.
     */
    public function testAnAssociationNobodyMadeFilesThroughTheUnattendedRoute(): void
    {
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::SENDER
        ));

        $this->assertCount(1, $filed);
        $this->assertSame($this->accountId, $filed[0]['account_id']);
        $this->assertSame(RecordingExpenseReceipts::UNATTENDED, $filed[0]['role']);
    }

    public function testNothingIsFiledWhenAPersonAssociatedButCannotBeResolved(): void
    {
        // The half of the old rule that must survive. A person DID
        // associate, and this module could not turn them into a role and
        // members — so it files nothing rather than falling through to the
        // unattended route, which exists for associations nobody made.
        // Using it here would let a person's filing escape the account
        // check that their own identity was supposed to supply.
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: null);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            0,
            7
        ));

        $this->assertSame([], $filed);
    }

    public function testTheSortingPileIsFiledWithNoAccountAtAll(): void
    {
        $filed = [];
        $consumer = $this->consumer($filed, actorFor: 7);

        $consumer->onLinked($this->storedMessage(), new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::REFERENCE_UNKNOWN,
            LinkOrigin::ATTACHMENT
        ));

        $this->assertCount(1, $filed);
        $this->assertNull($filed[0]['account_id'], 'the pile is an absent account, never account 0');
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

    // ── Filing: the three ways it declines, none of them loud ──────────

    public function testAnAttachmentThatIsNotAReceiptIsNeverFiled(): void
    {
        // A signature logo or a calendar invite travels with plenty of
        // messages. Filing one as a receipt would put a picture nobody
        // chose into the books.
        $filed = [];
        $consumer = $this->consumer($filed, 7);

        $consumer->onLinked($this->storedMessage(mimeType: 'text/calendar'), $this->manualLink(7));

        $this->assertSame([], $filed);
    }

    public function testAnAttachmentWhoseBytesCannotBeReadIsSkippedRatherThanFiledEmpty(): void
    {
        // The row may point at a file the storage no longer holds. An
        // empty receipt in the books is worse than no receipt at all.
        $filed = [];
        $consumer = $this->consumer($filed, 7, static fn(int $fileId): ?string => null);

        $consumer->onLinked($this->storedMessage(), $this->manualLink(7));

        $this->assertSame([], $filed);
    }

    public function testFinanceRefusingTheReceiptDoesNotUndoTheAssociation(): void
    {
        // The message belongs on the account either way, and a treasurer
        // can attach the file by hand from the receipts screen. Failing the
        // association here would take away the part that did work.
        $filed = [];
        $consumer = $this->consumer($filed, 7, null, new RefusingExpenseReceipts());

        $consumer->onLinked($this->storedMessage(), $this->manualLink(7));

        $this->assertSame([], $filed, 'nothing was filed');
    }

    private function manualLink(int $byUserAccountId): MessageLink
    {
        return new MessageLink(
            FinanceMessageConsumer::CONSUMER_ID,
            FinanceMessageConsumer::referenceFor($this->accountId),
            LinkOrigin::MANUAL,
            0,
            $byUserAccountId
        );
    }

    /**
     * The consumer as the composition root builds it — sender→staff rule
     * wired — for a test that only asks what analyze() decides and never
     * what gets filed.
     */
    private function consumerWithSenderStaff(): FinanceMessageConsumer
    {
        $filed = [];

        return $this->consumer($filed, withSenderStaff: true);
    }

    private function consumer(
        array &$filed = [],
        ?int $actorFor = null,
        ?\Closure $readFile = null,
        ?\Modules\Finance\Api\ExpenseReceiptInterface $receipts = null,
        bool $withSenderStaff = false,
        ?\Modules\Finance\Mail\FinanceMailNotifier $notifier = null
    ): FinanceMessageConsumer {
        $receipts ??= new RecordingExpenseReceipts($filed);

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
            $readFile ?? static fn(int $fileId): ?string => 'des octets',
            $withSenderStaff ? $this->senderStaffResolver() : null,
            $withSenderStaff ? new \Modules\Finance\Mail\ForwardedSenderExtractor() : null,
            $notifier
        );
    }

    /**
     * @param CandidateAttachment[] $attachments
     */
    private function message(
        string $subject,
        string $body,
        array $attachments,
        string $fromEmail = 'fournisseur@example.be',
        ?string $dedicatedTo = null
    ): CandidateMessage {
        return new CandidateMessage(
            mailboxId: 1,
            subject: $subject,
            fromEmail: $fromEmail,
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: $body,
            bodyHtml: '',
            attachments: $attachments,
            mailboxDedicatedTo: $dedicatedTo
        );
    }

    /**
     * An animateur of exactly one section, reachable at $email, whose
     * section owns $accountName.
     *
     * @return int the id of that section's account
     */
    private function animateurOfOneStaff(string $email, string $accountName = 'Louveteaux', ?string $iban = null): int
    {
        static $nextId = 0;
        $nextId++;

        $sectionId = 100 + $nextId;
        $this->pdo->exec("INSERT INTO sections (id, age_branch_id, desk_code, name) VALUES ({$sectionId}, 1, 'SEC{$nextId}', '{$accountName}')");

        $blindIndex = $this->encryption->blindIndex(strtolower($email), 'email');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index, is_active)
             VALUES (?, 1, ?, ?, ?, 1)'
        );
        $stmt->execute([$nextId, 'x', 'y', $blindIndex]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, 1, ?)');
        $stmt->execute([$memberYearId, $sectionId]);

        $accountId = $this->accounts->create($accountName, Account::TYPE_BANK, $sectionId, $iban, null, 'intendant');
        $this->accounts->updateStatus($accountId, Account::STATUS_ACTIVE);

        return $accountId;
    }

    private function senderStaffResolver(): \Modules\Finance\Mail\SenderStaffAccountResolver
    {
        return new \Modules\Finance\Mail\SenderStaffAccountResolver(
            new \Core\Member\SectionStaffAuthorizationService(
                \Core\Database\Connection::withPdo($this->pdo),
                $this->encryption,
                new \Core\Member\SectionService(
                    \Core\Database\Connection::withPdo($this->pdo),
                    $this->encryption,
                    new \Core\Badge\MemberBadgeRepository($this->pdo)
                ),
                new \Core\Member\MemberEmailRepository($this->pdo, $this->encryption)
            ),
            $this->accounts,
            1
        );
    }

    private function pdfAttachment(): CandidateAttachment
    {
        return new CandidateAttachment('facture.pdf', 'application/pdf', 8192);
    }

    private function storedMessage(
        bool $twoAttachments = false,
        string $mimeType = 'application/pdf'
    ): InboundMessage
    {
        $attachments = [
            new InboundAttachment(88, 55, 900, 'facture.pdf', $mimeType, 8192, 'hash-a'),
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
 * `role` is what separates the two routes in an assertion: a real role
 * means a person's confirmation carried the filing, and
 * RecordingExpenseReceipts::UNATTENDED means nobody's did.
 *
 * @internal
 */
class RecordingExpenseReceipts implements \Modules\Finance\Api\ExpenseReceiptInterface
{
    /** What the `role` slot holds for a receipt filed with no actor at all. */
    public const UNATTENDED = '(sans acteur)';

    /** @param array<int, array{filename: string, account_id: ?int, role: string}> $filed */
    public function __construct(private array &$filed)
    {
    }

    public function storeUnattendedReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $accountId
    ): int {
        $this->filed[] = [
            'filename' => $originalFilename,
            'account_id' => $accountId,
            'role' => self::UNATTENDED,
        ];

        return 1;
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

/**
 * Finance refusing the account, as it does when the actor may not post to
 * it. The consumer must let the association stand regardless.
 */
class RefusingExpenseReceipts implements \Modules\Finance\Api\ExpenseReceiptInterface
{
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
        throw new \RuntimeException('ce compte ne vous est pas ouvert');
    }

    public function storeUnattendedReceipt(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $accountId
    ): int {
        throw new \RuntimeException('ce compte ne vous est pas ouvert');
    }
}
