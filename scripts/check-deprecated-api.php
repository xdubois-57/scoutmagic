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
 *  - This gate asks whether any engine has REMOVED it, from the published
 *    compatibility data — including the engines CI does not run. It is the
 *    early warning, and it is the only thing here that can speak about
 *    Firefox or Safari at all.
 *
 * WHAT IT DETECTS, AND WHAT IT CANNOT
 * ---------------------------------------------------------------------
 * A removal that has SHIPPED, which in this data is a `version_removed` on
 * the current statement. Nothing more, and the earlier version of this
 * header claimed more: it said the gate blocks on « a removal or a
 * deprecation ANNOUNCEMENT », and no code path ever read `status` or
 * `deprecated`. It could not usefully: MDN has marked this API deprecated
 * for years, so treating deprecation as the signal would block every
 * release from the day it was written.
 *
 * So an « Intent to Remove » that has been announced but not yet shipped is
 * exactly what this cannot see, and that is the one thing the human page in
 * the report line is for.
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
 * A removal found in the data does block — exit code 2 — because at that
 * point the question stops being about the future.
 */

/** Where the published compatibility data lives. */
const DEPRECATED_API_SOURCE =
    'https://raw.githubusercontent.com/mdn/browser-compat-data/main/api/Document.json';

/** The page a human reads when this gate could not read the data. */
const DEPRECATED_API_HUMAN_PAGE = 'https://caniuse.com/document-execcommand';

/** How long to wait for the data before calling it unverified, in seconds. */
const DEPRECATED_API_TIMEOUT = 20;

/**
 * The commands this product actually issues, which is what decides which of
 * MDN's PER-COMMAND entries are read.
 *
 * `api.Document.execCommand` is not one entry: MDN publishes a `__compat` of
 * its own for several individual commands as sibling keys — today `copy`,
 * `cut`, `defaultParagraphSeparator`, `insertBrOnReturn`, `insertHTML` and
 * `paste`. Two of those, `copy` and `insertHTML`, are commands the editors
 * issue, and the first version of this gate read none of them: it inspected
 * the generic entry alone, so an engine dropping `insertHTML` while keeping
 * `execCommand` would have been reported as « supporté ».
 *
 * Intersected with what MDN publishes rather than listing the two: the
 * product's list is the thing that changes, and
 * tests/Architecture/DeprecatedBrowserApiIsWatchedTest.php reads the
 * commands out of the product and fails until this constant holds each one
 * — the same rule that keeps the end-to-end alarm honest.
 *
 * NOT every sub-entry, deliberately. `defaultParagraphSeparator` carries
 * `version_removed: 79` for Edge — the EdgeHTML lineage ending at the
 * Chromium switch — and `version_added: false` for Chrome and Safari. It is
 * a command nothing here issues, and blocking on it would abort every
 * release over a capability the product never asks for.
 */
const DEPRECATED_API_COMMANDS = [
    'bold',
    'copy',
    'createLink',
    'formatBlock',
    'insertHTML',
    'insertImage',
    'insertOrderedList',
    'insertText',
    'insertUnorderedList',
    'italic',
    'removeFormat',
    'underline',
];

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
 * How many of DEPRECATED_API_ENGINES this support object actually carries a
 * verdict for.
 *
 * Exists because the absence of a removal and the absence of DATA are the
 * same empty array, and the gate used to report the first while looking at
 * the second: a `support` of `[]` passed the shape check, produced no
 * removals, and came back as « supporté par 7 moteurs » — the constant's
 * size, not anything measured. Seven is now whatever was read.
 *
 * A `"mirror"` string is not counted: it defers to another entry, which is
 * counted on its own line.
 *
 * @param array<string, mixed> $support the `__compat.support` object
 */
function deprecatedApiEnginesInspected(array $support): int
{
    $inspected = 0;

    foreach (DEPRECATED_API_ENGINES as $engine) {
        $statements = $support[$engine] ?? null;
        if (!is_array($statements)) {
            continue;
        }
        $current = array_is_list($statements) ? ($statements[0] ?? null) : $statements;
        if (is_array($current)) {
            $inspected++;
        }
    }

    return $inspected;
}

/**
 * Every feature under `api.Document.execCommand` this gate reads, with the
 * removals found in it and how many engines answered.
 *
 * The generic entry, plus one per command of DEPRECATED_API_COMMANDS that
 * MDN publishes a `__compat` for — see that constant for why the product's
 * list decides, and not MDN's.
 *
 * @param array<string, mixed> $execCommand the `execCommand` subtree
 * @return array{removals: array<string, array<string, string>>, inspected: int, features: list<string>}
 */
