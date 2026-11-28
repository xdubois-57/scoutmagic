<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The document reader (public/assets/js/document-reader.js, issue #1431):
 * which links open in it, and what they must still be without it.
 *
 * The ticket fixed its scope strictly — the booking request's two links,
 * the three sign-in modes, the public registration form and the footer —
 * and excluded every other link. A `data-document-reader` added anywhere
 * else is a change of scope, so it fails here until someone decides it.
 *
 * Each marked link stays a real anchor with its canonical address and
 * `target="_blank"`: that is what opens a new tab in a browser, and what
 * still works without JavaScript.
 */
final class DocumentReaderScopeTest extends TestCase
{
    /** @var array<string, int> template => number of marked links */
    private const SCOPE = [
        'modules/rental/views/public/request.html.twig' => 2,
        'core/View/templates/auth/login.html.twig' => 3,
        'modules/registration/views/public.html.twig' => 1,
        'core/View/templates/base.html.twig' => 1,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function read(string $relative): string
    {
        $content = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($content, $relative);

        return $content;
    }

    /** @return list<string> every <a …> opening tag carrying the attribute */
    private static function markedLinks(string $template): array
    {
        preg_match_all('/<a\b[^>]*\bdata-document-reader\b[^>]*>/', $template, $matches);

        return $matches[0];
    }

    public function testOnlyTheLinksTheTicketNamesAreDocumentsToRead(): void
    {
        $found = [];
        foreach (['core', 'modules'] as $top) {
            $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $top, \FilesystemIterator::SKIP_DOTS));
            foreach ($dir as $file) {
                if (!str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(self::root()) + 1);
                $count = count(self::markedLinks(self::read($relative)));
                if ($count > 0) {
                    $found[$relative] = $count;
                }
            }
        }
        ksort($found);
        $expected = self::SCOPE;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            'data-document-reader is on a link issue #1431 did not name, or missing from one it did. The scope is the maintainer\'s decision.'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function scopedTemplates(): iterable
    {
        foreach (array_keys(self::SCOPE) as $template) {
            yield $template => [$template];
        }
    }

    #[DataProvider('scopedTemplates')]
    public function testEveryMarkedLinkStaysARealLinkThatOpensANewTab(string $template): void
    {
        foreach (self::markedLinks(self::read($template)) as $tag) {
            $this->assertMatchesRegularExpression('/\bhref="\/[^"]+"/', $tag, $template . ': ' . $tag);
            $this->assertStringContainsString('target="_blank"', $tag, $template . ': ' . $tag);
            $this->assertStringContainsString('rel="noopener"', $tag, $template . ': ' . $tag);
            $this->assertMatchesRegularExpression('/data-document-reader="[^"]+"/', $tag, $template . ' names the dialog: ' . $tag);
        }
    }

    public function testTheConditionsLinkPointsAtTheVersionTheFormHolds(): void
    {
        $template = self::read('modules/rental/views/public/request.html.twig');

        $this->assertStringContainsString('<input type="hidden" name="conditions_version" value="{{ conditions.version }}">', $template);
        $this->assertStringContainsString(
            '<a href="/locations/{{ asset.slug }}/conditions/{{ conditions.version }}" target="_blank" rel="noopener" data-document-reader="Conditions de location">',
            $template,
            'the dialog must show the version the box accepts, never « the latest »'
        );
    }

    public function testTheBaseLayoutCarriesOneSharedDialogAndItsScript(): void
    {
        $base = self::read('core/View/templates/base.html.twig');

        $this->assertSame(1, substr_count($base, "id: 'document-reader'"), 'one dialog for the whole site');
        $this->assertStringContainsString("{% embed 'partials/modal.html.twig' with { id: 'document-reader'", $base);
        $this->assertStringContainsString('Fermer et revenir', $base);
        $this->assertStringContainsString('data-document-reader-body', $base);
        $this->assertSame(1, substr_count($base, "asset('/assets/js/document-reader.js')"));
        $this->assertStringContainsString("'/assets/js/document-reader.js'", self::read('public/sw.js'), 'precached with the other site-wide scripts');
    }

    /** @return iterable<string, array{string}> */
    public static function documentPages(): iterable
    {
        yield 'privacy notice' => ['core/View/templates/pages/rgpd.html.twig'];
        yield 'rental conditions' => ['modules/rental/views/public/conditions.html.twig'];
    }

    /**
     * The reader takes this element and nothing else of the page: no
     * header, navigation, scripts or forms come along.
     */
    #[DataProvider('documentPages')]
    public function testEachDocumentMarksTheContentTheReaderShows(string $template): void
    {
        $content = self::read($template);

        $this->assertSame(1, preg_match_all('/<div\b[^>]*\bdata-document-reader-content\b/', $content), $template);
        $this->assertMatchesRegularExpression('/data-document-reader-title="[^"]+"/', $content, $template);
    }

    /** Reading is not agreeing (issue #1431): the reader never touches a consent box. */
    public function testTheReaderNeverTicksOrSubmitsAnything(): void
    {
        $script = self::read('public/assets/js/document-reader.js');

        $this->assertStringNotContainsString('.checked', $script);
        $this->assertStringNotContainsString('.submit(', $script);
        $this->assertStringNotContainsString('requestSubmit', $script);
        $this->assertStringNotContainsString('innerHTML', $script, 'content enters through DOMParser and importNode, after stripping');
    }
}
