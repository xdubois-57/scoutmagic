<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('DEPENDENCY_AGE_CHECK_TEST')) {
    define('DEPENDENCY_AGE_CHECK_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/check-dependency-age.php';

/**
 * The dependency age release gate of issue #841: « get the release date of
 * the last version of all dependencies … add the last release date in the
 * release notes … report a warning … for more than a year ».
 *
 * A throwaway repository holds the three lock files and one vendored
 * library, and the network is a map of URL to body — so every source's
 * document shape, the threshold, the « non vérifié » path and the notes
 * section are asserted without a registry being asked anything.
 */
class DependencyAgeCheckTest extends TestCase
{
    private const NOW = '2026-11-15T12:00:00+00:00';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/scoutmagic-dependency-age-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public/assets/vendor/chartjs', 0700, true);

        file_put_contents($this->root . '/composer.lock', (string) json_encode([
            'packages' => [
                ['name' => 'acme/alive', 'version' => 'v2.1.0', 'license' => ['MIT']],
                ['name' => 'acme/abandoned', 'version' => '1.0.3', 'license' => ['MIT']],
            ],
            'packages-dev' => [
                ['name' => 'acme/tooling', 'version' => '5.0.0', 'license' => ['MIT']],
            ],
        ]));
        file_put_contents($this->root . '/package.json', (string) json_encode([
            'devDependencies' => ['@scope/runner' => '^1.0.0'],
        ]));
        file_put_contents($this->root . '/package-lock.json', (string) json_encode([
            'packages' => ['node_modules/@scope/runner' => ['version' => '1.4.0', 'license' => 'MIT']],
        ]));
        file_put_contents(
            $this->root . '/public/assets/vendor/chartjs/chart.umd.min.js',
            "/*!\n * Chart.js v4.4.1\n */\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (['composer.lock', 'package.json', 'package-lock.json'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @unlink($this->root . '/public/assets/vendor/chartjs/chart.umd.min.js');
        foreach (['/public/assets/vendor/chartjs', '/public/assets/vendor', '/public/assets', '/public', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    /**
     * Every source answering, one PHP package stale.
     *
     * @return array<string, string>
     */
    private static function registries(): array
    {
        return [
            'https://repo.packagist.org/p2/acme/alive.json' => self::packagist('acme/alive', [
                '2.1.0' => '2026-06-01T10:00:00+00:00',
                // A backport published after the newest major still means
                // somebody is releasing: the newest DATE wins, not the first entry.
                '1.9.9' => '2026-09-01T10:00:00+00:00',
            ]),
            'https://repo.packagist.org/p2/acme/abandoned.json' => self::packagist('acme/abandoned', [
                '1.0.3' => '2023-02-10T08:00:00+00:00',
            ]),
            'https://repo.packagist.org/p2/acme/tooling.json' => self::packagist('acme/tooling', [
                '5.0.0' => '2026-10-20T08:00:00+00:00',
            ]),
            'https://registry.npmjs.org/@scope%2Frunner' => (string) json_encode(['time' => [
                // The two bookkeeping keys are not releases and must not date anything.
                'created' => '2020-01-01T00:00:00.000Z',
                'modified' => '2026-11-14T00:00:00.000Z',
                '1.4.0' => '2026-08-03T09:00:00.000Z',
                '1.3.0' => '2026-02-01T09:00:00.000Z',
            ]]),
            'https://api.github.com/repos/chartjs/Chart.js/releases/latest' => (string) json_encode([
                'tag_name' => 'v4.4.1',
                'published_at' => '2025-12-03T15:00:00Z',
            ]),
        ];
    }

    /**
     * Packagist's p2 document for one package: its stable releases, newest first.
     *
     * @param array<string, string> $times version => publication
     */
    private static function packagist(string $name, array $times): string
    {
        $entries = [];
        foreach ($times as $version => $time) {
            $entries[] = ['version' => $version, 'time' => $time];
        }

        return (string) json_encode(['packages' => [$name => $entries]]);
    }

    /**
     * @param array<string, string> $bodies
     * @return list<array{name: string, released: ?\DateTimeImmutable}>
     */
    private function dated(array $bodies): array
    {
        return dependencyAgeDate(
            dependencyAgeCollect($this->root),
            static fn (string $url): ?string => $bodies[$url] ?? null
        );
    }

    /** @return array<string, ?string> name => Y-m-d */
    private static function dates(array $dated): array
    {
        $dates = [];
        foreach ($dated as $row) {
            $dates[$row['name']] = $row['released']?->format('Y-m-d');
        }

        return $dates;
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    public function testEverySurfaceTheInventoryListsIsDated(): void
    {
        $this->assertSame(
            [
                'acme/abandoned' => '2023-02-10',
                'acme/alive' => '2026-09-01',
                'acme/tooling' => '2026-10-20',
                '@scope/runner' => '2026-08-03',
                'Chart.js' => '2025-12-03',
            ],
            self::dates($this->dated(self::registries()))
        );
    }

    /** npm's `modified` moves with the metadata; taking it would date an abandoned package today. */
    public function testNpmBookkeepingKeysAreNotReleases(): void
    {
        $date = dependencyAgeLatestRelease('npm', 'x', (string) json_encode(['time' => [
            'created' => '2020-01-01T00:00:00Z',
            'modified' => '2026-11-14T00:00:00Z',
            '0.1.0' => '2021-03-01T00:00:00Z',
        ]]));

        $this->assertSame('2021-03-01', $date?->format('Y-m-d'));
    }

    public function testOnlyTheDependencyPastAYearIsFlagged(): void
    {
        $findings = dependencyAgeFindings($this->dated(self::registries()), self::now());

        $this->assertSame(['acme/abandoned'], $findings['stale']);
        $this->assertSame([], $findings['unverified']);
    }

    /** The threshold is « more than a year »: a release exactly 365 days old is not yet flagged. */
    public function testTheThresholdIsMoreThanAYear(): void
    {
        $now = self::now();
        $rows = [
            ['name' => 'exactly', 'released' => $now->modify('-365 days')],
            ['name' => 'past', 'released' => $now->modify('-366 days')],
        ];

        $this->assertSame(['past'], dependencyAgeFindings($rows, $now)['stale']);
    }

    /**
     * A source that does not answer, answers with something that is not its
     * document, or gives no date, leaves the package « non vérifié » — never
     * dated by a guess, and never a refusal.
     */
    public function testAnUnansweredOrUndatedSourceIsNotVerifiedRatherThanGuessed(): void
    {
        $bodies = self::registries();
        unset($bodies['https://repo.packagist.org/p2/acme/tooling.json']);
        $bodies['https://registry.npmjs.org/@scope%2Frunner'] = '<html>502 Bad Gateway</html>';
        $bodies['https://api.github.com/repos/chartjs/Chart.js/releases/latest']
            = (string) json_encode(['message' => 'Not Found']);

        $findings = dependencyAgeFindings($this->dated($bodies), self::now());

        $this->assertSame(['acme/tooling', '@scope/runner', 'Chart.js'], $findings['unverified']);
        $this->assertSame(['acme/abandoned'], $findings['stale']);
    }

    public function testTheNotesSectionDatesEveryPackageAndMarksTheStaleOne(): void
    {
        $markdown = dependencyAgeMarkdown($this->dated(self::registries()), self::now());

        $this->assertStringStartsWith("## Dernières publications des dépendances\n", $markdown);
        $this->assertStringContainsString("**Sans publication depuis plus d'un an (1) :** `acme/abandoned`", $markdown);
        $this->assertStringContainsString('| `acme/abandoned` | 1.0.3 | 2023-02-10 | ⚠️ 1374 j |', $markdown);
        $this->assertStringContainsString('| `acme/alive` | v2.1.0 | 2026-09-01 | 75 j |', $markdown);
        $this->assertStringContainsString('| `Chart.js` | 4.4.1 | 2025-12-03 | 346 j |', $markdown);
        $surfaces = ['PHP — production', 'PHP — développement', 'JavaScript — développement', 'Front-end vendorisé'];
        foreach ($surfaces as $surface) {
            $this->assertStringContainsString('### ' . $surface . "\n\n| Paquet |", $markdown);
        }
        $this->assertStringNotContainsString('Non datées', $markdown);
    }

    public function testTheNotesSayWhenNothingIsStaleAndNameWhatCouldNotBeDated(): void
    {
        $bodies = self::registries();
        unset($bodies['https://repo.packagist.org/p2/acme/abandoned.json']);

        $markdown = dependencyAgeMarkdown($this->dated($bodies), self::now());

        $this->assertStringContainsString(
            "**Parmi les 4 dépendances datées, aucune n'est restée plus d'un an sans publication.**",
            $markdown
        );
        $this->assertStringContainsString('**Non datées (1) :** `acme/abandoned`', $markdown);
        $this->assertStringContainsString('| `acme/abandoned` | 1.0.3 | **non vérifiée** | — |', $markdown);
    }

    public function testTheReportLineWarnsAndNamesTheStaleAndTheUndated(): void
    {
        $this->assertSame(
            "vérifié — aucune des 5 dépendances datées n'est restée plus d'un an sans publication "
                . '(détail dans « Dernières publications des dépendances »).',
            dependencyAgeReport(5, ['stale' => [], 'unverified' => []])
        );
        $this->assertSame(
            "avertissement — 1 dépendance(s) sur 4 datées sans publication depuis plus d'un an : `acme/abandoned` ; "
                . '1 non datée(s), à vérifier à la main : `Chart.js` '
                . '(détail dans « Dernières publications des dépendances »).',
            dependencyAgeReport(5, ['stale' => ['acme/abandoned'], 'unverified' => ['Chart.js']])
        );
    }

    /**
     * Every registry down: nothing was dated, so nothing may be called
     * verified — not in the report line, not in the notes. The claim is
     * about the packages actually looked at, never the whole list.
     */
    public function testNothingDatedIsNeverCalledVerified(): void
    {
        $dated = $this->dated([]);
        $findings = dependencyAgeFindings($dated, self::now());

        $report = dependencyAgeReport(count($dated), $findings);
        $this->assertStringStartsWith(
            "non vérifié — aucune des 5 dépendances n'a pu être datée ; 5 non datée(s)",
            $report
        );

        $markdown = dependencyAgeMarkdown($dated, self::now());
        $this->assertStringContainsString("**Aucune dépendance n'a pu être datée**", $markdown);
        $this->assertStringNotContainsString('aucune n\'est restée', $markdown);
    }

    /** The whole run: the notes file, the report line, and a console warning per finding. */
    public function testTheRunWritesTheNotesAndTheReportAndWarnsOnTheConsole(): void
    {
        $markdownFile = $this->root . '/age.md';
        $reportFile = $this->root . '/age.report';
        $bodies = self::registries();

        $console = dependencyAgeRun(
            $this->root,
            static fn (string $url): ?string => $bodies[$url] ?? null,
            self::now(),
            $markdownFile,
            $reportFile
        );

        $this->assertStringContainsString(
            'WARNING: acme/abandoned has published nothing since 2023-02-10 (1374 days)',
            $console
        );
        $this->assertStringContainsString(
            '5 dependencies dated, 1 older than 365 days, 0 not dated. Not blocking',
            $console
        );
        $this->assertStringContainsString(
            '## Dernières publications des dépendances',
            (string) file_get_contents($markdownFile)
        );
        $this->assertStringStartsWith('avertissement — 1 dépendance(s) sur 5', (string) file_get_contents($reportFile));

        @unlink($markdownFile);
        @unlink($reportFile);
    }

    /** A table that silently dated half the dependencies would read as a complete one. */
    public function testAMissingLockFileIsTheScriptsOwnFailure(): void
    {
        unlink($this->root . '/package-lock.json');

        $this->expectException(\RuntimeException::class);
        dependencyAgeCollect($this->root);
    }

    public function testEachSourceIsAskedAtItsOwnAddress(): void
    {
        $this->assertSame(
            'https://repo.packagist.org/p2/twig/twig.json',
            dependencyAgeSourceUrl('packagist', 'twig/twig')
        );
        $this->assertSame(
            'https://registry.npmjs.org/@playwright%2Ftest',
            dependencyAgeSourceUrl('npm', '@playwright/test')
        );
        $this->assertSame('https://registry.npmjs.org/vitest', dependencyAgeSourceUrl('npm', 'vitest'));
        $this->assertSame(
            'https://api.github.com/repos/twbs/bootstrap/releases/latest',
            dependencyAgeSourceUrl('github', 'twbs/bootstrap')
        );
    }

    /**
     * The last status line decides, as in scripts/check-deprecated-api.php;
     * none at all (`file://`) is not a refusal.
     */
    public function testTheResponseStatusIsReadFromTheLastHop(): void
    {
        $this->assertTrue(dependencyAgeIsSuccessfulStatus(['HTTP/1.1 302 Found', 'Location: x', 'HTTP/1.1 200 OK']));
        $this->assertFalse(dependencyAgeIsSuccessfulStatus(['HTTP/1.1 200 OK', 'HTTP/1.1 404 Not Found']));
        $this->assertFalse(dependencyAgeIsSuccessfulStatus(['HTTP/2 403']));
        $this->assertTrue(dependencyAgeIsSuccessfulStatus([]));
    }

    /** The command: arguments, the files it writes, the exit code — without a process. */
    public function testTheCommandRunsTheRootItIsGivenAndWritesTheNotesWhereAsked(): void
    {
        $markdownFile = $this->root . '/age.md';
        $reportFile = $this->root . '/age.report';
        $bodies = self::registries();

        $result = dependencyAgeCommand(
            ['check-dependency-age.php', '--markdown', $markdownFile, $this->root],
            static fn (string $url): ?string => $bodies[$url] ?? null,
            self::now(),
            $reportFile,
            '/a/root/that/is/not/the/one/given'
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', $result['stderr']);
        $this->assertStringContainsString('Not blocking (issue #841)', $result['stdout']);
        $this->assertFileExists($markdownFile);
        $this->assertFileExists($reportFile);

        @unlink($markdownFile);
        @unlink($reportFile);
    }

    /** A warning on what it finds, never on the network — but its own failure is a failure. */
    public function testTheCommandFailsOnlyOnItsOwnFailure(): void
    {
        $missingPath = dependencyAgeCommand(
            ['check-dependency-age.php', '--markdown'],
            static fn (string $url): ?string => null,
            self::now(),
            false,
            $this->root
        );
        $this->assertSame(1, $missingPath['exit']);
        $this->assertStringContainsString('--markdown requires a path', $missingPath['stderr']);

        $nothingAnswers = dependencyAgeCommand(
            ['check-dependency-age.php'],
            static fn (string $url): ?string => null,
            self::now(),
            false,
            $this->root
        );
        $this->assertSame(0, $nothingAnswers['exit'], 'every registry down is « non vérifié », not a refused release');
        $this->assertStringContainsString('5 not dated', $nothingAnswers['stdout']);

        unlink($this->root . '/composer.lock');
        $noLock = dependencyAgeCommand(
            ['check-dependency-age.php'],
            static fn (string $url): ?string => null,
            self::now(),
            false,
            $this->root
        );
        $this->assertSame(1, $noLock['exit']);
        $this->assertStringContainsString('check-dependency-age: missing', $noLock['stderr']);
    }

    /**
     * The real fetch, over `file://` the way scripts/check-deprecated-api.php's
     * tests exercise theirs: a body is returned, an absent or empty one is null.
     */
    public function testTheFetchReturnsABodyOrNull(): void
    {
        $file = $this->root . '/answer.json';
        file_put_contents($file, '{"time":{}}');

        $this->assertSame('{"time":{}}', dependencyAgeFetch('file://' . $file));
        $this->assertNull(dependencyAgeFetch('file://' . $this->root . '/nothing-here.json'));

        file_put_contents($file, '');
        $this->assertNull(dependencyAgeFetch('file://' . $file));
        @unlink($file);
    }

    /** What the gate dates is exactly what the inventory publishes: the same lock readers and vendored map. */
    public function testItDatesTheRealRepositorysInventory(): void
    {
        $rows = dependencyAgeCollect(dirname(__DIR__, 3));
        $sources = array_count_values(array_column($rows, 'source'));

        $this->assertGreaterThan(20, $sources['packagist'] ?? 0);
        $this->assertGreaterThan(0, $sources['npm'] ?? 0);
        $this->assertSame(count(INVENTORY_VENDORED_LIBRARIES), $sources['github'] ?? 0);
    }
}
