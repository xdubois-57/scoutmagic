<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('DEPENDENCY_INVENTORY_TEST')) {
    define('DEPENDENCY_INVENTORY_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/dependency-inventory.php';

/**
 * scripts/dependency-inventory.php writes the "Dépendances livrées" section
 * scripts/release.sh appends to every release note. It declares no
 * namespace (a CLI script), so the functions under test live in the global
 * namespace — hence the leading backslashes, as in E2eActivationOrderTest.
 *
 * Two kinds of failure are pinned here, and both are silent in the script's
 * own output: a surface that quietly drops out of the inventory (a vendored
 * library nobody declared, a package the lock does not record), and a
 * licence that gets listed with a reassuring verdict nobody wrote. The
 * inventory's whole value is that a reader can trust it, so what it does
 * NOT know has to show.
 */
class DependencyInventoryTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testComposerPackagesAreReadFromTheLockWithTheirLicence(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'zeta/lib', 'version' => 'v2.0.0', 'license' => ['MIT']],
                ['name' => 'alpha/lib', 'version' => '1.2.3', 'license' => ['LGPL-2.1', 'MIT']],
                ['name' => 'no/licence', 'version' => '0.1.0'],
                ['name' => 'malformed'],
            ],
        ];

        $rows = \inventory_composer_packages($lock, 'packages');

        $this->assertSame(['alpha/lib', 'no/licence', 'zeta/lib'], array_keys($rows), 'sorted by name, malformed entry dropped');
        $this->assertSame('1.2.3', $rows['alpha/lib']['version']);
        $this->assertSame('LGPL-2.1 / MIT', $rows['alpha/lib']['licence']);
        $this->assertSame('**non déclarée**', $rows['no/licence']['licence']);
        $this->assertSame([], \inventory_composer_packages($lock, 'packages-dev'));
    }

    public function testNpmPackagesAreOnlyTheOnesTheManifestAsksFor(): void
    {
        $manifest = ['devDependencies' => ['vitest' => '^3.2.0', 'ghost' => '^1.0.0']];
        $lock = ['packages' => [
            'node_modules/vitest' => ['version' => '3.2.7', 'license' => 'MIT'],
            'node_modules/transitive' => ['version' => '9.9.9', 'license' => 'MIT'],
        ]];

        $rows = \inventory_npm_packages($manifest, $lock);

        $this->assertSame(['ghost', 'vitest'], array_keys($rows), 'the transitive tree is not listed');
        $this->assertSame('3.2.7', $rows['vitest']['version']);
        $this->assertSame('MIT', $rows['vitest']['licence']);
        // A declared package the lock does not record is a finding, not a gap.
        $this->assertSame('**absente du lock**', $rows['ghost']['version']);
        $this->assertSame('**non déclarée**', $rows['ghost']['licence']);
    }

    /**
     * The real vendored directory: every library under public/assets/vendor/
     * must be declared in the map, with a banner the pattern actually reads.
     * This is the test that fails on the commit that vendors a new library
     * without declaring it — the same rule AGENTS.md § CSS / frontend sets
     * for the freshness gate, applied to the inventory.
     */
    public function testEveryVendoredLibraryInTheRepositoryIsDeclaredAndReadable(): void
    {
        $rows = \inventory_vendored_libraries(self::repoRoot() . '/public/assets/vendor');

        $this->assertNotSame([], $rows);
        foreach ($rows as $name => $row) {
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                $row['version'],
                "{$name}: version not read from its banner — declare it in INVENTORY_VENDORED_LIBRARIES, or the pattern no longer matches"
            );
            $this->assertStringNotContainsString('à déclarer', $row['licence'], "{$name} has no licence declared");
        }
    }

    public function testAnUndeclaredVendoredDirectoryIsReportedNotOmitted(): void
    {
        $dir = sys_get_temp_dir() . '/inventory-' . bin2hex(random_bytes(4));
        mkdir($dir . '/mystery-lib', 0o777, true);
        mkdir($dir . '/leaflet', 0o777, true);
        file_put_contents($dir . '/leaflet/leaflet.js', "/* @preserve\n * Leaflet 1.9.4, a JS library\n */\n!function(){}");

        try {
            $rows = \inventory_vendored_libraries($dir);
        } finally {
            unlink($dir . '/leaflet/leaflet.js');
            rmdir($dir . '/leaflet');
            rmdir($dir . '/mystery-lib');
            rmdir($dir);
        }

        $this->assertSame('1.9.4', $rows['Leaflet']['version']);
        $this->assertSame('BSD-2-Clause', $rows['Leaflet']['licence']);
        $this->assertArrayHasKey('mystery-lib', $rows);
        $this->assertStringContainsString('à déclarer', $rows['mystery-lib']['version']);
    }

    public function testABannerWithoutAVersionIsSaidSoRatherThanGuessed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'banner');
        file_put_contents($file, "/*! Chart.js, no version here */");
        try {
            $this->assertSame(
                '**version illisible dans la bannière**',
                \inventory_banner_version($file, '/Chart\.js v([0-9]+\.[0-9]+\.[0-9]+)/')
            );
        } finally {
            unlink($file);
        }
        $this->assertStringContainsString('introuvable', \inventory_banner_version('/nonexistent/chart.js', '/x/'));
    }

    /**
     * The compatibility table must never reassure about a licence nobody
     * has classified. Every licence in the real lock files has to be in the
     * map — adding a dependency under a new licence is a decision to write
     * down (CLAUDE.md-style: MIT/BSD/ISC/Apache-2.0 are routine, anything
     * else is raised), and this is where it gets noticed.
     */
    public function testEveryLicenceActuallyShippedHasAWrittenVerdict(): void
    {
        $output = \inventory_render(self::repoRoot());

        $this->assertStringNotContainsString('À examiner', $output, 'a licence in composer.lock, package-lock.json or a vendored banner has no entry in INVENTORY_LICENCE_COMPATIBILITY');
        $this->assertStringNotContainsString('non déclarée', $output, 'a shipped package declares no licence');
        $this->assertStringNotContainsString('à déclarer', $output);
    }

    public function testAnUnknownLicenceIsFlaggedInTheCompatibilityTable(): void
    {
        $table = \inventory_compatibility_table([
            'a/b' => ['version' => '1', 'licence' => 'MIT'],
            'c/d' => ['version' => '1', 'licence' => 'MIT / WTFPL'],
            'e/f' => ['version' => '1', 'licence' => '**non déclarée**'],
        ]);

        $this->assertStringContainsString('| MIT | 2 | Licence permissive : compatible. |', $table);
        $this->assertStringContainsString('| WTFPL | 1 | **À examiner**', $table);
        $this->assertStringContainsString('| **non déclarée** | 1 | **À examiner** — aucune licence déclarée. |', $table);
    }

    /**
     * The document itself, against the real repository: French headings,
     * every surface present, counts in the headings so a reader sees at a
     * glance that nothing is empty.
     */
    public function testTheRenderedInventoryCoversEverySurface(): void
    {
        $output = \inventory_render(self::repoRoot());

        $this->assertStringStartsWith('## Dépendances livrées', $output);
        $this->assertMatchesRegularExpression('/### PHP — production \(\d+\)/', $output);
        $this->assertMatchesRegularExpression('/### PHP — développement \(\d+\)/', $output);
        $this->assertMatchesRegularExpression('/### JavaScript — développement \(\d+\)/', $output);
        $this->assertMatchesRegularExpression('/### Bibliothèques front-end vendorisées \(\d+\)/', $output);
        $this->assertStringContainsString('### Compatibilité avec la licence du projet', $output);
        $this->assertStringContainsString('AGPL-3.0-or-later', $output);
        // The tools whose verdict a release rests on, by name.
        $this->assertStringContainsString('| `phpunit/phpunit` |', $output);
        $this->assertStringContainsString('| `phpstan/phpstan` |', $output);
        $this->assertStringContainsString('| `vitest` |', $output);
        $this->assertStringContainsString('| `@playwright/test` |', $output);
        $this->assertStringContainsString('| `Bootstrap` |', $output);
    }

    public function testAMissingLockFileRefusesRatherThanPrintingAPartialInventory(): void
    {
        $this->expectException(\RuntimeException::class);
        \inventory_render(sys_get_temp_dir() . '/no-such-repository-' . bin2hex(random_bytes(4)));
    }

    /**
     * A lock file that exists and is corrupt is the worse case of the
     * two: a partial inventory looks complete, and the release note it
     * lands in is read as a statement of what shipped.
     */
    public function testACorruptLockFileRefusesRatherThanReadingAsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'lock');
        file_put_contents($file, '{"packages": [truncated');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('is not valid JSON');
            \inventory_read_json($file);
        } finally {
            unlink($file);
        }
    }

    /**
     * `packages-dev` is absent from a lock file with no dev dependencies,
     * and could be malformed in one somebody edited. Neither is a reason
     * to stop — the section is simply empty — but neither may be read as
     * a list of packages either.
     */
    public function testAnAbsentOrMalformedComposerSectionIsEmptyRatherThanFatal(): void
    {
        $this->assertSame([], \inventory_composer_packages([], 'packages-dev'));
        $this->assertSame([], \inventory_composer_packages(['packages' => 'not-a-list'], 'packages'));
    }

    /**
     * An empty table says so in words. A release note carrying a heading
     * with nothing under it reads as a section somebody forgot to fill.
     */
    public function testAnEmptyTableSaysSoRatherThanRenderingAHeaderWithNoRows(): void
    {
        $this->assertSame("_Aucune._\n", \inventory_table([], '_Aucune._'));
        $this->assertStringContainsString(
            '| `a/b` | 1.0.0 | MIT |',
            \inventory_table(['a/b' => ['version' => '1.0.0', 'licence' => 'MIT']], '_Aucune._')
        );
    }

    /**
     * The freshness gate in scripts/release.sh reads the same banners with
     * its own copies of these patterns. They have to agree, or a banner
     * that changes shape breaks one and not the other — and the one that
     * keeps working is the one nobody re-reads.
     */
    public function testTheBannerPatternsMatchTheFreshnessGates(): void
    {
        $release = (string) file_get_contents(self::repoRoot() . '/scripts/release.sh');

        foreach (INVENTORY_VENDORED_LIBRARIES as $library) {
            // The bash regex is the PHP one without delimiters and capture
            // group: 'Bootstrap v[0-9]+\.[0-9]+\.[0-9]+'.
            $bash = str_replace(['([0-9]+\.[0-9]+\.[0-9]+)'], ['[0-9]+\.[0-9]+\.[0-9]+'], trim($library['pattern'], '/'));
            $this->assertStringContainsString(
                "'" . $bash . "'",
                $release,
                "{$library['name']}: the inventory reads a banner scripts/release.sh's freshness gate does not"
            );
            $this->assertStringContainsString(
                '"' . $library['upstream'] . '"',
                $release,
                "{$library['name']}: upstream repository differs from the freshness gate's"
            );
        }
    }
}
