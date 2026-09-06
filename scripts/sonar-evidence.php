<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * Pull SonarCloud's complete analysis of one commit into the evidence pack.
 *
 *   SONAR_TOKEN=... php scripts/sonar-evidence.php <output-dir>
 *
 * The quality gate alone says pass or fail. It does not say what the
 * analysis found, how much of the code it covered, or where the debt sits —
 * and an evidence pack whose reader has to log in to SonarCloud to learn
 * that is not evidence. So this fetches the whole picture, as the API
 * returns it:
 *
 *   sonarcloud-analysis.json          which analysis this is: its date and
 *                                     the commit it analysed
 *   sonarcloud-quality-gate.json      the verdict, condition by condition
 *   sonarcloud-measures.json          every project-level metric
 *   sonarcloud-measures-by-file.json  the same, per file, so debt has a place
 *   sonarcloud-issues.json            every open issue, all pages, each one
 *                                     sorted by the release rule
 *   sonarcloud-hotspots.json          security hotspots — a separate endpoint
 *   sonarcloud-report.md              the front page, for a human, in French
 *
 * Hotspots are fetched deliberately. They live behind their own endpoint, so
 * a pack built from `issues/search` alone looks complete and quietly omits
 * the one category a security reviewer opens first.
 *
 * THE COMMIT MATTERS. SonarCloud analyses main on every push (ci.yml's
 * sonarqube job), and the release workflow starts on the tag push that
 * follows the same commit — so the analysis is usually still running when
 * this script runs. With SONAR_EXPECTED_REVISION set, it waits for the
 * analysis OF THAT COMMIT (bounded by SONAR_WAIT_ATTEMPTS × SONAR_WAIT_SECONDS,
 * twenty times thirty seconds by default) and refuses if it never arrives:
 * "not analysed yet" and "analysed and clean" are different answers, and
 * only one of them is a pass. Without the variable — a rehearsal run on a
 * branch SonarCloud never sees — it records the latest analysis of the
 * branch and says which commit that was.
 *
 * IT ALSO JUDGES, and only when a revision is expected — that is, on a real
 * release rather than a rehearsal. The rule is the one in AGENTS.md
 * § SonarQube Cloud release gate, the same one scripts/check-sonar-release.sh
 * applies in bash before the tag is pushed: a non-OK Quality Gate, one
 * unresolved issue that is not an exempt convention nit, or one Security
 * Hotspot still TO_REVIEW, and this exits non-zero — which creates no draft
 * Release at all.
 *
 * Repeating that check here rather than trusting the local one is the whole
 * point of the runner being a second judge: the local gate ran minutes
 * earlier, on the releaser's machine, against whatever SonarCloud said then.
 * Nothing about a pack that RECORDS a failing analysis while the Release goes
 * out anyway would be worth attaching.
 *
 * Without a token it writes an UNAVAILABLE marker and exits 0, unless a
 * revision was expected, in which case it refuses: a Release must carry the
 * analysis of what it ships. A missing file reads as an oversight; a file
 * that says "not available, and why" reads as a fact.
 *
 * Configuration (environment):
 *   SONAR_TOKEN               required for anything but the marker; falls
 *                             back to the gitignored .sonar-token file the
 *                             way scripts/check-sonar-release.sh does
 *   SONAR_HOST_URL            default https://sonarcloud.io
 *   SONAR_PROJECT_KEY         default xdubois-57_scoutmagic
 *   SONAR_BRANCH              default main
 *   SONAR_EXPECTED_REVISION   the commit the analysis must be of
 *   SONAR_WAIT_ATTEMPTS       default 20
 *   SONAR_WAIT_SECONDS        default 30
 *
 * Tested by tests/Core/System/SonarEvidenceTest.php with a fake API and a
 * fake clock, which is why the fetching, the waiting and the report are
 * functions taking callables, and every side effect is in
 * sonar_evidence_main(): the test defines SONAR_EVIDENCE_TEST and includes
 * this file for its functions.
 */

