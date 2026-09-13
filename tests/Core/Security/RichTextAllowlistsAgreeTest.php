<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The editor's allowlist and the sanitiser's allowlist must say the same
 * thing.
 *
 * `public/assets/js/rich-text-link.js` carries a copy of what
 * {@see HtmlSanitizer} keeps. That copy buys no security — the server
 * sanitises whatever the client sends — it buys HONESTY: without it the
 * editor shows, and saves back into the page, markup the server is about to
 * drop, so a heading looks applied until the next page load. That was half
 * of issue #306, and a copy nothing checks is a copy that drifts back into
 * the same state.
 *
 * Read out of the JavaScript source rather than executed: the alternative
 * is a browser, and this assertion is about two literals agreeing.
 */
class RichTextAllowlistsAgreeTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/assets/js/rich-text-link.js';

    public function testTheEditorKeepsExactlyTheTagsTheSanitizerKeeps(): void
    {
        $this->assertSame(
            array_keys($this->phpConstant('ALLOWED')),
            array_keys($this->jsAllowed()),
            'rich-text-link.js and HtmlSanitizer disagree on which tags survive.'
        );
    }

    public function testTheEditorKeepsExactlyTheAttributesTheSanitizerKeeps(): void
    {
        /** @var array<string, array<string>> $php */
        $php = $this->phpConstant('ALLOWED');
        $js = $this->jsAllowed();

        foreach ($php as $tag => $attributes) {
            $this->assertSame(
                $attributes,
                $js[$tag] ?? [],
                sprintf('rich-text-link.js and HtmlSanitizer disagree on the attributes of <%s>.', $tag)
            );
        }
    }

    public function testTheEditorRemovesWithTheirContentTheTagsTheSanitizerDoes(): void
    {
        $this->assertSame(
            $this->phpConstant('STRIP_WITH_CONTENT'),
            $this->jsArray('STRIPPED_WITH_CONTENT'),
            'A tag stripped with its content on one side and unwrapped on the other '
            . 'shows the author words the save then loses.'
        );
    }

    public function testTheEditorAcceptsExactlyTheUrlSchemesTheSanitizerAccepts(): void
    {
        $this->assertSame(
            $this->phpConstant('URL_SCHEME_ALLOWLIST'),
            $this->jsArray('ALLOWED_SCHEMES'),
            'A scheme accepted by the editor and refused by the server is a link '
            . 'that loses its href on save.'
        );
    }

    /**
     * The JavaScript's `var ALLOWED = { … }` as PHP.
     *
     * @return array<string, array<string>>
     */
    private function jsAllowed(): array
    {
        $body = $this->literal('/\bvar ALLOWED = \{(.*?)\n    \};/s');

        $matched = preg_match_all('/([a-z0-9]+)\s*:\s*\[([^\]]*)\]/i', $body, $entries, PREG_SET_ORDER);
        $this->assertNotSame(0, $matched, 'No tag found in the JavaScript allowlist — has its shape changed?');

        $allowed = [];
        foreach ($entries as $entry) {
            $allowed[$entry[1]] = $this->quotedStrings($entry[2]);
        }

        return $allowed;
    }

    /**
     * A `var NAME = [ … ]` or `new Set([ … ])` of string literals, as PHP.
     *
     * @return array<int, string>
     */
    private function jsArray(string $name): array
    {
        return $this->quotedStrings(
            $this->literal('/\bvar ' . preg_quote($name, '/') . ' = (?:new Set\()?\[(.*?)\]/s')
        );
    }

    private function literal(string $pattern): string
    {
        $source = file_get_contents(self::JS);
        $this->assertIsString($source, 'rich-text-link.js is unreadable.');

        $found = preg_match($pattern, $source, $match);
        $this->assertSame(1, $found, sprintf('%s matched nothing in rich-text-link.js.', $pattern));

        return $match[1];
    }

    /**
     * @return array<int, string>
     */
    private function quotedStrings(string $body): array
    {
        preg_match_all('/\'([^\']*)\'/', $body, $strings);

        return $strings[1];
    }

    /**
     * A private constant of the sanitiser, read the only way there is.
     *
     * @return array<mixed>
     */
    private function phpConstant(string $name): array
    {
        /** @var array<mixed> $value */
        $value = (new \ReflectionClass(HtmlSanitizer::class))->getConstant($name);

        return $value;
    }
}
