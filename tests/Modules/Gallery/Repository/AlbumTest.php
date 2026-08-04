<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Repository;

use Modules\Gallery\Repository\Album;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the mojibake bug: an external album created before
 * Modules\Gallery\Service\OgScraperService's UTF-8 decoding fix kept
 * stale mangled bytes in `title` forever, since only `og_title` gets
 * re-scraped on update()/refreshOg(). displayTitle() is what every
 * rendering path (chief "Gérer la galerie" list, member gallery list,
 * the home-page/member-page gallery API) must call instead of reading
 * `title` directly for an external album.
 */
class AlbumTest extends TestCase
{
    public function testDisplayTitlePrefersOgTitleForExternalAlbumWhenPresent(): void
    {
        $album = $this->makeAlbum(Album::TYPE_EXTERNAL, 'Visite camp SeeonÃ©e Â·', 'Visite camp Seeonee · Saturday');

        $this->assertSame('Visite camp Seeonee · Saturday', $album->displayTitle());
    }

    public function testDisplayTitleFallsBackToTitleForExternalAlbumWhenOgTitleIsNull(): void
    {
        $album = $this->makeAlbum(Album::TYPE_EXTERNAL, 'Album partagé', null);

        $this->assertSame('Album partagé', $album->displayTitle());
    }

    public function testDisplayTitleAlwaysUsesTitleForLocalAlbumsEvenIfOgTitleIsSet(): void
    {
        // og_title is only ever populated for external albums in practice,
        // but a local album must never prefer it if it somehow were set.
        $album = $this->makeAlbum(Album::TYPE_LOCAL, 'Camp d\'été', 'Should never be used');

        $this->assertSame('Camp d\'été', $album->displayTitle());
    }

    private function makeAlbum(string $type, string $title, ?string $ogTitle): Album
    {
        return new Album(
            1,
            $type,
            $title,
            null,
            '2026-07-01',
            null,
            1,
            null,
            $type === Album::TYPE_EXTERNAL ? 'https://example.com/album' : null,
            $ogTitle,
            null,
            null,
            null,
            null,
            Album::MIGRATION_NONE,
            null,
            null,
            1,
            '2026-01-01 00:00:00'
        );
    }
}
