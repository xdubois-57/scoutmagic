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
use Core\View\TwigFactory;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Service\RentalBookingMailService;
use PHPUnit\Framework\TestCase;
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
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );

        $settings = $this->createMock(SettingService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'site_name' => 'Unité Test',
                'base_url' => 'https://unite.test',
                default => null,
            }
        );

        $this->service = new RentalBookingMailService(
            $this->recordingMailService(),
            $this->twig,
            $settings,
            $this->createMock(JournalService::class)
        );
    }

    private function recordingMailService(bool $succeeds = true): MailService
    {
        $mock = $this->createMock(MailService::class);
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

    private function booking(BookingStatus $status = BookingStatus::RECEIVED): RentalBooking
    {
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

    /** @return array{to: string, subject: string, html: string, text: string} */
    private function onlyMail(): array
    {
        self::assertCount(1, $this->sent, 'Exactly one email should have gone out.');

        return $this->sent[0];
    }

    // ── What goes out at all ────────────────────────────────────────────

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
            'manager_notification' => fn () => $this->service->sendManagerNotification(
                $this->booking(),
                $this->asset(),
                ['gestion@example.test']
            ),
            'document' => fn () => $this->service->sendDocument(
                $this->booking(),
                $this->asset(),
                'Contrat',
                '/tmp/contract.pdf',
                'contrat.pdf'
            ),
            'practical_info' => fn () => $this->service->sendPracticalInfo($this->booking(), $this->asset()),
            'tracking_link' => fn () => $this->service->sendTrackingLink(
                $this->booking(),
                $this->asset(),
                str_repeat('a', 64)
            ),
        ];
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
            $this->assertStringContainsString('max-width:560px', $html, $name);
            // The frame names the site twice and only twice: the document
            // title, and the one footer it draws itself.
            $this->assertStringContainsString('<title>Unité Test</title>', $html, $name);
            $this->assertSame(2, substr_count($html, 'Unité Test'), $name);
            $this->assertStringNotContainsString('À bientôt', $html, $name);
            $this->assertStringNotContainsString('Bien à vous', $html, $name);
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
            $this->twig,
            $this->createMock(SettingService::class),
            $this->createMock(JournalService::class)
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
