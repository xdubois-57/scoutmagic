<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Repository;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * A message is an entity of its own, associable with zero, one or several
 * business objects.
 *
 * This is the file that pins what the split of `consumer_id` /
 * `business_reference` / `link_origin` out of `inbound_messages` bought:
 * two modules can recognise the same email without it being stored twice,
 * one of them letting go does not destroy the other's copy, and the message
 * only goes when nobody points at it any more.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMessageLinkTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMessageRepository $messages;
    private InboundMailService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $this->encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $this->encryption);
        $this->service = new InboundMailService(
            $this->messages,
            $mailboxes,
            new FileRepository($this->pdo)
        );

        $this->mailboxId = $mailboxes->create(
            'Unité',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            ['INBOX'],
            true
        );
    }

    // ── One message, several owners ─────────────────────────────────────

    public function testOneMessageCarriesTwoConsumersAssociationsAtOnce(): void
    {
        $id = $this->storeMessage('facture-1@example.be');

        $this->assertTrue($this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE));
        $this->assertTrue($this->messages->addLink($id, 'finance', 'ACC-2027-0007', LinkOrigin::SENDER));

        $this->assertSame(2, $this->messages->countLinks($id));
    }

    public function testOneConsumersReadNeverReturnsAnothersAssociation(): void
    {
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($id, 'finance', 'ACC-2027-0007', LinkOrigin::SENDER);

        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0042'));

        // The reference is real, the message is real — they belong to
        // somebody else. Knowing one half of another module's pair must
        // never be enough.
        $this->assertSame([], $this->service->findForReference('rental', 'ACC-2027-0007'));
        $this->assertNull($this->service->findOneForReference('rental', 'ACC-2027-0007', $id));
    }

    public function testAMessageReadThroughOneAssociationStillListsTheOthers(): void
    {
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($id, 'finance', 'ACC-2027-0007', LinkOrigin::SENDER);

        $message = $this->service->findOneForReference('rental', 'LOC-2027-0042', $id);

        $this->assertNotNull($message);
        // The scoped view says which association this read was made
        // through; $links says what else is true of the message.
        $this->assertSame('rental', $message->consumerId);
        $this->assertSame('LOC-2027-0042', $message->businessReference);
        $this->assertCount(2, $message->links);
        $this->assertCount(1, $message->linksFor('finance'));
    }

    // ── Idempotence (A2, D19) ───────────────────────────────────────────

    public function testAssociatingTwiceTowardsTheSameTargetProducesOneAssociation(): void
    {
        $id = $this->storeMessage('facture-1@example.be');

        $this->assertTrue($this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE));
        // Two people orienting the same message towards the same booking.
        // One association, and no error presented to the second.
        $this->assertFalse($this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::MANUAL));

        $this->assertSame(1, $this->messages->countLinks($id));
    }

    public function testAnAttachmentLevelAssociationDoesNotCollideWithTheWholeMessageOne(): void
    {
        $id = $this->storeMessage('facture-1@example.be');

        $this->assertTrue($this->messages->addLink($id, 'finance', 'ACC-1', LinkOrigin::SENDER));
        $this->assertTrue($this->messages->addLink($id, 'finance', 'ACC-1', LinkOrigin::SENDER, 77));

        $this->assertSame(2, $this->messages->countLinks($id));
    }

    // ── Detaching (§7.6, §7.7) ──────────────────────────────────────────

    public function testDetachingOneAssociationLeavesAMessageAnotherModuleStillRecognises(): void
    {
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($id, 'finance', 'ACC-2027-0007', LinkOrigin::SENDER);

        $this->assertTrue($this->service->detach('rental', 'LOC-2027-0042', $id));

        $this->assertSame([], $this->service->findForReference('rental', 'LOC-2027-0042'));
        $this->assertCount(1, $this->service->findForReference('finance', 'ACC-2027-0007'));
        $this->assertSame(1, $this->countRows('inbound_messages'));
    }

    public function testDetachingTheLastAssociationKeepsTheMessageAndItsFiles(): void
    {
        // The behaviour this replaces destroyed the message here, which
        // meant a manager correcting a mis-filing destroyed the very thing
        // they were about to re-file. It now falls back into the unit's
        // general mail and waits for the retention.
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($id, 'finance', 'ACC-2027-0007', LinkOrigin::SENDER);

        $fileId = $this->storeFile();
        $this->messages->addAttachment($id, $fileId, 'contrat.pdf', 'application/pdf', 1024, 'hash-a');

        $this->assertTrue($this->service->detach('rental', 'LOC-2027-0042', $id));
        $this->assertTrue($this->service->detach('finance', 'ACC-2027-0007', $id));

        $this->assertSame(1, $this->countRows('inbound_messages'));
        $this->assertSame(0, $this->countRows('inbound_message_links'));
        $this->assertSame(1, $this->countRows('inbound_message_attachments'));
        $this->assertNotNull((new FileRepository($this->pdo))->findById($fileId));
    }

    public function testDetachingStampsTheGraceClockSoAnOldMessageIsNotPurgedTonight(): void
    {
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $this->assertTrue($this->service->detach('rental', 'LOC-2027-0042', $id));

        $stamp = $this->pdo->query('SELECT last_unlinked_at FROM inbound_messages')->fetchColumn();
        $this->assertNotNull($stamp);
        $this->assertNotSame('', (string) $stamp);
    }

    public function testAReclassifiedAttachmentIsReleasedFromTheMessageRatherThanLeftPointingAtIt(): void
    {
        // Without the release, the retention purge would delete the file
        // ninety days later — taking a booking's signed contract with the
        // email it happened to arrive in.
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $kept = $this->storeFile();
        $dropped = $this->storeFile();
        $this->messages->addAttachment($id, $kept, 'contrat.pdf', 'application/pdf', 1024, 'hash-kept');
        $this->messages->addAttachment($id, $dropped, 'photo.jpg', 'image/jpeg', 2048, 'hash-dropped');

        $this->assertTrue($this->service->detach('rental', 'LOC-2027-0042', $id, [$kept]));

        $this->assertSame([$dropped], $this->messages->findFileIdsForMessage($id));
        $this->assertSame(0, $this->messages->countAttachmentsForFile($kept));

        $message = $this->messages->findAnyForAnalysis($id);
        $this->assertNotNull($message);
        $this->assertCount(1, $message->attachments);
        $this->assertCount(1, $message->omittedAttachments);
        $this->assertSame(
            \Modules\InboundMail\Api\AttachmentOmission::RECLASSIFIED,
            $message->omittedAttachments[0]->reason
        );
    }

    public function testDetachingRefusesAReferenceTheMessageWasNeverAssociatedWith(): void
    {
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $this->assertFalse($this->service->detach('rental', 'LOC-2027-9999', $id));
        $this->assertFalse($this->service->detach('finance', 'LOC-2027-0042', $id));
        $this->assertSame(1, $this->countRows('inbound_messages'));
    }

    // ── Attachment deduplication, now per mailbox (A5) ──────────────────

    public function testTheSameBytesOnTwoMessagesOfOneBoxAreOneStoredFile(): void
    {
        $first = $this->storeMessage('a@example.be');
        $second = $this->storeMessage('b@example.be');
        // Two different business objects — which used to be what scoped the
        // deduplication, and is exactly why it had to stop being that.
        $this->messages->addLink($first, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);
        $this->messages->addLink($second, 'camps', 'camp-3', LinkOrigin::SENDER);

        $fileId = $this->storeFile();
        $this->messages->addAttachment($first, $fileId, 'logo.png', 'image/png', 512, 'shared-hash');

        $this->assertSame(
            $fileId,
            $this->messages->findFileIdByHash($this->mailboxId, 'shared-hash')
        );
        $this->assertNull($this->messages->findFileIdByHash($this->mailboxId, 'other-hash'));
    }

    public function testDeduplicationDoesNotReachIntoAnotherMailbox(): void
    {
        $other = (new InboundMailboxRepository($this->pdo, $this->encryption))->create(
            'Trésorerie',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'tresorerie@unite.be',
            'secret',
            ['INBOX'],
            true
        );

        $id = $this->storeMessage('a@example.be');
        $this->messages->addAttachment($id, $this->storeFile(), 'logo.png', 'image/png', 512, 'shared-hash');

        $this->assertNull($this->messages->findFileIdByHash($other, 'shared-hash'));
    }

    // ── Deduplication of the message itself, now per mailbox ────────────

    public function testAMessageIsFoundInItsBoxWhateverItIsAssociatedWith(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'camps', 'unsorted', LinkOrigin::SENDER);

        // A manual filing moved it. A re-read after a UIDVALIDITY reset
        // must still recognise it rather than storing a second copy.
        $this->messages->moveToReference($id, 'camps', 'unsorted', 'camp-3');

        $this->assertSame($id, $this->messages->findIdByMessageId($this->mailboxId, 'a@example.be'));
    }

    public function testMovingOntoAnAssociationThatAlreadyExistsMergesRatherThanDuplicates(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'camps', 'unsorted', LinkOrigin::SENDER);
        $this->messages->addLink($id, 'camps', 'camp-3', LinkOrigin::THREAD);

        $this->assertTrue($this->messages->moveToReference($id, 'camps', 'unsorted', 'camp-3'));

        $this->assertSame(1, $this->messages->countLinks($id));
        $this->assertTrue($this->messages->hasLink($id, 'camps', 'camp-3'));
    }

    // ── The one-time reprise ────────────────────────────────────────────

    public function testTheBackfillTurnsEveryLegacyTripletIntoAnAssociation(): void
    {
        $first = $this->storeMessage('a@example.be');
        $second = $this->storeMessage('b@example.be');
        $this->writeLegacyColumns($first, 'rental', 'LOC-2027-0042', 'reference');
        $this->writeLegacyColumns($second, 'camps', 'camp-3', 'sender');

        $this->assertSame(2, $this->messages->backfillLinks());

        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0042'));
        $this->assertCount(1, $this->service->findForReference('camps', 'camp-3'));
    }

    public function testTheBackfillIsIdempotent(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->writeLegacyColumns($id, 'rental', 'LOC-2027-0042', 'reference');

        $this->assertSame(1, $this->messages->backfillLinks());
        // Second run: nothing new, and above all nothing duplicated.
        $this->assertSame(0, $this->messages->backfillLinks());

        $this->assertSame(1, $this->messages->countLinks($id));
    }

    public function testTheBackfillKeepsAnAssociationWhoseOriginThisBuildNoLongerKnows(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->writeLegacyColumns($id, 'rental', 'LOC-2027-0042', 'telepathy');

        $this->assertSame(1, $this->messages->backfillLinks());

        $message = $this->service->findOneForReference('rental', 'LOC-2027-0042', $id);
        $this->assertNotNull($message);
        // The association survives; only the label for how it was decided
        // is lost, and it falls back to an origin never presented as
        // certain rather than to one that is.
        $this->assertFalse($message->linkOrigin->isCertain());
    }

    public function testTheBackfillLeavesAnAlreadyMigratedInstallationAlone(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::MANUAL);

        // No legacy values anywhere: nothing to carry over.
        $this->assertSame(0, $this->messages->backfillLinks());
        $this->assertSame(1, $this->messages->countLinks($id));
    }

    // ── The reprise of the attachments' ownership ───────────────────────

    public function testTheOwnerBackfillGivesEveryOrphanedAttachmentItsMessage(): void
    {
        $id = $this->storeMessage('a@example.be');
        $files = new FileRepository($this->pdo);
        $fileId = $this->storeFile();
        $this->messages->addAttachment($id, $fileId, 'contrat.pdf', 'application/pdf', 1024, 'hash-a');

        // Written before the ownership existed: gated by its role_min
        // floor alone, which is to say readable by any intendant.
        $this->assertNull($files->findById($fileId)?->ownerType);

        $this->assertSame(1, $this->service->backfillAttachmentOwners());

        $file = $files->findById($fileId);
        $this->assertNotNull($file);
        $this->assertSame('inbound_message', $file->ownerType);
        $this->assertSame($id, $file->ownerId);
    }

    public function testTheOwnerBackfillIsIdempotent(): void
    {
        $id = $this->storeMessage('a@example.be');
        $this->messages->addAttachment($id, $this->storeFile(), 'contrat.pdf', 'application/pdf', 1024, 'hash-a');

        $this->assertSame(1, $this->service->backfillAttachmentOwners());
        $this->assertSame(0, $this->service->backfillAttachmentOwners());
    }

    public function testASharedFileKeepsAnOwnerThatStillHoldsItAfterTheFirstOneGoes(): void
    {
        // Deduplication: two messages, one stored file, and `files.owner_id`
        // naming only one of them. Destroying that one must hand the
        // ownership over — otherwise the file points at a message that no
        // longer exists, the access registry finds no associations to ask
        // about, and the people who may read it are locked out. The
        // destruction is now purgeReference()'s, since detaching no longer
        // destroys anything.
        $first = $this->storeMessage('a@example.be');
        $second = $this->storeMessage('b@example.be');
        $this->messages->addLink($first, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->messages->addLink($second, 'rental', 'LOC-2', LinkOrigin::REFERENCE);

        $files = new FileRepository($this->pdo);
        $fileId = $this->storeFile();
        $files->updateOwner($fileId, 'inbound_message', $first);
        $this->messages->addAttachment($first, $fileId, 'logo.png', 'image/png', 512, 'shared');
        $this->messages->addAttachment($second, $fileId, 'logo.png', 'image/png', 512, 'shared');

        $this->assertSame(1, $this->service->purgeReference('rental', 'LOC-1'));

        $file = $files->findById($fileId);
        $this->assertNotNull($file, 'The second message still holds these bytes.');
        $this->assertSame($second, $file->ownerId);
    }

    public function testPurgingABusinessObjectStillDestroysItsMailAndItsFiles(): void
    {
        // The one path that still erases: it is a consumer's own RGPD
        // deletion of the object, where the promise to the person concerned
        // is that the mail attached to their file goes with the file.
        $id = $this->storeMessage('facture-1@example.be');
        $this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE);

        $fileId = $this->storeFile();
        $this->messages->addAttachment($id, $fileId, 'contrat.pdf', 'application/pdf', 1024, 'hash-a');

        $this->assertSame(1, $this->service->purgeReference('rental', 'LOC-2027-0042'));

        $this->assertSame(0, $this->countRows('inbound_messages'));
        $this->assertSame(0, $this->countRows('inbound_message_attachments'));
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    // ── An association answers the propositions it was asked about ─────

    public function testAWholeMessageAssociationSetsAsideEveryPropositionOfThatConsumer(): void
    {
        // The propositions were alternatives to one question — « which of
        // these two bookings? » — and a person answering it by filing the
        // message under LOC-42 has answered it. A proposition that survived
        // its own answer kept asking on the next page load, and kept the
        // message out of the retention purge for ever.
        $id = $this->storeMessage('settled@mail');
        $this->messages->addCandidate($id, 'rental', $this->candidate('LOC-2027-0042'));
        $this->messages->addCandidate($id, 'rental', $this->candidate('LOC-2027-0043'));
        $this->messages->addCandidate($id, 'finance', $this->candidate('account-3'));

        $this->assertTrue($this->messages->addLink($id, 'rental', 'LOC-2027-0042', LinkOrigin::MANUAL));

        $standing = $this->messages->findActiveCandidates($id);
        $this->assertCount(1, $standing);
        $this->assertSame('finance', $standing[0]->consumerId, 'another module\'s question is still open');
    }

    public function testAnAttachmentLevelAssociationSetsAsideOnlyThePropositionsAboutThatAttachment(): void
    {
        $id = $this->storeMessage('partial@mail');
        $this->messages->addCandidate($id, 'finance', $this->candidate('account-3', 7));
        $this->messages->addCandidate($id, 'finance', $this->candidate('account-3', 8));

        $this->messages->addLink($id, 'finance', 'account-3', LinkOrigin::MANUAL, 7);

        $standing = $this->messages->findActiveCandidates($id);
        $this->assertCount(1, $standing);
        $this->assertSame(8, $standing[0]->attachmentId);
    }

    private function candidate(string $reference, int $attachmentId = 0): MessageCandidate
    {
        return new MessageCandidate($reference, 'Cible', 'sender_window', 'Explication', $attachmentId);
    }

    private function storeMessage(string $messageId): int
    {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Facture',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    private function storeFile(): int
    {
        return (new FileRepository($this->pdo))->create(
            'inbound_mail/attachments/' . bin2hex(random_bytes(8)) . '.pdf',
            'contrat.pdf',
            'application/pdf',
            1024,
            'intendant',
            'inbound_mail',
            null
        );
    }

    /**
     * Put an installation back into the shape it had before
     * inbound_message_links existed: the association lived in the message's
     * own columns.
     */
    private function writeLegacyColumns(int $messageId, string $consumerId, string $reference, string $origin): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_messages SET consumer_id = ?, business_reference = ?, link_origin = ? WHERE id = ?'
        );
        $stmt->execute([$consumerId, $reference, $origin, $messageId]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
