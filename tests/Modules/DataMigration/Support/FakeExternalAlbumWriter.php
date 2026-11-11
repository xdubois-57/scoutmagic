<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Support;

use Modules\Gallery\Api\ExternalAlbumUrl;
use Modules\Gallery\Api\ExternalAlbumWriter;
use Modules\Gallery\Api\ImportedExternalAlbum;

/**
 * The gallery's writer as the import sees it, in memory: what the import
 * asked to create, and which addresses already exist.
 */
final class FakeExternalAlbumWriter implements ExternalAlbumWriter
{
    public bool $allowed = true;

    /** @var list<ImportedExternalAlbum> */
    public array $created = [];

    /**
     * @param list<string> $knownUrls addresses the gallery already has
     */
    public function __construct(public array $knownUrls = [])
    {
    }

    public function externalAlbumsAllowed(): bool
    {
        return $this->allowed;
    }

    public function findExternalAlbumByUrl(string $url): ?int
    {
        $normalized = ExternalAlbumUrl::normalize($url);
        foreach ($this->knownUrls as $index => $known) {
            if ($normalized !== null && ExternalAlbumUrl::normalize($known) === $normalized) {
                return $index + 1;
            }
        }

        return null;
    }

    public function createImportedExternalAlbum(ImportedExternalAlbum $album): int
    {
        $this->created[] = $album;
        $this->knownUrls[] = $album->url;

        return count($this->knownUrls);
    }
}
