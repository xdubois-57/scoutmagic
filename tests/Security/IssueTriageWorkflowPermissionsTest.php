<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two workflows under `.github/workflows/issue-*.yml` are the only
 * ones in this repository whose input is written by a member of the
 * public: anyone with a GitHub account can open an issue here, and its
 * title and body reach a model that then acts on this repository.
 *
 * What keeps that safe is not the prompt and not the skill file — a
 * prompt is an instruction, and instructions are what a hostile issue
 * body competes with. It is the token: each job holds `issues: write` and
 * `id-token: write`, checks nothing out, and therefore has no path to
 * `main` at all, whatever anybody talks it into. Adding `contents: write`
 * "just to look at a file" would hand every future issue body a way to
 * reach the default branch, and the GitHub MCP tools already read code
 * without a working copy.
 *
 * That property is one line away from being lost, and nothing else would
 * notice: a widened `permissions:` block is a green pull request, a green
 * CI run and a working workflow. So it is asserted here rather than left
 * to review — the same reasoning as every other audit in this directory.
 *
 * Every invariant below is asserted against BOTH workflows, from one data
 * provider, rather than written once and copied. The backlog scan was
 * added after the per-issue job and inherits exactly the same guarantees;
 * a copy would have let the two drift, and the one that drifted would be
 * the one nobody re-read.
 *
 * Deliberately line-based rather than YAML-parsed: this repository ships
 * no YAML parser for PHP (no `symfony/yaml`, no `ext-yaml`), and pulling
 * a dependency in for one audit would cost more than it buys on small,
 * hand-written files. The assertions below are written to fail closed —
 * an unreadable or restructured file fails rather than passes.
 */
class IssueTriageWorkflowPermissionsTest extends TestCase
{
    /** Triage on the issue that just opened. */
    private const TRIAGE = '.github/workflows/issue-triage.yml';

    /** The nightly pass over the issues that job never saw. */
    private const BACKLOG_SCAN = '.github/workflows/issue-backlog-scan.yml';

    /** The complete set either job may hold. Nothing may be added. */
    private const ALLOWED_PERMISSIONS = [
        'issues' => 'write',
        'id-token' => 'write',
    ];

    /**
     * The workflows every invariant in this file is asserted against.
     *
     * @return array<string, array{string}>
     */
    public static function issueWorkflows(): array
    {
        return [
            'per-issue triage' => [self::TRIAGE],
            'nightly backlog scan' => [self::BACKLOG_SCAN],
        ];
    }

    /**
     * A third workflow reading issue bodies would get none of the
     * assertions below, and nothing would say so: the suite would stay
     * green over a file it has never looked at. So the provider above is
     * checked against the directory rather than trusted.
     *
     * Matching on the filename prefix rather than on content is the point
     * — a new `issue-*.yml` fails this test on the commit that adds it,
     * which is when somebody is still thinking about what it may do.
     */
    public function testEveryIssueWorkflowIsCoveredByThisAudit(): void
    {
        $found = glob(dirname(__DIR__, 2) . '/.github/workflows/issue-*.yml');

        self::assertIsArray($found, 'Could not list .github/workflows/.');

        $onDisk = array_map(
            static fn (string $path): string => '.github/workflows/' . basename($path),
            $found,
        );
        sort($onDisk);

        $covered = array_map(
            static fn (array $case): string => $case[0],
            array_values(self::issueWorkflows()),
        );
        sort($covered);

        self::assertSame(
            $onDisk,
            $covered,
            'A workflow reading issue bodies is not covered by this audit. Every `issue-*.yml` must be '
            . 'listed in issueWorkflows(), because a job that reads untrusted public input and holds a '
            . 'Claude token is exactly the thing these assertions exist for.',
        );
    }