function deprecatedApiFeatureRemovals(array $execCommand): array
{
    $features = ['document.execCommand' => $execCommand['__compat'] ?? null];

    foreach (DEPRECATED_API_COMMANDS as $command) {
        $entry = $execCommand[$command]['__compat'] ?? null;
        if (is_array($entry)) {
            $features["document.execCommand('{$command}')"] = $entry;
        }
    }

    $removals = [];
    $inspected = 0;
    $read = [];

    foreach ($features as $name => $compat) {
        if (!is_array($compat) || !is_array($compat['support'] ?? null)) {
            continue;
        }
        $read[] = $name;
        $inspected += deprecatedApiEnginesInspected($compat['support']);
        $found = deprecatedApiRemovals($compat['support']);
        if ($found !== []) {
            $removals[$name] = $found;
        }
    }

    return ['removals' => $removals, 'inspected' => $inspected, 'features' => $read];
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

    $execCommand = $data['api']['Document']['execCommand'] ?? null;
    if (!is_array($execCommand) || !is_array($execCommand['__compat']['support'] ?? null)) {
        // The shape moved. That is a real possibility for an upstream file
        // and it is precisely why this case is "unverified" rather than
        // "supported": saying nothing found means nothing was looked at.
        return deprecatedApiUnverified(
            'api.Document.execCommand.__compat.support is not where it was in the data'
        );
    }

    $read = deprecatedApiFeatureRemovals($execCommand);

    // THE SAME PRINCIPLE ONE LEVEL DOWN, and the earlier version of this
    // function stopped short of it. The shape check above passes for a
    // `support` of `[]`, or for one whose keys are engines this gate does not
    // watch: no removal is found, and « no removal found » was reported as
    // « supported ». Nothing had been looked at.
    if ($read['inspected'] === 0) {
        return deprecatedApiUnverified(
            'the data carries no verdict for any of the ' . count(DEPRECATED_API_ENGINES)
            . ' engines this gate watches'
        );
    }

    if ($read['removals'] !== []) {
        $named = [];
        foreach ($read['removals'] as $feature => $engines) {
            foreach ($engines as $engine => $version) {
                $named[] = "{$feature} in {$engine} ({$version})";
            }
        }
        $list = implode(', ', $named);

        return [
            'status' => 'blocked',
            'message' => "REMOVED: {$list}.\n"
                . "Six files under public/assets/js/ depend on document.execCommand (issue #379),\n"
                . "and the editors stop working silently in that engine. Option 1 of that issue —\n"
                . "rebuilding the toolbar on Selection/Range — is now due, and this release should\n"
                . "not go out claiming a working editor. See " . DEPRECATED_API_HUMAN_PAGE . "\n",
            'report' => "**bloquant** — retiré : {$list}. "
                . 'Les éditeurs de texte riche en dépendent (issue #379).',
        ];
    }

    // Both numbers are measured, not assumed — see
    // deprecatedApiEnginesInspected() for what the constant's size used to
    // be reported as.
    $features = count($read['features']);
    $engines = $read['inspected'];

    return [
        'status' => 'ok',
        'message' => "Deprecated browser API gate OK: no engine has removed document.execCommand.\n"
            . "Read {$engines} engine entries across {$features} MDN feature(s) — the generic one "
            . "plus a per-command entry for each command this product issues that MDN publishes "
            . "one for.\n"
            . "Still deprecated, as it has been for years; that is the baseline, not a signal.\n",
        'report' => 'vérifié — `document.execCommand` (dont dépendent les éditeurs de texte riche, '
            . "issue #379) est déprécié mais retiré par aucun moteur : {$engines} verdicts de "
            . "moteur lus sur {$features} entrée(s) MDN, celles que rend "
            . DEPRECATED_API_HUMAN_PAGE . '.',
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

    // Pre-initialised on purpose. The http wrapper overwrites this in the
    // local scope; a protocol that carries no status line — `file://`, which
    // is how the tests exercise this function — leaves it untouched, so an
    // empty list IS « nobody said anything », with no undefined variable to
    // coalesce around. It also settles a disagreement: PHPStan models this
    // variable as always defined, and without this line it is wrong (after a
    // `file://` read, `isset()` on it is false). With it, PHPStan is right.
    $http_response_header = [];

    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return null;
    }

    // `ignore_errors` means an error RESPONSE arrives as a body, so the status
    // has to be read. An earlier version of this function skipped it and said
    // so: the argument was that raw.githubusercontent answers a missing path
    // with the plain text "404: Not Found", which fails to decode and lands in
    // « the shape has moved » anyway. That is an assumption about how an
    // external host formats its errors — a proxy or a mirror returning a JSON
    // error page would have been decoded as the document — and it also made
    // every HTTP failure report the wrong cause.
    //
    // The WHOLE header list, not its first line. With `follow_location` on —
    // PHP's default, and wanted here — the wrapper concatenates every hop's
    // headers into one list, each hop opening with its own status line, so a
    // redirect puts a `302` first and the real `200` last. Reading `[0]`
    // rejected a document that had been fetched perfectly: measured against
    // github.com/…/raw/main, which answers « HTTP/1.1 302 Found » then
    // « HTTP/1.1 200 OK » for 14 793 bytes of body.
    //
    // core/ExternalSource/StreamPageFetcher::fromHeaders() is this
    // repository's precedent and says the same thing; this follows it rather
    // than inventing a second rule.
    if (!deprecatedApiIsSuccessfulStatus($http_response_header)) {
        return null;
    }

    return $body;
}

