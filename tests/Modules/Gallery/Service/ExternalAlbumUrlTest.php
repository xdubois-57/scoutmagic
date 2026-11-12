<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\ExternalAlbumUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The safe normalisation of an external album's address: only what never
 * changes the page a link opens is folded.
 */
final class ExternalAlbumUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function folded(): iterable
    {
        yield 'scheme and host lower-cased' => [
            'HTTPS://Photos.Example.ORG/Album/AbC',
            'https://photos.example.org/Album/AbC'
        ];
        yield 'fragment dropped' => ['https://example.org/a?b=C#Photo-3', 'https://example.org/a?b=C'];
        yield 'blanks around trimmed' => ["  https://example.org/a \n", 'https://example.org/a'];
        yield 'port kept, host lowered' => ['http://EXAMPLE.org:8080/x', 'http://example.org:8080/x'];
        yield 'user info kept as is' => ['https://Jean@Example.org/x', 'https://Jean@example.org/x'];
        yield 'empty fragment dropped' => ['https://example.org/#', 'https://example.org/'];
    }

    #[DataProvider('folded')]
    public function testWhatNeverChangesThePageIsFolded(string $url, string $expected): void
    {
        $this->assertSame($expected, ExternalAlbumUrl::normalize($url));
    }

    public function testWhatAPhotoHostMayGiveMeaningToIsKept(): void
    {
        $this->assertNotSame(
            ExternalAlbumUrl::normalize('https://example.org/a'),
            ExternalAlbumUrl::normalize('https://example.org/a/')
        );
        $this->assertNotSame(
            ExternalAlbumUrl::normalize('https://example.org/a'),
            ExternalAlbumUrl::normalize('https://example.org/A')
        );
        $this->assertNotSame(
            ExternalAlbumUrl::normalize('https://example.org/a'),
            ExternalAlbumUrl::normalize('http://example.org/a')
        );
        $this->assertNotSame(
            ExternalAlbumUrl::normalize('https://example.org/?id=1'),
            ExternalAlbumUrl::normalize('https://example.org/?id=2')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['/albums/12'];
        yield 'no scheme' => ['example.org/album'];
        yield 'another scheme' => ['ftp://example.org/album'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'no host' => ['https:///album'];
        yield 'port without host' => ['https://:443/album'];
        yield 'port that is not a number' => ['https://photos.example.org:bad/album'];
        yield 'port out of range' => ['https://photos.example.org:70000/album'];
        yield 'colon without a port' => ['https://photos.example.org:/album'];
        yield 'blank inside' => ['https://example.org/mon album'];
        yield 'control character' => ["https://example.org/\x00a"];
    }

    #[DataProvider('refused')]
    public function testWhatIsNotAnAbsoluteWebAddressIsRefused(string $url): void
    {
        $this->assertNull(ExternalAlbumUrl::normalize($url));
    }
}
