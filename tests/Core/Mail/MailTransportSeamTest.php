<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailException;
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
            public int $calls = 0;

            public function deliver(PHPMailer $mail): void
            {
                $this->received = $mail;
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
            public function deliver(PHPMailer $mail): void
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
}
