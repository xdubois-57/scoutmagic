<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Booking;

use Modules\Rental\Booking\BookingJourney;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterNextStep;
use PHPUnit\Framework\TestCase;

/**
 * « Et maintenant ? » for the renter (#708, IT-15): one sentence per step
 * and per status, in their words, with the date when there is one.
 */
final class RenterNextStepTest extends TestCase
{
    private function booking(BookingStatus $status = BookingStatus::RECEIVED, ?string $holdUntil = null): RentalBooking
    {
        return new RentalBooking(
            id: 1,
            assetId: 7,
            reference: 'LOC-2027-0001',
            arrivalDate: '2027-07-17',
            departureDate: '2027-07-20',
            units: 1,
            estimatedPersons: 30,
            renterCategoryId: null,
            renterName: 'Marie Dupont',
            renterEmail: 'marie@example.org',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: $status,
            receivedAt: new \DateTimeImmutable('2027-01-04 10:00:00'),
            finalAt: null,
            holdUntil: $holdUntil === null ? null : new \DateTimeImmutable($holdUntil),
            holdOrigin: $holdUntil === null ? null : HoldOrigin::MANAGER,
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

    /**
     * @param array<string, bool> $extras
     * @param list<string> $manual
     */
    private function journey(RentalBooking $booking, array $extras = [], array $manual = []): BookingJourney
    {
        return BookingJourney::of(
            BookingMilestones::for($booking, $this->now(), $extras, [], [], $manual),
            $booking->status
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-01-10 12:00:00');
    }

    /** Every step the checklist knows has its sentence — no step falls through. */
    public function testEveryStepHasASentenceInTheRentersWords(): void
    {
        foreach (array_keys(BookingMilestones::ACTORS) as $key) {
            $booking = $this->booking(BookingStatus::CONFIRMED);
            $sentence = RenterNextStep::forStep($key, $booking, $this->now())->sentence;

            $this->assertNotSame('', $sentence, $key);
            $this->assertMatchesRegularExpression('/^(À vous|Rien à faire)/u', $sentence, $key);
            $this->assertDoesNotMatchRegularExpression('/jalon|statut|milestone|_/iu', $sentence, $key);
        }
    }

    /** Every status, too — the ones a step speaks for and the final ones. */
    public function testEveryStatusEndsInASentence(): void
    {
        foreach (BookingStatus::cases() as $status) {
            $booking = $this->booking($status);
            $sentence = RenterNextStep::of($booking, $this->journey($booking), $this->now())->sentence;

            $this->assertMatchesRegularExpression('/^(À vous|Rien à faire)/u', $sentence, $status->value);
        }
    }

    public function testANewRequestHasNothingToDoAndSaysWhatComesNext(): void
    {
        $booking = $this->booking(BookingStatus::RECEIVED, '2027-02-01');

        $step = RenterNextStep::of(
            $booking,
            $this->journey($booking, [BookingMilestones::CONTRACT_GENERATED => false]),
            $this->now()
        );

        $this->assertSame(
            "Rien à faire de votre côté pour l'instant : nous étudions votre demande et vous enverrons le contrat."
            . " Les dates vous sont réservées jusqu'au 01/02/2027.",
            $step->sentence
        );
        $this->assertFalse($step->onTrackingPage);
    }

    public function testTheSignedCopyIsTheRentersOnTheirTrackingPageWithTheDate(): void
    {
        $booking = $this->booking(BookingStatus::CONTRACT_SENT, '2027-02-15');

        $step = RenterNextStep::of($booking, $this->journey($booking, [
            BookingMilestones::CONTRACT_GENERATED => true,
            BookingMilestones::CONTRACT_SENT => true,
            BookingMilestones::SIGNED_COPY_RECEIVED => false,
        ]), $this->now());

        $this->assertSame(
            'À vous : signez le contrat et déposez votre copie signée sur votre page de suivi.'
            . " Les dates vous sont réservées jusqu'au 15/02/2027.",
            $step->sentence
        );
        $this->assertTrue($step->onTrackingPage);
    }

    /** The contract's own e-mail names the hold it is about to set. */
    public function testTheContractsEmailNamesTheHoldItSets(): void
    {
        $step = RenterNextStep::signContract($this->booking(), $this->now(), new \DateTimeImmutable('2027-03-01'));

        $this->assertStringEndsWith("jusqu'au 01/03/2027.", $step->sentence);
    }

    /**
     * A hold that has run out is not promised any more: the manager's
     * « Cycle de vie » says the dates are free, and the renter must not be
     * told they are reserved until a day already past (IT-01).
     */
    public function testALapsedHoldIsNoLongerPromised(): void
    {
        $lapsed = $this->booking(BookingStatus::RECEIVED, '2027-01-08');
        $step = RenterNextStep::of(
            $lapsed,
            $this->journey($lapsed, [BookingMilestones::CONTRACT_GENERATED => false]),
            $this->now()
        );
        $this->assertSame(
            "Rien à faire de votre côté pour l'instant : nous étudions votre demande et vous enverrons le contrat.",
            $step->sentence
        );

        $question = $this->booking(BookingStatus::INFO_REQUESTED, '2027-01-08');
        $this->assertStringNotContainsString(
            'réservées',
            RenterNextStep::of($question, $this->journey($question), $this->now())->sentence
        );
        $this->assertStringNotContainsString(
            'réservées',
            RenterNextStep::signContract($this->booking(BookingStatus::CONTRACT_SENT, '2027-01-08'), $this->now())
                ->sentence
        );
    }

    /**
     * Every step that can come while the dates are still held says so:
     * the tracking page has no other line for the hold once a sentence
     * stands there — the countersignature and the deposit used to drop it.
     */
    public function testEveryStepSaysAHoldStillRunning(): void
    {
        $booking = $this->booking(BookingStatus::CONTRACT_SENT, '2027-02-15');

        foreach (array_keys(BookingMilestones::ACTORS) as $key) {
            $sentence = RenterNextStep::forStep($key, $booking, $this->now())->sentence;

            $this->assertSame(
                1,
                substr_count($sentence, "Les dates vous sont réservées jusqu'au 15/02/2027."),
                $key
            );
        }

        $confirmed = $this->booking(BookingStatus::CONFIRMED, '2027-02-15');
        $this->assertStringNotContainsString(
            'réservées',
            RenterNextStep::forStep(BookingMilestones::DEPOSIT_RECEIVED, $confirmed, $this->now())->sentence
        );
    }

    public function testAPaymentSaysItsDueDateAndItsCommunication(): void
    {
        $booking = $this->booking(BookingStatus::CONFIRMED);

        $step = RenterNextStep::forStep(BookingMilestones::DEPOSIT_RECEIVED, $booking, $this->now(), [
            'deposit_due_date' => '2027-03-10',
            'communication' => '+++123/4567/89012+++',
        ]);

        $this->assertSame(
            "À vous : versez l'acompte en indiquant la communication +++123/4567/89012+++ avant le 10/03/2027.",
            $step->sentence
        );
    }

    public function testAQuestionOrAProposalIsTheRentersWhateverTheNextStep(): void
    {
        $info = $this->booking(BookingStatus::INFO_REQUESTED);
        $proposed = $this->booking(BookingStatus::PROPOSED);

        $this->assertStringStartsWith('À vous : répondez à notre question', RenterNextStep::of($info, $this->journey($info), $this->now())->sentence);
        $this->assertStringStartsWith('À vous : acceptez ou refusez', RenterNextStep::of($proposed, $this->journey($proposed), $this->now())->sentence);
    }

    /** A step ticked by hand is done (IT-14): the sentence moves on. */
    public function testAStepTickedByHandIsSkipped(): void
    {
        $booking = $this->booking(BookingStatus::CONFIRMED);
        $extras = [
            BookingMilestones::CONTRACT_GENERATED => true,
            BookingMilestones::CONTRACT_SENT => true,
            BookingMilestones::SIGNED_COPY_RECEIVED => true,
            BookingMilestones::CONTRACT_COUNTERSIGNED => true,
            BookingMilestones::ARRIVAL_INVENTORY => true,
            BookingMilestones::DEPARTURE_INVENTORY => false,
        ];

        $journey = $this->journey($booking, $extras, [BookingMilestones::ARRIVAL_INVENTORY]);
        $step = RenterNextStep::of($booking, $journey, $this->now());

        $this->assertStringContainsString("l'état des lieux de sortie", $step->sentence);
    }

    public function testAnInvoiceCallsForItsPayment(): void
    {
        $this->assertStringStartsWith('À vous : réglez la facture', RenterNextStep::payInvoice()->sentence);
    }
}
