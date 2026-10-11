<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Modules\News\Service\BodyImageVariants;
use PHPUnit\Framework\TestCase;

/**
 * The images of an article's text, pointed at their `md` rendition (#883):
 * only originals, only where the rendition exists, nothing else touched.
 */
final class BodyImageVariantsTest extends TestCase
{
    public function testAnOriginalWithAMediumRenditionIsPointedAtIt(): void
    {
        $html = '<p>Avant<img src="/files/12" alt="Feu de camp" width="400">après</p>';

        $this->assertSame(
            '<p>Avant<img src="/files/12/md" alt="Feu de camp" width="400">après</p>',
            BodyImageVariants::rewrite($html, static fn (int $id): bool => $id === 12)
        );
    }

    public function testAnOriginalWithoutOneIsLeftAlone(): void
    {
        $html = '<img src="/files/12"><img src="/files/13">';

        $this->assertSame(
            '<img src="/files/12/md"><img src="/files/13">',
            BodyImageVariants::rewrite($html, static fn (int $id): bool => $id === 12)
        );
    }

    public function testNothingButAnOriginalInAnImageSourceIsRewritten(): void
    {
        $asked = [];
        $hasMedium = static function (int $id) use (&$asked): bool {
            $asked[] = $id;

            return true;
        };
        $html = '<a href="/files/7">le PDF</a><img src="/files/8/thumb"><img src="/files/9/md">'
            . '<img src="https://example.org/files/10"><p>/files/11</p>';

        $this->assertSame($html, BodyImageVariants::rewrite($html, $hasMedium));
        $this->assertSame([], $asked, 'nothing here is an original image, so nothing is looked up');
    }

    public function testAnImageAlreadyOnItsRenditionIsUntouched(): void
    {
        $html = '<img alt="x" src="/files/12/md">';

        $this->assertSame($html, BodyImageVariants::rewrite($html, static fn (): bool => true));
    }
}
