<?php

declare(strict_types=1);

namespace Tests\Core\Service;

use Core\Service\DateInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateInputTest extends TestCase
{
    public function testARealDateParses(): void
    {
        $parsed = DateInput::iso('2026-01-31');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-01-31', $parsed->format('Y-m-d'));
    }

    /**
     * The reason this class exists. Every site it replaces wrote
     * `createFromFormat(...) !== false`, which is a total function right
     * up until the value carries a NUL — then it raises a ValueError that
     * nothing between here and the front controller expects.
     */
    #[DataProvider('nullBytePayloads')]
    public function testANulByteIsRefusedRatherThanRaised(string $payload): void
    {
        $this->assertNull(DateInput::iso($payload));
        $this->assertFalse(DateInput::isIso($payload));
        $this->assertNull(DateInput::isoStringOrNull($payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nullBytePayloads(): array
    {
        return [
            'NUL inside an otherwise valid date' => ["2026-01\0-01"],
            'NUL splitting the year' => ["20\026-01-01"],
            'the raw payload a scanner sends' => ["2026-01-01\0.jpg"],
            'NUL alone' => ["\0"],
        ];
    }

    /**
     * Proof the danger is real rather than theoretical: the same value
     * through PHP's own function raises, and through this one does not.
     */
    public function testTheUnderlyingFunctionReallyDoesRaiseOnThatInput(): void
    {
        $this->expectException(\ValueError::class);
        \DateTimeImmutable::createFromFormat('Y-m-d', "2026-01\0-01");
    }

    #[DataProvider('nonDates')]
    public function testSomethingThatIsNotADateIsRefused(string $value): void
    {
        $this->assertNull(DateInput::iso($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonDates(): array
    {
        return [
            'a traversal payload' => ['../../../../etc/passwd'],
            'a Windows path' => ['c:/Windows/system.ini'],
            'empty' => [''],
            'a word' => ['bientôt'],
            'a very long number' => [str_repeat('9', 400)],
            'the right shape, impossible month' => ['2026-13-01'],
            'a datetime where a date was asked for' => ['2026-01-01 10:00:00'],
        ];
    }

    /**
     * createFromFormat rolls an impossible day forward — 2026-02-31
     * becomes 2026-03-03 — and returns it as a success. A date the
     * visitor did not type must never be stored as one they did.
     */
    public function testAnImpossibleDayIsRefusedRatherThanRolledForward(): void
    {
        $this->assertNull(DateInput::iso('2026-02-31'));
        $this->assertNull(DateInput::iso('2026-04-31'));
        $this->assertNotNull(DateInput::iso('2028-02-29'), 'a real leap day is still a date');
    }

    public function testTheDatetimeLocalFormatAFormPostsIsUnderstood(): void
    {
        $parsed = DateInput::parse(DateInput::ISO_DATETIME_LOCAL, '2026-01-31T14:30');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-01-31 14:30', $parsed->format('Y-m-d H:i'));
        $this->assertNull(DateInput::parse(DateInput::ISO_DATETIME_LOCAL, '2026-01-31 14:30'));
    }

    /**
     * `!` resets the fields the format does not mention, so the parse is
     * anchored to midnight instead of to the current time. It steers the
     * parse; it is not something the value should contain.
     */
    public function testALeadingResetInTheFormatDoesNotBreakTheRoundTrip(): void
    {
        $parsed = DateInput::parse('!Y-m-d', '2026-01-31');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-01-31 00:00:00', $parsed->format('Y-m-d H:i:s'));
    }

    public function testIsoStringOrNullTrimsButDoesNotSalvage(): void
    {
        $this->assertSame('2026-01-31', DateInput::isoStringOrNull('  2026-01-31  '));
        $this->assertSame('2026-01-31', DateInput::isoStringOrNull("2026-01-31\0"), 'trim() strips a trailing NUL');
        $this->assertNull(DateInput::isoStringOrNull(null));
        $this->assertNull(DateInput::isoStringOrNull(''));
        $this->assertNull(DateInput::isoStringOrNull('   '));
        $this->assertNull(DateInput::isoStringOrNull('demain'));
    }

    /**
     * The opposite trap. `new DateTimeImmutable('')` is not an error —
     * it is *now*, which is how an unvalidated field silently becomes
     * today's date in a column and stays there.
     */
    #[DataProvider('valuesThatWouldSilentlyBecomeNow')]
    public function testFromStorageNeverAnswersTheCurrentMoment(string $value): void
    {
        $this->assertNull(DateInput::fromStorage($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valuesThatWouldSilentlyBecomeNow(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'the literal word' => ['now'],
            'a relative day' => ['yesterday'],
            'a relative offset' => ['+1 day'],
            'a NUL' => ["a\0b"],
        ];
    }

    public function testTheUnderlyingConstructorReallyDoesAnswerNowForThose(): void
    {
        $this->assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            (new \DateTimeImmutable(''))->format('Y-m-d'),
            'if this ever stops being true, fromStorage() can be simplified'
        );
    }

    public function testFromStorageReadsWhatAColumnActuallyHolds(): void
    {
        $this->assertSame(
            '2026-01-31 14:30:00',
            DateInput::fromStorage('2026-01-31 14:30:00')?->format('Y-m-d H:i:s')
        );
        $this->assertSame('2026-01-31', DateInput::fromStorage('2026-01-31')?->format('Y-m-d'));
        $this->assertNull(DateInput::fromStorage(null));
    }

    /**
     * A malformed stored value is a fact to handle, not a 500. This is
     * the one PHP raises DateMalformedStringException for.
     */
    public function testFromStorageRefusesAMalformedValueWithoutThrowing(): void
    {
        $this->assertNull(DateInput::fromStorage('2026-99-99'));
    }

    /**
     * MySQL's zero date is its way of writing "no value", and PHP does
     * not refuse it — it answers the 30th of November, year -1, which a
     * template will happily print.
     */
    public function testFromStorageRefusesMysqlsZeroDate(): void
    {
        $this->assertSame('-0001-11-30', (new \DateTimeImmutable('0000-00-00 00:00:00'))->format('Y-m-d'));
        $this->assertNull(DateInput::fromStorage('0000-00-00 00:00:00'));
        $this->assertNull(DateInput::fromStorage('0000-00-00'));
    }
}
