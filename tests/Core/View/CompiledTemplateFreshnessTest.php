<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * A template that changed on disk is a template that gets recompiled.
 *
 * This is the deployment defect it exists to prevent, and it took down a
 * live site. Twig was built with `auto_reload => $debug`, so outside
 * development it never re-read a template it had already compiled — it
 * loaded the cached class without so much as stat'ing the source. The
 * compiled files are namespaced by VERSION
 * (TwigFactory::cacheDirectory()), which rescues a numbered release; but
 * `VersionFile::read()` falls back to the constant 'dev' when there is no
 * VERSION file, which is exactly what a site deployed from a checkout
 * has. On such an install the namespace never changes, so every deploy
 * after the first kept serving the previous deploy's markup — new
 * controllers handing new variables to old compiled templates — until
 * somebody emptied storage/temp by hand.
 *
 * The check has to span two Environments AND the class-exists shortcut:
 * Twig skips its cache entirely when the compiled class is already
 * declared in the process, so a naive same-process probe reports staleness
 * that a real request (one process, one render) would never see. Hence the
 * distinct template names below — each assertion gets a class of its own.
 */
final class CompiledTemplateFreshnessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm-twig-freshness-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    private function render(string $name): string
    {
        // debug: false — the production configuration, the one that was
        // broken. With true, Twig disables the cache outright and this
        // test could never fail.
        return trim(TwigFactory::create($this->dir, false, [])->render($name, []));
    }

    public function testAutoReloadIsOnEvenOutsideDevelopment(): void
    {
        // The one line that was wrong. `auto_reload => $debug` meant the
        // check was off in production, which is the only place a deploy
        // happens.
        $twig = TwigFactory::create($this->dir, false, []);

        $this->assertTrue($twig->isAutoReload(), 'A deployed template change must reach the visitor.');
        $this->assertFalse($twig->isDebug(), 'The production configuration is what is under test here.');
    }

    public function testTheCompiledCacheIsStillUsed(): void
    {
        // The fix is "re-read when the source changed", not "stop
        // caching": Twig must still compile to disk, or every request
        // re-parses every template it touches.
        $this->assertNotFalse(
            TwigFactory::create($this->dir, false, [])->getCache(),
            'Turning the freshness check on must not turn the cache off.'
        );
    }

    /**
     * The end-to-end proof, and the only shape that can give it.
     *
     * Twig skips its cache entirely when the compiled class is already
     * declared in the process, so two renders in ONE process can never
     * observe a source change — while a real site renders once per
     * request, in a fresh process every time. A subprocess per render is
     * therefore not indirection here, it is the production shape.
     */
    public function testAFreshProcessPicksUpATemplateEditedSinceItWasCompiled(): void
    {
        $name = 'freshness_' . bin2hex(random_bytes(6)) . '.html.twig';
        $script = $this->dir . '/render.php';
        file_put_contents($script, sprintf(
            '<?php require %s; echo trim(\Core\View\TwigFactory::create(%s, false, [])->render(%s, []));',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            var_export($this->dir, true),
            var_export($name, true)
        ));

        file_put_contents($this->dir . '/' . $name, 'AVANT');
        $this->assertSame('AVANT', $this->renderInSubprocess($script));

        // The deploy: the file changes, nothing empties storage/temp.
        file_put_contents($this->dir . '/' . $name, 'APRES');

        $this->assertSame(
            'APRES',
            $this->renderInSubprocess($script),
            'The second deploy onto an install with no VERSION file used to keep serving the first one.'
        );
    }

    private function renderInSubprocess(string $script): string
    {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, "Render subprocess failed:\n" . implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
