<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * The dependency age release gate (issue #841): when did each dependency
 * last publish a release, and which ones have published nothing for more
 * than a year?
 *
 *   php scripts/check-dependency-age.php [--markdown <path>] [<repository root>]
 *
 * **What it answers that the other dependency gates do not.** The Security
 * gate asks whether a version is KNOWN to be vulnerable, and the Dependency
 * freshness gate whether a newer version EXISTS. Neither notices a
 * dependency whose maintainers have simply stopped: no advisory is ever
 * filed against code nobody reads any more, and « up to date » is trivially
 * true of a package that publishes nothing. That is a long-term security
 * risk of its own — the day a flaw is found, nobody ships the fix — and the
 * date of the last release is the cheapest signal of it.
 *
 * **What it dates, and where it asks.** Exactly the dependencies the release
 * notes' inventory publishes (scripts/dependency-inventory.php, whose
 * functions this file reuses so the two lists cannot drift):
 *
 *  - every PHP package in composer.lock, production and development, dated
 *    by Packagist (`repo.packagist.org/p2/<name>.json`, stable releases);
 *  - every JavaScript package package.json names, dated by the npm
 *    registry (`registry.npmjs.org/<name>`);
 *  - every vendored front-end library, dated by its upstream's latest
 *    GitHub release (`api.github.com/repos/<upstream>/releases/latest`).
 *
 * « Last release » is the most recent publication of ANY version, not of
 * the version shipped: a project that still ships fixes to an older branch
 * is maintained, and the version gap itself is the freshness gate's job.
 *
 * **A warning, never a refusal**, as the issue asks — and therefore not fail
 * closed either: a source that does not answer leaves that package « non
 * vérifié » in the notes and in the report line, rather than stopping a
 * release over an unreachable registry. Refusing would buy nothing a reader
 * of the notes does not already get: the table names every package, its
 * date, and the ones nobody could date. Exit code 1 is kept for this
 * script's own failure — a lock file missing or unreadable — since a table
 * that silently dates half the dependencies would read as a complete one.
 *
 * Written for scripts/release.sh, which runs it as a gate, reads the line it
 * leaves in GATE_REPORT_FILE for « Vérifications effectuées », and appends
 * the Markdown written to --markdown to the release notes, beside the
 * inventory. Tested by tests/Core/System/DependencyAgeCheckTest.php, which
 * defines DEPENDENCY_AGE_CHECK_TEST and includes this file for its functions.
 */

if (!defined('DEPENDENCY_INVENTORY_LIBRARY')) {
    define('DEPENDENCY_INVENTORY_LIBRARY', true);
}
require_once __DIR__ . '/dependency-inventory.php';

/** A dependency that has published nothing for longer than this is reported. */
const DEPENDENCY_AGE_STALE_DAYS = 365;

/** Per request. A registry slower than this is « non vérifié », not a hung release. */
const DEPENDENCY_AGE_TIMEOUT = 20;

/**
 * Every dependency to date, with where to ask about it.
 *
 * @return list<array{surface: string, name: string, version: string, source: string, key: string}>
 * @throws RuntimeException when a lock file is missing or unreadable
 */
function dependencyAgeCollect(string $root): array
{
    $composerLock = inventoryReadJson($root . '/composer.lock');
    $packageJson = inventoryReadJson($root . '/package.json');
    $packageLock = inventoryReadJson($root . '/package-lock.json');

    $rows = [];
    foreach (['packages' => 'PHP — production', 'packages-dev' => 'PHP — développement'] as $section => $surface) {
        foreach (inventoryComposerPackages($composerLock, $section) as $name => $package) {
            $rows[] = [
                'surface' => $surface,
                'name' => $name,
                'version' => $package['version'],
                'source' => 'packagist',
                'key' => $name,
            ];
        }
    }

    foreach (inventoryNpmPackages($packageJson, $packageLock) as $name => $package) {
        $rows[] = [
            'surface' => 'JavaScript — développement',
            'name' => $name,
            'version' => $package['version'],
            'source' => 'npm',
            'key' => $name,
        ];
    }

    $vendorDir = $root . '/public/assets/vendor';
    foreach (INVENTORY_VENDORED_LIBRARIES as $directory => $library) {
        // Same rule as the inventory: a library whose directory is gone
        // shipped nothing, so there is nothing to date.
        if (!is_dir($vendorDir . '/' . $directory)) {
            continue;
        }
        $rows[] = [
            'surface' => 'Front-end vendorisé',
            'name' => $library['name'],
            'version' => inventoryBannerVersion($vendorDir . '/' . $library['file'], $library['pattern']),
            'source' => 'github',
            'key' => $library['upstream'],
        ];
    }

    return $rows;
}

