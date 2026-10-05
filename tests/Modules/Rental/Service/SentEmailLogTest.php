<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Mail\SentEmail;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalSentEmailRepository;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The booking's log of what the site sent the renter (#720, step 2): every
 * e-mail recorded, sent or failed, encrypted, with the tracking link
 * masked — and « Renvoyer » putting a valid link back.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class SentEmailLogTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';
    private const FRESH_TOKEN = 'ffffeeeeddddccccbbbbaaaa99998888777766665555444433332222111100aa';

    private \PDO $pdo;
    private RentalSentEmailRepository $log;
    /** @var list<array{to: string, subject: string, html: string, text: string, attachments: array<int, mixed>}> */
    private array $outbox = [];
    private bool $smtpUp = true;
    private RentalBookingMailService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->log = new RentalSentEmailRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views', 'inbound_mail' => dirname(__DIR__, 4) . '/modules/inbound_mail/views']
        );
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'site_name' => 'Unité Test',
                'base_url' => 'https://unite.test',
                default => null,
            }
        );

        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text, ?string $replyTo = null, array $attachments = []): void {
                if (!$this->smtpUp) {
                    throw new MailException('SMTP connect() failed.');
                }
                $this->outbox[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'attachments' => $attachments];
            }
        );

        $this->service = new RentalBookingMailService(
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class),
            sentEmails: $this->log
        );
    }

    /**
     * One test per kind of e-mail: each is in the log, under its own kind,
     * to the renter, with its own subject.
     *
     * @return array<string, array{string, \Closure(RentalBookingMailService, RentalBooking, RentalAsset, string): mixed}>
     */
    public static function everyEmail(): array
    {
        $pdf = __FILE__;

        return [
            'acknowledgement' => ['rental.acknowledgement', static fn($s, $b, $a, $t) => $s->sendAcknowledgement($b, $a, $t)],
            'decision' => ['rental.decision', static fn($s, $b, $a, $t) => $s->sendDecision($b, $a, RenterDecision::CONFIRMED, $t)],
            'document' => ['rental.document', static fn($s, $b, $a, $t) => $s->sendDocument($b, $a, 'Facture', $pdf, 'facture.pdf', false, DocumentType::INVOICE, 5)],
            'contract' => ['rental.contract', static fn($s, $b, $a, $t) => $s->sendContract($b, $a, 'Contrat', $pdf, 'contrat.pdf', false, $t, null, 6)],
            'signed copy reminder' => ['rental.signed_copy_reminder', static fn($s, $b, $a, $t) => $s->sendSignedCopyReminder($b, $a, $t)],
            'copy refused' => ['rental.copy_refused', static fn($s, $b, $a, $t) => $s->sendCopyRefused($b, $a, 'Illisible', $t)],
            'signed contract' => ['rental.signed_contract', static fn($s, $b, $a, $t) => $s->sendSignedContract($b, $a, $pdf, 'contrat-signe.pdf', $t, 7)],
            'practical info' => ['rental.practical_info', static fn($s, $b, $a, $t) => $s->sendPracticalInfo($b, $a)],
            'tracking link' => ['rental.tracking_link', static fn($s, $b, $a, $t) => $s->sendTrackingLink($b, $a, $t)],
        ];
    }

    /**
     * @param \Closure(RentalBookingMailService, RentalBooking, RentalAsset, string): mixed $send
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('everyEmail')]
    public function testEveryEmailToTheRenterIsLoggedAsSent(string $kind, \Closure $send): void
    {
        $send($this->service, $this->booking(), $this->asset(), self::TOKEN);

        $logged = $this->log->findForBooking(42);
        $this->assertCount(1, $logged);
        $this->assertSame($kind, $logged[0]->kind);
        $this->assertSame('camille@example.test', $logged[0]->recipient);
        $this->assertSame($this->outbox[0]['subject'], $logged[0]->subject);
        $this->assertSame(SentEmail::STATUS_SENT, $logged[0]->status);
        $this->assertStringContainsString('[LOC-2027-K7Q2MX]', $logged[0]->subject);
        // The token is never kept, wherever the e-mail carried it.
        $this->assertStringNotContainsString(self::TOKEN, $logged[0]->bodyText . $logged[0]->bodyHtml);
    }

    public function testTheLogIsEncryptedAtRest(): void
    {
        $this->service->sendAcknowledgement($this->booking(), $this->asset(), self::TOKEN);

        $raw = $this->pdo->query('SELECT * FROM rental_booking_sent_emails')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($raw);
        foreach (['recipient_encrypted', 'subject_encrypted', 'body_text_encrypted', 'body_html_encrypted'] as $column) {
            $this->assertStringNotContainsString('camille@example.test', (string) $raw[$column]);
            $this->assertStringNotContainsString('LOC-2027-K7Q2MX', (string) $raw[$column]);
        }
        // The Message-ID stays readable: it is what a copy in « Envoyés » is
        // recognised by.
        $this->assertNotSame('', (string) $raw['message_id']);
    }

    public function testTheTrackingLinkIsMaskedInTheStoredText(): void
    {
        $this->service->sendAcknowledgement($this->booking(), $this->asset(), self::TOKEN);

        $logged = $this->log->findForBooking(42)[0];
        $this->assertStringContainsString(self::TOKEN, $this->outbox[0]['text'], 'the renter did get the link');
        $this->assertTrue($logged->carriesTheTrackingLink());
        $this->assertStringContainsString(SentEmail::MASKED_LINK_LABEL, $logged->displayText());
    }

    public function testAFailedSendIsLoggedAsNotSentAndTheCallerStillHearsOfIt(): void
    {
        $this->smtpUp = false;

        $this->assertFalse($this->service->sendTrackingLink($this->booking(), $this->asset(), self::TOKEN));
        try {
            $this->service->sendAcknowledgement($this->booking(), $this->asset(), self::TOKEN);
            $this->fail('The acknowledgement still reports its failure.');
        } catch (MailException) {
        }

        $logged = $this->log->findForBooking(42);
        $this->assertCount(2, $logged);
        $this->assertTrue($logged[0]->failed());
        $this->assertTrue($logged[1]->failed());
    }

    public function testRenvoyerSendsTheSameTextWithTheCurrentLink(): void
    {
        $this->smtpUp = false;
        $this->assertFalse($this->service->sendTrackingLink($this->booking(), $this->asset(), self::TOKEN));
        $failed = $this->log->findForBooking(42)[0];

        $this->smtpUp = true;
        $this->service->resend($failed, $this->booking(), self::FRESH_TOKEN, static fn(int $id): ?array => null);

        $this->assertCount(1, $this->outbox);
        $this->assertSame($failed->subject, $this->outbox[0]['subject']);
        $this->assertStringContainsString(self::FRESH_TOKEN, $this->outbox[0]['text']);
        $this->assertStringContainsString(self::FRESH_TOKEN, $this->outbox[0]['html']);
        $this->assertStringNotContainsString(SentEmail::MASKED_LINK, $this->outbox[0]['text'] . $this->outbox[0]['html']);

        $logged = $this->log->findForBooking(42);
        $this->assertCount(2, $logged);
        $this->assertSame(SentEmail::STATUS_SENT, $logged[1]->status);
        $this->assertStringNotContainsString(self::FRESH_TOKEN, $logged[1]->bodyText . $logged[1]->bodyHtml);
    }

    public function testRenvoyerWithoutALinkToPutBackIsRefusedInFrench(): void
    {
        $this->smtpUp = false;
        $this->service->sendTrackingLink($this->booking(), $this->asset(), self::TOKEN);
        $failed = $this->log->findForBooking(42)[0];
        $this->smtpUp = true;

        $this->expectException(RentalException::class);
        $this->expectExceptionMessage('régénérez-le');
        $this->service->resend($failed, $this->booking(), null, static fn(int $id): ?array => null);
    }

    public function testRenvoyerAttachesTheDocumentsAgain(): void
    {
        $this->smtpUp = false;
        try {
            $this->service->sendDocument($this->booking(), $this->asset(), 'Facture', __FILE__, 'facture.pdf', false, DocumentType::INVOICE, 5);
        } catch (MailException) {
        }
        $failed = $this->log->findForBooking(42)[0];
        $this->assertSame([5], $failed->documentIds);
        $this->smtpUp = true;

        $this->service->resend(
            $failed,
            $this->booking(),
            null,
            static fn(int $id): ?array => $id === 5 ? ['path' => __FILE__, 'name' => 'facture.pdf'] : null
        );

        $this->assertSame([['path' => __FILE__, 'name' => 'facture.pdf']], $this->outbox[0]['attachments']);
    }

    public function testADocumentGoneSinceCannotBeResentAsItWas(): void
    {
        $this->smtpUp = false;
        try {
            $this->service->sendDocument($this->booking(), $this->asset(), 'Facture', __FILE__, 'facture.pdf', false, DocumentType::INVOICE, 5);
        } catch (MailException) {
        }
        $failed = $this->log->findForBooking(42)[0];
        $this->smtpUp = true;

        $this->expectException(RentalException::class);
        $this->service->resend($failed, $this->booking(), null, static fn(int $id): ?array => null);
    }

    public function testAnotherBookingsEmailIsNotResentFromThisOne(): void
    {
        $this->smtpUp = false;
        $this->service->sendTrackingLink($this->booking(), $this->asset(), self::TOKEN);
        $failed = $this->log->findForBooking(42)[0];
        $this->smtpUp = true;

        $this->expectException(RentalException::class);
        $this->service->resend($failed, $this->booking(43), self::TOKEN, static fn(int $id): ?array => null);
    }

    private function booking(int $id = 42): RentalBooking
    {
        return new RentalBooking(
            id: $id,
            assetId: 7,
            reference: 'LOC-2027-K7Q2MX',
            arrivalDate: '2027-08-14',
            departureDate: '2027-08-17',
            units: 1,
            estimatedPersons: 25,
            renterCategoryId: null,
            renterName: 'Camille Renard',
            renterEmail: 'camille@example.test',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: BookingStatus::CONFIRMED,
            receivedAt: new \DateTimeImmutable('2027-05-01 10:00:00'),
            finalAt: null,
            holdUntil: null,
            holdOrigin: null,
            estimatedPrice: null,
            estimatedTotalCents: null,
            agreedPrice: null,
            agreedTotalCents: null,
            conditionsVersion: null,
            conditionsHash: null,
            conditionsAcceptedAt: null,
            privacyVersion: null,
            privacyHash: null,
            privacyAcknowledgedAt: null
        );
    }

    private function asset(): RentalAsset
    {
        return new RentalAsset(
            id: 7,
            assetType: 'hall',
            name: 'Le Chalet',
            slug: 'le-chalet',
            capacity: 40,
            quantity: 1,
            arrivalTime: '16:00',
            departureTime: '11:00',
            emergencyPhone: null,
            isArchived: false,
            isPublic: true
        );
    }
}
