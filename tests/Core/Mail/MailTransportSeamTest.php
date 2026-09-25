<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailServiceFactory;
use Core\Mail\MailTransportInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * The transport seam (ARCHITECTURE.md §8.7): MailService still assembles
 * the whole message, a transport only delivers it. These tests assert the
 * assembly is unchanged by looking at what the transport receives.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailTransportSeamTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailtransport_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public ?PHPMailer $received = null;
            public ?MailPurpose $purpose = null;
            public int $calls = 0;

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->received = $mail;
                $this->purpose = $purpose;
                $this->calls++;
            }
        };
    }

    private function serviceWith(MailTransportInterface $transport): MailService
    {
        return new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport
        );
    }

    public function testTheTransportReceivesTheFullyConfiguredMessage(): void
    {
        $transport = $this->recordingTransport();
        $attachment = $this->tempDir . '/note.txt';
        file_put_contents($attachment, 'contenu');

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            replyTo: 'reponse@example.be',
            attachments: [['path' => $attachment, 'name' => 'note.txt']],
            extraHeaders: ['List-Unsubscribe' => '<mailto:stop@example.be>']
        );

        $this->assertSame(1, $transport->calls);
        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);

        $this->assertSame('[25SV] Bonjour', $mail->Subject);
        $this->assertSame('noreply@example.be', $mail->From);
        $this->assertSame('Unité Exemple', $mail->FromName);
        $this->assertSame('noreply@example.be', $mail->Sender);
        $this->assertSame([['destinataire@example.be', '']], $mail->getToAddresses());
        $this->assertSame([['reponse@example.be', '']], array_values($mail->getReplyToAddresses()));
        $this->assertSame('<p>Bonjour</p>', $mail->Body);
        $this->assertSame('Bonjour', $mail->AltBody);
        $this->assertSame('UTF-8', $mail->CharSet);

        $attachments = $mail->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('note.txt', $attachments[0][2]);

        $this->assertSame(
            [['List-Unsubscribe', '<mailto:stop@example.be>']],
            $mail->getCustomHeaders()
        );
    }

    public function testAFromOverrideReachesTheTransportButNeverTheEnvelopeSender(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            fromAddressOverride: 'section@example.be',
            fromNameOverride: 'Section'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame('section@example.be', $mail->From);
        $this->assertSame('Section', $mail->FromName);
        $this->assertSame('noreply@example.be', $mail->Sender);
    }

    public function testDkimParametersReachTheTransportWhenAKeyIsConfigured(): void
    {
        $dkimManager = new DkimManager($this->tempDir);
        $dkimManager->generateKey();

        $transport = $this->recordingTransport();
        $service = new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: $dkimManager,
            dkimSelector: 'mail',
            transport: $transport
        );

        $service->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $mail = $transport->received;
        $this->assertInstanceOf(PHPMailer::class, $mail);
        $this->assertSame('example.be', $mail->DKIM_domain);
        $this->assertSame('mail', $mail->DKIM_selector);
        $this->assertSame('noreply@example.be', $mail->DKIM_identity);
        $this->assertNotSame('', $mail->DKIM_private);
    }

    public function testATransportThatThrowsIsStillSurfacedAsAMailException(): void
    {
        $transport = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('transport en panne');
            }
        };

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('transport en panne');

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );
    }

    public function testTheFactoryPassesTheTransportThrough(): void
    {
        $transport = $this->recordingTransport();
        $service = MailServiceFactory::create(
            [
                'mail_mode' => 'local',
                'mail_from_address' => 'noreply@example.be',
                'mail_from_name' => 'Unité Exemple',
                'short_name' => '25SV',
            ],
            new DkimManager($this->tempDir),
            $transport
        );

        $service->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(1, $transport->calls);
    }

    /**
     * The purpose is a DELIVERY category, and its default is what keeps
     * the ~95 call sites of send() from ever naming it (MailPurpose).
     */
    public function testEveryOrdinaryCallSiteDeliversWithTheDefaultPurpose(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(MailPurpose::Ordinary, $transport->purpose);
    }

    public function testAStatedPurposeReachesTheTransportUnchanged(): void
    {
        $transport = $this->recordingTransport();

        $this->serviceWith($transport)->send(
            to: 'destinataire@example.be',
            subject: 'Votre lien de connexion',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            purpose: MailPurpose::MagicLink
        );

        $this->assertSame(MailPurpose::MagicLink, $transport->purpose);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ── the send receipt, and the one send that must not mint one ─────

    private function receiptsOver(\PDO $pdo): \Core\Mail\Feedback\Bounce\BounceStateRepository
    {
        return new \Core\Mail\Feedback\Bounce\BounceStateRepository(
            $pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private function serviceWithReceipts(
        MailTransportInterface $transport,
        \Core\Mail\Feedback\Bounce\BounceStateRepository $receipts
    ): \Core\Mail\MailService {
        return new \Core\Mail\MailService(
            mode: 'local',
            fromAddress: 'unite@example.com',
            fromName: 'Unité',
            shortName: '25SV',
            dkimManager: new \Core\Mail\DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            sendReceipts: $receipts
        );
    }

    private function addressOnFile(\PDO $pdo, string $email): void
    {
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute([
                $encryption->encrypt($email, 'user_accounts.email'),
                $encryption->blindIndex(
                    \Core\Security\EncryptionService::normalizeEmailForIndex($email),
                    'email'
                ),
            ]);
    }

    /**
     * A message to an address the site already holds is the site deciding
     * to write somewhere, so it is evidence a bounce naming that address
     * can be believed.
     */
    public function testASendToAnAddressOnFileIsNotedAsProof(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $receipts = $this->receiptsOver($pdo);
        $this->addressOnFile($pdo, 'parent@exemple.be');

        $this->serviceWithReceipts($this->recordingTransport(), $receipts)
            ->send('parent@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);

        $this->assertNotNull($receipts->lastSendAt('parent@exemple.be'));
    }

    /**
     * **And an on-file address is not enough on its own.** A signed-in
     * member can claim another member's confirmed address as their own
     * `pending` secondary — the unique index is per member — and the
     * confirmation that follows is aimed at the victim. Anyone can also
     * type a member's address into a public form. In both cases the
     * address IS on the site's books, so asking only the address mints
     * the receipt for the victim and the forged-report attack is back.
     *
     * A caller that has not said the site chose this recipient therefore
     * vouches for nothing, whoever the address belongs to.
     */
    public function testAnOnFileAddressStillNeedsTheSiteToHaveChosenIt(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $receipts = $this->receiptsOver($pdo);
        $this->addressOnFile($pdo, 'victime@exemple.be');

        $this->serviceWithReceipts($this->recordingTransport(), $receipts)
            ->send('victime@exemple.be', 'Confirmez', '<p>x</p>', 'x');

        $this->assertNull(
            $receipts->lastSendAt('victime@exemple.be'),
            'a send somebody else aimed must not vouch for its target.'
        );
    }

    /**
     * **But an address the site was merely handed vouches for nothing**,
     * and nothing at the call site has to remember that.
     *
     * This covers the confirmation of a freshly claimed secondary address
     * — any signed-in member can name any address there — and equally the
     * public forms that mail a visitor-supplied address, and the
     * deferred-mail queue replaying either of them. An earlier attempt
     * put the decision in a `send()` parameter defaulting to « yes »:
     * 42 call sites had to remember it and the deferred queue dropped it
     * on replay. Derived from the address, there is nothing to forget.
     */
    public function testASendToAnAddressTheSiteWasMerelyHandedIsNotProof(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $receipts = $this->receiptsOver($pdo);

        $this->serviceWithReceipts($this->recordingTransport(), $receipts)
            ->send('victime@exemple.be', 'Confirmez', '<p>x</p>', 'x', vouchesForRecipient: true);

        $this->assertNull(
            $receipts->lastSendAt('victime@exemple.be'),
            'an address nobody has proven vouches for nothing, even when the caller says otherwise.'
        );
    }

    /**
     * **A bookkeeping failure must never be reported as a delivery
     * failure.** The message has already left by the time the receipt is
     * written, and that write is several statements against the
     * database: a deadlock, a lock-wait timeout, a dropped connection, or
     * the table missing on an install upgraded without the new schema.
     *
     * Unguarded, any of those reaches `send()`'s outer catch, which
     * journals a send failure and throws `MailException` — and
     * `SendBatchHandler` then marks a delivered message as failed and
     * sends it again. A missing receipt costs one unrecorded bounce; a
     * duplicated mailing is seen by everybody who got it twice.
     */
    public function testAReceiptStoreThatFailsDoesNotTurnADeliveryIntoAFailure(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->addressOnFile($pdo, 'parent@exemple.be');
        $receipts = $this->receiptsOver($pdo);

        // The table goes out from under it, exactly as an install
        // upgraded without the new schema would behave.
        $pdo->exec('DROP TABLE mail_send_receipts');

        $transport = $this->recordingTransport();
        $this->serviceWithReceipts($transport, $receipts)
            ->send('parent@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);

        $this->assertSame(1, $transport->calls, 'the message left, and nothing may say otherwise.');
    }

    /**
     * And a message nobody accepted is not proof either — the receipt is
     * stamped after the transport returns, never before it is tried.
     */
    public function testARefusedSendNotesNothing(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $receipts = $this->receiptsOver($pdo);
        $this->addressOnFile($pdo, 'parent@exemple.be');
        $refusing = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('550 unknown recipient');
            }
        };

        try {
            $this->serviceWithReceipts($refusing, $receipts)
                ->send('parent@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);
        } catch (\Core\Mail\MailException) {
            // The refusal is the point; what matters is what it left.
        }

        $this->assertNull($receipts->lastSendAt('parent@exemple.be'));
    }

    private function blockedAddress(\PDO $pdo, string $email): void
    {
        $states = $this->receiptsOver($pdo);
        $t = new \DateTimeImmutable('2026-03-01 09:00:00');
        $this->addressOnFile($pdo, $email);
        $states->recordSend($email, $t);
        $state = $states->record(
            $email,
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent,
            '5.1.1',
            $t->modify('+1 minute')
        );
        self::assertNotNull($state);
        $states->block($state->id, $t->modify('+2 minutes'));
    }

    /**
     * **« L'adresse cesse d'être écrite » has to mean every message the
     * site sends of its own accord**, not mailings alone — which is
     * where the rule was first enforced and where it stayed. A
     * notification or a mailed document arriving in a mailbox the member
     * was just told had been suspended makes the promise false.
     */
    public function testTheSiteStopsWritingToABlockedAddressOnItsOwnAccount(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->blockedAddress($pdo, 'rebond@exemple.be');

        $transport = $this->recordingTransport();
        $service = $this->serviceWithReceipts($transport, $this->receiptsOver($pdo));

        try {
            $service->send('rebond@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);
        } catch (\Core\Mail\SuppressedRecipientException) {
            // The signal the next test is about; here only the silence on
            // the wire is being measured.
        }

        $this->assertSame(0, $transport->calls, 'a suspended address must stop receiving.');
    }

    /**
     * **And the caller has to be able to tell that nothing left.**
     *
     * Suppression used to `return` from a `void` method, which every
     * caller reads as « parti » — `BatchDistributionService` recorded
     * `DeliveryState::Sent`, a state it never retries, and the member
     * sheet flashed « Document renvoyé par e-mail ». The family was left
     * holding neither the document nor any trace that it was missing.
     *
     * A `MailException` subclass, so a caller that only knows about send
     * failures already does the right thing, and a caller with something
     * better to say can catch the subclass.
     */
    public function testASuppressedSendIsDistinguishableFromASuccessfulOne(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->blockedAddress($pdo, 'rebond@exemple.be');

        $service = $this->serviceWithReceipts($this->recordingTransport(), $this->receiptsOver($pdo));

        try {
            $service->send('rebond@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);
            $this->fail('a suppressed send must not return normally.');
        } catch (\Core\Mail\SuppressedRecipientException $e) {
            $this->assertInstanceOf(
                \Core\Mail\MailException::class,
                $e,
                'a caller catching MailException must already treat this as a non-delivery.'
            );
            $this->assertStringNotContainsString(
                'rebond@exemple.be',
                $e->getMessage(),
                'this message reaches a screen, so it carries no address (SECURITY.md §11).'
            );
        }
    }

    /**
     * **Asking whether the address is suspended must not be able to break
     * the send**, and it used to.
     *
     * The lookup runs before the `try` that turns everything into a
     * `MailException`, and it is a query plus a `decrypt()`. A transient
     * database error or a row encrypted under a rotated key therefore
     * threw a raw `PDOException` out of a method whose whole contract is
     * `MailException` — and `NotificationMailer` catches only that, while
     * its caller `NotificationService::deliverPendingEmails()` catches
     * nothing. One bad row aborted the entire delivery loop.
     *
     * **It fails open, unlike the receipt.** The two are not symmetrical:
     * a receipt not written costs a future bounce its proof, where a
     * suppression not applied costs one message to an address that may be
     * suspended. Withholding somebody's document because a read failed is
     * the worse mistake, and it is the judgement D9 already makes.
     */
    public function testAnUnreadableBounceTableDoesNotStopTheMessage(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->blockedAddress($pdo, 'rebond@exemple.be');

        // The ground removed from under the lookup — standing in for the
        // whole family, what matters being that reading the state fails
        // for a reason that has nothing to do with this message.
        $pdo->exec('DROP TABLE mail_bounce_states');

        $transport = $this->recordingTransport();
        $this->serviceWithReceipts($transport, $this->receiptsOver($pdo))
            ->send('rebond@exemple.be', 'Sujet', '<p>x</p>', 'x', vouchesForRecipient: true);

        $this->assertSame(
            1,
            $transport->calls,
            'a database that cannot answer must not cost somebody their document.'
        );
    }

    /**
     * **But authentication mail still goes.** A magic link or a password
     * reset is the one thing somebody is waiting for at that moment, and
     * withholding it over a bounce two months old locks them out of the
     * site rather than protecting its reputation (D9). Those sends do not
     * vouch for their recipient, which is the same line the receipt uses.
     */
    public function testAMessageSomebodyIsWaitingForStillReachesABlockedAddress(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->blockedAddress($pdo, 'rebond@exemple.be');

        $transport = $this->recordingTransport();
        $this->serviceWithReceipts($transport, $this->receiptsOver($pdo))
            ->send('rebond@exemple.be', 'Connexion', '<p>x</p>', 'x');

        $this->assertSame(1, $transport->calls, 'a sign-in link is not a mailing.');
    }
}
