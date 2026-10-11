<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Service\RecurringMonthDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The recurring yearly date behind the public form's scheduled opening and
 * closing: stored as "MM-DD", shown in a native date picker as its next
 * occurrence (issue #843, IT-01).
 */
class RecurringMonthDayTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function submittedValues(): array
    {
        return [
            'an emptied picker means no automatic transition' => ['', ''],
            'blank is empty too' => ['   ', ''],
            'what a native picker posts keeps its month and day' => ['2027-09-30', '09-30'],
            'whatever the year chosen' => ['2031-08-31', '08-31'],
            'a bare MM-DD typed where no picker exists' => ['09-30', '09-30'],
            'the last day of the year' => ['2026-12-31', '12-31'],
            'a day that does not exist is refused, not rolled over' => ['2027-02-30', null],
            'nor is the 31st of a 30-day month' => ['2027-04-31', null],
            'a bare impossible day neither' => ['02-30', null],
            'a month that does not exist' => ['13-01', null],
            'the 29th of February from a picker, even on a leap year' => ['2028-02-29', null],
            'the 29th of February typed' => ['02-29', null],
            'another shape entirely' => ['30/09/2027', null],
            'a date with a time' => ['2027-09-30T10:00', null],
        ];
    }

    #[DataProvider('submittedValues')]
    public function testFromSubmittedKeepsOnlyAnExistingMonthAndDay(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, RecurringMonthDay::fromSubmitted($raw));
    }

    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function occurrences(): array
    {
        return [
            'later this year' => ['09-30', '2026-03-15', '2026-09-30'],
            'already passed this year: next year' => ['09-30', '2026-10-11', '2027-09-30'],
            'today itself is still to come' => ['10-11', '2026-10-11', '2026-10-11'],
            'yesterday is next year' => ['10-10', '2026-10-11', '2027-10-10'],
            'the first of January seen from the last day of the year' => ['01-01', '2026-12-31', '2027-01-01'],
            // A 29th of February can only have been stored through the
            // generic settings page; the scheduled task acts on it on leap
            // years only, so that is the date shown.
            'a stored 29th of February: the next leap year' => ['02-29', '2026-10-11', '2028-02-29'],
            'on a leap year, before the day' => ['02-29', '2028-01-10', '2028-02-29'],
            'on a leap year, after the day' => ['02-29', '2028-03-01', '2032-02-29'],
            'nothing stored' => ['', '2026-10-11', null],
            'not a day of the year' => ['02-30', '2026-10-11', null],
            'not the stored shape' => ['2026-09-30', '2026-10-11', null],
        ];
    }

    #[DataProvider('occurrences')]
    public function testNextOccurrenceIsTheNextRealDateOnThatDay(string $monthDay, string $today, ?string $expected): void
    {
        $next = RecurringMonthDay::nextOccurrence($monthDay, new \DateTimeImmutable($today));

        $this->assertSame($expected, $next?->format('Y-m-d'));
    }

    /**
     * The round trip the settings page makes: what it shows, posted back
     * untouched, stores what was there.
     */
    public function testWhatThePickerShowsComesBackAsTheStoredValue(): void
    {
        foreach (['01-01', '03-31', '09-30', '12-31'] as $stored) {
            $shown = RecurringMonthDay::nextOccurrence($stored, new \DateTimeImmutable('2026-10-11'));
            $this->assertNotNull($shown);
            $this->assertSame($stored, RecurringMonthDay::fromSubmitted($shown->format('Y-m-d')));
        }
    }

    /**
     * @return array<string, array{string, string, string, int, ?string}>
     */
    public static function transitions(): array
    {
        return [
            'a date to come this year' => ['10-20', '2026-10-11 10:00', '', 7, '2026-10-20'],
            'a date long passed: next year' => ['09-30', '2026-10-11 10:00', '', 7, '2027-09-30'],
            // Picked in the calendar a few days back: the task will act on
            // it at its next run, so it is shown as still to come.
            'just passed, inside the catch-up window, not applied' => ['10-08', '2026-10-11 10:00', '', 7, '2026-10-08'],
            'just passed, already applied: next year' => ['10-08', '2026-10-11 10:00', '2026-10-08', 7, '2027-10-08'],
            'just passed, an older marker does not count' => ['10-08', '2026-10-11 10:00', '2025-10-08', 7, '2026-10-08'],
            'just passed, no catch-up window: next year' => ['10-08', '2026-10-11 10:00', '', 0, '2027-10-08'],
            'today, not applied yet' => ['10-11', '2026-10-11 10:00', '', 7, '2026-10-11'],
            'today, already applied: next year' => ['10-11', '2026-10-11 10:00', '2026-10-11', 7, '2027-10-11'],
            'a stored 29th of February: the next leap year' => ['02-29', '2026-10-11 10:00', '', 7, '2028-02-29'],
            'nothing stored' => ['', '2026-10-11 10:00', '', 7, null],
        ];
    }

    #[DataProvider('transitions')]
    public function testNextTransitionIsTheDateTheScheduledTaskWillActOn(
        string $monthDay,
        string $now,
        string $appliedOn,
        int $catchUpDays,
        ?string $expected
    ): void {
        $next = RecurringMonthDay::nextTransition($monthDay, new \DateTimeImmutable($now), $appliedOn, $catchUpDays);

        $this->assertSame($expected, $next?->format('Y-m-d'));
    }
}