/**
 * Whether the response the body came from says it is the document asked for.
 *
 * Takes the header list rather than reading `$http_response_header` itself,
 * so every case — including a recorded redirect chain — can be asserted
 * without standing up a server.
 *
 * **The LAST status line decides.** A redirect chain leaves one per hop, in
 * order, and the last one is the response the body belongs to. Reading the
 * first made a `302` reject a document already in hand. Same rule, and same
 * reason, as core/ExternalSource/StreamPageFetcher::fromHeaders().
 *
 * **No status line at all is not a failure.** A `file://` URL carries none,
 * and reading that as a refusal would reject the very fetch the tests use to
 * prove this function works.
 *
 * @param list<string> $headers what the stream wrapper collected
 */
function deprecatedApiIsSuccessfulStatus(array $headers): bool
{
    $status = null;

    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
            // Keep overwriting: the last one to match is this body's.
            $status = (int) $matches[1];
        }
    }

    if ($status === null) {
        return true;
    }

    return $status >= 200 && $status < 300;
}

/**
 * The gate's whole decision, with the fetching handed in.
 *
 * Separated from deprecatedApiMain() so that the path a release actually
 * depends on can be tested: that a fetch which comes back with nothing
 * produces « non vérifié » and not « supporté ». That distinction is the
 * entire safety of a gate which, by design, exits 0 either way
 * (AGENTS.md § Deprecated browser API release gate) — so leaving it to be
 * exercised only by a real network call was the wrong half to skip.
 *
 * @param callable(): ?string $fetch the document, or null if it cannot be had
 * @return array{status: 'ok'|'blocked'|'unverified', message: string, report: string}
 */
function deprecatedApiDecide(callable $fetch): array
{
    $body = $fetch();

    return $body === null
        ? deprecatedApiUnverified('the compatibility data could not be fetched')
        : deprecatedApiVerdict(json_decode($body, true));
}

/**
 * Writes the gate's report line where scripts/release.sh will read it.
 *
 * Takes what getenv() returns, false included, because the release script
 * only sets GATE_REPORT_FILE while a gate is running and this script is
 * also runnable by hand.
 *
 * @param array{status: string, message: string, report: string} $verdict
 */
function deprecatedApiWriteReport(array $verdict, string|false $reportFile): void
{
    if ($reportFile === false || $reportFile === '') {
        return;
    }

    file_put_contents($reportFile, $verdict['report']);
}

/**
 * 2 when an engine has removed the API, 0 otherwise — « not checked »
 * included, which is the one deliberate asymmetry in this file.
 *
 * @param array{status: string, message: string, report: string} $verdict
 */
function deprecatedApiExitCode(array $verdict): int
{
    return $verdict['status'] === 'blocked' ? 2 : 0;
}

// Guarded the same way scripts/dependency-inventory.php is: the test suite
// defines DEPRECATED_API_CHECK_TEST and includes this file for its
// functions, and the command must not run when it does.
if (!defined('DEPRECATED_API_CHECK_TEST')) {
    deprecatedApiMain();
}

/**
 * The wiring, and nothing else: the real URL, the real environment, the
 * real streams, the exit. Every decision it makes is made by one of the
 * functions above, each tested on its own.
 */
function deprecatedApiMain(): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "check-deprecated-api.php is a CLI script.\n");
        exit(1);
    }

    $verdict = deprecatedApiDecide(static fn (): ?string => deprecatedApiFetch(DEPRECATED_API_SOURCE));
    deprecatedApiWriteReport($verdict, getenv('GATE_REPORT_FILE'));

    fwrite($verdict['status'] === 'ok' ? STDOUT : STDERR, $verdict['message']);
    exit(deprecatedApiExitCode($verdict));
}
