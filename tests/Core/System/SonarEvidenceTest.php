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
        $analysis = \sonar_evidence_wait_for_analysis(
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

        \sonar_evidence_wait_for_analysis(
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
        $analysis = \sonar_evidence_wait_for_analysis(
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
        \sonar_evidence_wait_for_analysis($this->analysesApi([]), 'project_analyses/search?project=p&ps=1', null, 1, 0, $this->sleeper());
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

        $all = \sonar_evidence_fetch_all_pages($api, 'issues/search?componentKeys=p', 'issues');

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

        \sonar_evidence_fetch_all_pages($api, 'hotspots/search', 'hotspots');

        $this->assertSame(SONAR_EVIDENCE_MAX_PAGES, $calls);
    }

    /**
     * The release rule, every branch of it — the same cases
     * scripts/check-sonar-release.test.sh drives through the bash filter.
     */
    public function testTheReleaseRuleSortsIssuesTheWayAgentsMdSays(): void
    {
        $low = ['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'LOW'];

        $this->assertFalse(\sonar_evidence_is_blocking(['tags' => ['convention'], 'impacts' => [$low]]), 'all three at once: exempt');
        $this->assertTrue(\sonar_evidence_is_blocking(['tags' => [], 'impacts' => [$low]]), 'LOW maintainability without the tag blocks');
        $this->assertTrue(\sonar_evidence_is_blocking(['tags' => ['convention'], 'impacts' => []]), 'no impact at all is not an exemption');
        $this->assertTrue(\sonar_evidence_is_blocking(['tags' => ['convention'], 'impacts' => [
            ['softwareQuality' => 'MAINTAINABILITY', 'severity' => 'MEDIUM'],
        ]]), 'a MEDIUM convention finding blocks');
        $this->assertTrue(\sonar_evidence_is_blocking(['tags' => ['convention'], 'impacts' => [
            $low,
            ['softwareQuality' => 'RELIABILITY', 'severity' => 'LOW'],
        ]]), 'a mixed-impact issue blocks: every impact has to qualify');
        $this->assertTrue(\sonar_evidence_is_blocking([]), 'an issue with nothing on it blocks');
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

        $report = \sonar_evidence_report($gate, $measures, [], [], $analysis, 'abc123', 'xdubois-57_scoutmagic', 'main');

        $this->assertStringContainsString('## SonarCloud — analyse complète', $report);
        $this->assertStringContainsString('commit `abc123`', $report);
        $this->assertStringContainsString("C'est bien le commit livré par cette release.", $report);
        $this->assertStringContainsString('Quality Gate : **OK**', $report);
        $this->assertStringContainsString('| Fiabilité | A — 0 bug(s) |', $report);
        $this->assertStringContainsString('| Couverture | 81.5 %', $report);
        $this->assertStringContainsString('### Signalements ouverts (0)', $report);
        $this->assertStringContainsString('### Security hotspots (0)', $report);
        $this->assertStringNotContainsString('Conditions en échec', $report);

        $other = \sonar_evidence_report($gate, $measures, [], [], ['revision' => 'zzz'], 'abc123', 'p', 'main');
        $this->assertStringContainsString("**Attention : ce n'est pas le commit livré (`abc123`).**", $other);

        $rehearsal = \sonar_evidence_report($gate, $measures, [], [], $analysis, null, 'p', 'main');
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

        $report = \sonar_evidence_report($gate, [], $issues, $hotspots, ['revision' => 'r'], null, 'p', 'main');

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
                return ['analyses' => [['date' => '2026-09-06T10:00:00+0000', 'revision' => 'released']]];
            }
            if (str_starts_with($path, 'qualitygates/project_status')) {
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

        $summary = \sonar_evidence_collect($api, $this->outDir, 'xdubois-57_scoutmagic', 'main', 'released', 1, 0, $this->sleeper());

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

        $this->assertSame([], \sonar_evidence_release_refusals($clean));

        $gate = \sonar_evidence_release_refusals(['quality_gate' => 'ERROR'] + $clean);
        $this->assertCount(1, $gate);
        $this->assertStringContainsString('Quality Gate est ERROR', $gate[0]);

        $issues = \sonar_evidence_release_refusals(['blocking' => 3] + $clean);
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('3 signalement(s)', $issues[0]);
        $this->assertStringContainsString('convention', $issues[0], 'the reason has to say which findings do not count');

        $hotspots = \sonar_evidence_release_refusals(['hotspots_to_review' => 2] + $clean);
        $this->assertCount(1, $hotspots);
        $this->assertStringContainsString('TO_REVIEW', $hotspots[0]);

        $this->assertCount(3, \sonar_evidence_release_refusals(
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
        $this->assertSame([], \sonar_evidence_release_refusals([
            'quality_gate' => 'OK',
            'blocking' => 0,
            'hotspots_to_review' => 0,
            'revision' => 'r',
        ]));
    }

    public function testTheUnavailableMarkerSaysWhyRatherThanLeavingAGap(): void
    {
        \sonar_evidence_write_unavailable($this->outDir, 'pas de jeton');

        $gate = json_decode((string) file_get_contents($this->outDir . '/sonarcloud-quality-gate.json'), true);
        $this->assertSame('UNAVAILABLE', $gate['status']);
        $this->assertSame('pas de jeton', $gate['reason']);
        $this->assertStringContainsString('Indisponible : pas de jeton', (string) file_get_contents($this->outDir . '/sonarcloud-report.md'));
    }

    public function testRatingsAreLettersNotDecimals(): void
    {
        $this->assertSame('A', \sonar_evidence_rating('1.0'));
        $this->assertSame('E', \sonar_evidence_rating('5'));
        $this->assertSame('n/d', \sonar_evidence_rating('n/d'));
    }
}