    /**
     * The floor under everything else here: a test that reads a file
     * which is not there passes vacuously, and the suite would report
     * green over a triage pipeline that no longer exists.
     */
    #[DataProvider('issueWorkflows')]
    public function testTheWorkflowExists(string $workflow): void
    {
        self::assertFileExists(
            dirname(__DIR__, 2) . '/' . $workflow,
            $workflow . ' is missing — the tests below would pass over nothing.',
        );
    }

    /**
     * The assertion this whole file exists for. `contents:` in either of
     * these blocks would give a job that reads untrusted public text a
     * path to the default branch, and it would be a green pull request, a
     * green CI run and a working workflow while doing it.
     */
    #[DataProvider('issueWorkflows')]
    public function testItGrantsNothingBeyondIssuesAndIdToken(string $workflow): void
    {
        $blocks = $this->indentedPermissionBlocks($workflow);

        // Exactly one job per file, so exactly one job-level block. Two
        // would mean a job this test has never looked at.
        self::assertCount(
            1,
            $blocks,
            $workflow . ' has ' . count($blocks) . ' job-level `permissions:` blocks; this test '
            . 'reads one. A second job means a second permission surface nobody is checking.',
        );

        self::assertSame(
            self::ALLOWED_PERMISSIONS,
            $blocks[0],
            'The permissions of ' . $workflow . ' changed. This job reads issue bodies written by the '
            . 'public and must keep no path to the repository: `contents` in particular would give one. '
            . 'If a new permission is genuinely needed, change ALLOWED_PERMISSIONS here in the same '
            . 'commit and say why in the pull request.',
        );
    }

    /**
     * The permission block above is the boundary; this is the habit that
     * keeps anyone from needing to widen it. A checkout is only ever
     * added in order to write, so a checkout appearing here is the first
     * half of a change that ends with `contents: write`.
     */
    #[DataProvider('issueWorkflows')]
    public function testItNeverChecksTheRepositoryOut(string $workflow): void
    {
        foreach ($this->lines($workflow) as $number => $line) {
            // `uses:` lines only. The headers of those files discuss the
            // absence of a checkout at some length, and matching the
            // prose would fail on the very comment that explains the
            // rule — which is how an audit gets deleted for being noisy.
            if (preg_match('/^\s*-?\s*uses:\s*(\S+)/', $line, $m) !== 1) {
                continue;
            }

            self::assertStringNotContainsString(
                'actions/checkout',
                $m[1],
                'Line ' . ($number + 1) . ' of ' . $workflow . ' checks the repository out. '
                . 'A checkout is only ever needed in order to write; Claude reads code through the '
                . 'GitHub MCP tools, which need no working copy.',
            );
        }
    }

    /**
     * Deny-by-default at the top of the file, so that a job added later
     * without its own `permissions:` block inherits nothing rather than
     * the repository default. The failure it prevents is silent: such a
     * job runs, works, and holds write access nobody granted on purpose.
     */
    #[DataProvider('issueWorkflows')]
    public function testItStillDeniesEverythingAtTheWorkflowLevel(string $workflow): void
    {
        // Column zero, not merely somewhere: a nested `permissions: {}`
        // inside a job denies that job and says nothing about the file's
        // default, which is the whole point of this assertion. Trimming
        // before comparing — as this test first did — accepted the wrong
        // one as the right one.
        $atTopLevel = array_filter(
            $this->lines($workflow),
            static fn (string $line): bool => preg_match('/^permissions:\s*\{\s*\}\s*$/', $line) === 1,
        );

        self::assertCount(
            1,
            $atTopLevel,
            $workflow . ' must carry exactly one `permissions: {}` at column zero. Without it, a '
            . 'job that forgets its own `permissions:` block inherits the repository default — which '
            . 'is how a workflow ends up with write access nobody granted it on purpose.',
        );
    }

