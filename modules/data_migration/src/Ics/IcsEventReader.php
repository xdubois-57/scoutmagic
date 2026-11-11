<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Ics;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Parameter;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\TimeZoneUtil;

/**
 * Turns an iCalendar file (RFC 5545) into one JSON line per VEVENT, each
 * already in the calendar module's terms — or says why it cannot be.
 *
 * **The parsing is sabre/vobject's**: properties, parameters, escaping,
 * line folding, dates, durations and time zones. What this class adds is
 * the order in which it is asked, for one reason: memory. vobject builds
 * the whole document as objects, and a 5 MB calendar measured at 146 MiB
 * that way — over the 128 MiB the hosting gives a request. So the file is
 * read line by line, which RFC 5545 makes safe: a component starts and
 * ends with a `BEGIN:`/`END:` line of its own, and a folded continuation
 * line always starts with a space or a tab, so it can never be mistaken
 * for one. Each VEVENT's lines are handed to vobject alone, wrapped in a
 * VCALENDAR with the VTIMEZONE definitions it refers to, and forgotten
 * before the next.
 *
 * Every VEVENT gives exactly one line, in file order — that order is the
 * import's stable position — in one of five states:
 *
 * - `event`: importable, with its fields;
 * - `recurring`: it carries RRULE, RDATE, EXDATE or RECURRENCE-ID — a
 *   series, or one occurrence of one. All are excluded, without exception:
 *   the calendar has no notion of a series, and copying one occurrence of
 *   it would be a lie about the others;
 * - `cancelled`: STATUS:CANCELLED, which a calendar of real events would
 *   show as if it were still on;
 * - `unreadable`: anything else this cannot represent without distortion,
 *   with the reason.
 *
 * Times are converted to Europe/Brussels, the zone the calendar module
 * stores its local times in; a floating time (no zone) is read as Brussels
 * time. An event whose start is a DATE lasts the whole day (no start
 * time), and RFC 5545's exclusive end date becomes the module's inclusive
 * one.
 */
final class IcsEventReader
{
    public const TIME_ZONE = 'Europe/Brussels';
    /** One VEVENT bigger than this (an attachment inlined in base64…) is not read. */
    public const MAX_EVENT_BYTES = 256 * 1024;
    /** calendar_events.title and .location, in characters. */
    public const MAX_TITLE_CHARS = 200;
    public const MAX_LOCATION_CHARS = 255;
    /** calendar_events.description is a TEXT: 65 535 bytes. */
    public const MAX_DESCRIPTION_BYTES = 60000;

    public const STATE_EVENT = 'event';
    public const STATE_RECURRING = 'recurring';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_UNREADABLE = 'unreadable';

