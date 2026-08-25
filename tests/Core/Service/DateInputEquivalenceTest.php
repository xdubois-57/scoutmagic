<?php

declare(strict_types=1);

namespace Tests\Core\Service;

use Core\Service\DateInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The yardstick for the migration away from `new DateTimeImmutable($v)`.
 *
 * Some 160 call sites were converted to `DateInput::fromStorage()` and
 * `::requireFromStorage()`. The question that decides whether that was a
 * safe change is not "does the new function work" — it is **"does it
 * answer exactly what the old one answered, for every value the
 * application actually stores"**. A replacement that is safer and
 * subtly different is a regression wearing a security badge.
 *
 * So this pins the equivalence in both directions:
 *
 *   - for every value a `DATE`/`DATETIME` column can hold, the two
 *     produce the SAME instant, to the second and with the same
 *     timezone;
 *   - and the handful of inputs where they differ are enumerated here,
 *     one by one, with what each used to do — because those differences
 *     are the entire point of the change, and an undocumented one would
 *     be indistinguishable from a mistake.
 */
class DateInputEquivalenceTest extends TestCase
{
    /**
     * What MySQL actually hands back for a `DATE`, a `DATETIME`, and the
     * shapes the repositories write with `format('Y-m-d H:i:s')`.
     *
     * @return array<string, array{0: string}>
     */
    public static function valuesAColumnCanHold(): array
    {
        return [
            'a DATETIME as MySQL returns it' => ['2026-07-05 14:30:00'],
            'midnight' => ['2026-07-05 00:00:00'],
            'the last second of a day' => ['2026-12-31 23:59:59'],
            'a DATE column' => ['2026-07-05'],
            'the first day of a year' => ['2026-01-01'],
            'a leap day' => ['2028-02-29'],
            'the earliest year the schema allows' => ['1000-01-01 00:00:00'],
            'a far future expiry' => ['2999-12-31 23:59:59'],
            'with a fractional second' => ['2026-07-05 14:30:00.123456'],
            'a timestamp carrying an offset' => ['2026-07-05 14:30:00+02:00'],
            'ISO 8601 with a T' => ['2026-07-05T14:30:00'],
            'a value with surrounding whitespace' => ['  2026-07-05 14:30:00  '],
        ];
    }

    /**
     * The core claim. Same instant, same timezone, same everything a
     * caller can observe.
     */
    #[DataProvider('valuesAColumnCanHold')]
    public function testFromStorageAnswersExactlyWhatTheConstructorAnswered(string $stored): void
    {
        $before = new \DateTimeImmutable($stored);
        $after = DateInput::fromStorage($stored);

        $this->assertNotNull($after, "fromStorage() refused a value a column can hold: {$stored}");
        $this->assertSame(
            $before->format('Y-m-d H:i:s.u P'),
            $after->format('Y-m-d H:i:s.u P'),
            "the two readings disagree on {$stored}"
        );
        $this->assertSame($before->getTimestamp(), $after->getTimestamp());
    }

    /**
     * And requireFromStorage() is the same object again — it only adds
     * the refusal, never a different reading.
     */
    #[DataProvider('valuesAColumnCanHold')]
    public function testRequireFromStorageIsTheSameReading(string $stored): void
    {
        $this->assertSame(
            (new \DateTimeImmutable($stored))->format('Y-m-d H:i:s.u P'),
            DateInput::requireFromStorage($stored, 'test')->format('Y-m-d H:i:s.u P')
        );
    }

    /**
     * Every deliberate difference, named. If one of these ever starts
     * agreeing with the old behaviour again, that is a regression in the
     * fix and this says so.
     *
     * @return array<string, array{0: ?string, 1: string}> value, what the constructor used to do
     */
    public static function theDeliberateDifferences(): array
    {
        return [
            'empty — the constructor answered *now*, silently'
                => ['', 'now'],
            'whitespace only — same' => ['   ', 'now'],
            "MySQL's zero date — the constructor answered 30 November, year -1"
                => ['0000-00-00 00:00:00', '-0001-11-30'],
            "MySQL's zero DATE — same" => ['0000-00-00', '-0001-11-30'],
            'a relative expression is not a stored moment' => ['+1 day', 'a different answer every read'],
            'so is "now"' => ['now', 'a different answer every read'],
            'so is "yesterday"' => ['yesterday', 'a different answer every read'],
            'a NUL byte — the constructor answered *now*' => ["a\0b", 'now'],
            'a malformed date threw where a null is enough' => ['2026-99-99', 'DateMalformedStringException'],
            'a traversal payload, same' => ['../../../../etc/passwd', 'DateMalformedStringException'],
        ];
    }

    #[DataProvider('theDeliberateDifferences')]
    public function testTheOnlyDifferencesAreTheIntendedOnes(string $value, string $whatItUsedToDo): void
    {
        $this->assertNull(
            DateInput::fromStorage($value),
            'this value is refused on purpose — it used to give: ' . $whatItUsedToDo
        );
    }

    /**
     * The refusal is loud where the value was declared to be there.
     */
    public function testRequireFromStorageStopsRatherThanInventing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rental_bookings.arrival_date');

