<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Config\AppClock;
use Core\Service\DateInput;
use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Repository\CalendarEvent;

/**
 * Builds an RFC 5545 (iCalendar) document from a flat list of events. Pure
 * text generation, no database access — trivially unit-testable.
 */
class IcsBuilder
{
    private const PRODID = '-//ScoutMagic//Calendrier//FR';
    private const TIMEZONE_ID = 'Europe/Brussels';
    private const MAX_LINE_OCTETS = 75;

    /**
     * @param CalendarEvent[] $events
     * @param VirtualEvent[] $virtualEvents Events another module computes on
     *   the fly (Api\VirtualEventProviderInterface). They render through the
     *   same code as stored ones — one generator behind both paths — and
     *   carry their own stable UID rather than a `cal-event-` one.
     */
    public function build(string $calendarName, array $events, array $virtualEvents = []): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            $this->property('X-WR-CALNAME', $this->escapeText($calendarName)),
        ];

        array_push($lines, ...$this->buildTimezoneBlock());

        foreach ($events as $event) {
            array_push($lines, ...$this->buildEventBlock($event));
        }

        foreach ($virtualEvents as $virtualEvent) {
            array_push($lines, ...$this->buildVirtualEventBlock($virtualEvent));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Static, RRULE-based VTIMEZONE for Europe/Brussels. Belgium follows
     * the EU-wide CET/CEST rule (last Sunday of March → +02:00, last Sunday
     * of October → +01:00), fixed by EU law since 1996 with no defined end
     * date — the same definition every major calendar client's own
     * VTIMEZONE database uses for this zone. This is deliberately NOT
     * computed from DateTimeZone::getTransitions(): a finite transition
     * window is fragile to get exactly right (which offset-from applies,
     * historical pre-1996 rule changes polluting the list) and buys no
     * real correctness over the EU's actual, unchanging legal rule.
     *
     * @return string[]
     */
    private function buildTimezoneBlock(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:' . self::TIMEZONE_ID,
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /**
     * @return string[]
     */
    private function buildEventBlock(CalendarEvent $event): array
    {
        $lines = ['BEGIN:VEVENT'];
        $lines[] = $this->property('UID', "cal-event-{$event->id}@scoutmagic");
        $lines[] = $this->property('DTSTAMP', $this->formatUtc(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        if ($event->isAllDay()) {
            $endDate = $event->endDate ?? $event->startDate;
            // DTEND for an all-day event is exclusive per RFC 5545 — the
            // day after the last day the event actually occupies.
            $exclusiveEnd = $this->read($endDate, new \DateTimeZone('UTC'))->modify('+1 day');

            $lines[] = $this->property(
                'DTSTART',
                $this->read($event->startDate, new \DateTimeZone('UTC'))->format('Ymd'),
                ['VALUE' => 'DATE']
            );
            $lines[] = $this->property('DTEND', $exclusiveEnd->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $start = $this->read($event->startDate . ' ' . $event->startTime, new \DateTimeZone(self::TIMEZONE_ID));
            $lines[] = $this->property('DTSTART', $start->format('Ymd\THis'), ['TZID' => self::TIMEZONE_ID]);

            if ($event->endTime !== null) {
                $endDate = $event->endDate ?? $event->startDate;
                $end = $this->read($endDate . ' ' . $event->endTime, new \DateTimeZone(self::TIMEZONE_ID));
                $lines[] = $this->property('DTEND', $end->format('Ymd\THis'), ['TZID' => self::TIMEZONE_ID]);
            }
        }

        $lines[] = $this->property('SUMMARY', $this->escapeText($event->title));

        if ($event->location !== null && $event->location !== '') {
            $lines[] = $this->property('LOCATION', $this->escapeText($event->location));
        }
        if ($event->description !== null && $event->description !== '') {
            $lines[] = $this->property('DESCRIPTION', $this->escapeText($event->description));
        }

        $lines[] = $this->property('SEQUENCE', (string) $event->sequence);
        // updated_at is a naive DATETIME on the application clock
        // (Core\Config\AppClock) — read as such and then converted, rather
        // than relabelled as UTC, which would put LAST-MODIFIED an hour or
        // two ahead of the change it describes.
        $lines[] = $this->property('LAST-MODIFIED', $this->formatUtc(
            $this->read($event->updatedAt, AppClock::zone())
                ->setTimezone(new \DateTimeZone('UTC'))
        ));

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * The same VEVENT shape as a stored event, from a provider's DTO.
     *
     * Deliberately its own method rather than a conversion into
     * `CalendarEvent`: a virtual event carries two things a stored one has
     * no column for — its own UID and a cancelled flag — and faking a
     * `CalendarEvent` to reuse the block would mean inventing an id that
     * would then collide with a real event's UID.
     *
     * @return string[]
     */
    private function buildVirtualEventBlock(VirtualEvent $event): array
    {
        $lines = ['BEGIN:VEVENT'];
        $lines[] = $this->property('UID', $event->uid);
        $lines[] = $this->property('DTSTAMP', $this->formatUtc(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        if ($event->isAllDay()) {
            // DTEND for an all-day event is exclusive per RFC 5545 — the
            // day after the last day the event actually occupies.
            $exclusiveEnd = $this->read($event->endDate, new \DateTimeZone('UTC'))->modify('+1 day');

            $lines[] = $this->property(
                'DTSTART',
                $this->read($event->startDate, new \DateTimeZone('UTC'))->format('Ymd'),
                ['VALUE' => 'DATE']
            );
            $lines[] = $this->property('DTEND', $exclusiveEnd->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $start = $this->read(
                $event->startDate . ' ' . $event->startTime,
                new \DateTimeZone(self::TIMEZONE_ID)
            );
            $lines[] = $this->property('DTSTART', $start->format('Ymd\THis'), ['TZID' => self::TIMEZONE_ID]);

            $end = $this->read(
                $event->endDate . ' ' . ($event->endTime ?? $event->startTime),
                new \DateTimeZone(self::TIMEZONE_ID)
            );
            $lines[] = $this->property('DTEND', $end->format('Ymd\THis'), ['TZID' => self::TIMEZONE_ID]);
        }

        $lines[] = $this->property('SUMMARY', $this->escapeText($event->title));

        if ($event->location !== null && $event->location !== '') {
            $lines[] = $this->property('LOCATION', $this->escapeText($event->location));
        }
        if ($event->description !== null && $event->description !== '') {
            $lines[] = $this->property('DESCRIPTION', $this->escapeText($event->description));
        }
        if ($event->url !== null && $event->url !== '') {
            $lines[] = $this->property('URL', $this->escapeText($event->url));
        }

        // A cancelled event is PUBLISHED as cancelled, never omitted: a
        // subscriber who already has it needs to be told it is off, and
        // dropping it from the feed leaves it in their calendar forever.
        // A request nobody has agreed to yet is TENTATIVE, which is what
        // stops a reader blocking a weekend for a booking that is refused
        // the following week.
        $lines[] = $this->property('STATUS', $event->icsStatus());
        $lines[] = $this->property('SEQUENCE', (string) $event->sequence);
        $lines[] = $this->property('LAST-MODIFIED', $this->formatUtc(
            ($event->updatedAt ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))
        ));

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Ymd\THis\Z');
    }

    /**
     * A stored date, or a stored date and time concatenated, read in the
     * zone the caller says it was written in.
     *
     * The zone is not decoration: an all-day date is read as UTC because
     * an ICS `VALUE=DATE` is a floating calendar day, while a timed event
     * is read on the site's own clock. Reading either in the wrong zone
     * shifts the exported event by an hour or a day and nothing fails, so
     * the argument is passed through to the constructor unchanged — which
     * is exactly what DateInput::requireFromStorage() does with it.
     *
     * Loud rather than silent: an event row whose start_date does not
     * parse cannot be exported, and a feed that quietly skips an event is
     * worse than one that fails.
     */
    private function read(string $stored, \DateTimeZone $zone): \DateTimeImmutable
    {
        return DateInput::requireFromStorage($stored, 'calendar event date', $zone);
    }

    /**
     * @param array<string, string> $params
     */
    private function property(string $name, string $value, array $params = []): string
    {
        $head = $name;
        foreach ($params as $key => $val) {
            $head .= ";{$key}={$val}";
        }
        return $this->foldLine($head . ':' . $value);
    }

    /**
     * RFC 5545 §3.1: content lines longer than 75 octets must be folded —
     * split into continuation lines, each starting with a single space,
     * joined by CRLF. Never split in the middle of a multi-byte UTF-8
     * sequence.
     */
    private function foldLine(string $line): string
    {
        $totalBytes = strlen($line);
        if ($totalBytes <= self::MAX_LINE_OCTETS) {
            return $line;
        }

        $chunks = [];
        $offset = 0;
        $isFirst = true;

        while ($offset < $totalBytes) {
            // A continuation line's single leading space counts toward its
            // own 75-octet budget, leaving 74 for content.
            $budget = $isFirst ? self::MAX_LINE_OCTETS : self::MAX_LINE_OCTETS - 1;
            $take = min($budget, $totalBytes - $offset);

            // Back off while the next byte is a UTF-8 continuation byte
            // (10xxxxxx) so we never split inside a multi-byte character.
            while ($take > 0 && $offset + $take < $totalBytes && (ord($line[$offset + $take]) & 0xC0) === 0x80) {
                $take--;
            }

            $chunks[] = substr($line, $offset, $take);
            $offset += $take;
            $isFirst = false;
        }

        return implode("\r\n ", $chunks);
    }

    /**
     * Escape TEXT-value special characters per RFC 5545 §3.3.11. Order
     * matters: backslashes must be escaped first, before any of the other
     * escapes introduce new backslashes of their own.
     */
    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(';', '\\;', $value);
        return $value;
    }
}
