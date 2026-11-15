<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Core\Http\UrlMetadataFetcher;

/**
 * UrlMetadataFetcher without the network: fetchCalendar() answers with a
 * fixture file per address (null for any other), and records what it was
 * asked. Its own safety is UrlMetadataFetcherCalendarTest's subject.
 */
final class ScriptedCalendarFetcher extends UrlMetadataFetcher
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param array<string, string> $files address => path of the body
     */
    public function __construct(private array $files = [])
    {
    }

    public static function fixture(string $name): string
    {
        return dirname(__DIR__, 3) . '/fixtures/data-migration/ics/' . $name;
    }

    public function fetchCalendar(string $url): ?string
    {
        $this->asked[] = $url;
        $file = $this->files[$url] ?? null;

        return $file !== null ? (string) file_get_contents($file) : null;
    }
}