    /**
     * Named for what it can actually check. A 40-character hex object SHA
     * is immutable whether it names a commit or an annotated tag object,
     * and telling those apart means resolving the object against the
     * remote — which a unit test does not get to do. What it does check is
     * that no reference is a movable ref, which is the property that
     * matters: these jobs hold a Claude subscription token, so code
     * arriving through a moved `v1` would run with it.
     */
    #[DataProvider('issueWorkflows')]
    public function testEveryActionReferenceIsPinnedToAnImmutableObject(string $workflow): void
    {
        $references = 0;

        foreach ($this->lines($workflow) as $number => $line) {
            if (!str_contains($line, 'anthropics/claude-code-action@')) {
                continue;
            }

            ++$references;

            // Asserted per reference rather than accumulated into one
            // flag: a later pinned line used to overwrite an earlier
            // unpinned one, so a second, movable reference passed.
            self::assertSame(
                1,
                preg_match('/anthropics\/claude-code-action@[0-9a-f]{40}(\s|$)/', $line),
                'Line ' . ($number + 1) . ' of ' . $workflow . ' does not pin '
                . 'anthropics/claude-code-action to a 40-character object SHA, the convention '
                . 'ci.yml already follows.',
            );
        }

        self::assertGreaterThan(
            0,
            $references,
            $workflow . ' no longer references anthropics/claude-code-action at all — this test '
            . 'would otherwise pass over nothing.',
        );
    }

    /**
     * Neither workflow has a checkout, so neither `opens` its skill — each
     * asks Claude to fetch the file from `main` through the GitHub tools.
     * That indirection has a failure mode nothing else covers: rename or
     * move the file and the fetch returns nothing, while the job still
     * runs, still authenticates, still has `issues: write`, and still
     * ends `success`. Claude would triage with no method at all — and
     * since IT-03 that method is what decides whether an issue gets
     * CLOSED, and with which reason.
     *
     * A green run proving nothing is the failure this repository keeps
     * meeting (docs/quality-pipeline.md, last section), so the pairing is
     * asserted here: every skill file a prompt names must exist.
     */
    #[DataProvider('issueWorkflows')]
    public function testEverySkillTheWorkflowNamesExists(string $workflow): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $referenced = [];

        // The PROMPT only, never the whole file. Those headers discuss
        // the skill by name, so scanning every line — as this first did —
        // would keep passing after the path was deleted from the prompt,
        // reporting success over a workflow that no longer loads
        // anything. A check with that hole is worse than no check: it
        // states a guarantee it is not making.
        foreach ($this->promptLines($workflow) as $line) {
            if (preg_match_all('/(\.claude\/skills\/[A-Za-z0-9_\-\/]+\.md)/', $line, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $path) {
                $referenced[$path] = true;
            }
        }

        self::assertNotEmpty(
            $referenced,
            $workflow . "'s prompt no longer names a skill file. Either it stopped pointing at one "
            . '— in which case the triage runs with no method, including no rule about closing — or the '
            . 'reference changed shape and this test is now checking nothing.',
        );

        foreach (array_keys($referenced) as $path) {
            self::assertFileExists(
                $repoRoot . '/' . $path,
                $workflow . ' tells Claude to fetch ' . $path . ', which does not exist. The job '
                . 'would still run, still succeed, and triage with no method — including the '
                . 'decision to close an issue.',
            );
        }
    }

