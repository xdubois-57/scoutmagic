<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Core\Http\LinkPreview;
use Core\Http\LinkPreviewFetcher;

/**
 * The link preview fetcher of the gallery import's tests: no network,
 * ever. It answers from a table of addresses and records every address it
 * was asked for, so a test can say how many pages an import read, and
 * when.
 */
final class FakeLinkPreviewFetcher implements LinkPreviewFetcher
{
    /** @var list<string> every address asked for, in order */
    public array $fetched = [];

    /**
     * @param array<string, LinkPreview|null> $pages address => what the page says; absent or null = unreadable
     */
    public function __construct(public array $pages = [])
    {
    }

    public function fetch(string $url): ?LinkPreview
    {
        $this->fetched[] = $url;

        return $this->pages[$url] ?? null;
    }
}