/** Every metric worth recording, rather than the handful someone remembered. */
const SONAR_EVIDENCE_METRICS = [
    'alert_status', 'quality_gate_details',
    'bugs', 'reliability_rating', 'reliability_remediation_effort',
    'vulnerabilities', 'security_rating', 'security_remediation_effort',
    'security_hotspots', 'security_hotspots_reviewed', 'security_review_rating',
    'code_smells', 'sqale_rating', 'sqale_index', 'sqale_debt_ratio',
    'coverage', 'line_coverage', 'branch_coverage', 'lines_to_cover',
    'uncovered_lines', 'tests', 'test_failures', 'test_errors', 'skipped_tests',
    'duplicated_lines_density', 'duplicated_blocks', 'duplicated_files',
    'ncloc', 'lines', 'statements', 'functions', 'classes', 'files',
    'comment_lines_density', 'cognitive_complexity', 'complexity',
];

/** The per-file subset: enough to place debt and coverage, not the whole list. */
const SONAR_EVIDENCE_FILE_METRICS = 'ncloc,coverage,bugs,vulnerabilities,security_hotspots,code_smells,duplicated_lines_density,cognitive_complexity';

/** SonarCloud's page ceiling per request, and a hard cap on pages so a loop cannot run away. */
const SONAR_EVIDENCE_PAGE_SIZE = 500;
const SONAR_EVIDENCE_MAX_PAGES = 20;

// Guarded the same way scripts/authz-support.php is: the test suite defines
// SONAR_EVIDENCE_TEST and includes this file for its functions, and the
// command must not run when it does.
if (!defined('SONAR_EVIDENCE_TEST')) {
    sonar_evidence_main($argv);
}

/**
 * Every side effect this file has: reads argv and the environment, talks
 * to SonarCloud, writes files, sleeps, exits.
 *
 * @param string[] $argv
 */
function sonar_evidence_main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "sonar-evidence.php is a CLI script.\n");
        exit(1);
    }

    $outDir = $argv[1] ?? 'evidence';
    if (!is_dir($outDir) && !mkdir($outDir, 0o775, true) && !is_dir($outDir)) {
        fwrite(STDERR, "sonar-evidence: cannot create {$outDir}\n");
        exit(1);
    }

    // `getenv()` with no argument returns the whole environment, which is
    // what makes the settings below a pure function of a map rather than
    // of the process — and therefore testable. Same for the token: the
    // fallback to a gitignored file is the sort of thing that stops
    // working silently, so it is a function with a test rather than four
    // lines nobody can reach.
    try {
        $settings = sonar_evidence_settings(getenv());
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'sonar-evidence: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $expected = $settings['expected'];
    $token = sonar_evidence_resolve_token(
        (string) (getenv('SONAR_TOKEN') ?: ''),
        dirname(__DIR__) . '/.sonar-token'
    );

    if ($token === '') {
        if ($expected !== null) {
            fwrite(STDERR, "sonar-evidence: SONAR_TOKEN is not set and an analysis of {$expected} is required — refusing.\n");
            exit(1);
        }
        sonar_evidence_write_unavailable($outDir, 'Aucun SONAR_TOKEN n\'était disponible pour cette exécution.');
        fwrite(STDERR, "sonar-evidence: SONAR_TOKEN is not set; wrote an UNAVAILABLE marker.\n");
        exit(0);
    }

    $host = $settings['host'];
    $api = static fn (string $path): array => sonar_evidence_api($host, $token, $path);
    $sleep = static function (int $s): void {
        sleep($s);
    };

    try {
        $summary = sonar_evidence_collect(
            $api,
            $outDir,
            $settings['project_key'],
            $settings['branch'],
            $expected,
            $settings['attempts'],
            $settings['seconds'],
            $sleep
        );
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'sonar-evidence: ' . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDERR, sonar_evidence_summary_line($summary, $outDir));

    // Every file above is written before this point, on purpose: a refusal
    // is exactly when somebody wants to read the report, and the workflow
    // uploads the directory whether this exits 0 or 1.
    $refusals = $expected === null ? [] : sonar_evidence_release_refusals($summary);
    if ($refusals === []) {
        return;
    }

    fwrite(STDERR, sonar_evidence_refusal_message($refusals));
    exit(1);
}

/**
 * This script's configuration, read from an environment map rather than
 * from the process, with the defaults the header documents.
 *
 * The clamps are not decoration: `SONAR_WAIT_ATTEMPTS=0` would make the
 * wait loop below run zero times and fall straight through to its
 * timeout refusal, which reads as "SonarCloud never analysed this commit"
 * over a commit nobody ever asked about. One attempt is the floor.
 *
 * @param array<string, string> $env
 * @return array{expected: ?string, host: string, project_key: string, branch: string, attempts: int, seconds: int}
 */
