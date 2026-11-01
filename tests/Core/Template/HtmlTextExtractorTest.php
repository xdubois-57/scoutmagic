<?php

declare(strict_types=1);

namespace Tests\Core\Template;

use Core\Template\HtmlTextExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlTextExtractorTest extends TestCase
{
    public function testTextNodesSurviveMalformedAttributesWithoutIncludingActiveContent(): void
    {
        $text = HtmlTextExtractor::extract('<div class="broken"">Année<p>2026-2027</p>'
            . '<script>hidden()</script><style>hidden</style><noscript>hidden</noscript>'
            . '<p>57,50 &euro; &amp; &lt;texte&gt;</p></div><!-- hidden -->');
        $this->assertStringContainsString("Année\n2026-2027\n", $text);
        $this->assertStringContainsString('57,50 € & <texte>', $text);
        $this->assertStringNotContainsString('hidden', $text);
        $this->assertSame('', HtmlTextExtractor::extract(''));
    }
}
