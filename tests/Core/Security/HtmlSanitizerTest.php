<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testAllowedTagsPassThrough(): void
    {
        $html = '<p>Hello <strong>world</strong></p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<strong>', $result);
    }

    public function testAnchorTagWithAllowedAttributes(): void
    {
        $html = '<a href="https://example.com" title="Example">Link</a>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('title="Example"', $result);
    }

    public function testUlOlLiAllowed(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
    }

    public function testScriptTagRemoved(): void
    {
        $html = '<p>Text</p><script>alert(1)</script>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testStyleTagRemoved(): void
    {
        $html = '<style>.foo{color:red}</style><p>Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('<style>', $result);
        $this->assertStringNotContainsString('.foo', $result);
    }

    public function testIframeTagRemoved(): void
    {
        $html = '<iframe src="evil.com"></iframe><p>Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('<iframe>', $result);
    }

    public function testFormTagRemoved(): void
    {
        $html = '<form action="/steal"><input></form><p>Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('<form>', $result);
    }

    public function testEventHandlersStripped(): void
    {
        $html = '<p onclick="alert(1)" onerror="alert(2)">Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function testJavascriptUriRemovedFromHref(): void
    {
        $html = '<a href="javascript:alert(1)">Click</a>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Click', $result);
    }

    public function testDataUriRemovedFromHref(): void
    {
        $html = '<a href="data:text/html,<script>alert(1)</script>">Click</a>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('data:', $result);
    }

    public function testTargetBlankGetsRelNoopener(): void
    {
        $html = '<a href="https://example.com" target="_blank">Link</a>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testDeeplyNestedDisallowedTagsRemoved(): void
    {
        $html = '<p><strong><em><script>alert(1)</script></em></strong></p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function testContentOutsideAllowedTagsIsPreserved(): void
    {
        $html = '<div>Hello <span>world</span></div>';
        $result = $this->sanitizer->sanitize($html);
        // div and span are not in allowed list, but text content should remain
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('world', $result);
        $this->assertStringNotContainsString('<div>', $result);
        $this->assertStringNotContainsString('<span>', $result);
    }

    public function testDisallowedAttributesOnAllowedTags(): void
    {
        $html = '<p class="foo" style="color:red" id="bar">Text</p>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringNotContainsString('class=', $result);
        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringNotContainsString('id=', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function testHeadingTagsAllowed(): void
    {
        $html = '<h2>Title</h2><h3>Subtitle</h3><h4>Sub-subtitle</h4>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('<h2>', $result);
        $this->assertStringContainsString('<h3>', $result);
        $this->assertStringContainsString('<h4>', $result);
    }

    public function testBlockquoteAllowed(): void
    {
        $html = '<blockquote>A quote</blockquote>';
        $result = $this->sanitizer->sanitize($html);
        $this->assertStringContainsString('<blockquote>', $result);
    }
}
