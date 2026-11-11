<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Ics;

use Modules\DataMigration\Ics\IcsEventReader;
use Modules\DataMigration\Ics\IcsUnreadableException;
use PHPUnit\Framework\TestCase;

/**
 * The ICS files under tests/fixtures/data-migration/ics/, read the way the
 * import reads them: one line per VEVENT, in file order, already in the
 * calendar module's terms.
 */
final class IcsEventReaderTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/ics_reader_' . bin2hex(random_bytes(6)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
    }

    public function testTheValidFixtureGivesEveryEventInBrusselsTime(): void
    {
        $events = $this->convert('valide.ics');

        $this->assertCount(8, $events);
        foreach ($events as $event) {
            $this->assertSame(IcsEventReader::STATE_EVENT, $event['state'], (string) $event['uid']);
        }

        // A TZID of Brussels, its VTIMEZONE in the file.
        $this->assertSame(['2025-03-15', null, '14:00', '17:30'], self::when($events[0]));
        $this->assertSame('Réunion du samedi', $events[0]['title']);
        $this->assertSame('Local de l\'unité, rue des Exemples 1', $events[0]['location']);
        $this->assertSame("Apporter un pique-nique.\nPrévoir des bottes.", $events[0]['description']);
        // UTC in June: two hours later in Brussels (summer time).
        $this->assertSame(['2025-06-20', null, '20:00', '22:00'], self::when($events[1]));
        // A floating time is read as Brussels time; a DURATION gives the end.
        $this->assertSame(['2025-07-04', null, '20:00', '22:30'], self::when($events[2]));
        // A DATE is « toute la journée »: no time at all.
        $this->assertSame(['2025-09-13', null, null, null], self::when($events[3]));
        // RFC 5545's exclusive end date becomes the module's inclusive one.
        $this->assertSame(['2025-07-20', '2025-07-30', null, null], self::when($events[4]));
        // Past midnight: the end lands on the next day.
        $this->assertSame(['2025-11-22', '2025-11-23', '19:00', '01:00'], self::when($events[5]));
        // A Windows zone name (Outlook) is understood.
        $this->assertSame(['2026-04-10', null, '09:00', '16:00'], self::when($events[6]));
        $this->assertSame(['2020-10-03', null, null, null], self::when($events[7]));
    }

    public function testAnHtmlDescriptionBecomesPlainTextWithItsLineBreaks(): void
    {
        $events = $this->convert('valide.ics');

        $this->assertSame("Inscriptions avant le 15/11.\nMenu & boissons", $events[5]['description']);
    }

    /**
     * Every VEVENT carrying RRULE, RDATE, EXDATE or RECURRENCE-ID is
     * excluded — the series, its moved occurrence, a list of dates, an
     * emptied series — and the plain event beside them is not.
     */
    public function testEveryRecurringEventIsExcludedWithoutException(): void
    {
        $events = $this->convert('recurrences.ics');

        $this->assertSame(
            [...array_fill(0, 4, IcsEventReader::STATE_RECURRING), IcsEventReader::STATE_EVENT],
            array_column($events, 'state')
        );
        $this->assertSame('simple@exemple.test', $events[4]['uid']);
    }

    public function testAnUnparseableRecurringEventIsStillExcludedAsRecurring(): void
    {
        $record = (new IcsEventReader())->read(
            "BEGIN:VEVENT\r\nUID:x\r\nRRULE:FREQ=WEEKLY\r\nDTSTART:pas une date\r\n"
        );

        $this->assertSame(IcsEventReader::STATE_RECURRING, $record['state']);
    }

    /**
     * Each incomplete event is one line with its reason; the others are
     * read as usual, and the second event of a UID is not imported.
     */
    public function testIncompleteEventsAreReportedOneByOne(): void
    {
        $events = $this->convert('incomplets.ics');

        $this->assertSame([
            ['unreadable', 'pas d\'identifiant (UID)'],
            ['unreadable', 'pas de date de début'],
            ['unreadable', 'fuseau horaire inconnu'],
            ['unreadable', 'la fin précède le début'],
            ['unreadable', 'pas de titre'],
            ['unreadable', 'date ou heure invalide'],
            ['cancelled', null],
            ['event', null],
            ['unreadable', 'UID en double dans le fichier'],
        ], array_map(static fn (array $e): array => [$e['state'], $e['reason'] ?? null], $events));
    }

    public function testAFileThatIsNotACalendarIsRefusedAsAWhole(): void
    {
        $this->expectException(IcsUnreadableException::class);
        $this->convert('illisible.ics');
    }

    public function testACalendarWithoutAnyEventIsRefused(): void
    {
        $file = sys_get_temp_dir() . '/ics_empty_' . bin2hex(random_bytes(4)) . '.ics';
        file_put_contents($file, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n");

        try {
            $this->expectException(IcsUnreadableException::class);
            (new IcsEventReader())->convert($file, $this->out);
        } finally {
            @unlink($file);
        }
    }

    /**
     * A file cut in the middle of an event — a download truncated
     * somewhere — keeps the events before the cut and reports the last.
     */
    public function testAFileEndingInsideAnEventKeepsTheEventsBefore(): void
    {
        $file = sys_get_temp_dir() . '/ics_cut_' . bin2hex(random_bytes(4)) . '.ics';
        file_put_contents(
            $file,
            "\xEF\xBB\xBFBEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:a\nDTSTART;VALUE=DATE:20250101\nSUMMARY:Un\n"
            . "END:VEVENT\nBEGIN:VEVENT\nUID:b\nDTSTART"
        );

        try {
            $events = (new IcsEventReader())->convert($file, $this->out);
            $lines = array_map(
                static fn (string $l): array => json_decode($l, true),
                file($this->out, FILE_IGNORE_NEW_LINES) ?: []
            );
        } finally {
            @unlink($file);
        }

        $this->assertSame(2, $events);
        $this->assertSame('event', $lines[0]['state']);
        $this->assertSame('unreadable', $lines[1]['state']);
    }

    public function testATitleIsKeptOnOneLineAndCutToTheColumn(): void
    {
        $record = (new IcsEventReader())->read(
            "BEGIN:VEVENT\r\nUID:long\r\nDTSTART;VALUE=DATE:20250101\r\nSUMMARY:"
            . str_repeat('é', 250) . "\r\nEND:VEVENT\r\n"
        );

        $this->assertSame(IcsEventReader::MAX_TITLE_CHARS, mb_strlen($record['title']));
        $this->assertStringContainsString('…', $record['title']);
    }

    /**
     * A big calendar is read event by event, not as one document: 5 Mo
     * read whole by vobject took 146 MiB, over the hosting's 128.
     */
    public function testAFiveMegabyteCalendarStaysFarUnderTheMemoryLimit(): void
    {
        $file = sys_get_temp_dir() . '/ics_big_' . bin2hex(random_bytes(4)) . '.ics';
        $handle = fopen($file, 'wb');
        fwrite($handle, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n");
        $i = 0;
        while (ftell($handle) < 5 * 1024 * 1024 - 400) {
            $i++;
            fwrite($handle, "BEGIN:VEVENT\r\nUID:evt-$i@exemple.test\r\n"
                . "DTSTART;TZID=Europe/Brussels:20250101T140000\r\nDTEND;TZID=Europe/Brussels:20250101T170000\r\n"
                . "SUMMARY:Réunion $i\r\nDESCRIPTION:Une description pour remplir le fichier $i\r\nEND:VEVENT\r\n");
        }
        fwrite($handle, "END:VCALENDAR\r\n");
        fclose($handle);

        $before = memory_get_usage();
        memory_reset_peak_usage();
        try {
            $count = (new IcsEventReader())->convert($file, $this->out);
        } finally {
            @unlink($file);
        }

        $this->assertSame($i, $count);
        $this->assertLessThanOrEqual(32 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function convert(string $fixture): array
    {
        (new IcsEventReader())->convert(dirname(__DIR__, 3) . '/fixtures/data-migration/ics/' . $fixture, $this->out);

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($this->out, FILE_IGNORE_NEW_LINES) ?: []
        );
    }

    /**
     * @param array<string, mixed> $event
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
     */
    private static function when(array $event): array
    {
        return [$event['start_date'], $event['end_date'], $event['start_time'], $event['end_time']];
    }
}
