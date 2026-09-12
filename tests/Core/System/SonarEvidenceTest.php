<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('SONAR_EVIDENCE_TEST')) {
    define('SONAR_EVIDENCE_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/sonar-evidence.php';

/**
 * scripts/sonar-evidence.php fetches SonarCloud's complete analysis of the
 * released commit into the evidence pack (.github/workflows/release.yml).
 * It declares no namespace (a CLI script), so the functions under test live
 * in the global namespace — hence the leading backslashes.
 *
 * Everything that talks to the network or the clock takes a callable, so
 * the four decisions worth pinning are exercised here without either:
 * the wait for the analysis OF THE RELEASED COMMIT (a pass read off an
 * older analysis is not a pass), the pagination that stops on a short page
 * rather than truncating, the release rule that sorts every open issue
 * into blocking or exempt — the same rule scripts/check-sonar-release.sh
 * applies in bash — and the refusal that rule produces, which is what
 * makes this script a second judge rather than a recorder: on a release
 * run it exits non-zero over a dirty analysis, and no draft Release is
 * created at all.
 */
class SonarEvidenceTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    private string $outDir = '';

    protected function setUp(): void
    {
        $this->sleeps = [];
        $this->outDir = sys_get_temp_dir() . '/sonar-evidence-' . bin2hex(random_bytes(4));
        mkdir($this->outDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->outDir);
    }

    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    /**
     * A fake API answering by path prefix, in order, so a poll can be
     * scripted: each call to the analyses endpoint pops the next answer.
     *
     * @param list<string> $revisions the revision reported on each successive poll
     * @return callable(string): array<string, mixed>
     */
    private function analysesApi(array $revisions): callable
    {
        return static function (string $path) use (&$revisions): array {
            if (!str_starts_with($path, 'project_analyses/search')) {
                throw new \LogicException('unexpected call: ' . $path);
            }
            $revision = array_shift($revisions);
            if ($revision === null) {
                return ['analyses' => []];
            }

            return ['analyses' => [['key' => 'AX', 'date' => '2026-09-06T10:00:00+0000', 'revision' => $revision]]];
        };
    }

    public function testTheWaitReturnsOnceTheExpectedCommitIsAnalysed(): void
    {
        $analysis = \sonarEvidenceWaitForAnalysis(
            $this->analysesApi(['old', 'old', 'released']),
            'project_analyses/search?project=p&ps=1',
            'released',
            5,
            30,
            $this->sleeper()
        );

        $this->assertSame('released', $analysis['revision']);
        $this->assertSame([30, 30], $this->sleeps, 'slept between polls, and not after the one that matched');
    }

    public function testTheWaitRefusesWhenTheCommitIsNeverAnalysed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a pass read off an older analysis is not a pass');

        \sonarEvidenceWaitForAnalysis(
            $this->analysesApi(['old', 'old', 'old']),
            'project_analyses/search?project=p&ps=1',
            'released',
            3,
            1,
            $this->sleeper()
        );
    }

    public function testWithoutAnExpectedCommitTheLatestAnalysisIsTakenWithoutWaiting(): void
    {
        $analysis = \sonarEvidenceWaitForAnalysis(
            $this->analysesApi(['whatever']),
            'project_analyses/search?project=p&ps=1',
            null,
            20,
            30,
            $this->sleeper()
        );

        $this->assertSame('whatever', $analysis['revision']);
        $this->assertSame([], $this->sleeps);
    }

    public function testABranchWithNoAnalysisAtAllIsARefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        \sonarEvidenceWaitForAnalysis($this->analysesApi([]), 'project_analyses/search?project=p&ps=1', null, 1, 0, $this->sleeper());
    }

    public function testPaginationFollowsFullPagesAndStopsOnAShortOne(): void
    {
        $calls = [];
        $api = static function (string $path) use (&$calls): array {
            $calls[] = $path;
            preg_match('/&p=(\d+)/', $path, $m);
            $page = (int) $m[1];
            $size = $page < 3 ? SONAR_EVIDENCE_PAGE_SIZE : 7;

            return ['issues' => array_fill(0, $size, ['key' => "page{$page}"])];
        };

        $all = \sonarEvidenceFetchAllPages($api, 'issues/search?componentKeys=p', 'issues');

        $this->assertCount(2 * SONAR_EVIDENCE_PAGE_SIZE + 7, $all);
        $this->assertCount(3, $calls, 'stopped on the short page, not on a page count');
        $this->assertStringContainsString('&ps=' . SONAR_EVIDENCE_PAGE_SIZE . '&p=1', $calls[0]);
    }

    public function testPaginationIsCappedSoARunawayApiCannotLoopForever(): void
    {
        $calls = 0;
        $api = static function (string $path) use (&$calls): array {
            $calls++;

            return ['hotspots' => array_fill(0, SONAR_EVIDENCE_PAGE_SIZE, ['key' => 'h'])];
        };

        \sonarEvidenceFetchAllPages($api, 'hotspots/search', 'hotspots');

        $this->assertSame(SONAR_EVIDENCE_MAX_PAGES, $calls);
    }

    /**
     * The release rule, every branch of it — the same cases
     * scripts/check-sonar-release.test.sh drives through the bash filter.
     */
    public function testTheReleaseRuleSortsIssuesTheWayAgentsMdSays(): void
    {
        $low = ['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'LOW'];

        $this->assertFalse(\sonarEvidenceIsBlocking(['tags' => ['convention'], 'impacts' => [$low]]), 'all three at once: exempt');
        $this->assertTrue(\sonarEvidenceIsBlocking(['tags' => [], 'impacts' => [$low]]), 'LOW maintainability without the tag blocks');
        $this->assertTrue(\sonarEvidenceIsBlocking(['tags' => ['convention'], 'impacts' => []]), 'no impact at all is not an exemption');
        $this->assertTrue(\sonarEvidenceIsBlocking(['tags' => ['convention'], 'impacts' => [
            ['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'MEDIUM'],
        ]]), 'a MEDIUM convention finding blocks');
        $this->assertTrue(\sonarEvidenceIsBlocking(['tags' => ['convention'], 'impacts' => [
            $low,
            ['softwareQuality' => 'RELIABILITY', 'severity' => 'LOW'],
        ]]), 'a mixed-impact issue blocks: every impact has to qualify');
        $this->assertTrue(\sonarEvidenceIsBlocking([]), 'an issue with nothing on it blocks');
    }

    public function testTheReportSaysWhichCommitWasAnalysedAndWhetherItIsTheReleasedOne(): void
    {
        $gate = ['projectStatus' => ['status' => 'OK', 'conditions' => [['status' => 'OK', 'metricKey' => 'new_coverage']]]];
        $measures = ['component' => ['measures' => [
            ['metric' => 'coverage', 'value' => '81.5'],
            ['metric' => 'reliability_rating', 'value' => '1.0'],
            ['metric' => 'bugs', 'value' => '0'],
        ]]];
        $analysis = ['date' => '2026-09-06T10:00:00+0000', 'revision' => 'abc123'];

        $report = \sonarEvidenceReport($gate, $measures, [], [], $analysis, 'abc123', 'xdubois-57_scoutmagic', 'main');

        $this->assertStringContainsString('## SonarCloud — analyse complète', $report);
        $this->assertStringContainsString('commit `abc123`', $report);
        $this->assertStringContainsString("C'est bien le commit livré par cette release.", $report);
        $this->assertStringContainsString('Quality Gate : **OK**', $report);
        $this->assertStringContainsString('| Fiabilité | A — 0 bug(s) |', $report);
        $this->assertStringContainsString('| Couverture | 81.5 %', $report);
        $this->assertStringContainsString('### Signalements ouverts (0)', $report);
        $this->assertStringContainsString('### Security hotspots (0)', $report);
        $this->assertStringNotContainsString('Conditions en échec', $report);

        $other = \sonarEvidenceReport($gate, $measures, [], [], ['revision' => 'zzz'], 'abc123', 'p', 'main');
        $this->assertStringContainsString("**Attention : ce n'est pas le commit livré (`abc123`).**", $other);

        $rehearsal = \sonarEvidenceReport($gate, $measures, [], [], $analysis, null, 'p', 'main');
        $this->assertStringContainsString('exécution de répétition', $rehearsal);
    }

    public function testTheReportCountsIssuesBySeverityAndByRuleWithTheReleaseVerdict(): void
    {
        $gate = ['projectStatus' => ['status' => 'ERROR', 'conditions' => [
            ['status' => 'ERROR', 'metricKey' => 'new_coverage', 'actualValue' => '40', 'errorThreshold' => '80'],
        ]]];
        $issues = [
            ['rule' => 'php:S1', 'type' => 'BUG', 'severity' => 'MAJOR', 'tags' => [], 'impacts' => [['softwareQuality' => 'RELIABILITY', 'severity' => 'MEDIUM']]],
            ['rule' => 'php:S2', 'type' => 'CODE_SMELL', 'severity' => 'MINOR', 'tags' => ['convention'], 'impacts' => [['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'LOW']]],
            ['rule' => 'php:S2', 'type' => 'CODE_SMELL', 'severity' => 'MINOR', 'tags' => ['convention'], 'impacts' => [['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'LOW']]],
        ];
        $hotspots = [['vulnerabilityProbability' => 'LOW', 'status' => 'TO_REVIEW']];

        $report = \sonarEvidenceReport($gate, [], $issues, $hotspots, ['revision' => 'r'], null, 'p', 'main');

        $this->assertStringContainsString('Quality Gate : **ERROR**', $report);
        $this->assertStringContainsString('- `new_coverage` = 40 (seuil 80)', $report);
        $this->assertStringContainsString('### Signalements ouverts (3)', $report);
        $this->assertStringContainsString('1 bloquant(s) pour une release, 2 exempté(s)', $report);
        $this->assertStringContainsString('| MAJOR | 1 |', $report);
        $this->assertStringContainsString('| MINOR | 2 |', $report);
        $this->assertStringContainsString('| `php:S2` | CODE_SMELL | MINOR | exempté | 2 |', $report);
        $this->assertStringContainsString('| `php:S1` | BUG | MAJOR | bloquant | 1 |', $report);
        $this->assertStringContainsString('| LOW / TO_REVIEW | 1 |', $report);
    }

    /**
     * The whole collection against a fake API: every file the workflow's
     * pack expects, with the counts the report claims.
     */
    public function testCollectWritesEveryFileThePackExpects(): void
    {
        $api = static function (string $path): array {
            if (str_starts_with($path, 'project_analyses/search')) {
                return ['analyses' => [['key' => 'AX-released', 'date' => '2026-09-06T10:00:00+0000', 'revision' => 'released']]];
            }
            if (str_starts_with($path, 'qualitygates/project_status')) {
                // Asked for BY ANALYSIS, never by branch: the fake refuses
                // the branch form so the pinning cannot silently regress.
                if (!str_contains($path, 'analysisId=AX-released')) {
                    throw new \LogicException('the quality gate was not pinned to the accepted analysis: ' . $path);
                }

                return ['projectStatus' => ['status' => 'OK', 'conditions' => []]];
            }
            if (str_starts_with($path, 'measures/component?')) {
                return ['component' => ['measures' => [['metric' => 'ncloc', 'value' => '1000']]]];
            }
            if (str_starts_with($path, 'measures/component_tree')) {
                return ['components' => [['key' => 'p:core/A.php', 'measures' => []]]];
            }
            if (str_starts_with($path, 'issues/search')) {
                return ['issues' => [['key' => 'i1', 'tags' => [], 'impacts' => [], 'severity' => 'INFO']]];
            }
            if (str_starts_with($path, 'hotspots/search')) {
                // One triaged, one not: the release rule blocks on the
                // second alone, so the two counts must not be the same
                // number read twice.
                return ['hotspots' => [
                    ['key' => 'h1', 'status' => 'REVIEWED', 'vulnerabilityProbability' => 'LOW'],
                    ['key' => 'h2', 'status' => 'TO_REVIEW', 'vulnerabilityProbability' => 'HIGH'],
                ]];
            }
            throw new \LogicException('unexpected call: ' . $path);
        };

        $summary = \sonarEvidenceCollect($api, $this->outDir, 'xdubois-57_scoutmagic', 'main', 'released', 1, 0, $this->sleeper());

        foreach ([
            'sonarcloud-analysis.json', 'sonarcloud-quality-gate.json', 'sonarcloud-measures.json',
            'sonarcloud-measures-by-file.json', 'sonarcloud-issues.json', 'sonarcloud-hotspots.json',
            'sonarcloud-report.md',
        ] as $file) {
            $this->assertFileExists($this->outDir . '/' . $file);
        }

        $this->assertSame('released', $summary['revision']);
        $this->assertSame('OK', $summary['quality_gate']);
        $this->assertSame(1, $summary['issues']);
        $this->assertSame(1, $summary['blocking']);
        $this->assertSame(2, $summary['hotspots']);
        $this->assertSame(1, $summary['hotspots_to_review'], 'a triaged hotspot is not one to review');
        $this->assertFalse($summary['truncated']);

        $hotspots = json_decode((string) file_get_contents($this->outDir . '/sonarcloud-hotspots.json'), true);
        $this->assertSame(2, $hotspots['total']);
        $this->assertSame(1, $hotspots['to_review']);

        $issues = json_decode((string) file_get_contents($this->outDir . '/sonarcloud-issues.json'), true);
        $this->assertSame(['total' => 1, 'blocking' => 1, 'exempt' => 0], array_intersect_key($issues, array_flip(['total', 'blocking', 'exempt'])));
        $this->assertStringContainsString('AGENTS.md', (string) $issues['rule']);

        $analysis = json_decode((string) file_get_contents($this->outDir . '/sonarcloud-analysis.json'), true);
        $this->assertSame('released', $analysis['expected_revision']);
        $this->assertSame('main', $analysis['branch']);
    }

    /**
     * The refusal, clause by clause. Each one alone must be enough — a
     * rule that only blocks when everything is wrong at once blocks
     * nothing in practice.
     */
    public function testAnAnalysisThatMayNotShipIsRefusedForEachReasonSeparately(): void
    {
        $clean = ['quality_gate' => 'OK', 'blocking' => 0, 'hotspots_to_review' => 0, 'revision' => 'r'];

        $this->assertSame([], \sonarEvidenceReleaseRefusals($clean));

        $gate = \sonarEvidenceReleaseRefusals(['quality_gate' => 'ERROR'] + $clean);
        $this->assertCount(1, $gate);
        $this->assertStringContainsString('Quality Gate est ERROR', $gate[0]);

        $issues = \sonarEvidenceReleaseRefusals(['blocking' => 3] + $clean);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('3 signalement(s)', $issues[0]);
        $this->assertStringContainsString('convention', $issues[0], 'the reason has to say which findings do not count');

        $hotspots = \sonarEvidenceReleaseRefusals(['hotspots_to_review' => 2] + $clean);
        $this->assertCount(1, $hotspots);
        $this->assertStringContainsString('TO_REVIEW', $hotspots[0]);

        $this->assertCount(3, \sonarEvidenceReleaseRefusals(
            ['quality_gate' => 'ERROR', 'blocking' => 1, 'hotspots_to_review' => 1] + $clean
        ), 'every reason is reported, not just the first');
    }

    /**
     * An exempt convention nit is a finding and still not a refusal — the
     * exemption exists so formatting preferences cannot hold a release
     * hostage, and this is where that stays true.
     */
    public function testExemptFindingsAloneDoNotRefuseARelease(): void
    {
        $this->assertSame([], \sonarEvidenceReleaseRefusals([
            'quality_gate' => 'OK',
            'blocking' => 0,
            'hotspots_to_review' => 0,
            'revision' => 'r',
        ]));
    }

    /**
     * The gate must belong to the analysis that was accepted, not to
     * whatever the branch's gate happens to be by the time it is asked
     * for. Between the wait and this call another analysis can land, and
     * a gate read by branch would then certify a different commit —
     * undoing, one call later, the whole reason the wait exists.
     */
    public function testTheQualityGateIsAskedForByAnalysisNotByBranch(): void
    {
        $asked = [];
        $api = static function (string $path) use (&$asked): array {
            $asked[] = $path;
            if (str_starts_with($path, 'project_analyses/search')) {
                return ['analyses' => [['key' => 'AX-9', 'date' => 'now', 'revision' => 'released']]];
            }
            if (str_starts_with($path, 'qualitygates/project_status')) {
                return ['projectStatus' => ['status' => 'OK', 'conditions' => []]];
            }
            if (str_starts_with($path, 'measures/component?')) {
                return ['component' => ['measures' => []]];
            }
            if (str_contains($path, 'issues')) {
                return ['issues' => []];
            }
            if (str_contains($path, 'hotspots')) {
                return ['hotspots' => []];
            }

            return ['components' => []];
        };

        \sonarEvidenceCollect($api, $this->outDir, 'p', 'main', 'released', 1, 0, $this->sleeper());

        $gateCalls = array_values(array_filter($asked, static fn (string $p): bool => str_starts_with($p, 'qualitygates/')));
        $this->assertCount(1, $gateCalls);
        $this->assertStringContainsString('analysisId=AX-9', $gateCalls[0]);
        $this->assertStringNotContainsString('branch=', $gateCalls[0], 'a branch-scoped gate can belong to another commit');
    }

    /**
     * No key means no way to ask for that analysis by name. Falling back
     * to the branch query would be the silent substitution above, so it
     * refuses instead.
     */
    public function testAnAnalysisWithNoKeyIsARefusalRatherThanAFallback(): void
    {
        $api = static function (string $path): array {
            if (str_starts_with($path, 'project_analyses/search')) {
                return ['analyses' => [['date' => 'now', 'revision' => 'released']]];
            }
            throw new \LogicException('nothing else should be called: ' . $path);
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('carries no key');
        \sonarEvidenceCollect($api, $this->outDir, 'p', 'main', 'released', 1, 0, $this->sleeper());
    }

    /**
     * The token travels in an Authorization header on every request, so
     * an `http://` host would put it on the wire in cleartext.
     */
    public function testANonHttpsHostIsRefusedBeforeAnyTokenCouldBeSent(): void
    {
        $this->assertSame(
            'https://sonar.example.invalid',
            \sonarEvidenceSettings(['SONAR_HOST_URL' => 'https://sonar.example.invalid'])['host']
        );

        foreach (['http://sonarcloud.io', 'http://localhost:9000', 'ftp://x', 'sonarcloud.io'] as $host) {
            try {
                \sonarEvidenceSettings(['SONAR_HOST_URL' => $host]);
                $this->fail($host . ' was accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('must be https', $e->getMessage(), $host);
                $this->assertStringContainsString('cleartext', $e->getMessage(), $host);
            }
        }
    }

    /**
     * A list the page cap cut short is not the whole list, so every count
     * derived from it is a floor — and a floor of zero blocking findings
     * is not a clean bill of health.
     */
    public function testATruncatedListIsFlaggedAndRefusesARelease(): void
    {
        $full = static fn (string $path): array => ['issues' => array_fill(0, SONAR_EVIDENCE_PAGE_SIZE, ['key' => 'i'])];

        $truncated = null;
        \sonarEvidenceFetchAllPages($full, 'issues/search', 'issues', $truncated);
        $this->assertTrue($truncated, 'the page cap ended the loop and nothing said so');

        $short = static fn (string $path): array => ['issues' => [['key' => 'i']]];
        $notTruncated = null;
        \sonarEvidenceFetchAllPages($short, 'issues/search', 'issues', $notTruncated);
        $this->assertFalse($notTruncated, 'a short page is the ordinary end of the loop, not a truncation');

        $clean = ['quality_gate' => 'OK', 'blocking' => 0, 'hotspots_to_review' => 0, 'revision' => 'r'];
        $this->assertSame([], \sonarEvidenceReleaseRefusals($clean + ['truncated' => false]));

        $refusals = \sonarEvidenceReleaseRefusals(['truncated' => true] + $clean);
        $this->assertCount(1, $refusals, 'a truncated list refuses on its own, with nothing else wrong');
        $this->assertStringContainsString('tronquée', $refusals[0]);
        $this->assertStringContainsString('minorants', $refusals[0]);
    }

    public function testTheUnavailableMarkerSaysWhyRatherThanLeavingAGap(): void
    {
        \sonarEvidenceWriteUnavailable($this->outDir, 'pas de jeton');

        $gate = json_decode((string) file_get_contents($this->outDir . '/sonarcloud-quality-gate.json'), true);
        $this->assertSame('UNAVAILABLE', $gate['status']);
        $this->assertSame('pas de jeton', $gate['reason']);
        $this->assertStringContainsString('Indisponible : pas de jeton', (string) file_get_contents($this->outDir . '/sonarcloud-report.md'));
    }

    /**
     * The configuration, defaults included. Each default is a value
     * somebody would otherwise have to read the script to learn, and the
     * clamps are the reason this is a function rather than five lines in
     * an entry point nobody can call.
     */
    public function testTheSettingsCarryTheirDefaults(): void
    {
        $defaults = \sonarEvidenceSettings([]);

        $this->assertNull($defaults['expected'], 'no expected revision means a rehearsal, not a release');
        $this->assertSame('https://sonarcloud.io', $defaults['host']);
        $this->assertSame('xdubois-57_scoutmagic', $defaults['project_key']);
        $this->assertSame('main', $defaults['branch']);
        $this->assertSame(20, $defaults['attempts']);
        $this->assertSame(30, $defaults['seconds']);

        $set = \sonarEvidenceSettings([
            'SONAR_EXPECTED_REVISION' => 'abc123',
            'SONAR_HOST_URL' => 'https://sonar.example.invalid/',
            'SONAR_PROJECT_KEY' => 'other_project',
            'SONAR_BRANCH' => 'release',
            'SONAR_WAIT_ATTEMPTS' => '3',
            'SONAR_WAIT_SECONDS' => '5',
        ]);

        $this->assertSame('abc123', $set['expected']);
        $this->assertSame('https://sonar.example.invalid', $set['host'], 'the trailing slash is stripped, or every path becomes a double slash');
        $this->assertSame('other_project', $set['project_key']);
        $this->assertSame('release', $set['branch']);
        $this->assertSame(3, $set['attempts']);
        $this->assertSame(5, $set['seconds']);
    }

    /**
     * An empty variable means "unset", not "empty string" — a CI runner
     * writes `SONAR_BRANCH: ''` for an expression that resolved to
     * nothing, and a branch named '' would be queried and 404.
     */
    public function testAnEmptyVariableFallsBackToItsDefault(): void
    {
        $settings = \sonarEvidenceSettings([
            'SONAR_EXPECTED_REVISION' => '',
            'SONAR_HOST_URL' => '',
            'SONAR_BRANCH' => '',
        ]);

        $this->assertNull($settings['expected']);
        $this->assertSame('https://sonarcloud.io', $settings['host']);
        $this->assertSame('main', $settings['branch']);
    }

    /**
     * Zero attempts would make the wait loop run no times and fall
     * straight to its timeout refusal — reported as "SonarCloud never
     * analysed this commit" about a commit nobody ever asked after.
     */
    public function testTheWaitBudgetIsClampedRatherThanTakenLiterally(): void
    {
        $zero = \sonarEvidenceSettings(['SONAR_WAIT_ATTEMPTS' => '0', 'SONAR_WAIT_SECONDS' => '0']);
        $this->assertSame(20, $zero['attempts'], 'an explicit 0 is falsy, so it means "unset" and takes the default');

        $negative = \sonarEvidenceSettings(['SONAR_WAIT_ATTEMPTS' => '-5', 'SONAR_WAIT_SECONDS' => '-5']);
        $this->assertSame(1, $negative['attempts'], 'at least one attempt, always');
        $this->assertSame(0, $negative['seconds'], 'no sleep is legitimate; a negative one is not');
    }

    /**
     * The token: the environment wins, the gitignored file
     * scripts/check-sonar-release.sh writes is the fallback, and neither
     * is a silent absence.
     */
    public function testTheTokenFallsBackToTheGitignoredFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sonar-token');
        // As `echo > .sonar-token` leaves it: a Bearer header carrying a
        // newline is rejected with a 401 that says nothing about why.
        file_put_contents($file, "from-the-file\n");

        try {
            $this->assertSame('from-the-env', \sonarEvidenceResolveToken('from-the-env', $file));
            $this->assertSame('from-the-file', \sonarEvidenceResolveToken('', $file));
            $this->assertSame('', \sonarEvidenceResolveToken('', $file . '-absent'));
        } finally {
            unlink($file);
        }
    }

    /**
     * One HTTP answer, and the three ways it is refused. An unreadable
     * answer must never read as "nothing found": a pack whose SonarCloud
     * section quietly meant "the API was down" would be worse than one
     * that is missing.
     */
    public function testAnUnreadableAnswerIsRefusedRatherThanReadAsEmpty(): void
    {
        $this->assertSame(
            ['analyses' => []],
            \sonarEvidenceDecode('{"analyses":[]}', 200, '', 'project_analyses/search')
        );

        foreach ([
            'a transport error' => [false, 0, 'Could not resolve host'],
            'an HTTP 401' => ['{"errors":[]}', 401, ''],
            'an HTTP 500' => ['', 500, ''],
        ] as $case => [$body, $status, $error]) {
            try {
                \sonarEvidenceDecode($body, $status, $error, 'issues/search');
                $this->fail($case . ' was not refused');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('issues/search', $e->getMessage(), $case);
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return JSON');
        \sonarEvidenceDecode('<html>gateway</html>', 200, '', 'measures/component');
    }

    public function testTheSummaryLineNamesTheCommitAndTheCounts(): void
    {
        $line = \sonarEvidenceSummaryLine([
            'revision' => 'abcdef1234567890',
            'analysis_date' => '2026-09-06T10:00:00+0000',
            'quality_gate' => 'OK',
            'issues' => 4,
            'blocking' => 1,
            'hotspots' => 3,
            'hotspots_to_review' => 2,
        ], 'evidence');

        $this->assertStringContainsString('of abcdef1', $line, 'the short sha, not the whole one');
        $this->assertStringContainsString('quality gate OK', $line);
        $this->assertStringContainsString('4 issue(s) (1 blocking)', $line);
        $this->assertStringContainsString('3 hotspot(s) (2 to review)', $line);
        $this->assertStringContainsString('written to evidence', $line);
    }

    public function testTheRefusalMessageListsEveryReasonAndWhereTheRuleLives(): void
    {
        $message = \sonarEvidenceRefusalMessage(['première raison', 'seconde raison']);

        $this->assertStringContainsString('does not qualify for a release', $message);
        $this->assertStringContainsString('  - première raison', $message);
        $this->assertStringContainsString('  - seconde raison', $message);
        $this->assertStringContainsString('AGENTS.md § SonarQube Cloud release gate', $message);
    }

    public function testRatingsAreLettersNotDecimals(): void
    {
        $this->assertSame('A', \sonarEvidenceRating('1.0'));
        $this->assertSame('E', \sonarEvidenceRating('5'));
        $this->assertSame('n/d', \sonarEvidenceRating('n/d'));
    }
}