/** The URL that dates one dependency. */
function dependencyAgeSourceUrl(string $source, string $key): string
{
    return match ($source) {
        'packagist' => 'https://repo.packagist.org/p2/' . $key . '.json',
        // A scoped name keeps its `@` and has its `/` encoded: the registry
        // reads `@scope%2Fname` as one package.
        'npm' => 'https://registry.npmjs.org/' . str_replace('/', '%2F', $key),
        'github' => 'https://api.github.com/repos/' . $key . '/releases/latest',
        default => throw new InvalidArgumentException('unknown source ' . $source),
    };
}

/**
 * The date of the most recent release one source's document records, or
 * null when the document does not say — never a guess.
 */
function dependencyAgeLatestRelease(string $source, string $key, ?string $body): ?DateTimeImmutable
{
    if ($body === null) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }

    $stamps = match ($source) {
        // p2 lists the stable releases of one package, newest first; every
        // entry carries its own `time`, and the newest of them is taken
        // rather than the first, so a backport published after the latest
        // major still counts as the project being alive.
        'packagist' => array_column(is_array($data['packages'][$key] ?? null) ? $data['packages'][$key] : [], 'time'),
        // `time` maps every version to its publication, beside two
        // bookkeeping keys that are not releases: `created` is the first
        // publication, and `modified` moves whenever the metadata does.
        'npm' => array_values(array_diff_key(
            is_array($data['time'] ?? null) ? $data['time'] : [],
            ['created' => true, 'modified' => true]
        )),
        'github' => [$data['published_at'] ?? null],
        default => [],
    };

    $latest = null;
    foreach ($stamps as $stamp) {
        if (!is_string($stamp) || $stamp === '') {
            continue;
        }
        try {
            $date = new DateTimeImmutable($stamp);
        } catch (Exception) {
            continue;
        }
        if ($latest === null || $date > $latest) {
            $latest = $date;
        }
    }

    return $latest;
}

/**
 * Dates every row.
 *
 * @param list<array{surface: string, name: string, version: string, source: string, key: string}> $rows
 * @param callable(string): ?string $fetch the body at a URL, or null
 * @return list<array{
 *     surface: string, name: string, version: string, source: string, key: string, released: ?DateTimeImmutable
 * }>
 */
function dependencyAgeDate(array $rows, callable $fetch): array
{
    $dated = [];
    foreach ($rows as $row) {
        $row['released'] = dependencyAgeLatestRelease(
            $row['source'],
            $row['key'],
            $fetch(dependencyAgeSourceUrl($row['source'], $row['key']))
        );
        $dated[] = $row;
    }

    return $dated;
}

/** Whole days between a release and now — never negative. */
function dependencyAgeDays(DateTimeImmutable $released, DateTimeImmutable $now): int
{
    return max(0, (int) floor(($now->getTimestamp() - $released->getTimestamp()) / 86400));
}

/**
 * What the dating found: the rows older than the threshold, and the ones
 * nobody could date.
 *
 * @param list<array{name: string, released: ?DateTimeImmutable}> $dated
 * @return array{stale: list<string>, unverified: list<string>}
 */
