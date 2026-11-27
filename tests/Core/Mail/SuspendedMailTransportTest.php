<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailSendingSuspendedException;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\SuspendedMailTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * The transport of an installation whose mail capture is armed and no
 * longer acting (issue #1469): nothing leaves, and the caller is told so.
 */
final class SuspendedMailTransportTest extends TestCase
{
    public function testItRefusesEverySendWithTheSuspensionException(): void
    {
        $this->expectException(MailSendingSuspendedException::class);
        $this->expectExceptionMessage('L\'envoi des e-mails est suspendu');

        (new SuspendedMailTransport())->deliver(new PHPMailer(true), MailPurpose::Ordinary);
    }

    public function testWithoutTheExemptionEvenASignInLinkStays(): void
    {
        $this->expectException(MailSendingSuspendedException::class);

        (new SuspendedMailTransport())->deliver(new PHPMailer(true), MailPurpose::MagicLink);
    }

    /**
     * The sign-in link goes on leaving only where the capture was letting
     * it leave: the composition root hands the ordinary transport over
     * exactly then. Nothing else uses it.
     */
    public function testTheExemptedSignInLinkGoesThroughTheTransportHandedOver(): void
    {
        $delivery = $this->recordingTransport();
        $transport = new SuspendedMailTransport($delivery);

        $transport->deliver(new PHPMailer(true), MailPurpose::MagicLink);
        $this->assertSame([MailPurpose::MagicLink], $delivery->purposes);

        try {
            $transport->deliver(new PHPMailer(true), MailPurpose::Bulk);
            $this->fail('Only the sign-in link may pass.');
        } catch (MailSendingSuspendedException) {
            $this->assertSame([MailPurpose::MagicLink], $delivery->purposes, 'A mailing never reaches it.');
        }
    }

    /**
     * Through MailService, the failure is the ordinary one every caller
     * already handles — and it says why, in French.
     */
    public function testMailServiceReportsItAsASendFailure(): void
    {
        $service = new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager(sys_get_temp_dir() . '/scoutmagic-no-dkim-' . bin2hex(random_bytes(4))),
            dkimSelector: 'mail',
            transport: new SuspendedMailTransport()
        );

        try {
            $service->send('parent@example.be', 'Sujet', '<p>Corps</p>', 'Corps');
            $this->fail('A suspended send must throw.');
        } catch (MailException $e) {
            $this->assertStringContainsString('capture des e-mails est armée', $e->getMessage());
        }
    }

    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            /** @var list<MailPurpose> */
            public array $purposes = [];

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->purposes[] = $purpose;
            }
        };
    }
}
