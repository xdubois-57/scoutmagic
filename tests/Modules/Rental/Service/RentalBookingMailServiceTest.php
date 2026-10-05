<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\View\TwigFactory;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Service\RentalBookingMailService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\DatabaseTestHelper;
use Core\View\EditableContentService;
use Core\View\EditableContentRepository;
use Modules\Rental\Service\RentalConditionsService;
use Modules\Rental\Repository\RentalConditionsVersionRepository;
use Modules\Rental\Document\ConditionsVersion;
use Twig\Environment;

/**
 * The email a manager's decision sends to the renter.
 *
 * None of these existed. A booking was confirmed, refused, answered with a
 * proposal or with a question, and the renter — who has no account, no
 * notification centre and no reason to reload a page they saw once — was
 * told nothing at all; `propose()` set a flash reading « Proposition
 * envoyée » next to an outbox that had stayed empty.
 *
 * What is worth pinning here is less that an email goes out than what is in
 * it: the renter's own words back, the manager's sentence kept separate
 * from the site's, and the tracking link present exactly when the renter
 * can still act and absent when they cannot.
 */
// Through `EmailTemplateRendererFactory::shippedOnlyForModule()`, which
// reaches an in-memory database by way of its own `emptyStore()`: this
// class builds none itself and inherits none.
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalBookingMailServiceTest extends TestCase
{
    /** @var list<array{to: string, subject: string, html: string, text: string}> */
    private array $sent = [];

    private Environment $twig;
    private RentalBookingMailService $service;

    protected function setUp(): void
    {
        $this->sent = [];

        $this->twig = TwigFactory::create(
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

        $this->service = new RentalBookingMailService(
            $this->recordingMailService(),
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class)
        );
    }

    // ── The signed reply address (§8.58) ────────────────────────────────

    public function testEveryMailAboutABookingCarriesItsSignedReplyAddress(): void
    {
        $replyTos = [];
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            static function (string $to, string $subject, string $html, string $text, ?string $replyTo) use (&$replyTos): void {
                $replyTos[] = $replyTo;
            }
        );
        $inboundMail = new class implements \Modules\InboundMail\Api\InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            public function replyAddressFor(string $consumerId, string $businessReference): ?string
            {
                return 'locations+' . $consumerId . '.' . $businessReference . '.9f3a1b2c4d5e@unite.be';
            }
        };
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'site_name' => 'Unité Test',
                'base_url' => 'https://unite.test',
                default => null,
            }
        );
        $service = new RentalBookingMailService(
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class),
            $inboundMail
        );

        $service->sendAcknowledgement($this->booking(), $this->asset(), str_repeat('a', 64));
        $service->sendPracticalInfo($this->booking(), $this->asset());

        $this->assertSame(
            ['locations+rental.LOC-2027-0042.9f3a1b2c4d5e@unite.be', 'locations+rental.LOC-2027-0042.9f3a1b2c4d5e@unite.be'],
            $replyTos
        );
    }

    public function testWithoutAnAddressToMintTheMailGoesOutWithNoReplyTo(): void
    {
        $replyTos = [];
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            static function (string $to, string $subject, string $html, string $text, ?string $replyTo) use (&$replyTos): void {
                $replyTos[] = $replyTo;
            }
        );
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn(null);
        $service = new RentalBookingMailService(
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class)
        );

        $service->sendAcknowledgement($this->booking(), $this->asset(), str_repeat('a', 64));

        $this->assertSame([null], $replyTos);
    }

    /**
     * Signed addresses switched off: no box of the rentals' own to fall
     * back on (#720) — a dedicated box is no longer something rentals look
     * at — so the mail goes out with no Reply-To, even with one box
     * dedicated to them.
     */
    public function testWithoutASignedAddressTheMailGoesOutWithNoReplyToEvenWithADedicatedBox(): void
    {
        $replyTos = [];
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            static function (string $to, string $subject, string $html, string $text, ?string $replyTo) use (&$replyTos): void {
                $replyTos[] = $replyTo;
            }
        );
        $inboundMail = new class implements \Modules\InboundMail\Api\InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            public function dedicatedMailboxesFor(string $consumerId): array
            {
                return [new \Modules\InboundMail\Api\DedicatedMailbox(1, 'Locations', 'locations@unite.be')];
            }
        };
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturn(null);
        $service = new RentalBookingMailService(
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class),
            $inboundMail
        );

        $service->sendAcknowledgement($this->booking(), $this->asset(), str_repeat('a', 64));

        $this->assertSame([null], $replyTos);
    }

    private function recordingMailService(bool $succeeds = true): MailService
    {
        $mock = $this->createStub(MailService::class);
        $mock->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText
            ) use ($succeeds): void {
                if (!$succeeds) {
                    throw new MailException('SMTP connect() failed.');
                }
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $bodyHtml, 'text' => $bodyText];
            }
        );

        return $mock;
    }

    private function booking(
        BookingStatus $status = BookingStatus::RECEIVED,
        ?ConditionsVersion $accepted = null
    ): RentalBooking {
        return new RentalBooking(
            id: 42,
            assetId: 7,
            reference: 'LOC-2027-0042',
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
            status: $status,
            receivedAt: new \DateTimeImmutable('2027-05-01 10:00:00'),
            finalAt: null,
            holdUntil: null,
            holdOrigin: null,
            estimatedPrice: null,
            estimatedTotalCents: null,
            agreedPrice: null,
            agreedTotalCents: null,
            conditionsVersion: $accepted?->version,
            conditionsHash: $accepted?->hash,
            conditionsAcceptedAt: $accepted === null ? null : new \DateTimeImmutable('2027-05-01 10:00:00'),
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

    /** @return array{to: string, subject: string, html: string, text: string} */
    private function onlyMail(): array
    {
        self::assertCount(1, $this->sent, 'Exactly one email should have gone out.');

        return $this->sent[0];
    }

    // ── What goes out at all ────────────────────────────────────────────

    // ── The conditions the renter accepted (issue #494) ────────────────

    /**
     * The archive and a service that reads it, over the asset these tests
     * send about (id 7), with one wording the booking will have accepted.
     *
     * @return array{RentalBookingMailService, ConditionsVersion, RentalConditionsService}
     */
    private function serviceWithAcceptedConditions(): array
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($pdo);
        $pdo->prepare(
            "INSERT INTO rental_assets (id, asset_type, name, slug, quantity, is_public) VALUES (7, 'hall', 'Le Chalet', 'le-chalet', 1, 1)"
        )->execute();
        $conditions = new RentalConditionsService(
            new RentalConditionsVersionRepository($pdo),
            new EditableContentService(new EditableContentRepository($pdo))
        );
        $accepted = $conditions->recordSave(7, '<p>Le local est rendu balayé.</p>', 1);

        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'site_name' => 'Unité Test',
                'base_url' => 'https://unite.test',
                default => null,
            }
        );

        return [
            new RentalBookingMailService(
                $this->recordingMailService(),
                EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
                $settings,
                $this->createStub(JournalService::class),
                conditions: $conditions
            ),
            $accepted,
            $conditions,
        ];
    }

    /**
     * Every email to the renter ends with the version THEY accepted — in
     * both halves of the message, and still that version after the unit
     * has rewritten its conditions.
     */
    public function testAnEmailToTheRenterLinksToTheConditionsTheyAccepted(): void
    {
        [$service, $accepted, $conditions] = $this->serviceWithAcceptedConditions();
        $conditions->recordSave(7, '<p>Le local est rendu lavé.</p>', 1);

        $service->sendAcknowledgement($this->booking(accepted: $accepted), $this->asset(), str_repeat('a', 64));

        $link = 'https://unite.test/locations/le-chalet/conditions/' . $accepted->version;
        $mail = $this->onlyMail();
        $this->assertStringContainsString('Conditions de location acceptées le 01/05/2027', $mail['html']);
        $this->assertStringContainsString('href="' . $link . '"', $mail['html']);
        $this->assertStringContainsString('Conditions de location acceptées le 01/05/2027', $mail['text']);
        $this->assertStringContainsString($link, $mail['text']);
    }

    public function testTheOtherRenterEmailsCarryTheLinkToo(): void
    {
        [$service, $accepted] = $this->serviceWithAcceptedConditions();
        $booking = $this->booking(BookingStatus::CONFIRMED, $accepted);

        $service->sendDecision($booking, $this->asset(), RenterDecision::CONFIRMED, str_repeat('a', 64), null);
        $service->sendPracticalInfo($booking, $this->asset());

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as $mail) {
            $this->assertStringContainsString('/conditions/' . $accepted->version, $mail['html'], $mail['subject']);
        }
    }

    /**
     * A booking whose version is not in the archive — conditions
     * overwritten before the archive existed — gets no link at all rather
     * than a link to some other text.
     */
    public function testABookingWhoseVersionIsNotArchivedGetsNoLink(): void
    {
        [$service] = $this->serviceWithAcceptedConditions();
        $lost = new ConditionsVersion(7, 'abcdef012345', str_repeat('f', 64), '<p>Perdu.</p>', new \DateTimeImmutable());

        $service->sendAcknowledgement($this->booking(accepted: $lost), $this->asset(), str_repeat('a', 64));

        $this->assertStringNotContainsString('Conditions de location acceptées', $this->onlyMail()['html']);
    }

    /**
     * Twelve characters name a version; the full hash proves it. A booking
     * whose version prefix matches an archived text it did NOT accept —
     * two wordings colliding on their prefix — gets no link either.
     */
    public function testAVersionWhoseHashDiffersFromTheBookingsGetsNoLink(): void
    {
        [$service, $accepted] = $this->serviceWithAcceptedConditions();
        $other = new ConditionsVersion(7, $accepted->version, str_repeat('f', 64), '<p>Autre.</p>', new \DateTimeImmutable());

        $service->sendAcknowledgement($this->booking(accepted: $other), $this->asset(), str_repeat('a', 64));

        $this->assertStringNotContainsString('Conditions de location acceptées', $this->onlyMail()['html']);
    }

    public function testAConfirmationReachesTheRenterWithTheReferenceInTheSubject(): void
    {
        $sent = $this->service->sendDecision(
            $this->booking(),
            $this->asset(),
            RenterDecision::CONFIRMED,
            str_repeat('a', 64),
            null
        );

        $this->assertTrue($sent);
        $mail = $this->onlyMail();
        $this->assertSame('camille@example.test', $mail['to']);
        // The reference first, in every subject: it is the most reliable of
        // the inbound-matching rules (§7.6).
        $this->assertStringStartsWith('[LOC-2027-0042] ', $mail['subject']);
        $this->assertStringContainsString('confirmée', $mail['subject']);
    }

    public function testTheRenterIsAddressedByNameAndToldWhichStayThisIs(): void
    {
        $this->service->sendDecision($this->booking(), $this->asset(), RenterDecision::CONFIRMED, 'tok', null);

        foreach (['html', 'text'] as $part) {
            $body = $this->onlyMail()[$part];
            $this->assertStringContainsString('Camille Renard', $body);
            $this->assertStringContainsString('Le Chalet', $body);
            $this->assertStringContainsString('LOC-2027-0042', $body);
            // French dates, not '2027-08-14' — the renter is not reading a
            // database row.
            $this->assertStringContainsString('14/08/2027', $body);
            $this->assertStringContainsString('17/08/2027', $body);
        }
    }

    public function testEveryDecisionSaysWhatHappenedInPlainFrench(): void
    {
        foreach (RenterDecision::cases() as $decision) {
            $this->sent = [];
            $this->service->sendDecision($this->booking(), $this->asset(), $decision, 'tok', null);

            $mail = $this->onlyMail();
            $this->assertStringContainsString($decision->announcement(), $mail['text'], $decision->value);
            // Never the state machine's own word.
            $this->assertStringNotContainsString($decision->value, $mail['text'], $decision->value);
        }
    }

    // ── The manager's optional word ─────────────────────────────────────

    public function testTheManagersWordIsCarriedToTheRenter(): void
    {
        $this->service->sendDecision(
            $this->booking(),
            $this->asset(),
            RenterDecision::REFUSED,
            null,
            'Le chalet est déjà pris ce week-end-là par un autre groupe.'
        );

        $mail = $this->onlyMail();
        $this->assertStringContainsString('déjà pris ce week-end-là', $mail['html']);
        $this->assertStringContainsString('déjà pris ce week-end-là', $mail['text']);
    }

    public function testTheManagersWordIsQuotedRatherThanFoldedIntoTheAnnouncement(): void
    {
        $this->service->sendDecision(
            $this->booking(),
            $this->asset(),
            RenterDecision::REFUSED,
            null,
            'Désolé pour cette fois.'
        );

        // The renter should be able to tell what the site says from what a
        // person wrote.
        $this->assertStringContainsString('<blockquote', $this->onlyMail()['html']);
    }

    public function testTheManagersWordIsNeverInterpretedAsMarkup(): void
    {
        $this->service->sendDecision(
            $this->booking(),
            $this->asset(),
            RenterDecision::REFUSED,
            null,
            '<script>alert(1)</script> & voilà'
        );

        $html = $this->onlyMail()['html'];
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testNoWordAtAllStillProducesAnEmailThatSaysWhatHappened(): void
    {
        // The word is optional. Its absence must not leave an empty quote
        // block or an email that says nothing.
        foreach ([null, '', '   '] as $word) {
            $this->sent = [];
            $this->service->sendDecision($this->booking(), $this->asset(), RenterDecision::CONFIRMED, 'tok', $word);

            $mail = $this->onlyMail();
            $this->assertStringNotContainsString('<blockquote', $mail['html']);
            $this->assertStringContainsString('Votre réservation est confirmée.', $mail['text']);
        }
    }

    // ── The tracking link ───────────────────────────────────────────────

    public function testAnEmailTheRenterCanActOnCarriesTheirLink(): void
    {
        $token = str_repeat('b', 64);
        $this->service->sendDecision($this->booking(), $this->asset(), RenterDecision::PROPOSED, $token, null);

        $expected = 'https://unite.test/locations/suivi/42/' . $token;
        $this->assertStringContainsString($expected, $this->onlyMail()['html']);
        $this->assertStringContainsString($expected, $this->onlyMail()['text']);
    }

    public function testARefusalCarriesNoLinkEvenWhenOneIsAvailable(): void
    {
        // The booking is over. Re-issuing a capability inside a message
        // nobody can act on is how a link ends up forwarded (§6.29's
        // reasoning, applied here).
        $token = str_repeat('c', 64);
        foreach ([RenterDecision::REFUSED, RenterDecision::CANCELLED, RenterDecision::CHANGE_REFUSED] as $decision) {
            $this->sent = [];
            $this->service->sendDecision($this->booking(), $this->asset(), $decision, $token, null);

            $this->assertStringNotContainsString($token, $this->onlyMail()['html'], $decision->value);
            $this->assertStringNotContainsString('/locations/suivi/', $this->onlyMail()['text'], $decision->value);
        }
    }

    public function testAMissingTokenSkipsTheLinkRatherThanTheEmail(): void
    {
        // A booking whose token cannot be read still had a decision taken
        // on it. An email without a link beats no email at all.
        $sent = $this->service->sendDecision($this->booking(), $this->asset(), RenterDecision::CONFIRMED, null, null);

        $this->assertTrue($sent);
        $this->assertStringNotContainsString('/locations/suivi/', $this->onlyMail()['html']);
        $this->assertStringContainsString('Votre réservation est confirmée.', $this->onlyMail()['text']);
    }

    /**
     * Every sender of this service, keyed by the template it renders.
     *
     * @return array<string, \Closure(): mixed>
     */
    private function everySender(): array
    {
        return [
            'acknowledgement' => fn () => $this->service->sendAcknowledgement(
                $this->booking(),
                $this->asset(),
                str_repeat('a', 64)
            ),
            'decision' => fn () => $this->service->sendDecision(
                $this->booking(),
                $this->asset(),
                RenterDecision::CONFIRMED,
                str_repeat('a', 64),
                null
            ),
            'document' => fn () => $this->service->sendDocument(
                $this->booking(),
                $this->asset(),
                'Contrat',
                '/tmp/contract.pdf',
                'contrat.pdf'
            ),
            'practical_info' => fn () => $this->service->sendPracticalInfo($this->booking(), $this->asset()),
            // The contract's two signatures (#708, IT-16).
            'contract' => fn () => $this->service->sendContract(
                $this->booking(),
                $this->asset(),
                'Contrat v1',
                '/tmp/contract.pdf',
                'contrat.pdf',
                false,
                str_repeat('a', 64),
                new \DateTimeImmutable('2027-05-28')
            ),
            'signed_copy_reminder' => fn () => $this->service->sendSignedCopyReminder(
                $this->booking(),
                $this->asset(),
                str_repeat('a', 64)
            ),
            'copy_refused' => fn () => $this->service->sendCopyRefused(
                $this->booking(),
                $this->asset(),
                'La deuxième page n\'est pas signée.',
                str_repeat('a', 64)
            ),
            'signed_contract' => fn () => $this->service->sendSignedContract(
                $this->booking(),
                $this->asset(),
                '/tmp/contract.pdf',
                'contrat-signe.pdf',
                str_repeat('a', 64)
            ),
            'tracking_link' => fn () => $this->service->sendTrackingLink(
                $this->booking(),
                $this->asset(),
                str_repeat('a', 64)
            ),
        ];
    }

    /**
     * The contract says what to do with it and by when (#708, IT-16) — and
     * not that nothing can be downloaded, which the generic document
     * e-mail says and the contract, the one document that comes back,
     * contradicts.
     */
    public function testTheContractSaysToSignItAndSendItBackBeforeTheHoldEnds(): void
    {
        $this->sent = [];
        ($this->everySender()['contract'])();
        $mail = $this->onlyMail();

        $this->assertStringContainsString('déposez votre copie signée sur votre page de suivi', $mail['text']);
        $this->assertStringContainsString("jusqu'au 28/05/2027", $mail['text']);
        $this->assertStringContainsString('/locations/suivi/', $mail['text']);
        $this->assertStringNotContainsString('ne peut pas être téléchargé', $mail['text']);
    }

    // ── « Et maintenant ? » (#708, IT-15) ───────────────────────────────

    /**
     * A service whose journey answers the given step, and records the
     * renderer it was built with so a test can customise a body.
     */
    private function serviceWithNextStep(
        \Modules\Rental\Booking\RenterNextStep $step,
        ?\Core\Mail\Template\EmailTemplateRenderer $renderer = null
    ): RentalBookingMailService {
        $journey = $this->createStub(\Modules\Rental\Service\RentalJourneyService::class);
        $journey->method('renterNextStep')->willReturn($step);
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'site_name' => 'Unité Test',
                'base_url' => 'https://unite.test',
                default => null,
            }
        );

        return new RentalBookingMailService(
            $this->recordingMailService(),
            $renderer ?? EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $settings,
            $this->createStub(JournalService::class),
            journey: $journey
        );
    }

    /** Every e-mail to the renter ends with the block, in both halves. */
    public function testEveryEmailToTheRenterEndsWithWhatComesNext(): void
    {
        $this->service = $this->serviceWithNextStep(new \Modules\Rental\Booking\RenterNextStep(
            "Rien à faire de votre côté pour l'instant : nous étudions votre demande et vous enverrons le contrat."
        ));

        foreach ($this->everySender() as $name => $send) {
            $this->sent = [];
            $send();
            $mail = $this->onlyMail();

            foreach (['html' => $mail['html'], 'text' => $mail['text']] as $half => $body) {
                $this->assertStringContainsString('Et maintenant ?', $body, "{$name} {$half}");
            }
            // Each e-mail its own sentence where it knows better: the
            // contract calls for its signature.
            if ($name !== 'contract') {
                $this->assertStringContainsString('nous étudions votre demande', $mail['text'], $name);
            }
        }
    }

    /** The contract calls for its signature, with the link to where it is done. */
    public function testTheContractsBlockSaysToSignWithTheTrackingLink(): void
    {
        $this->service = $this->serviceWithNextStep(new \Modules\Rental\Booking\RenterNextStep('Rien à faire.'));
        $this->sent = [];
        ($this->everySender()['contract'])();
        $mail = $this->onlyMail();

        $block = substr($mail['text'], (int) strpos($mail['text'], 'Et maintenant ?'));
        $this->assertStringContainsString('À vous : signez le contrat', $block);
        $this->assertStringContainsString("jusqu'au 28/05/2027", $block);
        $this->assertStringContainsString('/locations/suivi/', $block);
        $this->assertStringContainsString('Ouvrir ma page de suivi</a>', $mail['html']);
    }

    /** An invoice calls for its payment. */
    public function testAnInvoicesBlockCallsForItsPayment(): void
    {
        $this->service = $this->serviceWithNextStep(new \Modules\Rental\Booking\RenterNextStep('Rien à faire.'));
        $this->sent = [];
        $this->service->sendDocument(
            $this->booking(),
            $this->asset(),
            'Facture v1',
            '/tmp/invoice.pdf',
            'facture.pdf',
            false,
            \Modules\Rental\Document\DocumentType::INVOICE
        );

        $this->assertStringContainsString('À vous : réglez la facture', $this->onlyMail()['text']);
    }

    /** No link is invented for a step that is not done on the tracking page. */
    public function testNoLinkGoesWithAStepDoneElsewhere(): void
    {
        $this->service = $this->serviceWithNextStep(new \Modules\Rental\Booking\RenterNextStep('À vous : versez le solde.'));
        $this->sent = [];
        ($this->everySender()['decision'])();

        $this->assertStringNotContainsString('Ouvrir ma page de suivi', $this->onlyMail()['html']);
    }

    /** A customised body cannot drop it: it is the frame's. */
    public function testACustomisedBodyKeepsTheBlock(): void
    {
        $store = new \PDO('sqlite::memory:');
        $store->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $store->exec('CREATE TABLE email_template_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT, template_id TEXT NOT NULL UNIQUE, subject TEXT NOT NULL,
            body_html TEXT NOT NULL, updated_at TEXT, updated_by INTEGER
        )');
        $overrides = new \Core\Mail\Template\EmailTemplateOverrideRepository($store);
        $overrides->save('rental.acknowledgement', 'Merci', '<p>Notre propre texte.</p>', null);
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/rental/module.json')
        );
        $renderer = new \Core\Mail\Template\EmailTemplateRenderer($this->twig, $registry, $overrides);
        $this->service = $this->serviceWithNextStep(
            new \Modules\Rental\Booking\RenterNextStep('Rien à faire de votre côté pour l\'instant.'),
            $renderer
        );
        $this->sent = [];
        ($this->everySender()['acknowledgement'])();
        $mail = $this->onlyMail();

        $this->assertStringContainsString('Notre propre texte.', $mail['html']);
        $this->assertStringContainsString('Et maintenant ?', $mail['html']);
        $this->assertStringContainsString('Et maintenant ?', $mail['text']);
    }

    /** Without the journey, the e-mails go out as they did. */
    public function testWithoutAJourneyTheEmailsCarryNoBlock(): void
    {
        $this->sent = [];
        ($this->everySender()['acknowledgement'])();

        $this->assertStringNotContainsString('Et maintenant ?', $this->onlyMail()['text']);
    }

    /** The unit's reason reaches the renter as written, and only as text. */
    public function testARefusedCopyCarriesTheReasonEscaped(): void
    {
        $this->sent = [];
        $this->service->sendCopyRefused($this->booking(), $this->asset(), '<b>Page 2</b> non signée', null);
        $mail = $this->onlyMail();

        $this->assertStringContainsString('&lt;b&gt;Page 2&lt;/b&gt; non signée', $mail['html']);
        $this->assertStringContainsString('<b>Page 2</b> non signée', $mail['text']);
    }

    // ── The shared HTML frame ───────────────────────────────────────────

    /**
     * All six were bare `<p>` fragments handed to a mail client, each with
     * its own hand-rolled « À bientôt, {{ site_name }} » — no doctype, no
     * charset, no width, and a signature that drifted three ways
     * (« À bientôt », « Bien à vous », « Bon séjour ») across messages
     * about the same booking. `email/base.html.twig` is where every other
     * module's mail already lives.
     */
    public function testEveryEmailIsRenderedThroughTheSharedFrame(): void
    {
        foreach ($this->everySender() as $name => $send) {
            $this->sent = [];
            $send();
            $html = $this->onlyMail()['html'];

            $this->assertStringStartsWith('<!DOCTYPE html>', trim($html), $name);
            $this->assertStringContainsString('<meta charset="UTF-8">', $html, $name);
            $this->assertStringContainsString('max-width:600px', $html, $name);
            // The frame names the site three times and only three: the
            // document title, the signature and the footer — all three
            // drawn by email/base.html.twig, none of them by a template.
            $this->assertStringContainsString('<title>Unité Test</title>', $html, $name);
            $this->assertSame(3, substr_count($html, 'Unité Test'), $name);
            // Signed once, by the frame, so that a reworded e-mail is
            // signed too — and never by the template itself, which is
            // where the three drifting sign-offs came from.
            $this->assertSame(1, substr_count($html, 'Bien à vous'), $name);
            $this->assertStringNotContainsString('À bientôt', $html, $name);
            $this->assertStringNotContainsString('Bon séjour,', $html, $name);
        }
    }

    // ── One booking, one date format ────────────────────────────────────

    /**
     * Every message this service sends, in both parts.
     *
     * The renter reads their acknowledgement, then their contract, then
     * their confirmation. Three emails about one stay used to disagree
     * about what its dates look like — « du 2027-08-14 au 2027-08-17 » in
     * the first two, « du 14/08/2027 au 17/08/2027 » in the third —
     * because only `decision` and `practical_info` had ever been given
     * `|date_fr`. A stored `Y-m-d` string is a database value, not a date
     * anybody reads.
     */
    public function testEveryEmailNamesTheStaysDatesTheFrenchWay(): void
    {
        foreach ($this->everySender() as $name => $send) {
            $this->sent = [];
            $send();
            $mail = $this->onlyMail();

            foreach (['html', 'text'] as $part) {
                $this->assertStringNotContainsString('2027-08-14', $mail[$part], $name . '.' . $part);
                $this->assertStringNotContainsString('2027-08-17', $mail[$part], $name . '.' . $part);
            }
        }
    }

    /**
     * The five that actually spell the stay out say it in French.
     *
     * `tracking_link` is left out only because it is checked above like
     * every other message; it names both dates too. What is checked here
     * is the positive half — a template that simply stopped printing the
     * dates would satisfy the assertion above and fail this one.
     */
    public function testTheEmailsThatNameBothDatesUseTheFrenchFormat(): void
    {
        foreach ($this->everySender() as $name => $send) {
            if ($name === 'practical_info') {
                // Names arrival and departure in two separate sentences
                // rather than as a « du … au … » pair.
                continue;
            }

            $this->sent = [];
            $send();
            $mail = $this->onlyMail();

            foreach (['html', 'text'] as $part) {
                $this->assertStringContainsString('14/08/2027', $mail[$part], $name . '.' . $part);
                $this->assertStringContainsString('17/08/2027', $mail[$part], $name . '.' . $part);
            }
        }
    }

    // ── Failure ─────────────────────────────────────────────────────────

    public function testAFailedEmailIsReportedRatherThanThrown(): void
    {
        $service = new RentalBookingMailService(
            $this->recordingMailService(succeeds: false),
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
            $this->createStub(SettingService::class),
            $this->createStub(JournalService::class)
        );

        // The decision is already recorded by the time this runs: an SMTP
        // timeout must not roll a confirmed booking back, and must not
        // reach the manager as a 500 either.
        $this->assertFalse(
            $service->sendDecision($this->booking(), $this->asset(), RenterDecision::CONFIRMED, 'tok', null)
        );
        $this->assertSame([], $this->sent);
    }
}
