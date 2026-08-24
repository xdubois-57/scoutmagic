<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\LinkPreview;
use Core\Http\LinkPreviewFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The contract for reaching an address a member typed, now in core beside
 * `Core\Security\SsrfUrlValidator` rather than inside the module that
 * needed it first.
 *
 * The deprecated gallery names are kept for one release, and they are
 * ALIASES rather than sub-interfaces: an object implementing only the core
 * interface would not be an instance of a sub-interface of it, so
 * extending would have broken exactly the out-of-tree call sites the
 * deprecation exists to spare. That is what the second test pins.
 */
class LinkPreviewFetcherTest extends TestCase
{
    public function testAPreviewCarriesImageBytesRatherThanARemoteUrl(): void
    {
        // Deliberately not a URL: the caller stores the bytes as its own
        // `files` row, so a preview image is served under the caller's
        // access rules and cannot change after the fact.
        $preview = new LinkPreview('Titre', 'Description', 'RAWBYTES');

        $this->assertSame('Titre', $preview->title);
        $this->assertSame('Description', $preview->description);
        $this->assertSame('RAWBYTES', $preview->imageBytes);
    }

    public function testTheDeprecatedGalleryNamesStillResolveToTheCoreOnes(): void
    {
        $this->assertTrue(interface_exists(\Modules\Gallery\Api\LinkPreviewFetcher::class));
        $this->assertTrue(class_exists(\Modules\Gallery\Api\LinkPreview::class));

        $fetcher = new class implements LinkPreviewFetcher {
            public function fetch(string $url): ?LinkPreview
            {
                return new LinkPreview(null, null, null);
            }
        };

        $this->assertInstanceOf(\Modules\Gallery\Api\LinkPreviewFetcher::class, $fetcher);
        $this->assertInstanceOf(\Modules\Gallery\Api\LinkPreview::class, $fetcher->fetch('https://example.org'));
    }

    /**
     * `gallery`'s own implementation still satisfies the moved contract —
     * the point of the move was the namespace, not the provider.
     */
    public function testGalleryStillProvidesTheOneImplementation(): void
    {
        $this->assertTrue(
            is_a(\Modules\Gallery\Service\LinkPreviewService::class, LinkPreviewFetcher::class, true)
        );
    }
}
