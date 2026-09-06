<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Maintenance\GitHubReleaseClient;
use PHPUnit\Framework\TestCase;

/**
 * The release pipeline is three workflow files and a shell script that no
 * compiler connects: checks.yml holds the gates, ci.yml and release.yml
 * both call it, release.yml builds the evidence pack and the draft
 * Release, and scripts/release.sh finishes that draft. Each of them can be
 * edited into a shape that still runs and proves less — a gate dropped
 * from the release job's `needs:`, an evidence upload left without its
 * guard, a second workflow quietly growing its own copy of the gates — and
 * nothing would go red for it.
 *
 * Two of the assertions below are about damage rather than drift, and both
 * are stated in the files themselves:
 *
 *  - THE EVIDENCE PACK MUST NEVER BE A .zip. Every installed site takes the
 *    first Release asset whose name ends in .zip as the application
 *    (GitHubReleaseClient::selectZipAssetUrl() and bootstrap.php), GitHub
 *    sorts assets alphabetically, and those sites run the code they already
 *    have — so no fix in this repository reaches them before they install
 *    a tarball of test reports as ScoutMagic.
 *  - THE VERDICT JOB MUST WAIT FOR EVERYTHING. `All checks` exists so that
 *    one required status check can stand for every gate (issue #170); a
 *    `needs:` that stops listing the reusable workflow, or an `if:` that
 *    lets the job be skipped, turns it back into a check that is green
 *    while a gate is red.
 *
 * Deliberately line-based rather than YAML-parsed, for the reason
 * tests/Security/IssueTriageWorkflowPermissionsTest gives: no YAML parser
 * ships with this repository, and the files are small and hand-written.
 * The assertions fail closed — a restructured file fails rather than
 * passes.
 */
final class ReleasePipelineIsWiredTest extends TestCase
{
    private const CHECKS = '.github/workflows/checks.yml';
    private const CI = '.github/workflows/ci.yml';
    private const RELEASE = '.github/workflows/release.yml';
    private const RELEASE_SCRIPT = 'scripts/release.sh';

