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
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BodyImageVariantsTest extends TestCase
{
    public function testAnOriginalWithAMediumRenditionIsPointedAtIt(): void
    {
        [$id, $public] = $this->file('a.jpg');
        $html = '<p>Avant<img src="/files/' . $public . '" alt="Feu de camp" width="400">après</p>';

        $this->assertSame(
            '<p>Avant<img src="/files/' . $public . '/md" alt="Feu de camp" width="400">après</p>',
            BodyImageVariants::rewrite($html, static fn (int $fileId): bool => $fileId === $id)
        );
    }

    public function testAnOriginalWithoutOneIsLeftAlone(): void
    {
        [$id, $public] = $this->file('a.jpg');
        [, $other] = $this->file('b.jpg');
        $html = '<img src="/files/' . $public . '"><img src="/files/' . $other . '">';

        $this->assertSame(
            '<img src="/files/' . $public . '/md"><img src="/files/' . $other . '">',
            BodyImageVariants::rewrite($html, static fn (int $fileId): bool => $fileId === $id)
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

    /**
     * Issue #1109: an image is named by its opaque id; one that names no
     * file is left alone without asking, and a sequential address — which
     * names nothing any more — is not an original either.
     */
    public function testAnUnknownOrSequentialAddressIsLeftAlone(): void
    {
        $asked = [];
        $hasMedium = static function (int $fileId) use (&$asked): bool {
            $asked[] = $fileId;

            return true;
        };
        $html = '<img src="/files/' . str_repeat('Q', 22) . '"><img src="/files/12">';

        $this->assertSame($html, BodyImageVariants::rewrite($html, $hasMedium));
        $this->assertSame([], $asked);
    }

    protected function setUp(): void
    {
        $this->files = new \Core\File\FileRepository(\Tests\DatabaseTestHelper::createTestDatabase());
        \Core\File\FilePublicIds::useRepository($this->files);
    }

    protected function tearDown(): void
    {
        \Core\File\FilePublicIds::useRepository(null);
    }

    private \Core\File\FileRepository $files;

    /**
     * @return array{0: int, 1: string}
     */
    private function file(string $path): array
    {
        $id = $this->files->create($path, $path, 'image/jpeg', 1, 'public', null, null);

        return [$id, (string) $this->files->findById($id)?->publicId];
    }
}