        DateInput::requireFromStorage('', 'rental_bookings.arrival_date');
    }

    /**
     * Proof that the differences above are differences, not a story about
     * them: the old reading really did answer these things.
     */
    public function testTheOldReadingReallyDidAnswerNowForAnEmptyColumn(): void
    {
        $this->assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            (new \DateTimeImmutable(''))->format('Y-m-d')
        );
        $this->assertSame(
            '-0001-11-30',
            (new \DateTimeImmutable('0000-00-00 00:00:00'))->format('Y-m-d')
        );
    }

    /**
     * The other half of the migration: eleven copies of
     * `new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m))` became
     * DateInput::firstOfMonth().
     *
     * This is the assertion that caught the one real regression the
     * conversion introduced. firstOfMonth() reads through
     * `createFromFormat()`, which fills every field the format does not
     * mention from the CURRENT time — so the first of the month came back
     * at the wall clock of the moment it was asked for, where the
     * constructor answers midnight. Nothing formats differently;
     * Rental\Availability\MonthWindow::previous() compares `<=` against
     * the first of the current month, and the calendar quietly stopped
     * disabling its own "previous month" arrow.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function monthsAGridCanShow(): array
    {
        return [
            'the first month of a year' => [2026, 1],
            'a month in the middle' => [2026, 7],
            'the last month of a year' => [2026, 12],
            'a February in a leap year' => [2028, 2],
            'a month that starts on a Sunday' => [2026, 2],
            'the earliest year the schema allows' => [1000, 1],
            'a far future month' => [2999, 12],
        ];
    }

    #[DataProvider('monthsAGridCanShow')]
    public function testFirstOfMonthIsTheSameMomentTheConstructorGave(int $year, int $month): void
    {
        $before = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $after = DateInput::firstOfMonth($year, $month);

        $this->assertSame(
            $before->format('Y-m-d H:i:s.u P'),
            $after->format('Y-m-d H:i:s.u P'),
            'the first of the month must be MIDNIGHT, as the constructor answered'
        );
    }

    /**
     * And the same trap, at its source: a date with no time means
     * midnight, not "today's clock stamped onto that day".
     */
    public function testAnIsoDateHasNoTimeOfItsOwn(): void
    {
        $this->assertSame(
            '2026-08-01 00:00:00.000000',
            DateInput::iso('2026-08-01')?->format('Y-m-d H:i:s.u')
        );
        $this->assertSame(
            '2026-08-01 09:30:00.000000',
            DateInput::parse(DateInput::ISO_DATETIME_LOCAL, '2026-08-01T09:30')?->format('Y-m-d H:i:s.u')
        );
    }

    /**
     * The same trap's other victim, found while verifying this migration
     * and fixed by the same `!`: two dates read a moment apart, then
     * subtracted.
     *
     * Rental\Service\RentalDocumentService::nightsBetween() reads an
     * arrival and a departure through iso() and takes `diff()->days`.
     * While iso() inherited the clock, the two readings carried the times
     * of two separate calls — so a pair that straddled a second boundary
     * came out one night short, on a contract, roughly once every few
     * thousand renders. Nothing was there to notice.
     */
    public function testTwoDatesReadSeparatelyAreStillAWholeNumberOfDaysApart(): void
    {
        $arrival = DateInput::iso('2026-07-17');
        $departure = DateInput::iso('2026-07-20');

        $this->assertNotNull($arrival);
        $this->assertNotNull($departure);
        $this->assertSame(3, (int) $arrival->diff($departure)->days);
        $this->assertSame(
            '00:00:00',
            $arrival->format('H:i:s'),
            'two readings can only be subtracted safely if neither carries a clock'
        );
    }

    /**
     * Proof the trap is real rather than a story about it — pinned so
     * that if PHP ever changes, this says so instead of the calendar
     * saying it silently.
     */
    public function testCreateFromFormatWithoutTheResetReallyDoesInheritTheClock(): void
    {
        $inherited = \DateTimeImmutable::createFromFormat('Y-m-d', '2026-08-01');

        $this->assertNotFalse($inherited);
        $this->assertSame(
            (new \DateTimeImmutable())->format('H:i'),
            $inherited->format('H:i'),
            'the unnamed fields come from the current time — hence the ! in DateInput::ISO_DATE'
        );
    }

    /**
     * A timezone claim worth its own test, because it is the one way a
     * migration like this could go wrong invisibly: every date on every
     * page would shift by an hour, and nothing would fail.
     */
    public function testNeitherReadingImposesATimezoneOfItsOwn(): void
    {
        $stored = '2026-07-05 14:30:00';

        $this->assertSame(
            (new \DateTimeImmutable($stored))->getTimezone()->getName(),
            DateInput::fromStorage($stored)?->getTimezone()->getName()
        );
        $this->assertSame(
            date_default_timezone_get(),
            DateInput::fromStorage($stored)?->getTimezone()->getName()
        );
    }

    /**
     * The timezone argument the ICS export and the groups module needed:
     * it has to mean what it means on the constructor, or an exported
     * event moves by an hour and no test anywhere fails.
     */
    public function testTheTimezoneArgumentMeansWhatItDoesOnTheConstructor(): void
    {
        $stored = '2026-07-05 14:30:00';
        $utc = new \DateTimeZone('UTC');

        $this->assertSame(
            (new \DateTimeImmutable($stored, $utc))->format('Y-m-d H:i:s P'),
            DateInput::fromStorage($stored, $utc)?->format('Y-m-d H:i:s P')
        );
        $this->assertSame(
            (new \DateTimeImmutable($stored, $utc))->getTimestamp(),
            DateInput::requireFromStorage($stored, 'test', $utc)->getTimestamp()
        );
    }

    /**
     * And that it is ignored, on both sides, when the value carries its
     * own offset — the rule PHP applies and the one an ICS feed relies on.
     */
    public function testAnExplicitOffsetInTheValueStillWinsOverTheArgument(): void
    {
        $stored = '2026-07-05 14:30:00+05:00';
        $utc = new \DateTimeZone('UTC');

        $this->assertSame(
            (new \DateTimeImmutable($stored, $utc))->getTimestamp(),
            DateInput::fromStorage($stored, $utc)?->getTimestamp()
        );
    }
}
