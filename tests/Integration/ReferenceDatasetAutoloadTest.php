<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The reference dataset's CLI entry points still load their own classes on
 * an installation, not just in a development checkout.
 *
 * `Tests\Fixtures\ReferenceDataset\` is declared in composer.json's
 * `autoload-dev`, which exists nowhere but a development checkout: every
 * real installation runs a `vendor/` dumped by
 * `composer install --no-dev --optimize-autoloader`
 * (`scripts/build-artifact.sh`). Running the builder there — the
 * documented way to use it, README.md §8 « sans `--root`, le builder cible
 * l'installation dans laquelle il se trouve » — died on the first line
 * naming one of these classes:
 *
 *     PHP Fatal error: Uncaught Error: Class
 *     "Tests\Fixtures\ReferenceDataset\InstanceContext" not found in
 *     /htdocs/tests/fixtures/reference-dataset/build.php:98
 *
 * Every test in this repository runs under a development checkout, where
 * Composer maps the namespace and nothing can reproduce that — so the
 * subprocess below unmaps it first, which is exactly what `--no-dev`
 * leaves behind, and then asks the two questions in order: the class is
 * genuinely unreachable (the bug is real), and requiring the entry
 * points' own bootstrap makes it reachable again (the fix works).
 *
 * @see tests/fixtures/reference-dataset/autoload.php
 */
final class ReferenceDatasetAutoloadTest extends TestCase
{
    public function testTheBootstrapLoadsTheDatasetClassesWithoutComposerAutoloadDev(): void
    {
        $result = $this->probeInSubprocess();

        $this->assertSame(
            'unmapped=no bootstrapped=yes',
            $result,
            'tests/fixtures/reference-dataset/autoload.php must map its own namespace: on an installation, '
                . "Composer's autoload-dev is not there to do it."
        );
    }

    public function testBothEntryPointsGoThroughThatBootstrap(): void
    {
        foreach (['build.php', 'generate.php'] as $entryPoint) {
            $source = (string) file_get_contents(self::datasetRoot() . '/' . $entryPoint);

            $this->assertStringContainsString(
                "require_once __DIR__ . '/autoload.php';",
                $source,
                $entryPoint . ' must bootstrap through the directory\'s own autoload.php.'
            );
            $this->assertStringNotContainsString(
                "require_once __DIR__ . '/../../../vendor/autoload.php';",
                $source,
                $entryPoint . " must not reach for Composer's autoloader directly — autoload.php does that, "
                    . 'and adds the mapping a --no-dev install does not carry.'
            );
        }
    }

    private static function datasetRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/reference-dataset';
    }

    /**
     * Runs the two class_exists() checks in a subprocess, because the
     * first one has to happen in an interpreter where Composer's
     * autoload-dev mapping has been taken away — which cannot be undone
     * for the rest of this suite.
     */
    private function probeInSubprocess(): string
    {
        $repositoryRoot = dirname(__DIR__, 2);
        // No `.php` appended: tempnam() CREATES the file it names, so a
        // suffixed path writes and deletes a second file and leaves the
        // reservation behind on every run. The CLI does not care about the
        // extension.
        $script = tempnam(sys_get_temp_dir(), 'reference-dataset-autoload-');
        if ($script === false) {
            self::fail('Could not create the autoload probe script.');
        }

        file_put_contents($script, <<<PHP
        <?php
        \$loader = require '{$repositoryRoot}/vendor/autoload.php';
        // What `composer install --no-dev` leaves: the application mapped,
        // autoload-dev's own prefixes gone.
        \$loader->setPsr4('Tests\\\\', []);
        \$loader->setPsr4('Tests\\\\Fixtures\\\\ReferenceDataset\\\\', []);

        \$class = 'Tests\\\\Fixtures\\\\ReferenceDataset\\\\InstanceContext';
        echo class_exists(\$class) ? 'unmapped=yes' : 'unmapped=no';

        require '{$repositoryRoot}/tests/fixtures/reference-dataset/autoload.php';
        echo class_exists(\$class) ? ' bootstrapped=yes' : ' bootstrapped=no';
        PHP);

        try {
            $output = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);

            $this->assertSame(0, $status, "Autoload probe failed:\n" . implode("\n", $output));

            return trim(implode("\n", $output));
        } finally {
            @unlink($script);
        }
    }
}
