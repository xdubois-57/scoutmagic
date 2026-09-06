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

    $expected = (string) getenv('SONAR_EXPECTED_REVISION');
    $expected = $expected === '' ? null : $expected;

    $token = (string) getenv('SONAR_TOKEN');
    $tokenFile = dirname(__DIR__) . '/.sonar-token';
    if ($token === '' && is_file($tokenFile)) {
        $token = trim((string) file_get_contents($tokenFile));
    }
    if ($token === '') {
        if ($expected !== null) {
            fwrite(STDERR, "sonar-evidence: SONAR_TOKEN is not set and an analysis of {$expected} is required — refusing.\n");
            exit(1);
        }
        sonar_evidence_write_unavailable($outDir, 'Aucun SONAR_TOKEN n\'était disponible pour cette exécution.');
        fwrite(STDERR, "sonar-evidence: SONAR_TOKEN is not set; wrote an UNAVAILABLE marker.\n");
        exit(0);
    }

    $host = rtrim((string) (getenv('SONAR_HOST_URL') ?: 'https://sonarcloud.io'), '/');
    $projectKey = (string) (getenv('SONAR_PROJECT_KEY') ?: 'xdubois-57_scoutmagic');
    $branch = (string) (getenv('SONAR_BRANCH') ?: 'main');
    $attempts = max(1, (int) (getenv('SONAR_WAIT_ATTEMPTS') ?: 20));
    $seconds = max(0, (int) (getenv('SONAR_WAIT_SECONDS') ?: 30));

    $api = static fn (string $path): array => sonar_evidence_api($host, $token, $path);
    $sleep = static function (int $s): void {
        sleep($s);
    };

    try {
        $summary = sonar_evidence_collect($api, $outDir, $projectKey, $branch, $expected, $attempts, $seconds, $sleep);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'sonar-evidence: ' . $e->getMessage() . "\n");
        exit(1);
    }

    fwrite(STDERR, sprintf(
        "sonar-evidence: analysis %s of %s — quality gate %s, %d issue(s) (%d blocking), %d hotspot(s), written to %s\n",
        $summary['analysis_date'],
        substr($summary['revision'], 0, 7),
        $summary['quality_gate'],
        $summary['issues'],
        $summary['blocking'],
        $summary['hotspots'],
        $outDir
    ));
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
 * @return array{revision: string, analysis_date: string, quality_gate: string, issues: int, blocking: int, hotspots: int}
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

    $gate = $api("qualitygates/project_status?projectKey={$project}&branch={$branchQuery}");
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

    $issues = sonar_evidence_fetch_all_pages(
        $api,
        "issues/search?componentKeys={$project}&branch={$branchQuery}&resolved=false",
        'issues'
    );
    $blocking = array_values(array_filter($issues, 'sonar_evidence_is_blocking'));
    sonar_evidence_write_json($outDir . '/sonarcloud-issues.json', [
        'total' => count($issues),
        'blocking' => count($blocking),
        'exempt' => count($issues) - count($blocking),
        'rule' => 'AGENTS.md § SonarQube Cloud release gate: every unresolved issue blocks a release except one that is, all at once, MAINTAINABILITY, LOW and tagged convention.',
        'issues' => $issues,
    ]);

    $hotspots = sonar_evidence_fetch_all_pages(
        $api,
        "hotspots/search?projectKey={$project}&branch={$branchQuery}",
        'hotspots'
    );
    sonar_evidence_write_json($outDir . '/sonarcloud-hotspots.json', ['total' => count($hotspots), 'hotspots' => $hotspots]);

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
 * @param callable(string): array<string, mixed> $api
 * @return list<array<string, mixed>>
 */
function sonar_evidence_fetch_all_pages(callable $api, string $path, string $key): array
{
    $all = [];
    $separator = str_contains($path, '?') ? '&' : '?';

    for ($page = 1; $page <= SONAR_EVIDENCE_MAX_PAGES; $page++) {
        $response = $api($path . $separator . 'ps=' . SONAR_EVIDENCE_PAGE_SIZE . '&p=' . $page);
        $batch = is_array($response[$key] ?? null) ? array_values($response[$key]) : [];
        foreach ($batch as $item) {
            if (is_array($item)) {
                $all[] = $item;
            }
        }
        if (count($batch) < SONAR_EVIDENCE_PAGE_SIZE) {
            break;
        }
    }

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
    $lines[] = 'Les fichiers lisibles par une machine sont à côté de celui-ci : '
        . '`sonarcloud-analysis.json`, `sonarcloud-quality-gate.json`, `sonarcloud-measures.json`, '
        . '`sonarcloud-measures-by-file.json`, `sonarcloud-issues.json` et `sonarcloud-hotspots.json`.';
    $lines[] = '';

    return implode("\n", $lines);
}