function sonar_evidence_settings(array $env): array
{
    $expected = (string) ($env['SONAR_EXPECTED_REVISION'] ?? '');

    // Parenthesised deliberately. `$a ?? '' ?: $b` does mean
    // `($a ?? '') ?: $b` — but only because `??` binds tighter than `?:`,
    // which is a precedence nobody should have to recall to read a
    // default. An empty variable takes the default here, which is the
    // point: a CI runner writes `SONAR_BRANCH: ''` for an expression that
    // resolved to nothing, and a branch named '' would be queried and 404.
    $host = rtrim(((string) ($env['SONAR_HOST_URL'] ?? '')) ?: 'https://sonarcloud.io', '/');

    // Every request carries the token in an Authorization header, so the
    // scheme is a credential question rather than a preference: an
    // `http://` host would put it on the wire in cleartext. Refused where
    // the host is resolved, because there is no path from a configured
    // value to a request that skips this function.
    if (strtolower((string) parse_url($host, PHP_URL_SCHEME)) !== 'https') {
        throw new RuntimeException(
            'SONAR_HOST_URL must be https (got ' . ($host === '' ? '<empty>' : $host)
            . ') — refusing to send the token in cleartext'
        );
    }

    return [
        'expected' => $expected === '' ? null : $expected,
        'host' => $host,
        'project_key' => ((string) ($env['SONAR_PROJECT_KEY'] ?? '')) ?: 'xdubois-57_scoutmagic',
        'branch' => ((string) ($env['SONAR_BRANCH'] ?? '')) ?: 'main',
        'attempts' => max(1, ((int) ($env['SONAR_WAIT_ATTEMPTS'] ?? 0)) ?: 20),
        'seconds' => max(0, ((int) ($env['SONAR_WAIT_SECONDS'] ?? 0)) ?: 30),
    ];
}

/**
 * The token: the environment first, then the gitignored file
 * scripts/check-sonar-release.sh may have written, then nothing.
 *
 * Trimmed, because a file written by `echo` ends in a newline and a
 * Bearer header carrying one is rejected with a 401 that says nothing
 * about why.
 */
function sonar_evidence_resolve_token(string $fromEnvironment, string $tokenFile): string
{
    if ($fromEnvironment !== '') {
        return $fromEnvironment;
    }

    if (is_file($tokenFile)) {
        return trim((string) file_get_contents($tokenFile));
    }

    return '';
}

/**
 * The one line this script prints on a run that worked.
 *
 * @param array{revision: string, analysis_date: string, quality_gate: string, issues: int, blocking: int, hotspots: int, hotspots_to_review: int} $summary
 */
function sonar_evidence_summary_line(array $summary, string $outDir): string
{
    return sprintf(
        "sonar-evidence: analysis %s of %s — quality gate %s, %d issue(s) (%d blocking), %d hotspot(s) (%d to review), written to %s\n",
        $summary['analysis_date'],
        substr($summary['revision'], 0, 7),
        $summary['quality_gate'],
        $summary['issues'],
        $summary['blocking'],
        $summary['hotspots'],
        $summary['hotspots_to_review'],
        $outDir
    );
}

/**
 * What a refusal prints: every reason, then where the rule is written.
 *
 * @param list<string> $refusals
 */
function sonar_evidence_refusal_message(array $refusals): string
{
    $message = "\nsonar-evidence: this analysis does not qualify for a release.\n";
    foreach ($refusals as $refusal) {
        $message .= '  - ' . $refusal . "\n";
    }

    return $message
        . "See AGENTS.md § SonarQube Cloud release gate. Fix or resolve them, then release the commit that does.\n";
}

