<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\View;

use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageDirection;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * Issue #1121: the one partial every screen showing a received message
 * includes (rentals' Courrier, a camp's correspondence, finance's « Courrier
 * à trier », the triage and mailbox screens) says « Expéditeur non
 * vérifié » exactly when nobody vouched for the sender.
 */
final class SenderBadgeTemplateTest extends TestCase
{
    public function testAnUnverifiedSenderIsMarked(): void
    {
        $this->assertStringContainsString('Expéditeur non vérifié', $this->render(senderVerified: false));
    }

    public function testAVerifiedSenderIsNot(): void
    {
        $this->assertSame('', trim($this->render(senderVerified: true)));
    }

    public function testAMessageTheBoxSentIsNever(): void
    {
        $this->assertSame('', trim($this->render(senderVerified: false, direction: MessageDirection::SENT)));
    }

    private function render(bool $senderVerified, MessageDirection $direction = MessageDirection::RECEIVED): string
    {
        $message = new InboundMessage(
            id: 1,
            mailboxId: 1,
            consumerId: 'rental',
            businessReference: 'LOC-K7Q2M4',
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Nouvelle adresse pour la caution',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            messageId: '<m1@example.be>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-07-01 10:00:00'),
            bodyText: 'Bonjour,',
            bodyHtml: '',
            direction: $direction,
            senderVerified: $senderVerified
        );

        return TestTwig::create(['inbound_mail'])
            ->render('@inbound_mail/partials/sender_badge.html.twig', ['message' => $message]);
    }
}
