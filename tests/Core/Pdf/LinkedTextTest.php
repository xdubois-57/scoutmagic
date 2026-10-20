<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Pdf;

use Core\Pdf\DocumentPdfService;
use Core\Pdf\LinkedText;
use PHPUnit\Framework\TestCase;

/**
 * A line in which one web address is a real link (issue #977): escaped like
 * any value, linked only where it may be.
 */
final class LinkedTextTest extends TestCase
{
    public function testTheAddressIsWrappedInALinkAndStillPrinted(): void
    {
        $line = new LinkedText('version du 12/09/2027, https://unite.be/c/a1', 'https://unite.be/c/a1');

        $this->assertSame(
            'version du 12/09/2027, <a href="https://unite.be/c/a1">https://unite.be/c/a1</a>',
            $line->toHtml()
        );
    }

    public function testEverythingAroundTheLinkIsEscaped(): void
    {
        $line = new LinkedText('<b>Scouts & co</b> https://unite.be/?a=1&b=2', 'https://unite.be/?a=1&b=2');

        $this->assertSame(
            '&lt;b&gt;Scouts &amp; co&lt;/b&gt; '
                . '<a href="https://unite.be/?a=1&amp;b=2">https://unite.be/?a=1&amp;b=2</a>',
            $line->toHtml()
        );
    }

    /** Only http(s) becomes a link, and only an address the text actually prints. */
    public function testAnythingElseStaysText(): void
    {
        foreach ([
            new LinkedText('javascript:alert(1)', 'javascript:alert(1)'),
            new LinkedText('voir https://unite.be/a', 'https://unite.be/b'),
            new LinkedText('voir https://unite.be/a', null),
            new LinkedText('voir https://unite.be/a"onclick="x', 'https://unite.be/a"onclick="x'),
        ] as $line) {
            $this->assertStringNotContainsString('<a ', $line->toHtml());
        }
    }

    /** What dompdf makes of it: a link annotation, which an address printed as text never is. */
    public function testDompdfTurnsAMetaLineLinkIntoAnAnnotation(): void
    {
        $service = new DocumentPdfService();
        $url = 'https://unite.example.be/locations/local/conditions/abc123';

        $linked = $service->generate('Contrat', '<p>Corps</p>', 'Unité', [new LinkedText('Conditions : ' . $url, $url)]);
        $plain = $service->generate('Contrat', '<p>Corps</p>', 'Unité', ['Conditions : ' . $url]);

        $this->assertStringContainsString('/URI (' . $url . ')', $linked);
        $this->assertStringNotContainsString('/URI', $plain);
    }
}