/**
 * Why this analysis may not ship, in French, or an empty list.
 *
 * The rule, in one sentence, from AGENTS.md § SonarQube Cloud release gate:
 * every unresolved finding blocks a release except one that is, all three at
 * once, MAINTAINABILITY, LOW and tagged `convention`. Plus the Quality Gate
 * itself, and plus any hotspot nobody has triaged — an unreviewed hotspot is
 * an unresolved security question, not an absent one.
 *
 * A TRUNCATED list refuses on its own, which is why the flag is carried
 * this far: every count here comes from the lists fetched above, so a
 * capped run's `blocking` of zero means "we did not see the whole list",
 * not "nothing blocks". Reading the first as the second is how a release
 * ships over a finding nobody was ever shown.
 *
 * @param array{quality_gate: string, blocking: int, hotspots_to_review: int, revision: string, truncated?: bool} $summary
 * @return list<string>
 */
function sonar_evidence_release_refusals(array $summary): array
{
    $refusals = [];

    if ($summary['quality_gate'] !== 'OK') {
        $refusals[] = 'le Quality Gate est ' . $summary['quality_gate'] . ' (il doit être OK).';
    }
    if ($summary['blocking'] > 0) {
        $refusals[] = $summary['blocking'] . ' signalement(s) non résolu(s) bloquant(s) — les nits de convention exemptés ne comptent pas.';
    }
    if ($summary['hotspots_to_review'] > 0) {
        $refusals[] = $summary['hotspots_to_review'] . ' Security Hotspot(s) encore à trier (TO_REVIEW).';
    }
    if ($summary['truncated'] ?? false) {
        $refusals[] = 'la liste des signalements a été tronquée au plafond de pagination : les comptes ci-dessus sont des minorants, pas un état complet.';
    }

    return $refusals;
}

/**
 * One authenticated GET against the SonarCloud Web API, decoded.
 *
 * The token goes in a Bearer header, as scripts/check-sonar-release.sh
 * sends it. Any transport error, non-200 status or non-JSON body is a
 * refusal: this script never guesses, and never treats an unreadable
 * answer as "nothing found".
 *
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function sonar_evidence_api(string $host, string $token, string $path): array
{
    $handle = curl_init($host . '/api/' . $path);
    if ($handle === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FAILONERROR => false,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    return sonar_evidence_decode($body, $status, $error, $path);
}

/**
 * What to make of one HTTP answer.
 *
 * Separated from the curl call above because this is where the decisions
 * are, and they are the ones that matter: a transport error, a non-200,
 * or a body that is not JSON are all refusals. This script never guesses
 * and never reads an unreadable answer as "nothing found" — an evidence
 * pack whose SonarCloud section silently means "the API was down" would
 * be worse than one that is missing.
 *
 * @param string|bool $body what curl_exec returned
 * @return array<string, mixed>
 * @throws RuntimeException
 */
function sonar_evidence_decode(string|bool $body, int $status, string $error, string $path): array
{
    if (!is_string($body) || $status !== 200) {
        throw new RuntimeException("GET {$path} failed (HTTP {$status}) {$error}");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("GET {$path} did not return JSON");
    }

    return $decoded;
}

/**
 * Fetch, wait, write: the whole collection for one project and branch.
 *
 * @param callable(string): array<string, mixed> $api
 * @param callable(int): void $sleep
 * @return array{revision: string, analysis_date: string, quality_gate: string, issues: int, blocking: int, hotspots: int, hotspots_to_review: int, truncated: bool}
 * @throws RuntimeException
 */
