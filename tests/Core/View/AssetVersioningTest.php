<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\TwigFunction;

/**
 * The invariant that makes /assets/** safe to cache for a year: every
 * template reference goes through the asset() helper, whose ?v=…
 * changes with the release. One raw reference is one file the far-future
 * lifetime in .htaccess would serve stale for up to a year after an
 * update — exactly the failure nobody notices until a page half-breaks.
 */
class AssetVersioningTest extends TestCase
{
    public function testNoTemplateReferencesAssetsWithoutTheVersionHelper(): void
    {
        $root = dirname(__DIR__, 3);
        $templates = array_merge(
            glob($root . '/core/View/templates/{,**/,**/**/}*.twig', GLOB_BRACE) ?: [],
            glob($root . '/modules/*/views/{,**/,**/**/}*.twig', GLOB_BRACE) ?: []
        );
        self::assertNotEmpty($templates, 'template scan found nothing — did the layout move?');

        $raw = [];
        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            if (preg_match_all('~(?:src|href)="(/assets/[^"]*)"~', $source, $m) > 0) {
                foreach ($m[1] as $path) {
                    $raw[] = substr($template, strlen($root) + 1) . ' → ' . $path;
                }
            }
        }

        self::assertSame(
            [],
            $raw,
            "These templates reference /assets/ without asset('…') — the far-future\n"
            . "cache lifetime would serve them stale for up to a year after a release:\n  "
            . implode("\n  ", $raw)
        );
    }

    public function testAssetAppendsTheReleaseVersion(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $twig->addGlobal('app_version', '1.2.3');

        $asset = null;
        foreach ($twig->getFunctions() as $function) {
            if ($function instanceof TwigFunction && $function->getName() === 'asset') {
                $asset = $function->getCallable();
            }
        }
        self::assertNotNull($asset, 'asset() function not registered.');

        self::assertSame('/assets/css/app.css?v=1.2.3', $asset('/assets/css/app.css'));
    }
}
