<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\UrlMetadataFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * UrlMetadataFetcher::fetchCalendar() — the data migration module's copy
 * of an ICS calendar, through the one outbound chain SECURITY.md §17
 * allows: every hop re-validated, the IP pinned, and its own ceiling.
 *
 * The network half is replaced the same way UrlMetadataFetcherRedirect-
 * ChainTest does: hostnames resolve to a public documentation address
 * without a DNS lookup, IP literals still go through the real check, and
 * performRawRequest() answers from a script while recording what it was
 * asked.
 */
final class UrlMetadataFetcherCalendarTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";

    public function testACalendarServedAsTextCalendarIsReturnedWhole(): void
    {
        $fetcher = self::answering([['status' => 200, 'headers' => ['content-type' => 'text/calendar; charset=utf-8'], 'body' => self::ICS]]);

        $this->assertSame(self::ICS, $fetcher->fetchCalendar('https://agenda.example/basic.ics'));
        $this->assertSame([['agenda.example', '93.184.216.34', '/basic.ics']], $fetcher->requests);
    }

    /**
     * One byte more than the ceiling is asked for: a calendar cut at 5 Mo
     * would lose its last events without a word, so « too large » must be
     * told apart from « exactly 5 Mo ».
     */
    public function testTheCeilingIsFiveMegabytesAndLargerIsRefusedRatherThanCut(): void
    {
        $exact = self::answering([['status' => 200, 'headers' => ['content-type' => 'text/calendar'], 'body' => str_repeat('a', UrlMetadataFetcher::MAX_CALENDAR_BYTES)]]);
        $this->assertSame(UrlMetadataFetcher::MAX_CALENDAR_BYTES, strlen((string) $exact->fetchCalendar('https://agenda.example/a.ics')));
        $this->assertSame([UrlMetadataFetcher::MAX_CALENDAR_BYTES + 1], $exact->maxBytes);

        $over = self::answering([['status' => 200, 'headers' => ['content-type' => 'text/calendar'], 'body' => str_repeat('a', UrlMetadataFetcher::MAX_CALENDAR_BYTES + 1)]]);
        $this->assertNull($over->fetchCalendar('https://agenda.example/a.ics'));
        $this->assertSame(5 * 1024 * 1024, UrlMetadataFetcher::MAX_CALENDAR_BYTES);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notACalendar(): array
    {
        return [
            'a web page' => ['text/html; charset=utf-8'],
            'no content type' => [''],
            'plain text' => ['text/plain'],
            'an image' => ['image/png'],
        ];
    }

    #[DataProvider('notACalendar')]
    public function testAnythingButTextCalendarIsRefused(string $contentType): void
    {
        $fetcher = self::answering([['status' => 200, 'headers' => $contentType === '' ? [] : ['content-type' => $contentType], 'body' => self::ICS]]);

        $this->assertNull($fetcher->fetchCalendar('https://agenda.example/basic.ics'));
    }

    public function testAnErrorStatusIsRefused(): void
    {
        $fetcher = self::answering([['status' => 404, 'headers' => ['content-type' => 'text/calendar'], 'body' => self::ICS]]);

        $this->assertNull($fetcher->fetchCalendar('https://agenda.example/basic.ics'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeTargets(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/agenda.ics'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private network' => ['https://10.0.0.5/agenda.ics'],
            'IPv6 loopback' => ['http://[::1]/agenda.ics'],
            'another port' => ['https://agenda.example:8443/agenda.ics'],
            'credentials' => ['https://user:secret@agenda.example/agenda.ics'],
            'not http' => ['ftp://agenda.example/agenda.ics'],
            'webcal, which the importer rewrites first' => ['webcal://agenda.example/agenda.ics'],
            'a file' => ['file:///etc/passwd'],
        ];
    }

    /**
     * Refused before any connection: performRawRequest() is never called.
     */
    #[DataProvider('unsafeTargets')]
    public function testAnUnsafeTargetIsRefusedBeforeAnyRequest(string $url): void
    {
        $fetcher = self::answering([['status' => 200, 'headers' => ['content-type' => 'text/calendar'], 'body' => self::ICS]]);

        $this->assertNull($fetcher->fetchCalendar($url));
        $this->assertSame([], $fetcher->requests);
    }

    /**
     * The classic bypass: a public address that redirects to an internal
     * one. The second hop is re-validated like the first and refused.
     */
    public function testARedirectToAPrivateAddressIsRefused(): void
    {
        $fetcher = self::answering([
            ['status' => 302, 'headers' => ['location' => 'http://169.254.169.254/latest/meta-data/'], 'body' => ''],
            ['status' => 200, 'headers' => ['content-type' => 'text/calendar'], 'body' => self::ICS],
        ]);

        $this->assertNull($fetcher->fetchCalendar('https://agenda.example/basic.ics'));
        $this->assertCount(1, $fetcher->requests);
    }

    public function testARedirectToAPublicAddressIsFollowed(): void
    {
        $fetcher = self::answering([
            ['status' => 301, 'headers' => ['location' => '/v2/basic.ics'], 'body' => ''],
            ['status' => 200, 'headers' => ['content-type' => 'text/calendar'], 'body' => self::ICS],
        ]);

        $this->assertSame(self::ICS, $fetcher->fetchCalendar('https://agenda.example/basic.ics'));
        $this->assertSame('/v2/basic.ics', $fetcher->requests[1][2]);
    }

    public function testAnEndlessRedirectChainStops(): void
    {
        $fetcher = self::answering(array_fill(0, 20, ['status' => 302, 'headers' => ['location' => '/encore'], 'body' => '']));

        $this->assertNull($fetcher->fetchCalendar('https://agenda.example/basic.ics'));
        $this->assertLessThanOrEqual(6, count($fetcher->requests));
    }

    /**
     * @param list<array{status: ?int, headers: array<string, string>, body: ?string}> $answers
     */
    private static function answering(array $answers): UrlMetadataFetcher
    {
        return new class ($answers) extends UrlMetadataFetcher {
            /** @var list<array{0: string, 1: string, 2: string}> */
            public array $requests = [];
            /** @var list<int> */
            public array $maxBytes = [];

            /**
             * @param list<array{status: ?int, headers: array<string, string>, body: ?string}> $answers
             */
            public function __construct(private array $answers)
            {
            }

            protected function resolveValidatedIp(string $host): ?string
            {
                $literal = trim($host, '[]');
                if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
                    return parent::resolveValidatedIp($literal);
                }

                return '93.184.216.34';
            }

            protected function performRawRequest(
                string $scheme,
                string $host,
                string $ip,
                string $pathAndQuery,
                int $maxBytes,
                float $timeoutSeconds
            ): array {
                $this->requests[] = [$host, $ip, $pathAndQuery];
                $this->maxBytes[] = $maxBytes;

                return array_shift($this->answers) ?? ['status' => null, 'headers' => [], 'body' => null];
            }
        };
    }
}