function sonar_evidence_collect(
    callable $api,
    string $outDir,
    string $projectKey,
    string $branch,
    ?string $expectedRevision,
    int $attempts,
    int $seconds,
    callable $sleep
): array {
    $project = rawurlencode($projectKey);
    $branchQuery = rawurlencode($branch);

    $analysis = sonar_evidence_wait_for_analysis(
        $api,
        "project_analyses/search?project={$project}&branch={$branchQuery}&ps=1",
        $expectedRevision,
        $attempts,
        $seconds,
        $sleep
    );
    sonar_evidence_write_json($outDir . '/sonarcloud-analysis.json', [
        'project' => $projectKey,
        'branch' => $branch,
        'expected_revision' => $expectedRevision,
        'analysis' => $analysis,
    ]);

    // Pinned to the analysis accepted above BY ITS OWN KEY, not queried by
    // project and branch. Those are not the same question: the branch query
    // answers with whatever the gate is NOW, so an analysis landing between
    // the wait and this line would have the pack certify a gate belonging
    // to a different commit — the exact substitution the wait exists to
    // prevent, undone one call later. The measures, the per-file tree, the
    // issues and the hotspots below stay branch-scoped, which the report
    // says out loud.
    $analysisKey = (string) ($analysis['key'] ?? '');
    if ($analysisKey === '') {
        throw new RuntimeException(
            'the accepted analysis carries no key, so its quality gate cannot be asked for by name; refusing '
            . "rather than reading the branch's current gate, which may belong to another commit"
        );
    }
    $gate = $api('qualitygates/project_status?analysisId=' . rawurlencode($analysisKey));
    sonar_evidence_write_json($outDir . '/sonarcloud-quality-gate.json', $gate);

    $measures = $api(
        "measures/component?component={$project}&branch={$branchQuery}&metricKeys=" . implode(',', SONAR_EVIDENCE_METRICS)
    );
    sonar_evidence_write_json($outDir . '/sonarcloud-measures.json', $measures);

    $byFile = sonar_evidence_fetch_all_pages(
        $api,
        "measures/component_tree?component={$project}&branch={$branchQuery}&qualifiers=FIL&metricKeys=" . SONAR_EVIDENCE_FILE_METRICS,
        'components'
    );
    sonar_evidence_write_json($outDir . '/sonarcloud-measures-by-file.json', ['total' => count($byFile), 'components' => $byFile]);

    // $truncated matters more than it looks. Every count below is derived
    // from these lists, so one the page cap cut short yields counts that
    // are FLOORS — and a `blocking` of zero read off a truncated list says
    // "nothing blocks this release" where the honest answer is "we did not
    // see the whole list". It is carried into the summary and refused on
    // rather than left for a reader to notice.
    $issuesTruncated = false;
    $issues = sonar_evidence_fetch_all_pages(
        $api,
        "issues/search?componentKeys={$project}&branch={$branchQuery}&resolved=false",
        'issues',
        $issuesTruncated
    );
    $blocking = array_values(array_filter($issues, 'sonar_evidence_is_blocking'));
    sonar_evidence_write_json($outDir . '/sonarcloud-issues.json', [
        'total' => count($issues),
        'blocking' => count($blocking),
        'exempt' => count($issues) - count($blocking),
        'truncated' => $issuesTruncated,
        'rule' => 'AGENTS.md § SonarQube Cloud release gate: every unresolved issue blocks a release except one that is, all at once, MAINTAINABILITY, LOW and tagged convention.',
        'issues' => $issues,
    ]);

    $hotspotsTruncated = false;
    $hotspots = sonar_evidence_fetch_all_pages(
        $api,
        "hotspots/search?projectKey={$project}&branch={$branchQuery}",
        'hotspots',
        $hotspotsTruncated
    );
    $toReview = array_values(array_filter(
        $hotspots,
        static fn (array $hotspot): bool => ($hotspot['status'] ?? '') === 'TO_REVIEW'
    ));
    sonar_evidence_write_json($outDir . '/sonarcloud-hotspots.json', [
        'total' => count($hotspots),
        'to_review' => count($toReview),
        'truncated' => $hotspotsTruncated,
        'hotspots' => $hotspots,
    ]);

    file_put_contents(
        $outDir . '/sonarcloud-report.md',
        sonar_evidence_report($gate, $measures, $issues, $hotspots, $analysis, $expectedRevision, $projectKey, $branch)
    );

    return [
        'revision' => (string) ($analysis['revision'] ?? ''),
        'analysis_date' => (string) ($analysis['date'] ?? ''),
        'quality_gate' => (string) ($gate['projectStatus']['status'] ?? 'UNKNOWN'),
        'issues' => count($issues),
        'blocking' => count($blocking),
        'hotspots' => count($hotspots),
        'hotspots_to_review' => count($toReview),
        'truncated' => $issuesTruncated || $hotspotsTruncated,
    ];
}

/**
 * The latest analysis of the branch — and, when a revision is expected,
 * the analysis OF THAT REVISION, waited for.
 *
 * Polled rather than refused outright, because releasing minutes after a
 * push is the normal case and SonarCloud is usually still working on that
 * commit. The wait is bounded, and a timeout is a refusal: a pass read off
 * an older analysis is not a pass.
 *
 * @param callable(string): array<string, mixed> $api
 * @param callable(int): void $sleep
 * @return array<string, mixed> the analysis record as the API returns it
 * @throws RuntimeException
 */