    /**
     * Both jobs spend model time on public input, so both must have a
     * ceiling on it. `timeout-minutes` bounds the wall clock and
     * `--max-turns` bounds the work; a job missing either can be made to
     * run until GitHub's own six-hour limit by a sufficiently confusing
     * issue, and it would report success afterwards.
     */
    #[DataProvider('issueWorkflows')]
    public function testItBoundsBothTheClockAndTheWork(string $workflow): void
    {
        $lines = $this->lines($workflow);

        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/^\s+timeout-minutes:\s*\d+\s*$/', $line) === 1,
            ),
            $workflow . ' declares no `timeout-minutes`. Without one the job inherits GitHub\'s '
            . 'six-hour default, and a confusing issue can spend six hours of the subscription.',
        );

        // The `claude_args:` line itself, never a comment mentioning it.
        // Both files explain --max-turns in prose above the line that
        // passes it, so `str_contains` over every line — as this first
        // did — kept passing after the flag was deleted from the
        // argument, which is the exact mutation it exists to catch.
        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool =>
                    preg_match('/^\s+claude_args:\s*.*--max-turns\s+\d+/', $line) === 1,
            ),
            $workflow . ' passes no `--max-turns` in its `claude_args:`. The timeout bounds the '
            . 'clock; only this bounds the work, and the two are not substitutes.',
        );
    }

    /**
     * A `schedule:` trigger only ever runs from the DEFAULT BRANCH, so
     * nothing about this workflow can be exercised on a pull request
     * branch — pushing a change to it proves the YAML parses and nothing
     * else. `workflow_dispatch` is the only way to find out whether it
     * works, before or after it merges.
     *
     * Deleting it would leave a workflow that can only be tested by
     * waiting until 03:00 and reading the logs in the morning, which in
     * practice means it stops being tested.
     */
    public function testTheBacklogScanIsScheduledAndCanAlsoBeRunByHand(): void
    {
        $lines = $this->lines(self::BACKLOG_SCAN);

        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match("/^\s+-\s*cron:\s*'\d*[1-9]\d* \d+ \* \* \*'\s*$/", $line) === 1,
            ),
            self::BACKLOG_SCAN . ' has no daily cron, or its cron fires at minute zero. GitHub defers '
            . 'and drops scheduled jobs under load, and load peaks at the top of the hour where every '
            . '`0 *` cron in the world fires at once — a dropped run here is a night of backlog nobody '
            . 'triages, and nothing reports it. Keep the minute non-zero.',
        );

        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/^\s+workflow_dispatch:\s*$/', $line) === 1,
            ),
            self::BACKLOG_SCAN . ' has no `workflow_dispatch:`. A `schedule:` trigger only runs from '
            . 'the default branch, so without a manual trigger this workflow cannot be tested at all '
            . '— not on a branch, and not after merging except by waiting for 03:00.',
        );
    }

    /**
     * The cap is the difference between draining a backlog and waking two
     * hundred reporters at three in the morning with a comment each. It
     * lives in the prompt because the prompt is what the model reads, and
     * it is asserted here because a prompt is one edit away from losing
     * a sentence nobody misses.
     *
     * The wording is matched, not merely the number: a run cannot be
     * "capped at five" by mentioning five somewhere. If a rewrite makes
     * this fail, the right response is to re-read why the cap is there
     * and then update this test deliberately — not to loosen the regex.
     */
    public function testTheBacklogScanCapsWhatOneRunCanDo(): void
    {
        $prompt = implode("\n", $this->promptLines(self::BACKLOG_SCAN));

        self::assertSame(
            1,
            preg_match('/at most the first five/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer caps a run at five issues. Without the cap, the "
            . 'first run against a real backlog posts a comment on every untriaged issue at once — and '
            . 'spends the subscription doing it.',
        );

        self::assertSame(
            1,
            preg_match('/oldest first/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer says which five. Without an order, a capped scan "
            . 'can pick the same five every night and never reach the rest of the backlog.',
        );
    }

    /**
     * The two workflows cannot share a lock: `issue-triage.yml` groups its
     * concurrency per issue number, this one groups per workflow, and
     * GitHub offers nothing that spans the two. So an issue opened while a
     * scan is running is `triage:pending` and looks untriaged to BOTH
     * agents, neither of which sees the other's comment until both have
     * posted one — and the reporter gets two verdicts on the same report.
     *
     * What closes the window is the prompt leaving brand-new issues to the
     * job that already has them. Deleting that one clause reopens it, and
     * nothing else in this repository would notice: the race needs an
     * issue opened inside a particular twenty-minute window at three in
     * the morning, so it would show up as an occasional inexplicable
     * double comment long after the change that caused it.
     */
    public function testTheBacklogScanStandsClearOfThePerIssueWorkflow(): void
    {
        $prompt = implode(' ', array_map('trim', $this->promptLines(self::BACKLOG_SCAN)));

        self::assertSame(
            1,
            preg_match('/opened more than one hour ago/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer excludes issues opened in the last hour. "
            . 'issue-triage.yml is triaging those already, and the two jobs hold no lock in common, '
            . 'so both can decide an issue is untriaged and both can post a verdict on it.',
        );
    }

    /**
     * The lines of the step's `prompt:` block scalar — what the model is
     * actually told, with the file's own commentary about it left out.
     *
     * @return array<int, string>
     */
    private function promptLines(string $workflow): array
    {
        $prompt = [];
        $indent = null;

        foreach ($this->lines($workflow) as $line) {
            if ($indent === null) {
                if (preg_match('/^(\s+)prompt:\s*\|/', $line, $match) === 1) {
                    $indent = strlen($match[1]);
                }

                continue;
            }

            // A blank line belongs to the scalar; the block ends at the
            // first non-blank line no deeper than the `prompt:` key.
            if (trim($line) === '') {
                $prompt[] = $line;

                continue;
            }

            if (preg_match('/^(\s*)\S/', $line, $depth) === 1 && strlen($depth[1]) <= $indent) {
                break;
            }

            $prompt[] = $line;
        }

        self::assertNotNull(
            $indent,
            $workflow . ' has no `prompt: |` block. The workflow runs in automation mode, so without '
            . 'one it tells the model nothing — and this test would silently check an empty set.',
        );

        return $prompt;
    }

    /**
     * Every job-level `permissions:` block, as a map of what it grants.
     *
     * Scoped deliberately. The first version of this test scanned the
     * whole file for `key: read|write|none` lines, which had two holes a
     * review found: an inline `permissions: { contents: write }` granted
     * something no line of that shape would show, and two unrelated
     * `issues: write` / `id-token: write` lines anywhere in the file could
     * reconstruct the expected set while the real block said something
     * else. Both are refused here — an inline mapping fails outright, and
     * only the lines indented inside the block are read.
     *
     * @return array<int, array<string, string>>
     */
    private function indentedPermissionBlocks(string $workflow): array
    {
        $lines = $this->lines($workflow);
        $blocks = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s+)permissions:(.*)$/', $line, $m) !== 1) {
                continue;
            }

            [, $indent, $rest] = $m;

            // An inline mapping is legal YAML and unreadable here, so it
            // is refused rather than skipped — skipping it is exactly how
            // a grant would pass unseen.
            self::assertSame(
                '',
                trim($rest),
                'The job-level `permissions:` in ' . $workflow . ' is written inline. Write it as '
                . 'an indented block, one `key: value` per line, so this audit can read what it grants.',
            );

            $granted = [];

            for ($i = $index + 1; $i < count($lines); $i++) {
                $next = $lines[$i];

                if (trim($next) === '' || preg_match('/^\s*#/', $next) === 1) {
                    continue;
                }

                // The block ends at the first line no deeper than the
                // `permissions:` key itself.
                if (preg_match('/^(\s*)\S/', $next, $n) === 1 && strlen($n[1]) <= strlen($indent)) {
                    break;
                }

                if (preg_match('/^\s+([a-z][a-z-]*):\s*(read|write|none)\s*$/', $next, $g) === 1) {
                    // `none` grants nothing and is never a widening.
                    if ($g[2] !== 'none') {
                        $granted[$g[1]] = $g[2];
                    }

                    continue;
                }

                self::fail(
                    'Unreadable line inside the `permissions:` block of ' . $workflow . ': '
                    . trim($next) . ' — this audit fails closed rather than ignore what it cannot parse.',
                );
            }

            $blocks[] = $granted;
        }

        return $blocks;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $workflow): array
    {
        $path = dirname(__DIR__, 2) . '/' . $workflow;
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'Could not read ' . $workflow . '.');

        return explode("\n", $contents);
    }
}
