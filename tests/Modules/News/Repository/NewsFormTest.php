<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\TestCase;

class NewsFormTest extends TestCase
{
    private function build(
        bool $isForceClosed,
        ?string $opensAt,
        ?string $closesAt,
        bool $issuesTicket = false,
        ?string $eventDate = null,
        ?string $eventLocation = null
    ): NewsForm {
        return new NewsForm(
            1, 1, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, $opensAt, $closesAt,
            $isForceClosed, 'chief', null, $issuesTicket, $eventDate, $eventLocation, null, null, '2026-01-01 00:00:00'
        );
    }

    public function testOpenWithNoDates(): void
    {
        $this->assertTrue($this->build(false, null, null)->isOpen());
    }

    public function testForceClosedOverridesDates(): void
    {
        $this->assertFalse($this->build(true, null, null)->isOpen());
    }

    public function testClosedBeforeOpensAt(): void
    {
        $form = $this->build(false, '2026-06-01', null);
        $this->assertFalse($form->isOpen(new \DateTimeImmutable('2026-05-01')));
        $this->assertTrue($form->isOpen(new \DateTimeImmutable('2026-06-01')));
    }

    public function testClosedAfterClosesAt(): void
    {
        $form = $this->build(false, null, '2026-06-01');
        $this->assertTrue($form->isOpen(new \DateTimeImmutable('2026-06-01')));
        $this->assertFalse($form->isOpen(new \DateTimeImmutable('2026-06-02')));
    }

    // --- Issue #925: why a form is not open, from one reference date ---

    public function testAvailabilityIsOpenWithNoBounds(): void
    {
        $this->assertSame(NewsForm::AVAILABILITY_OPEN, $this->build(false, null, null)->availability());
    }

    /**
     * The reported case: closed by hand on 14 October 2026 while the closing
     * date, six months ahead by default, is still in the future. The reason
     * is the manual closure, not an expiry.
     */
    public function testAManualClosureIsNotMistakenForAnExpiry(): void
    {
        $form = $this->build(true, '2026-10-14', '2027-04-14');

        $this->assertSame(
            NewsForm::AVAILABILITY_FORCE_CLOSED,
            $form->availability(new \DateTimeImmutable('2026-10-14'))
        );
        $this->assertFalse($form->isOpen(new \DateTimeImmutable('2026-10-14')));
    }

    public function testAFormNotYetOpenSaysSo(): void
    {
        $form = $this->build(false, '2026-10-20', '2027-04-14');

        $this->assertSame(NewsForm::AVAILABILITY_NOT_YET_OPEN, $form->availability(new \DateTimeImmutable('2026-10-14')));
    }

    public function testAFormPastItsClosingDateIsExpired(): void
    {
        $form = $this->build(false, null, '2026-04-14');

        $this->assertSame(NewsForm::AVAILABILITY_EXPIRED, $form->availability(new \DateTimeImmutable('2026-10-14')));
    }

    public function testBothBoundsAreInclusiveOnTheirOwnDay(): void
    {
        $form = $this->build(false, '2026-10-14', '2026-10-20');

        $this->assertSame(NewsForm::AVAILABILITY_OPEN, $form->availability(new \DateTimeImmutable('2026-10-14')));
        $this->assertSame(NewsForm::AVAILABILITY_OPEN, $form->availability(new \DateTimeImmutable('2026-10-20 23:59:59')));
        $this->assertSame(NewsForm::AVAILABILITY_NOT_YET_OPEN, $form->availability(new \DateTimeImmutable('2026-10-13')));
        $this->assertSame(NewsForm::AVAILABILITY_EXPIRED, $form->availability(new \DateTimeImmutable('2026-10-21')));
    }

    /**
     * The booleans every other caller relies on — the submission refusal
     * among them — must keep agreeing with the reason, whatever the dates.
     */
    public function testIsOpenAgreesWithTheReasonForEveryCombination(): void
    {
        foreach ([false, true] as $forced) {
            foreach ([null, '2026-10-10', '2026-10-14', '2026-10-20'] as $opensAt) {
                foreach ([null, '2026-10-10', '2026-10-14', '2026-10-20'] as $closesAt) {
                    $form = $this->build($forced, $opensAt, $closesAt);
                    $now = new \DateTimeImmutable('2026-10-14');

                    $this->assertSame(
                        $form->availability($now) === NewsForm::AVAILABILITY_OPEN,
                        $form->isOpen($now),
                        sprintf('forced=%d opens=%s closes=%s', $forced, $opensAt ?? '-', $closesAt ?? '-')
                    );
                }
            }
        }
    }

    // --- IT-02: the event's own date, never closes_at ---

    public function testAFormWithNoEventDateHasNoDetailsToShow(): void
    {
        // The degraded mode, and it is a usable one: the ticket names the
        // article and nothing more. Nobody forgets when they are going out.
        $this->assertFalse($this->build(false, null, null, true)->hasEventDetails());
    }

    public function testALocationWithoutADateIsStillNoDetails(): void
    {
        // A place with no date puts the event nowhere in time and cannot
        // produce a calendar entry at all — the date is what decides, and
        // the location rides along.
        $this->assertFalse($this->build(false, null, null, true, null, 'Salle paroissiale')->hasEventDetails());
    }

    public function testADateIsEnoughToDescribeTheEvent(): void
    {
        $this->assertTrue($this->build(false, null, null, true, '2026-03-14')->hasEventDetails());
    }

    public function testTheEventDateNeverClosesTheForm(): void
    {
        // closes_at closes the REGISTRATIONS; a dinner on 14 March closes
        // its bookings on the 10th. Reading the event date as a closing
        // date would shut the form — and later hide the event from the
        // door screen — on precisely the evening it is being controlled.
        $form = $this->build(false, null, null, true, '2020-01-01');

        $this->assertTrue($form->isOpen(new \DateTimeImmutable('2026-05-01')));
    }
}
