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

    private function receiptsOverFreshDatabase(): \Core\Mail\Feedback\Bounce\BounceStateRepository
    {
        return new \Core\Mail\Feedback\Bounce\BounceStateRepository(
            \Tests\DatabaseTestHelper::createTestDatabase(),
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

    /**
     * An ordinary message is the site deciding to write somewhere, so it
     * is evidence a bounce naming that address can be believed.
     */
    public function testAnOrdinarySendIsNotedAsProofTheSiteWroteThere(): void
    {
        $receipts = $this->receiptsOverFreshDatabase();

        $this->serviceWithReceipts($this->recordingTransport(), $receipts)
            ->send('parent@exemple.be', 'Sujet', '<p>x</p>', 'x');

        $this->assertNotNull($receipts->lastSendAt('parent@exemple.be'));
    }

    /**
     * **But the confirmation of a newly claimed address is not.** Any
     * signed-in member can name any address in `addEmail()` — that is
     * what claiming one is — and this is the message that follows.
     * Minting a receipt here would let the claim stand in for proof the
     * site writes there, and a forged delivery-status report naming the
     * same address would then be credited against somebody else's
     * mailbox.
     *
     * Nothing real is lost: a `pending` address is never resolved for a
     * mailing, so this confirmation is the only message it can receive.
     */
    public function testTheConfirmationOfAClaimedAddressIsNotProofOfAnything(): void
    {
        $receipts = $this->receiptsOverFreshDatabase();

        $this->serviceWithReceipts($this->recordingTransport(), $receipts)
            ->send('victime@exemple.be', 'Confirmez', '<p>x</p>', 'x', countsAsProofOfSend: false);

        $this->assertNull(
            $receipts->lastSendAt('victime@exemple.be'),
            'claiming an address must not vouch for it.'
        );
    }

    /**
     * And a message nobody accepted is not proof either — the receipt is
     * stamped after the transport returns, never before it is tried.
     */
    public function testARefusedSendNotesNothing(): void
    {
        $receipts = $this->receiptsOverFreshDatabase();
        $refusing = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('550 unknown recipient');
            }
        };

        try {
            $this->serviceWithReceipts($refusing, $receipts)
                ->send('parent@exemple.be', 'Sujet', '<p>x</p>', 'x');
        } catch (\Core\Mail\MailException) {
            // The refusal is the point; what matters is what it left.
        }

        $this->assertNull($receipts->lastSendAt('parent@exemple.be'));
    }
}