    private static function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents, $relativePath . ' is unreadable');

        return $contents;
    }

    /**
     * Top-level job ids of a workflow file: two-space-indented keys under
     * `jobs:`, in order.
     *
     * @return list<string>
     */
    private static function jobIds(string $workflow): array
    {
        $contents = self::read($workflow);
        $jobsAt = strpos($contents, "\njobs:\n");
        self::assertNotFalse($jobsAt, $workflow . ' has no jobs: block');
        preg_match_all('/^  ([a-z][a-z0-9-]*):\s*$/m', substr($contents, $jobsAt), $matches);

        return $matches[1];
    }

    // ── The gates live once, and both pipelines call them ────────────────

    public function testBothPipelinesCallTheSameReusableGates(): void
    {
        foreach ([self::CI, self::RELEASE] as $workflow) {
            $this->assertStringContainsString(
                'uses: ./.github/workflows/checks.yml',
                self::read($workflow),
                $workflow . ' no longer calls checks.yml — the gates would drift from the other pipeline'
            );
            // Without it SONAR_TOKEN never reaches the sonarqube job, which
            // then fails at authentication on every same-repository run.
            $this->assertStringContainsString('secrets: inherit', self::read($workflow), $workflow . ' does not pass the secrets through');
        }

        $checks = self::read(self::CHECKS);
        $this->assertStringContainsString("on:\n  workflow_call:", $checks, 'checks.yml is no longer a reusable workflow');
        $this->assertStringContainsString("      evidence:\n", $checks, 'checks.yml lost its evidence input');
        $this->assertStringNotContainsString("\n  push:", $checks, 'checks.yml must run only when called — a direct trigger would run every gate twice');
    }

    /**
     * The seven gates and SonarCloud, by job id. A job that moves back
     * into ci.yml or release.yml is a gate one of the two pipelines no
     * longer runs.
     */
    public function testEveryGateIsInTheReusableWorkflow(): void
    {
        $expected = [
            'test', 'database-mariadb', 'javascript-tests', 'e2e-tests',
            'authorization-matrix', 'dast-passive', 'security', 'sonarqube',
        ];

        $this->assertSame($expected, self::jobIds(self::CHECKS));
        $this->assertSame(['checks', 'all-checks'], self::jobIds(self::CI), 'ci.yml carries only the call and the verdict');
    }

    public function testSonarCloudIsSkippedOnAnEvidenceRun(): void
    {
        $checks = self::read(self::CHECKS);
        $sonar = substr($checks, (int) strpos($checks, "\n  sonarqube:\n"));

        // A tag push is not a branch SonarCloud should analyse; release.yml
        // reads the analysis of the same commit back instead.
        $this->assertStringContainsString('!inputs.evidence', $sonar);
        $this->assertStringContainsString('scripts/sonar-evidence.php', self::read(self::RELEASE));
    }

    /**
     * Every evidence upload is guarded, named `evidence-*`, and the
     * release job collects exactly that pattern. An upload without the
     * guard runs on every pull request; one outside the pattern never
     * reaches the pack.
     */
    public function testEvidenceUploadsAreGuardedAndCollectedByOnePattern(): void
    {
        $checks = self::read(self::CHECKS);
        preg_match_all('/name: (evidence-[a-z0-9-]+)\n/', $checks, $names);
        $this->assertGreaterThanOrEqual(7, count($names[1]), 'fewer evidence artifacts than gates');

        $steps = preg_split('/\n      - name: /', $checks) ?: [];
        foreach ($steps as $step) {
            if (!str_contains($step, 'name: evidence-')) {
                continue;
            }
            $this->assertStringContainsString(
                'if: ${{ inputs.evidence }}',
                $step,
                'an evidence upload runs without the evidence guard: ' . strtok($step, "\n")
            );
        }

        $release = self::read(self::RELEASE);
        $this->assertStringContainsString("pattern: evidence-*\n", $release);
        $this->assertStringContainsString('with:\n      evidence: true', str_replace("\n", '\n', $release), 'release.yml does not ask for evidence');
    }

    // ── The verdict job: one required check standing for every gate ──────

    public function testTheVerdictJobNeedsTheGatesAndAlwaysRuns(): void
    {
        $ci = self::read(self::CI);
        $verdict = substr($ci, (int) strpos($ci, "\n  all-checks:\n"));

        $this->assertStringContainsString('name: All checks', $verdict, 'the required-check name changed; the ruleset lists it by name');
        $this->assertStringContainsString('needs: [checks]', $verdict);
        // Without always(), a failed gate leaves this job SKIPPED, which a
        // ruleset reads as "expected" rather than as red.
        $this->assertStringContainsString('if: always()', $verdict);
        $this->assertStringContainsString('needs.checks.result', $verdict);
        $this->assertStringContainsString('exit 1', $verdict);
    }

    // ── The release workflow: gates first, then the pack, then a draft ───

    public function testTheReleaseJobRunsOnlyAfterEveryOtherJob(): void
    {
        $release = self::read(self::RELEASE);
        $ids = self::jobIds(self::RELEASE);
        $this->assertContains('release', $ids);
        $others = array_values(array_diff($ids, ['release']));

        preg_match('/\n  release:\n.*?needs: \[([^\]]+)\]/s', $release, $needs);
        $this->assertNotEmpty($needs, 'the release job has no needs:');
        $needed = array_map('trim', explode(',', $needs[1]));
        sort($needed);
        sort($others);
        $this->assertSame($others, $needed, 'a job in release.yml is not waited for by the release job — its failure would not stop the draft');
    }

    public function testTheReleaseWorkflowIsStartedByATagAndCreatesADraft(): void
    {
        $release = self::read(self::RELEASE);

        $this->assertStringContainsString("  push:\n    tags:\n      - 'v*'", $release);
        $this->assertStringContainsString('workflow_dispatch:', $release, 'the rehearsal trigger is gone — a change to this file could only be tested by cutting a version');
        $this->assertStringContainsString("if: \${{ github.ref_type == 'tag' }}", $release, 'a dispatch run must never create a Release');
        $this->assertMatchesRegularExpression('/gh release create .*\n\s+--draft/s', $release, 'the Release is not created as a draft');
        $this->assertStringContainsString('actions/attest-build-provenance', $release, 'the pack is no longer signed');
        $this->assertStringContainsString('SHA256SUMS', $release);
        $this->assertStringContainsString('manifest.json', $release);
    }

    public function testTheEvidencePackIsNeverAZip(): void
    {
        $release = self::read(self::RELEASE);
        // The whole line: the name carries a `${{ … }}` expression with spaces in it.
        preg_match('/EVIDENCE_ASSET: (.+)$/m', $release, $asset);
        $this->assertNotEmpty($asset, 'release.yml no longer names the evidence asset in EVIDENCE_ASSET');
        $this->assertStringEndsWith('.tar.gz', $asset[1]);
        $this->assertStringStartsWith('evidence-', $asset[1]);

        $script = self::read(self::RELEASE_SCRIPT);
        $this->assertStringContainsString('EVIDENCE_ASSET="evidence-${TAG}.tar.gz"', $script, 'release.sh expects a different evidence asset name than release.yml produces');
    }

    /**
     * The trap the naming rule protects against, demonstrated on the real
     * selector: with a `.zip` evidence pack, every installed site would
     * download the test reports as the application.
     */
    public function testWhyTheEvidencePackMustNotBeAZip(): void
    {
        $safe = [
            ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.com/bootstrap.php'],
            ['name' => 'evidence-v1.0.42.tar.gz', 'browser_download_url' => 'https://example.com/evidence.tar.gz'],
            ['name' => 'release-v1.0.42.zip', 'browser_download_url' => 'https://example.com/release.zip'],
        ];
        $this->assertSame('https://example.com/release.zip', GitHubReleaseClient::selectZipAssetUrl($safe));

        $trap = $safe;
        $trap[1] = ['name' => 'evidence-v1.0.42.zip', 'browser_download_url' => 'https://example.com/evidence.zip'];
        $this->assertSame(
            'https://example.com/evidence.zip',
            GitHubReleaseClient::selectZipAssetUrl($trap),
            'if this ever stops picking the evidence archive, the selector changed — and installed sites still run the old one'
        );
    }

    public function testTheReleaseWorkflowHoldsOnlyThePermissionsItNeeds(): void
    {
        $release = self::read(self::RELEASE);

        $this->assertStringContainsString("\npermissions:\n  contents: read\n", $release, 'the workflow default is not read-only');
        // Only the job that creates the Release and signs the pack may write.
        $this->assertSame(1, substr_count($release, 'contents: write'));
        $this->assertSame(1, substr_count($release, 'id-token: write'));
        $this->assertSame(1, substr_count($release, 'attestations: write'));
        foreach (['issues: write', 'pull-requests: write', 'security-events: write', 'packages: write'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $release, $forbidden . ' has no business in the release workflow');
        }
    }

    // ── The script that finishes the draft ───────────────────────────────

    public function testTheScriptWaitsForTheWorkflowAndPublishesTheDraft(): void
    {
        $script = self::read(self::RELEASE_SCRIPT);

        $this->assertStringContainsString('gh run list --workflow=release.yml --branch "${TAG}"', $script);
        $this->assertStringContainsString('!= "success"', $script, 'the workflow conclusion is not checked');
        $this->assertStringNotContainsString('gh release create', $script, 'release.sh creates a Release of its own — it would collide with the workflow\'s draft');
        $this->assertStringContainsString('gh release upload "${TAG}" "${ARTIFACT}" "bootstrap/bootstrap.php"', $script);
        $this->assertStringContainsString('gh release edit "${TAG}" --draft=false --latest', $script);
        // The guard that stands between a second .zip and every installed site.
        $this->assertStringContainsString('if [[ "${ZIP_COUNT}" -ne 1 ]]', $script);
        $this->assertStringContainsString('grep -qx "${EVIDENCE_ASSET}"', $script, 'the script publishes without checking the evidence pack is attached');
    }

    public function testTheNotesCarryTheGatesTheEvidenceAndTheInventory(): void
    {
        $script = self::read(self::RELEASE_SCRIPT);
        $notes = substr($script, (int) strpos($script, '## Vérifications effectuées pour cette release'));

        $this->assertStringContainsString('printf \'%s\' "${GATE_REPORT}"', $notes);
        $this->assertStringContainsString('gh release view "${TAG}" --json body -q .body', $notes, 'the workflow\'s description of the pack is dropped from the notes');
        $this->assertStringContainsString('dependency-inventory.php', $notes, 'the dependency inventory no longer reaches the notes');
        $this->assertStringContainsString('## Dépendances livrées', self::read('scripts/dependency-inventory.php'));
    }

    public function testARerunAfterARedWorkflowDoesNotDieOnAnUnchangedVersionFile(): void
    {
        $script = self::read(self::RELEASE_SCRIPT);

        $this->assertStringContainsString('if git diff --quiet -- VERSION; then', $script);
        $this->assertStringContainsString('git push --delete origin ${TAG} && git tag -d ${TAG}', $script, 'the recovery from a red workflow is no longer written where the failure is printed');
    }

    /**
     * The doc that carries the ruleset state and the reproduction table
     * both name the jobs as a pull request shows them. A renamed job here
     * is a stale table there (AGENTS.md § Pipeline documentation
     * maintenance).
     */
    public function testTheDocumentationNamesTheChecksAsGitHubDisplaysThem(): void
    {
        foreach (['docs/quality-pipeline.md', '.claude/skills/steward/SKILL.md'] as $file) {
            $contents = self::read($file);
            foreach (['Checks / test', 'Checks / database-mariadb', 'Checks / End-to-end (browser)', 'Checks / Dynamic scan (passive)', 'All checks'] as $name) {
                $this->assertStringContainsString('`' . $name . '`', $contents, $file . ' does not name the check ' . $name);
            }
        }

        $map = self::read('docs/quality-pipeline.md');
        $this->assertStringContainsString('gh attestation verify', $map, 'the map no longer says how the evidence pack is verified');
        $this->assertStringContainsString('never be a `.zip`', $map, 'the map no longer records why the evidence pack is a tarball');
    }
}
