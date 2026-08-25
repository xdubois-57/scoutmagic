<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * SECURITY.md § 9: `style-src-elem` no longer allows `'unsafe-inline'`,
 * so an inline `<style>` ELEMENT is authorised by its nonce or not at
 * all. Every one this codebase emits must therefore carry one.
 *
 * This is worth a source-level audit rather than trusting review,
 * because the failure is quiet in exactly the wrong way: the browser
 * refuses the block, logs to a console nobody is watching, and renders
 * the page **unstyled**. No test fails, no request errors, no journal
 * entry — the page simply looks wrong, and only on the pages that
 * happen to carry a `<style>`. Two of the three this repository has are
 * the update-in-progress and migration-progress pages, which by design
 * cannot load an external stylesheet and which a developer almost never
 * sees.
 *
 * A `style="…"` ATTRIBUTE is a different directive (`style-src-attr`,
 * which still allows inline) and is deliberately not checked here —
 * there are some 260 of them, and retiring those is the template rework
 * SECURITY.md § 33 tracks.
 */
class InlineStyleElementNonceTest extends TestCase
{
    public function testEveryStyleElementInATemplateCarriesANonce(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $offenders = [];
        $checked = 0;

        foreach ($this->templates($repoRoot) as $relativePath) {
            $contents = (string) file_get_contents($repoRoot . '/' . $relativePath);

            // Email templates are the one legitimate exception: a mail
            // client is not a browser, sends no CSP, and strips most of
            // what it is given anyway — an emailed <style> has no nonce
            // to carry and nothing to check it against.
            if (str_contains($relativePath, '/email/') || str_contains($relativePath, 'email_')) {
                continue;
            }

            // Twig comments are stripped first: a docblock explaining
            // why the tag below carries a nonce writes "<style>" in
            // prose, and an audit that failed on its own explanation
            // would teach everyone to stop writing them.
            $markup = (string) preg_replace('/\{#.*?#\}/s', '', $contents);

            if (preg_match_all('/<style\b[^>]*>/i', $markup, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $tag) {
                $checked++;
                if (!str_contains($tag, 'nonce=')) {
                    $offenders[] = "{$relativePath}: {$tag}";
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'the scan found no <style> element at all — did the template tree move?');
        $this->assertSame(
            [],
            $offenders,
            "An inline <style> element needs nonce=\"{{ csp_nonce }}\" — style-src-elem refuses it otherwise,\n"
            . "and the page renders unstyled without failing anything:\n" . implode("\n", $offenders)
        );
    }

    /**
     * The two pages that build their HTML in PHP rather than in a
     * template, both of which carry a `<style>` because neither may
     * request an external stylesheet: the migration-progress page
     * (public/index.php, pre-routing) and, for the record, the
     * fatal-error fallback.
     */
    public function testTheMigrationProgressPageNoncesItsStyleElement(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        $this->assertStringContainsString('<style nonce="__CSP_NONCE__">', $source);
        $this->assertStringNotContainsString("<style>\n", $source);
    }

    /**
     * @return list<string> repository-relative paths
     */
    private function templates(string $repoRoot): array
    {
        $templates = [];

        foreach (['core/View/templates', 'modules'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                    $templates[] = substr($file->getPathname(), strlen($repoRoot) + 1);
                }
            }
        }

        sort($templates);

        return $templates;
    }
}