function dependencyAgeFindings(array $dated, DateTimeImmutable $now): array
{
    $stale = [];
    $unverified = [];
    foreach ($dated as $row) {
        if ($row['released'] === null) {
            $unverified[] = $row['name'];
        } elseif (dependencyAgeDays($row['released'], $now) > DEPENDENCY_AGE_STALE_DAYS) {
            $stale[] = $row['name'];
        }
    }

    return ['stale' => $stale, 'unverified' => $unverified];
}

/**
 * The section appended to the release notes, in French.
 *
 * @param list<array{surface: string, name: string, version: string, released: ?DateTimeImmutable}> $dated
 */
function dependencyAgeMarkdown(array $dated, DateTimeImmutable $now): string
{
    $findings = dependencyAgeFindings($dated, $now);

    $out = "## Dernières publications des dépendances\n\n";
    $out .= 'Pour chaque dépendance, la date de sa publication la plus récente — toutes versions confondues — '
        . "lue chez Packagist, sur le registre npm ou dans les releases GitHub de la bibliothèque. Une dépendance "
        . "qui n'a rien publié depuis plus d'un an est marquée ⚠️ : sans vulnérabilité connue, elle est peut-être "
        . "abandonnée, et une faille qu'on y trouverait demain n'aurait personne pour la corriger. Généré par "
        . "`scripts/check-dependency-age.php`.\n\n";

    if ($findings['stale'] === []) {
        $out .= "**Aucune dépendance sans publication depuis plus d'un an.**\n\n";
    } else {
        $out .= '**Sans publication depuis plus d\'un an (' . count($findings['stale']) . ') :** `'
            . implode('`, `', $findings['stale']) . "`\n\n";
    }
    if ($findings['unverified'] !== []) {
        $out .= '**Non datées (' . count($findings['unverified']) . ') :** `'
            . implode('`, `', $findings['unverified'])
            . "` — la source n'a pas répondu ou ne donnait pas de date.\n\n";
    }

    $surface = null;
    foreach ($dated as $row) {
        if ($row['surface'] !== $surface) {
            $out .= $surface === null ? '' : "\n";
            $surface = $row['surface'];
            $out .= '### ' . $surface . "\n\n"
                . "| Paquet | Version livrée | Dernière publication | Il y a |\n|---|---|---|---|\n";
        }
        if ($row['released'] === null) {
            $out .= '| `' . $row['name'] . '` | ' . $row['version'] . " | **non vérifiée** | — |\n";
            continue;
        }
        $days = dependencyAgeDays($row['released'], $now);
        $out .= '| `' . $row['name'] . '` | ' . $row['version'] . ' | ' . $row['released']->format('Y-m-d') . ' | '
            . ($days > DEPENDENCY_AGE_STALE_DAYS ? '⚠️ ' : '') . $days . " j |\n";
    }

    return $out;
}

/**
 * The line « Vérifications effectuées » carries.
 *
 * @param array{stale: list<string>, unverified: list<string>} $findings
 */
function dependencyAgeReport(int $count, array $findings): string
{
    $line = $findings['stale'] === []
        ? 'vérifié — aucune des ' . $count . " dépendances n'est restée plus d'un an sans publication"
        : 'avertissement — ' . count($findings['stale']) . ' dépendance(s) sur ' . $count
            . " sans publication depuis plus d'un an : `" . implode('`, `', $findings['stale']) . '`';

    if ($findings['unverified'] !== []) {
        $line .= ' ; ' . count($findings['unverified']) . ' non datée(s), à vérifier à la main : `'
            . implode('`, `', $findings['unverified']) . '`';
    }

    return $line . ' (détail dans « Dernières publications des dépendances »).';
}

/**
 * The console's own account, in English like the rest of the release
 * script's output: one line per finding, so a releaser reads what to look at.
 *
 * @param list<array{name: string, released: ?DateTimeImmutable}> $dated
 */
