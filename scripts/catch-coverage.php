<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Which `catch` blocks of the product the test suite never runs (issue #449).
 *
 *   vendor/bin/phpunit --coverage-php /tmp/cov.php
 *   php scripts/catch-coverage.php /tmp/cov.php [--list] [--json]
 *
 * Every `catch` under core/ and modules/ is found with PHP's own tokenizer —
 * never a regular expression, which a string or a comment holding the word
 * would fool — and judged against the line coverage of that run:
 *
 *   executed     the first executable line of its body ran at least once;
 *   never        it did not;
 *   never-empty  the body holds no statement at all (a comment, or nothing),
 *                so there is no first line to read: the `catch` line itself is
 *                judged instead, which PCOV records. These are the ones that
 *                swallow an error on purpose, and the reason they are counted
 *                apart — the measure of issue #449 as first written skipped
 *                them;
 *   unreachable  the body carries the marker below, with a reason: a branch
 *                judged impossible to reach honestly (a TOCTOU window between
 *                two reads of the same request, say). Counted apart, never as
 *                executed, so the decision lives next to the code and the
 *                measure still shows it;
 *   unknown      the report cannot say (a line the coverage driver did not
 *                record). Listed, never folded into another column.
 *
 * THE MARKER is one comment inside the body of the `catch`:
 *
 *   } catch (FooException) {
 *       // @catch-unreachable Re-read of the row the request just checked.
 *       ...
 *   }
 *
 * A marker with no reason after it is refused: it is reported as `never`, and
 * listed as an invalid marker, because « unreachable » with no why is exactly
 * the undocumented decision the marker exists to replace.
 *
 * Two traps in reading the report, both paid for once already (docs/chantiers/
 * CHANTIER-revue-des-tests.md, iterations 9 and 10): the payload's
 * `codeCoverage` IS the ProcessedCodeCoverageData, with no getData(); and its
 * files are keyed by path RELATIVE to `basePath`. A lookup by absolute path
 * finds nothing and reports almost every branch as never executed.
 *
 * Exit code 0 whatever the result: this is a measure, not a gate (the
 * maintainer set a per-file ratchet aside, issue #449, 2026-09-28).
 */

/** The comment that marks a `catch` as unreachable, followed by its reason. */
const CATCH_COVERAGE_MARKER = '@catch-unreachable';

// Guarded the same way scripts/sonar-evidence.php is: the test suite defines
// CATCH_COVERAGE_TEST and includes this file for its functions, and the
// command must not run when it does.
if (!defined('CATCH_COVERAGE_TEST')) {
    exit(catchCoverageMain($argv));
}

/**
 * @param list<string> $argv
 */
function catchCoverageMain(array $argv): int
{
    $root = dirname(__DIR__);
    $args = array_slice($argv, 1);
    $list = in_array('--list', $args, true);
    $json = in_array('--json', $args, true);
    $paths = array_values(array_filter($args, static fn(string $a): bool => !str_starts_with($a, '--')));
    if (count($paths) !== 1) {
        fwrite(STDERR, "usage: php scripts/catch-coverage.php <coverage.php> [--list] [--json]\n");

        return 2;
    }

    require_once $root . '/vendor/autoload.php';
    $coverage = catchCoverageLoadReport($paths[0], $root);

    $catches = [];
    foreach (catchCoverageSourceFiles($root) as $relative) {
        $source = (string) file_get_contents($root . '/' . $relative);
        foreach (catchCoverageScan($source) as $catch) {
            $catches[] = ['file' => $relative] + $catch
                + ['verdict' => catchCoverageJudge($catch, $coverage[$relative] ?? null)];
        }
    }

    $summary = catchCoverageSummarise($catches);
    fwrite(STDOUT, $json
        ? json_encode(['summary' => $summary, 'catches' => $catches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        : catchCoverageRender($summary, $list ? $catches : []));

    return 0;
}

/**
 * The line coverage of a `--coverage-php` report, keyed by path relative to
 * the repository root.
 *
 * @return array<string, array<int, array<int, string>|null>>
 */
function catchCoverageLoadReport(string $path, string $root): array
{
    $payload = include $path;
    if (is_object($payload) && method_exists($payload, 'getData')) {
        $data = $payload->getData();
        $basePath = '';
    } elseif (is_array($payload) && isset($payload['codeCoverage']) && is_object($payload['codeCoverage'])) {
        $data = $payload['codeCoverage'];
        $basePath = (string) ($payload['basePath'] ?? '');
    } else {
        throw new RuntimeException("catch-coverage: {$path} is not a --coverage-php report.");
    }

    $byFile = [];
    foreach ($data->lineCoverage() as $file => $lines) {
        $byFile[catchCoverageRelative((string) $file, $basePath, $root)] = $lines;
    }

    return $byFile;
}

/** A report key, absolute or relative to $basePath, as a path relative to $root. */
function catchCoverageRelative(string $file, string $basePath, string $root): string
{
    if ($basePath !== '' && !str_starts_with($file, '/')) {
        $file = rtrim($basePath, '/') . '/' . $file;
    }
    $prefix = rtrim($root, '/') . '/';

    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}

/**
 * Every PHP file of the product: core/ and modules/, relative to $root.
 *
 * @return list<string>
 */
function catchCoverageSourceFiles(string $root): array
{
    $files = [];
    foreach (['core', 'modules'] as $top) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $top, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = substr($entry->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * Every `catch` of one PHP source.
 *
 * @return list<array{line: int, bodyLines: list<int>, empty: bool, marker: ?string, sharedLine: bool}>
 *   line       the line of the `catch` keyword;
 *   bodyLines  the lines on which a statement token of the body starts, in
 *              order (comments and whitespace excluded) — empty for an empty
 *              body;
 *   empty      whether the body holds no statement;
 *   marker     the reason given after the marker, '' for a marker without
 *              one, null when there is no marker;
 *   sharedLine whether code other than the closing `}` precedes `catch` on its
 *              line (`try { a(); } catch (X) {}`): line coverage cannot then
 *              tell the `try` body's execution from the `catch`'s, so that line
 *              proves nothing about the `catch`
 */
function catchCoverageScan(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);
    $catches = [];

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->id !== T_CATCH) {
            continue;
        }
        // Anything but closing braces — the try's, and those of blocks it
        // ends with, none of which runs — before `catch` on its own line makes
        // that line shared.
        $sharedLine = false;
        for ($k = $i - 1; $k >= 0 && $tokens[$k]->line === $tokens[$i]->line; $k--) {
            if ($tokens[$k]->id !== T_WHITESPACE && $tokens[$k]->text !== '}') {
                $sharedLine = true;
                break;
            }
        }

        // The body opens at the first `{` after the caught types.
        $open = $i + 1;
        while ($open < $count && $tokens[$open]->text !== '{') {
            $open++;
        }
        $depth = 0;
        $bodyLines = [];
        $marker = null;
        for ($j = $open; $j < $count; $j++) {
            $token = $tokens[$j];
            // `{` — including the `{` of "{$var}", whose token text is `{` too — and
            // the `${` of "${var}" each open a level that a `}` closes.
            if ($token->text === '{' || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($j === $open) {
                    continue;
                }
            } elseif ($token->text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                $pattern = '/' . preg_quote(CATCH_COVERAGE_MARKER, '/') . '(.*)/';
                // Only at the body's own depth: a marker inside a nested try or
                // catch names that block, never the one enclosing it.
                if ($marker === null && $depth === 1 && preg_match($pattern, $token->text, $m) === 1) {
                    $marker = trim(rtrim(trim($m[1]), '*/'));
                }
                continue;
            }
            if ($token->id === T_WHITESPACE) {
                continue;
            }
            if ($bodyLines === [] || end($bodyLines) !== $token->line) {
                $bodyLines[] = $token->line;
            }
        }

        $catches[] = [
            'line' => $tokens[$i]->line,
            'bodyLines' => array_values(array_unique($bodyLines)),
            'empty' => $bodyLines === [],
            'marker' => $marker,
            'sharedLine' => $sharedLine,
        ];
        $i = $open;
    }

    return $catches;
}

/**
 * The verdict on one `catch`, given its file's line coverage — null when the
 * file is absent from the report altogether, which phpunit.xml's <source>
 * makes mean « never loaded by any test ».
 *
 * A line shared with code before `catch` is never taken as evidence (see
 * catchCoverageScan()): an empty body there is `unknown`, and a body is judged
 * on its other lines only.
 *
 * @param array{line: int, bodyLines: list<int>, empty: bool, marker: ?string, sharedLine?: bool} $catch
 * @param array<int, array<int, string>|null>|null $lines
 * @return 'executed'|'never'|'never-empty'|'unreachable'|'unknown'
 */
function catchCoverageJudge(array $catch, ?array $lines): string
{
    if ($catch['marker'] !== null && $catch['marker'] !== '') {
        return 'unreachable';
    }
    $empty = $catch['empty'];
    if ($lines === null) {
        return $empty ? 'never-empty' : 'never';
    }

    // The first line of the body the driver records as executable; for an
    // empty body, the `catch` line itself.
    $candidates = $empty ? [$catch['line']] : $catch['bodyLines'];
    if ($catch['sharedLine'] ?? false) {
        $candidates = array_values(array_diff($candidates, [$catch['line']]));
    }
    foreach ($candidates as $line) {
        if (array_key_exists($line, $lines) && is_array($lines[$line])) {
            if ($lines[$line] !== []) {
                return 'executed';
            }

            return $empty ? 'never-empty' : 'never';
        }
    }

    return 'unknown';
}

/**
 * @param list<array{file: string, line: int, verdict: string, marker: ?string}> $catches
 * @return array{total: int, verdicts: array<string, int>, invalidMarkers: int,
 *   byDomain: array<string, array<string, int>>, byFile: array<string, int>}
 */
function catchCoverageSummarise(array $catches): array
{
    $verdicts = ['executed' => 0, 'never' => 0, 'never-empty' => 0, 'unreachable' => 0, 'unknown' => 0];
    $byDomain = [];
    $byFile = [];
    $invalid = 0;
    foreach ($catches as $catch) {
        $verdict = $catch['verdict'];
        $verdicts[$verdict]++;
        $domain = catchCoverageDomain($catch['file']);
        $byDomain[$domain] ??= ['total' => 0, 'never' => 0, 'never-empty' => 0, 'unreachable' => 0];
        $byDomain[$domain]['total']++;
        if (isset($byDomain[$domain][$verdict])) {
            $byDomain[$domain][$verdict]++;
        }
        if ($verdict === 'never' || $verdict === 'never-empty') {
            $byFile[$catch['file']] = ($byFile[$catch['file']] ?? 0) + 1;
        }
        if ($catch['marker'] === '') {
            $invalid++;
        }
    }
    uasort($byDomain, static fn(array $a, array $b): int
        => ($b['never'] + $b['never-empty']) <=> ($a['never'] + $a['never-empty']));
    arsort($byFile);

    return [
        'total' => count($catches),
        'verdicts' => $verdicts,
        'invalidMarkers' => $invalid,
        'byDomain' => $byDomain,
        'byFile' => $byFile,
    ];
}

/** The lot a file belongs to: `core/<Dir>` or `modules/<name>`. */
function catchCoverageDomain(string $file): string
{
    $parts = explode('/', $file);

    return count($parts) >= 3 ? $parts[0] . '/' . $parts[1] : $parts[0];
}

/**
 * The report for a human.
 *
 * @param array{total: int, verdicts: array<string, int>, invalidMarkers: int,
 *   byDomain: array<string, array<string, int>>, byFile: array<string, int>} $summary
 * @param list<array{file: string, line: int, verdict: string, marker: ?string}> $catches listed when non-empty
 */
function catchCoverageRender(array $summary, array $catches): string
{
    $v = $summary['verdicts'];
    $never = $v['never'] + $v['never-empty'];
    $percent = $summary['total'] > 0 ? round(100 * $never / $summary['total'], 1) : 0.0;
    $out = [];
    $out[] = "catch blocks under core/ and modules/: {$summary['total']}";
    $out[] = "  executed:                 {$v['executed']}";
    $out[] = "  never executed:           {$never} ({$percent} %)";
    $out[] = "    with a body:            {$v['never']}";
    $out[] = "    empty body:             {$v['never-empty']}";
    $out[] = "  marked unreachable:       {$v['unreachable']}";
    $out[] = "  unknown:                  {$v['unknown']}";
    if ($summary['invalidMarkers'] > 0) {
        $out[] = "  invalid markers (no reason, counted as never): {$summary['invalidMarkers']}";
    }
    $out[] = '';
    $out[] = 'by domain (never / of which empty / marked / total):';
    foreach ($summary['byDomain'] as $domain => $row) {
        $out[] = sprintf(
            '  %-32s %4d %4d %4d %5d',
            $domain,
            $row['never'] + $row['never-empty'],
            $row['never-empty'],
            $row['unreachable'],
            $row['total']
        );
    }
    if ($catches !== []) {
        $out[] = '';
        $out[] = 'never executed:';
        foreach ($catches as $catch) {
            if (in_array($catch['verdict'], ['never', 'never-empty', 'unknown'], true)) {
                $out[] = sprintf('  %s:%d %s', $catch['file'], $catch['line'], $catch['verdict']);
            }
        }
    }

    return implode("\n", $out) . "\n";
}
