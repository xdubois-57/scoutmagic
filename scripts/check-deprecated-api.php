#!/usr/bin/env php
<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Is the deprecated browser API the editors are built on still supported?
 *
 *   php scripts/check-deprecated-api.php
 *
 * The release gate the maintainer asked for on issue #379: « Ajoute une
 * vérification à faire quand on demande une release pour s'assurer dans la
 * documentation des navigateurs ou sur caniuse.com que c'est encore
 * supporté correctement. »
 *
 * WHAT IT ANSWERS THAT NOTHING ELSE DOES
 * ---------------------------------------------------------------------
 * `document.execCommand` is deprecated with no standard replacement, and
 * six files under public/assets/js/ depend on it (issue #379). Two layers
 * watch that, and they answer different questions:
 *
 *  - tests/e2e/specs/rich-text-commands.spec.js asks whether the commands
 *    still WORK. It is exact and blocking, and it only ever knows about
 *    ONE engine: the Chromium the browser job runs. It also cannot know
 *    anything until a removal has already shipped and broken the editor.
 *  - This gate asks whether any engine has REMOVED it, or announced doing
 *    so, from the published compatibility data — including the engines CI
 *    does not run. It is the early warning, and it is the only thing here
 *    that can speak about Firefox or Safari at all.
 *
 * WHY MDN RATHER THAN caniuse.com
 * ---------------------------------------------------------------------
 * The maintainer named « la documentation des navigateurs ou caniuse.com ».
 * caniuse has no API; what it renders for this feature is MDN's
 * browser-compat-data, which is published as plain JSON. So this reads the
 * same data caniuse displays, from the source that is machine-readable.
 * The human page stays in the report line, because a releaser reading
 * « à vérifier » needs somewhere to go.
 *
 * WHY IT DOES NOT FAIL CLOSED
 * ---------------------------------------------------------------------
 * Every other gate in scripts/release.sh aborts on any error, and says so.
 * This one reports UNVERIFIED instead — exit code 0 with a report line
 * saying the check did not happen and naming the page to read by hand.
 *
 * That is deliberate, and it is not a softer standard. The other gates
 * answer questions about THIS release: its tests, its dependencies, its
 * findings. This one answers a question about a removal that has not
 * happened anywhere yet, and whose arrival CI would catch in the browser
 * job on the day it shipped. Blocking a release because a raw.github URL
 * 502'd would buy nothing and cost the release; the honest failure mode
 * for an early warning is to say it did not run.
 *
 * A removal or a deprecation ANNOUNCEMENT found in the data does block —
 * exit code 2 — because at that point the question stops being about the
 * future.
 */

/** Where the published compatibility data lives. */
const DEPRECATED_API_SOURCE =
    'https://raw.githubusercontent.com/mdn/browser-compat-data/main/api/Document.json';

/** The page a human reads when this gate could not read the data. */
const DEPRECATED_API_HUMAN_PAGE = 'https://caniuse.com/document-execcommand';

/** How long to wait for the data before calling it unverified, in seconds. */
const DEPRECATED_API_TIMEOUT = 20;

/** The engines whose verdict matters — the ones a scout unit's browser is. */
const DEPRECATED_API_ENGINES = [
    'chrome',
    'chrome_android',
    'edge',
    'firefox',
    'firefox_android',
    'safari',
    'safari_ios',
];

/**
 * Every engine in DEPRECATED_API_ENGINES that has removed the API,
 * with the version it went in.
 *
 * Three shapes have to be understood, and all three are in the real data —
 * measured against it rather than assumed:
 *
 *   "chrome":  {"version_added": "1"}                  a single statement
 *   "firefox": [{"version_added": "69", …}, {…}]        several, NEWEST FIRST
 *   "chrome_android": "mirror"                          "same as the parent"
 *
 * A `"mirror"` string carries no verdict of its own: it defers to the
 * desktop entry, which is inspected in its own right. Skipping it is not
 * a gap — reading it as data would be, since it has none.
 *
 * ONLY THE FIRST STATEMENT SAYS WHAT IS TRUE NOW, and that is the whole
 * subtlety of this file. A `version_removed` further down the list is
 * history: it says when an EARLIER form of the support ended, not that the
 * API is gone. The first version of this function read any
 * `version_removed` anywhere and reported Firefox as having removed
 * `document.execCommand` in version 69 — on data that says the opposite.
 * Firefox's real entry, today:
 *
 *   [ {"version_added": "69", "notes": […]},
 *     {"version_added": "1", "version_removed": "69",
 *      "partial_implementation": true, "notes": "Only … HTMLDocument …"} ]
 *
 * Read newest-first, that is "fully supported since 69, and before that
 * only partially" — the 69 in `version_removed` is when the PARTIAL
 * implementation ended. Read indiscriminately, it is a release blocked for
 * a removal that never happened.
 *
 * @param array<string, mixed> $support the `__compat.support` object
 * @return array<string, string> engine => the version that removed it
 */
function deprecatedApiRemovals(array $support): array
{
    $removals = [];

    foreach (DEPRECATED_API_ENGINES as $engine) {
        $statements = $support[$engine] ?? null;
        if (!is_array($statements)) {
            // Absent, or the "mirror" string: no verdict of its own.
            continue;
        }
        // One statement is an associative array; several are a list of
        // them, newest first — so the current state is the first either way.
        $current = array_is_list($statements) ? ($statements[0] ?? null) : $statements;
        if (!is_array($current)) {
            continue;
        }

        // The key is absent while supported, and `true` or a version string
        // once it is gone. `false` is also a legal way to say "still there",
        // and `?? false` folds an explicit null into the same answer.
        $removed = $current['version_removed'] ?? false;
        if ($removed === false) {
            continue;
        }
        $removals[$engine] = $removed === true ? 'an unstated version' : (string) $removed;
    }

    return $removals;
}

/**
 * The gate's verdict on a decoded api/Document.json.
 *
 * @param mixed $data whatever json_decode returned
 * @return array{status: 'ok'|'blocked'|'unverified', message: string, report: string}
 */
function deprecatedApiVerdict(mixed $data): array
{
    if (!is_array($data)) {
        return deprecatedApiUnverified('the compatibility data is not a JSON object');
    }

    $compat = $data['api']['Document']['execCommand']['__compat'] ?? null;
    if (!is_array($compat) || !is_array($compat['support'] ?? null)) {
        // The shape moved. That is a real possibility for an upstream file
        // and it is precisely why this case is "unverified" rather than
        // "supported": saying nothing found means nothing was looked at.
        return deprecatedApiUnverified(
            'api.Document.execCommand.__compat.support is not where it was in the data'
        );
    }

    $removals = deprecatedApiRemovals($compat['support']);

    if ($removals !== []) {
        $named = [];
        foreach ($removals as $engine => $version) {
            $named[] = "{$engine} ({$version})";
        }
        $list = implode(', ', $named);

        return [
            'status' => 'blocked',
            'message' => "document.execCommand has been REMOVED by: {$list}.\n"
                . "Six files under public/assets/js/ depend on it (issue #379), and the editors\n"
                . "stop working silently in that engine. Option 1 of that issue — rebuilding the\n"
                . "toolbar on Selection/Range — is now due, and this release should not go out\n"
                . "claiming a working editor. See " . DEPRECATED_API_HUMAN_PAGE . "\n",
            'report' => "**bloquant** — `document.execCommand` a été retiré par {$list}. "
                . 'Les éditeurs de texte riche en dépendent (issue #379).',
        ];
    }

    return [
        'status' => 'ok',
        'message' => "Deprecated browser API gate OK: document.execCommand is still supported by "
            . count(DEPRECATED_API_ENGINES) . " engines in MDN's published compatibility data "
            . "(deprecated, as it has been for years, but removed nowhere).\n",
        'report' => 'vérifié — `document.execCommand` (dont dépendent les éditeurs de texte riche, '
            . 'issue #379) est déprécié mais retiré par aucun moteur, d\'après les données de '
            . 'compatibilité publiées par MDN — celles que rend ' . DEPRECATED_API_HUMAN_PAGE . '.',
    ];
}

/**
 * @return array{status: 'unverified', message: string, report: string}
 */
function deprecatedApiUnverified(string $because): array
{
    return [
        'status' => 'unverified',
        'message' => "WARNING: the deprecated browser API check did not run — {$because}.\n"
            . 'Check by hand that document.execCommand is still supported: '
            . DEPRECATED_API_HUMAN_PAGE . "\n"
            . "Not blocking: this gate is an early warning about a removal that has happened\n"
            . "nowhere yet, and the browser job would catch a real one (see the script header).\n",
        'report' => 'non vérifié automatiquement (' . $because . ') — à vérifier à la main sur '
            . DEPRECATED_API_HUMAN_PAGE . '.',
    ];
}

/**
 * Fetches the compatibility data, or null when it cannot be had.
 */
function deprecatedApiFetch(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => DEPRECATED_API_TIMEOUT,
            'ignore_errors' => true,
            'header' => "User-Agent: ScoutMagic-release-gate\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return null;
    }

    // No status-line inspection, on purpose. `ignore_errors` means an error
    // response arrives as a body — but an error body is not this document:
    // raw.githubusercontent answers a missing path with the plain text
    // "404: Not Found", which fails to decode as JSON and lands in
    // deprecatedApiVerdict()'s "the shape has moved" branch, reported as
    // unverified. Reading $http_response_header to reach the same verdict
    // would be a second mechanism for one outcome.
    return $body;
}

// Guarded the same way scripts/dependency-inventory.php is: the test suite
// defines DEPRECATED_API_CHECK_TEST and includes this file for its
// functions, and the command must not run when it does.
if (!defined('DEPRECATED_API_CHECK_TEST')) {
    deprecatedApiMain();
}

/**
 * Every side effect this file has: one HTTP GET, writes to stdout/stderr,
 * exits.
 *
 * Exit codes: 0 supported or unverified, 2 removed somewhere.
 */
function deprecatedApiMain(): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "check-deprecated-api.php is a CLI script.\n");
        exit(1);
    }

    $body = deprecatedApiFetch(DEPRECATED_API_SOURCE);
    $verdict = $body === null
        ? deprecatedApiUnverified('the compatibility data could not be fetched')
        : deprecatedApiVerdict(json_decode($body, true));

    $reportFile = getenv('GATE_REPORT_FILE');
    if (is_string($reportFile) && $reportFile !== '') {
        file_put_contents($reportFile, $verdict['report']);
    }

    if ($verdict['status'] === 'blocked') {
        fwrite(STDERR, $verdict['message']);
        exit(2);
    }

    fwrite($verdict['status'] === 'unverified' ? STDERR : STDOUT, $verdict['message']);
    exit(0);
}