function dependencyAgeConsole(array $dated, DateTimeImmutable $now): string
{
    $out = '';
    foreach ($dated as $row) {
        if ($row['released'] === null) {
            $out .= 'WARNING: could not date ' . $row['name'] . " — its source did not answer or gave no date.\n";
        } elseif (($days = dependencyAgeDays($row['released'], $now)) > DEPENDENCY_AGE_STALE_DAYS) {
            $out .= 'WARNING: ' . $row['name'] . ' has published nothing since ' . $row['released']->format('Y-m-d')
                . ' (' . $days . " days) — is it still maintained?\n";
        }
    }

    $findings = dependencyAgeFindings($dated, $now);

    return $out . sprintf(
        "Dependency age: %d dependencies dated, %d older than %d days, %d not dated. Not blocking (issue #841).\n",
        count($dated) - count($findings['unverified']),
        count($findings['stale']),
        DEPENDENCY_AGE_STALE_DAYS,
        count($findings['unverified'])
    );
}

/**
 * The body at a URL, or null when it cannot be had or the server refused.
 */
function dependencyAgeFetch(string $url): ?string
{
    $headers = "User-Agent: ScoutMagic-release-gate\r\nAccept: application/json\r\n";
    $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN');
    if (is_string($token) && $token !== '' && str_starts_with($url, 'https://api.github.com/')) {
        $headers .= 'Authorization: Bearer ' . $token . "\r\n";
    }

    $context = stream_context_create([
        'http' => ['timeout' => DEPENDENCY_AGE_TIMEOUT, 'ignore_errors' => true, 'header' => $headers],
    ]);

    // Pre-initialised for the reason scripts/check-deprecated-api.php gives:
    // a `file://` read leaves it untouched.
    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '' || !dependencyAgeIsSuccessfulStatus($http_response_header)) {
        return null;
    }

    return $body;
}

/**
 * Whether the LAST status line — the response the body belongs to, after
 * any redirect — is a 2xx. No status line at all (`file://`) is not a
 * failure. Same rule as scripts/check-deprecated-api.php.
 *
 * @param list<string> $headers
 */
function dependencyAgeIsSuccessfulStatus(array $headers): bool
{
    $status = null;
    foreach ($headers as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return $status === null || ($status >= 200 && $status < 300);
}

/**
 * Everything but the side effects: dates the repository at $root through
 * $fetch, writes the notes section and the report line where asked, and
 * returns what the console should say.
 *
 * @param callable(string): ?string $fetch
 * @throws RuntimeException when a lock file is missing or unreadable
 */
function dependencyAgeRun(
    string $root,
    callable $fetch,
    DateTimeImmutable $now,
    ?string $markdownFile,
    string|false $reportFile
): string {
    $dated = dependencyAgeDate(dependencyAgeCollect($root), $fetch);

    if ($markdownFile !== null) {
        file_put_contents($markdownFile, dependencyAgeMarkdown($dated, $now));
    }
    if ($reportFile !== false && $reportFile !== '') {
        file_put_contents($reportFile, dependencyAgeReport(count($dated), dependencyAgeFindings($dated, $now)));
    }

    return dependencyAgeConsole($dated, $now);
}

if (!defined('DEPENDENCY_AGE_CHECK_TEST')) {
    dependencyAgeMain($argv);
}

/**
 * The wiring: arguments, the real network, the real clock, the exit.
 *
 * @param list<string> $argv
 */
function dependencyAgeMain(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "check-dependency-age.php is a CLI script.\n");
        exit(1);
    }

    $markdownFile = null;
    $root = dirname(__DIR__);
    $arguments = array_slice($argv, 1);
    while ($arguments !== []) {
        $argument = array_shift($arguments);
        if ($argument === '--markdown') {
            $markdownFile = array_shift($arguments);
            if ($markdownFile === null || $markdownFile === '') {
                fwrite(STDERR, "check-dependency-age: --markdown requires a path.\n");
                exit(1);
            }
            continue;
        }
        $root = $argument;
    }

    try {
        echo dependencyAgeRun(
            $root,
            static fn (string $url): ?string => dependencyAgeFetch($url),
            new DateTimeImmutable(),
            $markdownFile,
            getenv('GATE_REPORT_FILE')
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'check-dependency-age: ' . $e->getMessage() . "\n");
        exit(1);
    }

    exit(0);
}
