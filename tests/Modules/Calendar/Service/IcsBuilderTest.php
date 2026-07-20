<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Service\IcsBuilder;
use PHPUnit\Framework\TestCase;

class IcsBuilderTest extends TestCase
{
    private IcsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new IcsBuilder();
    }

    private function makeEvent(
        int $id = 1,
        string $title = 'Réunion',
        string $startDate = '2026-03-15',
        ?string $endDate = null,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $location = null,
        ?string $description = null,
        int $sequence = 0,
        string $updatedAt = '2026-03-01 10:00:00'
    ): CalendarEvent {
        return new CalendarEvent($id, 10, $title, $startDate, $endDate, $startTime, $endTime, $location, $description, $sequence, null, $updatedAt);
    }

    private function lines(string $ics): array
    {
        return explode("\r\n", rtrim($ics, "\r\n"));
    }

    public function testBuildProducesValidVcalendarWrapper(): void
    {
        $ics = $this->builder->build('Mon calendrier', []);
        $lines = $this->lines($ics);

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertContains('PRODID:-//ScoutMagic//Calendrier//FR', $lines);
        $this->assertContains('CALSCALE:GREGORIAN', $lines);
        $this->assertContains('METHOD:PUBLISH', $lines);
        $this->assertContains('X-WR-CALNAME:Mon calendrier', $lines);
        $this->assertSame('END:VCALENDAR', $lines[count($lines) - 1]);
    }

    public function testOutputUsesCrlfLineEndings(): void
    {
        $ics = $this->builder->build('Test', [$this->makeEvent()]);

        $this->assertStringContainsString("\r\n", $ics);
        // No bare LF: every \n must be immediately preceded by \r.
        $withoutCrlf = str_replace("\r\n", '', $ics);
        $this->assertStringNotContainsString("\n", $withoutCrlf);
        $this->assertStringNotContainsString("\r\n\r\n", $ics);
    }

    public function testOutputIsValidUtf8(): void
    {
        $ics = $this->builder->build('Calendrier', [$this->makeEvent(title: 'Réunion — spéciale')]);

        $this->assertTrue(mb_check_encoding($ics, 'UTF-8'));
    }

    public function testIncludesVtimezoneBlock(): void
    {
        $ics = $this->builder->build('Test', []);
        $lines = $this->lines($ics);

        $this->assertContains('BEGIN:VTIMEZONE', $lines);
        $this->assertContains('TZID:Europe/Brussels', $lines);
        $this->assertContains('END:VTIMEZONE', $lines);
        $this->assertContains('BEGIN:STANDARD', $lines);
        $this->assertContains('BEGIN:DAYLIGHT', $lines);
    }

    public function testTimedEventUsesTzidDtstartAndDtend(): void
    {
        $event = $this->makeEvent(startDate: '2026-03-15', startTime: '14:00:00', endTime: '16:00:00');
        $ics = $this->builder->build('Test', [$event]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;TZID=Europe/Brussels:20260315T140000', $lines);
        $this->assertContains('DTEND;TZID=Europe/Brussels:20260315T160000', $lines);
    }

    public function testTimedEventWithoutEndTimeOmitsDtend(): void
    {
        $event = $this->makeEvent(startTime: '14:00:00', endTime: null);
        $ics = $this->builder->build('Test', [$event]);

        $this->assertStringNotContainsString('DTEND', $ics);
    }

    public function testSingleDayAllDayEventUsesValueDateWithExclusiveDtend(): void
    {
        $event = $this->makeEvent(startDate: '2026-03-15', endDate: null, startTime: null);
        $ics = $this->builder->build('Test', [$event]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;VALUE=DATE:20260315', $lines);
        // Exclusive end: the day AFTER the single all-day date.
        $this->assertContains('DTEND;VALUE=DATE:20260316', $lines);
    }

    public function testMultiDayAllDayEventDtendIsExclusive(): void
    {
        $event = $this->makeEvent(startDate: '2026-04-04', endDate: '2026-04-06', startTime: null);
        $ics = $this->builder->build('Test', [$event]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;VALUE=DATE:20260404', $lines);
        // Exclusive end: one day past the last actual day (06 -> 07).
        $this->assertContains('DTEND;VALUE=DATE:20260407', $lines);
    }

    public function testMultiDayTimedEventDtendIsNotShiftedByOneDay(): void
    {
        // A timed (non all-day) multi-day event must NOT get the all-day
        // "+1 day" exclusive-end treatment.
        $event = $this->makeEvent(startDate: '2026-04-04', endDate: '2026-04-06', startTime: '18:00:00', endTime: '16:00:00');
        $ics = $this->builder->build('Test', [$event]);
        $lines = $this->lines($ics);

        $this->assertContains('DTSTART;TZID=Europe/Brussels:20260404T180000', $lines);
        $this->assertContains('DTEND;TZID=Europe/Brussels:20260406T160000', $lines);
    }

    public function testSummaryLocationAndDescriptionArePresent(): void
    {
        $event = $this->makeEvent(title: 'Réunion', location: 'Local Scout', description: 'Prévoir le pique-nique');
        $ics = $this->builder->build('Test', [$event]);
        $lines = $this->lines($ics);

        $this->assertContains('SUMMARY:Réunion', $lines);
        $this->assertContains('LOCATION:Local Scout', $lines);
        $this->assertContains('DESCRIPTION:Prévoir le pique-nique', $lines);
    }

    public function testEmptyLocationAndDescriptionAreOmitted(): void
    {
        $event = $this->makeEvent(location: null, description: null);
        $ics = $this->builder->build('Test', [$event]);

        $this->assertStringNotContainsString('LOCATION', $ics);
        $this->assertStringNotContainsString('DESCRIPTION', $ics);
    }

    public function testTextEscapesCommaSemicolonBackslashAndNewline(): void
    {
        $event = $this->makeEvent(title: 'Titre, avec; des\\caractères' . "\n" . 'sur deux lignes');
        $ics = $this->builder->build('Test', [$event]);

        $this->assertStringContainsString('SUMMARY:Titre\\, avec\\; des\\\\caractères\\nsur deux lignes', $ics);
    }

    public function testSequenceIsIncludedFromEvent(): void
    {
        $event = $this->makeEvent(sequence: 3);
        $ics = $this->builder->build('Test', [$event]);

        $this->assertStringContainsString('SEQUENCE:3', $ics);
    }

    public function testUidIsStableAndUniquePerEvent(): void
    {
        $ics = $this->builder->build('Test', [$this->makeEvent(id: 1), $this->makeEvent(id: 2)]);

        $this->assertStringContainsString('UID:cal-event-1@scoutmagic', $ics);
        $this->assertStringContainsString('UID:cal-event-2@scoutmagic', $ics);
    }

    public function testEmptyEventListStillProducesValidCalendar(): void
    {
        $ics = $this->builder->build('Calendrier vide', []);
        $lines = $this->lines($ics);

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', $lines[count($lines) - 1]);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    public function testNoContentLineExceeds75Octets(): void
    {
        $event = $this->makeEvent(
            title: str_repeat('Un titre vraiment très très long pour forcer le pliage de ligne RFC 5545. ', 3)
        );
        $ics = $this->builder->build('Test', [$event]);

        foreach ($this->lines($ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Line exceeds 75 octets: {$line}");
        }
    }

    public function testFoldedContinuationLinesStartWithASingleSpace(): void
    {
        $event = $this->makeEvent(
            title: str_repeat('Un titre vraiment très très long pour forcer le pliage de ligne RFC 5545. ', 3)
        );
        $ics = $this->builder->build('Test', [$event]);

        $summaryStart = strpos($ics, 'SUMMARY:');
        $this->assertNotFalse($summaryStart);
        $nextEventProp = strpos($ics, "\r\n", $summaryStart);
        $this->assertNotFalse($nextEventProp);
        // The line immediately after the first fold must start with " ".
        $afterFirstFold = substr($ics, $nextEventProp + 2, 1);
        $this->assertSame(' ', $afterFirstFold);
    }

    public function testFoldingNeverSplitsAMultiByteUtf8Character(): void
    {
        // Craft a title where a 2-byte UTF-8 character (é) sits right at
        // the 75-octet boundary of the "SUMMARY:" property line.
        $prefix = str_repeat('a', 75 - strlen('SUMMARY:') - 1);
        $title = $prefix . 'é' . 'END';

        $ics = $this->builder->build('Test', [$this->makeEvent(title: $title)]);

        // Unfolding (removing CRLF + the single leading space of each
        // continuation) must exactly reconstruct the original property —
        // any byte loss/duplication indicates the fold split the é.
        $unfolded = preg_replace('/\r\n /', '', $ics);
        $this->assertStringContainsString('SUMMARY:' . $title, $unfolded);
        $this->assertTrue(mb_check_encoding($ics, 'UTF-8'));
    }

    public function testMultipleEventsEachGetTheirOwnVeventBlock(): void
    {
        $ics = $this->builder->build('Test', [$this->makeEvent(id: 1), $this->makeEvent(id: 2), $this->makeEvent(id: 3)]);

        $this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertSame(3, substr_count($ics, 'END:VEVENT'));
    }
}