function sonar_evidence_wait_for_analysis(
    callable $api,
    string $path,
    ?string $expectedRevision,
    int $attempts,
    int $seconds,
    callable $sleep
): array {
    $latest = [];
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $response = $api($path);
        $analyses = is_array($response['analyses'] ?? null) ? $response['analyses'] : [];
        $latest = is_array($analyses[0] ?? null) ? $analyses[0] : [];

        if ($expectedRevision === null) {
            if ($latest === []) {
                throw new RuntimeException('SonarCloud has no analysis at all for this branch');
            }

            return $latest;
        }

        if (($latest['revision'] ?? null) === $expectedRevision) {
            return $latest;
        }

        if ($attempt < $attempts) {
            $sleep($seconds);
        }
    }

    throw new RuntimeException(sprintf(
        'SonarCloud has not analysed %s (latest analysis: %s) after %d attempt(s) — a pass read off an older analysis is not a pass',
        $expectedRevision,
        (string) ($latest['revision'] ?? 'none'),
        $attempts
    ));
}

/**
 * Follow the pagination to the end.
 *
 * The loop stops on a short page rather than on a page count, so it cannot
 * silently truncate the day this project has more than one page of issues
 * — which is exactly the day the report matters most. The hard cap is a
 * safety net against an API that keeps answering full pages, not a limit
 * anybody expects to reach.
 *
 * `$truncated` is set when the CAP is what stopped the loop rather than a
 * short page — the one case where the returned list is not the whole list,
 * and therefore the one case a caller must not read as complete. Without
 * it, a capped run looks exactly like an ordinary one.
 *
 * @param callable(string): array<string, mixed> $api
 * @param bool|null $truncated set to true when the page cap ended the loop
 * @return list<array<string, mixed>>
 */
function sonar_evidence_fetch_all_pages(callable $api, string $path, string $key, ?bool &$truncated = null): array
{
    $all = [];
    $separator = str_contains($path, '?') ? '&' : '?';
    $truncated = false;

    for ($page = 1; $page <= SONAR_EVIDENCE_MAX_PAGES; $page++) {
        $response = $api($path . $separator . 'ps=' . SONAR_EVIDENCE_PAGE_SIZE . '&p=' . $page);
        $batch = is_array($response[$key] ?? null) ? array_values($response[$key]) : [];
        foreach ($batch as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }
        if (count($batch) < SONAR_EVIDENCE_PAGE_SIZE) {
            return $all;
        }
    }

    $truncated = true;
    fwrite(STDERR, sprintf(
        "sonar-evidence: %s still answered a full page after %d pages — the list is TRUNCATED at %d "
        . "and every count derived from it is a floor.\n",
        $key,
        SONAR_EVIDENCE_MAX_PAGES,
        count($all)
    ));

    return $all;
}

/**
 * Whether one open issue blocks a release under the rule in AGENTS.md
 * § SonarQube Cloud release gate — the same filter
 * scripts/check-sonar-release.sh applies, written once more here because
 * that one is bash and this is the only PHP reader of the same list.
 *
 * Exempt means ALL of: tagged `convention`, and every impact the issue
 * carries is MAINTAINABILITY at LOW. An issue with no impacts at all is not
 * exempt.
 *
 * @param array<string, mixed> $issue
 */
function sonar_evidence_is_blocking(array $issue): bool
{
    $tags = is_array($issue['tags'] ?? null) ? $issue['tags'] : [];
    $impacts = is_array($issue['impacts'] ?? null) ? $issue['impacts'] : [];

    if (!in_array('convention', $tags, true) || $impacts === []) {
        return true;
    }

    foreach ($impacts as $impact) {
        if (!is_array($impact)
            || ($impact['softwareQuality'] ?? '') !== 'MAINTAINABILITY'
            || ($impact['severity'] ?? '') !== 'LOW') {
            return true;
        }
    }

    return false;
}

/**
 * The marker written when there is no token: a file that says why, rather
 * than a file that is not there.
 */