    /**
     * Reads `$icsPath` and writes `$jsonlPath`, one line per VEVENT.
     *
     * @return int the number of events written
     *
     * @throws IcsUnreadableException when the file is not an iCalendar, or
     *         holds no VEVENT
     */
    public function convert(string $icsPath, string $jsonlPath): int
    {
        $in = @fopen($icsPath, 'rb');
        if ($in === false) {
            throw new IcsUnreadableException('Cannot open the calendar file.');
        }

        try {
            $timeZones = $this->timeZones($in);
            rewind($in);
            $out = @fopen($jsonlPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Cannot write the converted calendar.');
            }
            try {
                $count = 0;
                $uids = [];
                foreach ($this->events($in) as $raw) {
                    $record = $raw === null
                        ? self::unreadable('événement trop volumineux')
                        : $this->read($raw, $timeZones);
                    $record = self::withUniqueUid($record, $uids);
                    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    fwrite($out, $json . "\n");
                    $count++;
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }

        if ($count === 0) {
            throw new IcsUnreadableException('The calendar holds no event.');
        }

        return $count;
    }

    /**
     * One VEVENT's lines, read on its own.
     *
     * @param array<string, string> $timeZones TZID => its VTIMEZONE block
     * @return array<string, mixed>
     */
    public function read(string $eventText, array $timeZones = []): array
    {
        // On the raw lines first, so that even an event vobject could not
        // parse is excluded as recurring when it says it is one.
        if (preg_match('/^(?:RRULE|RDATE|EXDATE|RECURRENCE-ID)[;:]/mi', $eventText) === 1) {
            $uid = preg_match('/^UID(?:;[^:\r\n]*)?:([^\r\n]*)/mi', $eventText, $m) === 1 ? trim($m[1]) : '';

            return ['state' => self::STATE_RECURRING, 'uid' => $uid, 'date' => null];
        }
        if (!mb_check_encoding($eventText, 'UTF-8')) {
            return self::unreadable('texte qui n\'est pas en UTF-8');
        }

        $wrapped = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ScoutMagic//Migration//FR\r\n";
        foreach ($timeZones as $tzid => $block) {
            if (str_contains($eventText, $tzid)) {
                $wrapped .= $block;
            }
        }
        $wrapped .= $eventText . "END:VCALENDAR\r\n";

        try {
            $calendar = Reader::read($wrapped, Reader::OPTION_FORGIVING);
        } catch (\Throwable) {
            return self::unreadable('syntaxe ICS invalide');
        }
        $event = $calendar instanceof VCalendar ? ($calendar->select('VEVENT')[0] ?? null) : null;
        if (!$calendar instanceof VCalendar || !$event instanceof VEvent) {
            return self::unreadable('syntaxe ICS invalide');
        }

        $uid = self::text($event, 'UID');
        $date = $this->startDateForReference($event, $calendar);
        if ($uid === '') {
            return self::unreadable('pas d\'identifiant (UID)', $date);
        }
        if (strtoupper(self::text($event, 'STATUS')) === 'CANCELLED') {
            return ['state' => self::STATE_CANCELLED, 'uid' => $uid, 'date' => $date];
        }

        try {
            $when = $this->when($event, $calendar);
        } catch (IcsUnreadableException $e) {
            return self::unreadable($e->getMessage(), $date, $uid);
        }

        $title = self::oneLine(self::text($event, 'SUMMARY'));
        if ($title === '') {
            return self::unreadable('pas de titre', $date, $uid);
        }

        return [
            'state' => self::STATE_EVENT,
            'uid' => $uid,
            'date' => $when['start_date'],
            'title' => self::cut($title, self::MAX_TITLE_CHARS),
            ...$when,
            'location' => self::nullIfEmpty(
                self::cut(self::oneLine(self::text($event, 'LOCATION')), self::MAX_LOCATION_CHARS)
            ),
            'description' => self::nullIfEmpty(self::description(self::text($event, 'DESCRIPTION'))),
        ];
    }

    /**
     * Start and end, in the module's terms.
     *
     * @return array{start_date: string, end_date: ?string, start_time: ?string, end_time: ?string}
     *
     * @throws IcsUnreadableException with the French reason
     */
    private function when(VEvent $event, VCalendar $calendar): array
    {
        $start = self::property($event, 'DTSTART');
        if (!$start instanceof DateTimeProperty) {
            throw new IcsUnreadableException('pas de date de début');
        }
        $brussels = new \DateTimeZone(self::TIME_ZONE);

        try {
            $this->assertKnownZone($start, $calendar);
            $startAt = $start->getDateTime($brussels);
            $end = self::property($event, 'DTEND');
            $duration = self::text($event, 'DURATION');
            if ($end instanceof DateTimeProperty) {
                $this->assertKnownZone($end, $calendar);
                $endAt = $end->getDateTime($brussels);
            } elseif ($duration !== '') {
                $endAt = $startAt->add(\Sabre\VObject\DateTimeParser::parseDuration($duration));
            } else {
                $endAt = null;
            }
        } catch (IcsUnreadableException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new IcsUnreadableException('date ou heure invalide');
        }

        if (!$start->hasTime()) {
            // A DATE: the whole day. RFC 5545's DTEND is the day AFTER the
            // last one; the module stores the last one.
            $first = $startAt->format('Y-m-d');
            $last = $endAt !== null ? $endAt->modify('-1 day')->format('Y-m-d') : $first;
            if ($endAt !== null && $endAt->format('Y-m-d') < $first) {
                throw new IcsUnreadableException('la fin précède le début');
            }

            return [
                'start_date' => $first,
                'end_date' => $last > $first ? $last : null,
                'start_time' => null,
                'end_time' => null,
            ];
        }

        $startLocal = $startAt->setTimezone($brussels);
        $endLocal = $endAt?->setTimezone($brussels);
        if ($endLocal !== null && $endLocal < $startLocal) {
            throw new IcsUnreadableException('la fin précède le début');
        }
        $hasEnd = $endLocal !== null && $endLocal > $startLocal;

        return [
            'start_date' => $startLocal->format('Y-m-d'),
            'end_date' => $hasEnd && $endLocal->format('Y-m-d') !== $startLocal->format('Y-m-d')
                ? $endLocal->format('Y-m-d')
                : null,
            'start_time' => $startLocal->format('H:i'),
            'end_time' => $hasEnd ? $endLocal->format('H:i') : null,
        ];
    }

    /**
     * A TZID vobject cannot place would otherwise be read in PHP's default
     * zone, without a word: the event would land at the wrong hour.
     *
     * vobject only says so through a flag its own PHPDoc types as `false`,
     * so the question is asked the other way: a TZID it really knows names
     * the same zone whatever PHP's default is, and one it falls back on
     * follows the default. The default is restored before returning.
     */
    private function assertKnownZone(DateTimeProperty $property, VCalendar $calendar): void
    {
        $tzid = $property->offsetGet('TZID');
        if (!$tzid instanceof Parameter) {
            return;
        }

        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $first = TimeZoneUtil::getTimeZone((string) $tzid->getValue(), $calendar)->getName();
            date_default_timezone_set('Pacific/Kiritimati');
            $second = TimeZoneUtil::getTimeZone((string) $tzid->getValue(), $calendar)->getName();
        } catch (\Throwable) {
            throw new IcsUnreadableException('fuseau horaire inconnu');
        } finally {
            date_default_timezone_set($previous);
        }
        if ($first !== $second) {
            throw new IcsUnreadableException('fuseau horaire inconnu');
        }
    }

    /** The start day, for the reference shown in the report — or null. */
    private function startDateForReference(VEvent $event, VCalendar $calendar): ?string
    {
        try {
            $start = self::property($event, 'DTSTART');
            if (!$start instanceof DateTimeProperty) {
                return null;
            }
            $this->assertKnownZone($start, $calendar);

            return $start->getDateTime(new \DateTimeZone(self::TIME_ZONE))
                ->setTimezone(new \DateTimeZone(self::TIME_ZONE))
                ->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The VTIMEZONE blocks at the top level of the calendar, by TZID — and
     * the check that the file is a calendar at all.
     *
     * @param resource $in
     * @return array<string, string>
     *
     * @throws IcsUnreadableException
     */
    private function timeZones($in): array
    {
        $zones = [];
        $first = true;
        $collecting = null;
        $depth = 0;
        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($first) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                if (trim($line) === '') {
                    continue;
                }
                if (strcasecmp(trim($line), 'BEGIN:VCALENDAR') !== 0) {
                    throw new IcsUnreadableException('Not an iCalendar file.');
                }
                $first = false;
                continue;
            }
            [$begin, $end] = self::boundary($line);
            if ($collecting !== null) {
                $collecting .= $line . "\r\n";
                $depth += $begin !== null ? 1 : ($end !== null ? -1 : 0);
                if ($depth === 0) {
                    if (preg_match('/^TZID(?:;[^:]*)?:(.+)$/mi', $collecting, $m) === 1) {
                        $zones[trim($m[1])] = $collecting;
                    }
                    $collecting = null;
                }
                continue;
            }
            if ($begin === 'VTIMEZONE' && strlen(implode('', $zones)) < self::MAX_EVENT_BYTES) {
                $collecting = $line . "\r\n";
                $depth = 1;
            }
        }
        if ($first) {
            throw new IcsUnreadableException('Not an iCalendar file.');
        }

        return $zones;
    }

    /**
     * Each top-level VEVENT's lines, in file order; null for one larger
     * than MAX_EVENT_BYTES, whose lines are skipped rather than kept.
     *
     * @param resource $in
     * @return \Generator<int, ?string>
     */
    private function events($in): \Generator
    {
        $depth = 0;
        $event = null;
        $eventDepth = 0;
        $tooLarge = false;
        $first = true;
        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($first) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                $first = false;
            }
            [$begin, $end] = self::boundary($line);
            if ($event !== null) {
                if (!$tooLarge) {
                    $event .= $line . "\r\n";
                    if (strlen($event) > self::MAX_EVENT_BYTES) {
                        $tooLarge = true;
                        $event = '';
                    }
                }
                if ($begin !== null) {
                    $eventDepth++;
                } elseif ($end !== null) {
                    $eventDepth--;
                }
                if ($eventDepth === 0) {
                    yield $tooLarge ? null : $event;
                    $event = null;
                    $tooLarge = false;
                }
                continue;
            }
            if ($begin !== null) {
                $depth++;
                // Depth 2: a component directly inside the VCALENDAR.
                if ($begin === 'VEVENT' && $depth === 2) {
                    $event = $line . "\r\n";
                    $eventDepth = 1;
                    $depth--;
                }
            } elseif ($end !== null) {
                $depth--;
            }
        }
        if ($event !== null) {
            // The file ended inside an event.
            yield null;
        }
    }

    private static function property(VEvent $event, string $name): ?Property
    {
        $found = $event->select($name)[0] ?? null;

        return $found instanceof Property ? $found : null;
    }

    /** A property's text, trimmed; empty when it is absent. */
    private static function text(VEvent $event, string $name): string
    {
        return trim((string) self::property($event, $name)?->getValue());
    }

    /**
     * @return array{0: ?string, 1: ?string} the component a BEGIN or an END line names
     */
    private static function boundary(string $line): array
    {
        if (preg_match('/^(BEGIN|END):([A-Za-z0-9-]+)\s*$/i', $line, $m) !== 1) {
            return [null, null];
        }
        $name = strtoupper($m[2]);

        return strcasecmp($m[1], 'BEGIN') === 0 ? [$name, null] : [null, $name];
    }

    /**
     * One UID per file: a second event with the same UID is not imported —
     * the first one is, and the deduplication key could not tell them apart.
     *
     * @param array<string, mixed> $record
     * @param array<string, true> $seen
     * @return array<string, mixed>
     */
    private static function withUniqueUid(array $record, array &$seen): array
    {
        $uid = (string) ($record['uid'] ?? '');
        if ($uid === '' || ($record['state'] ?? '') !== self::STATE_EVENT) {
            return $record;
        }
        if (isset($seen[$uid])) {
            return self::unreadable('UID en double dans le fichier', $record['date'] ?? null, $uid);
        }
        $seen[$uid] = true;

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unreadable(string $reason, ?string $date = null, string $uid = ''): array
    {
        return ['state' => self::STATE_UNREADABLE, 'reason' => $reason, 'uid' => $uid, 'date' => $date];
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function cut(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars - 1)) . '…' : $text;
    }

    private static function nullIfEmpty(string $text): ?string
    {
        return $text === '' ? null : $text;
    }

    /**
     * The calendar shows a description as plain text. Google Calendar
     * exports some as HTML: their line breaks are kept and their tags
     * dropped, rather than shown as `<br>` in the middle of the text.
     */
    private static function description(string $text): string
    {
        if (preg_match('/<\/?[a-z][a-z0-9]*[\s>\/]/i', $text) === 1) {
            $text = (string) preg_replace('/<br\s*\/?>|<\/p>|<\/div>|<\/li>/i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)));

        return strlen($text) > self::MAX_DESCRIPTION_BYTES
            ? mb_strcut($text, 0, self::MAX_DESCRIPTION_BYTES - 3) . '…'
            : $text;
    }
}