function sonar_evidence_write_unavailable(string $outDir, string $reason): void
{
    sonar_evidence_write_json($outDir . '/sonarcloud-quality-gate.json', [
        'status' => 'UNAVAILABLE',
        'reason' => $reason,
    ]);
    file_put_contents(
        $outDir . '/sonarcloud-report.md',
        "## SonarCloud\n\nIndisponible : " . $reason . "\n"
    );
}

/**
 * @param array<string, mixed> $data
 */
function sonar_evidence_write_json(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** SonarCloud returns ratings as 1..5; nobody reads "3.0". */
function sonar_evidence_rating(string $value): string
{
    $letters = ['1' => 'A', '2' => 'B', '3' => 'C', '4' => 'D', '5' => 'E'];

    return $letters[substr($value, 0, 1)] ?? $value;
}

/**
 * The front page: what a human reads first, in French, with the
 * machine-readable files named at the end.
 *
 * @param array<string, mixed> $gate
 * @param array<string, mixed> $measures
 * @param list<array<string, mixed>> $issues
 * @param list<array<string, mixed>> $hotspots
 * @param array<string, mixed> $analysis
 */
function sonar_evidence_report(
    array $gate,
    array $measures,
    array $issues,
    array $hotspots,
    array $analysis,
    ?string $expectedRevision,
    string $projectKey,
    string $branch
): string {
    $map = [];
    $componentMeasures = $measures['component']['measures'] ?? [];
    foreach (is_array($componentMeasures) ? $componentMeasures : [] as $measure) {
        if (is_array($measure) && isset($measure['metric'])) {
            $map[(string) $measure['metric']] = (string) ($measure['value'] ?? '');
        }
    }
    $m = static fn (string $key): string => $map[$key] ?? 'n/d';

    $revision = (string) ($analysis['revision'] ?? '');
    $lines = [];
    $lines[] = '## SonarCloud — analyse complète';
    $lines[] = '';
    $lines[] = 'Projet `' . $projectKey . '`, branche `' . $branch . '`.';
    $lines[] = 'Analyse du ' . (string) ($analysis['date'] ?? 'n/d') . ', commit `' . $revision . '`.';
    if ($expectedRevision !== null) {
        $lines[] = $revision === $expectedRevision
            ? 'C\'est bien le commit livré par cette release.'
            : '**Attention : ce n\'est pas le commit livré (`' . $expectedRevision . '`).**';
    } else {
        $lines[] = 'Aucun commit particulier n\'était attendu (exécution de répétition) : ceci est la dernière analyse de la branche.';
    }
    $lines[] = '';
    $lines[] = 'Quality Gate : **' . (string) ($gate['projectStatus']['status'] ?? 'INCONNU') . '**';
    $lines[] = '';

    $conditions = is_array($gate['projectStatus']['conditions'] ?? null) ? $gate['projectStatus']['conditions'] : [];
    $failed = array_filter(
        $conditions,
        static fn ($c): bool => is_array($c) && ($c['status'] ?? '') !== 'OK'
    );
    if ($failed !== []) {
        $lines[] = 'Conditions en échec :';
        $lines[] = '';
        foreach ($failed as $condition) {
            $lines[] = '- `' . (string) ($condition['metricKey'] ?? '?') . '` = '
                . (string) ($condition['actualValue'] ?? '?')
                . ' (seuil ' . (string) ($condition['errorThreshold'] ?? '?') . ')';
        }
        $lines[] = '';
    }

    $rows = [
        'Fiabilité' => sonar_evidence_rating($m('reliability_rating')) . ' — ' . $m('bugs') . ' bug(s)',
        'Sécurité' => sonar_evidence_rating($m('security_rating')) . ' — ' . $m('vulnerabilities') . ' vulnérabilité(s)',
        'Revue de sécurité' => sonar_evidence_rating($m('security_review_rating')) . ' — ' . $m('security_hotspots')
            . ' hotspot(s), ' . $m('security_hotspots_reviewed') . ' % revus',
        'Maintenabilité' => sonar_evidence_rating($m('sqale_rating')) . ' — ' . $m('code_smells') . ' code smell(s), '
            . $m('sqale_index') . ' min de dette',
        'Couverture' => $m('coverage') . ' % (' . $m('uncovered_lines') . ' lignes non couvertes sur ' . $m('lines_to_cover') . ')',
        'Tests rapportés' => $m('tests') . ' (échecs : ' . $m('test_failures') . ', erreurs : ' . $m('test_errors') . ', ignorés : ' . $m('skipped_tests') . ')',
        'Duplication' => $m('duplicated_lines_density') . ' % sur ' . $m('duplicated_blocks') . ' bloc(s)',
        'Taille' => $m('ncloc') . ' lignes de code, ' . $m('files') . ' fichier(s)',
        'Complexité' => $m('cognitive_complexity') . ' cognitive, ' . $m('complexity') . ' cyclomatique',
    ];
    $lines[] = '| Mesure | Valeur |';
    $lines[] = '|---|---|';
    foreach ($rows as $label => $value) {
        $lines[] = '| ' . $label . ' | ' . $value . ' |';
    }
    $lines[] = '';

    $blocking = array_filter($issues, 'sonar_evidence_is_blocking');
    $lines[] = '### Signalements ouverts (' . count($issues) . ')';
    $lines[] = '';
    $lines[] = count($blocking) . ' bloquant(s) pour une release, ' . (count($issues) - count($blocking))
        . ' exempté(s) — la règle est celle d\'`AGENTS.md` § SonarQube Cloud release gate : tout signalement non résolu bloque, sauf s\'il est à la fois `MAINTAINABILITY`, `LOW` et étiqueté `convention`.';
    $lines[] = '';

    if ($issues === []) {
        $lines[] = 'Aucun.';
    } else {
        $bySeverity = [];
        $byRule = [];
        foreach ($issues as $issue) {
            $severity = (string) ($issue['severity'] ?? 'INCONNUE');
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
            $ruleKey = (string) ($issue['rule'] ?? '?') . '|' . (string) ($issue['type'] ?? '?') . '|' . $severity
                . '|' . (sonar_evidence_is_blocking($issue) ? 'bloquant' : 'exempté');
            $byRule[$ruleKey] = ($byRule[$ruleKey] ?? 0) + 1;
        }

        $order = ['BLOCKER' => 0, 'CRITICAL' => 1, 'MAJOR' => 2, 'MINOR' => 3, 'INFO' => 4];
        uksort($bySeverity, static fn ($a, $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

        $lines[] = '| Sévérité | Nombre |';
        $lines[] = '|---|---|';
        foreach ($bySeverity as $severity => $count) {
            $lines[] = '| ' . $severity . ' | ' . $count . ' |';
        }
        $lines[] = '';

        arsort($byRule);
        $lines[] = '| Règle | Type | Sévérité | Release | Nombre |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($byRule as $key => $count) {
            [$rule, $type, $severity, $verdict] = explode('|', $key);
            $lines[] = '| `' . $rule . '` | ' . $type . ' | ' . $severity . ' | ' . $verdict . ' | ' . $count . ' |';
        }
    }

    $lines[] = '';
    $lines[] = '### Security hotspots (' . count($hotspots) . ')';
    $lines[] = '';
    if ($hotspots === []) {
        $lines[] = 'Aucun.';
    } else {
        $byStatus = [];
        foreach ($hotspots as $hotspot) {
            $status = (string) ($hotspot['vulnerabilityProbability'] ?? '?') . ' / ' . (string) ($hotspot['status'] ?? '?');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }
        ksort($byStatus);
        $lines[] = '| Probabilité / statut | Nombre |';
        $lines[] = '|---|---|';
        foreach ($byStatus as $status => $count) {
            $lines[] = '| ' . $status . ' | ' . $count . ' |';
        }
    }

    $lines[] = '';
    $lines[] = 'Le Quality Gate ci-dessus est celui de **cette analyse précisément** (demandé par son identifiant, '
        . "pas par branche, pour qu'une analyse plus récente ne puisse pas s'y substituer). Les mesures, les "
        . 'signalements et les hotspots, eux, sont ceux de la branche `' . $branch . '` à l\'instant de la collecte.';
    $lines[] = '';
    $lines[] = 'Les fichiers lisibles par une machine sont à côté de celui-ci : '
        . '`sonarcloud-analysis.json`, `sonarcloud-quality-gate.json`, `sonarcloud-measures.json`, '
        . '`sonarcloud-measures-by-file.json`, `sonarcloud-issues.json` et `sonarcloud-hotspots.json`.';
    $lines[] = '';

    return implode("\n", $lines);
}
