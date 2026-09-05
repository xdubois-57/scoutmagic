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
        //
        // Asserted per line rather than once for the file: issue-triage.yml
        // invokes the agent twice, and a retry given no ceiling is a
        // retry that can run until `timeout-minutes` on the one issue
        // confusing enough to need it. Accumulating into a single flag
        // would let the second `claude_args:` say anything at all.
        $arguments = array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^\s+claude_args:\s*\S/', $line) === 1,
        );

        self::assertNotEmpty(
            $arguments,
            $workflow . ' passes no `claude_args:` at all — this assertion would otherwise pass '
            . 'over nothing, and the GitHub MCP server it names is also what starts that server.',
        );

        foreach ($arguments as $number => $line) {
            self::assertSame(
                1,
                preg_match('/--max-turns\s+\d+/', $line),
                'Line ' . ($number + 1) . ' of ' . $workflow . ' passes no `--max-turns` in its '
                . '`claude_args:`. The timeout bounds the clock; only this bounds the work, and '
                . 'the two are not substitutes.',
            );
        }
    }

    /**
     * A run of the per-issue workflow that triaged nothing at all used to
     * end `success`, and that was the loudest signal anybody got.
     *
     * On 2026-09-05 four issues opened within nine minutes (#172–#175)
     * produced four green runs and four issues still carrying
     * `triage:pending`. #172 got its verdict comment and never got its
     * labels; the other three got neither, inside their turn budget, with
     * no timeout and no permission denial. `claude-code-action` exits 0
     * whenever the agent's turn ends normally, and "ended normally having
     * written nothing" is a normal end — so the job could not tell the two
     * apart, and neither could anybody reading the Actions tab.
     *
     * What tells them apart is the issue itself: `triage:done` present and
     * `triage:pending` gone. The labels are the state
     * (docs/quality-pipeline.md § Labels), so reading them back is the
     * only assertion available that a reporter actually got an answer.
     *
     * This is the habit at the end of that document, applied to the
     * workflow that most needed it: ask what a green result would look
     * like if the thing had not run at all. Delete this check and the
     * answer goes back to "the same".
     */
    public function testThePerIssueTriageChecksWhetherTheVerdictActuallyLanded(): void
    {
        $scripts = $this->runScripts(self::TRIAGE);

        self::assertNotEmpty(
            $scripts,
            self::TRIAGE . ' runs no shell script of its own. The agent step cannot report a triage '
            . 'that did not happen — it exits 0 on an agent that wrote nothing — so without a step '
            . 'that reads the outcome back, the job cannot fail for the one reason it exists.',
        );

        // The scripts that read the labels back, found by what they call
        // rather than by what they say. An earlier version of this test
        // searched every script for the two label names and passed a
        // mutation that gutted the check but left it named in an error
        // message — the same hole the header of promptLines() describes,
        // met again three tests later.
        $checks = array_filter(
            $scripts,
            static fn (string $script): bool => str_contains($script, '/labels'),
        );

        self::assertNotEmpty(
            $checks,
            self::TRIAGE . ' no longer reads the issue\'s labels back from GitHub. Nothing else in '
            . 'this pipeline can distinguish an issue that was triaged from one the agent silently '
            . 'gave up on.',
        );

        // Both halves of the verdict, asserted as the expression that
        // decides rather than as the label names anywhere in the file.
        // `triage:done` is the agent saying it reached a verdict; the
        // ABSENCE of `triage:pending` is what stops the nightly scan
        // triaging the issue all over again. An issue carrying both is
        // the half-finished state #172 was left in, and a check that
        // tested only the first would have called it triaged.
        foreach ($checks as $script) {
            foreach (['index("triage:done") != null', 'index("triage:pending") == null'] as $half) {
                self::assertStringContainsString(
                    $half,
                    $script,
                    self::TRIAGE . ' reads the labels back but no longer tests `' . $half . '`. '
                    . 'Both halves are the verdict, and a check missing either one reports an '
                    . 'untriaged issue as triaged — which is the failure it was added to catch.',
                );
            }
        }

        self::assertNotEmpty(
            array_filter($scripts, static fn (string $script): bool => str_contains($script, 'exit 1')),
            self::TRIAGE . ' checks its outcome and does nothing with the answer. A job that knows '
            . 'the reporter was never answered and still reports success is the defect this check '
            . 'was added for, one step further along.',
        );
    }

    /**
     * Verifying alone would turn a silent failure into a red run, which is
     * better and still leaves the reporter unanswered. The retry is what
     * fixes the issue rather than merely reporting it, and every failure
     * observed so far would have been fixed by one: whatever ends an
     * attempt early is transient and short.
     *
     * A second attempt is only safe because the prompt re-checks for an
     * existing verdict immediately before it writes — see
     * testThePromptCanBeRunTwiceOnTheSameIssue(). Those two assertions
     * hold each other up: drop the re-check and this retry starts posting
     * a second verdict on somebody's report.
     */
    public function testThePerIssueTriageTriesAgainBeforeGivingUp(): void
    {
        $attempts = array_filter(
            $this->lines(self::TRIAGE),
            static fn (string $line): bool =>
                preg_match('/^\s+uses:\s*anthropics\/claude-code-action@/', $line) === 1,
        );

        self::assertCount(
            2,
            $attempts,
            self::TRIAGE . ' invokes the agent ' . count($attempts) . ' time(s); it must be twice — '
            . 'an attempt and one retry. With a single attempt the workflow can report that an issue '
            . 'went untriaged but can do nothing about it, and the reporter waits for the nightly '
            . 'backlog scan instead of minutes.',
        );

        // The retry is CONDITIONAL, and on the check rather than on
        // anything the action reports about itself: the action's own
        // outcome is `success` on an agent that wrote nothing, which is
        // precisely the case that needs retrying. An unconditional retry
        // would also pay for a second full triage of every issue the
        // first attempt got right.
        $lines = $this->lines(self::TRIAGE);

        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/^\s+id:\s*first_check\s*$/', $line) === 1,
            ),
            self::TRIAGE . ' has no step with `id: first_check`. Deleting the step that reads the '
            . 'labels back leaves the retry gated on an output nothing produces — which in GitHub '
            . 'Actions is an empty string, not an error, so every run would retry and the job would '
            . 'never fail for an untriaged issue.',
        );

        $gated = array_filter(
            $lines,
            static fn (string $line): bool =>
                preg_match('/^\s+if:\s*steps\.first_check\.outputs\.landed\s*!=\s*.true./', $line) === 1,
        );

        self::assertGreaterThanOrEqual(
            2,
            count($gated),
            self::TRIAGE . ' no longer gates both the retry and the final failure on '
            . '`steps.first_check.outputs.landed`. Ungated, the retry triages every issue twice; '
            . 'and a final check that runs unconditionally would fail the job on issues the first '
            . 'attempt triaged perfectly well.',
        );
    }

    /**
     * The two attempts must be given the SAME instructions. An attempt
     * that retries with a different prompt is a different triage, and the
     * difference would stay invisible until the day the two disagreed
     * about whether an issue may be closed — the outcome
     * `.claude/skills/triage/SKILL.md` guards hardest.
     *
     * GitHub Actions supports no YAML anchors, so the prompt lives once
     * under the job's `env:` and both steps reference it. That is the only
     * shape in which the two cannot drift, which is why it is asserted
     * rather than left to whoever next edits one of the two steps. It is
     * also what lets promptLines() keep reading one prompt per workflow.
     */
    public function testBothTriageAttemptsAreGivenTheSamePrompt(): void
    {
        $inputs = array_values(array_filter(
            $this->lines(self::TRIAGE),
            static fn (string $line): bool => preg_match('/^\s+prompt:\s*\S/', $line) === 1,
        ));

        self::assertCount(
            2,
            $inputs,
            self::TRIAGE . ' passes ' . count($inputs) . ' `prompt:` input(s); one per agent step is '
            . 'two. A step given no prompt runs in mention mode and triages nothing at all.',
        );

        foreach ($inputs as $line) {
            self::assertSame(
                1,
                preg_match('/^\s+prompt:\s*\$\{\{\s*env\.TRIAGE_PROMPT\s*\}\}\s*$/', $line),
                self::TRIAGE . ' writes a prompt inline on an agent step instead of referencing '
                . '`${{ env.TRIAGE_PROMPT }}`. Two inline copies drift, and the one that drifts is '
                . 'the retry — the attempt nobody reads, because it only runs when something has '
                . 'already gone wrong.',
            );
        }
    }

    /**
     * The prompt carries two clauses that the retry above depends on, and
     * neither is decoration.
     *
     * The re-check is what makes running the same prompt twice safe: the
     * second attempt looks at the issue as it is at that moment and, if a
     * verdict is already there, sets the missing labels instead of posting
     * a second one. Placed immediately before the write and nowhere
     * earlier — reaching a verdict takes minutes, and a comment landing
     * inside that window is exactly what it guards against. This is the
     * same reasoning issue-backlog-scan.yml's own prompt is built on.
     *
     * The other clause says the labels are not the optional half. #172 is
     * why it is spelled out: a perfectly good verdict comment, posted, and
     * `triage:pending` still on the issue — which reads to every other
     * part of this pipeline exactly like an issue nobody looked at.
     */
    public function testThePromptCanBeRunTwiceOnTheSameIssue(): void
    {
        $prompt = implode(' ', array_map('trim', $this->promptLines(self::TRIAGE)));

        self::assertSame(
            1,
            preg_match('/before writing anything/i', $prompt),
            self::TRIAGE . "'s prompt no longer re-checks for an existing verdict immediately before "
            . 'it writes. The workflow runs this prompt a second time whenever the first attempt left '
            . "no verdict, so without that clause the retry posts a second verdict on somebody's "
            . 'report — and the reporter is the one who pays for the fix.',
        );

        self::assertSame(
            1,
            preg_match('/failed triage/i', $prompt),
            self::TRIAGE . "'s prompt no longer tells the agent that a verdict comment without the "
            . 'labels is a failed triage. That is not a nicety: the labels are the state, and an '
            . 'issue left carrying `triage:pending` is picked up and triaged from scratch by the '
            . 'nightly scan, which pays for the same analysis twice and tells the reporter nothing.',
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

        // The floor covers the ordinary overlap. It does not cover a
        // reopened issue, which fires the per-issue workflow on an issue
        // of any age, nor that workflow running late. What covers those is
        // WHERE the verdict check sits: triage takes minutes, so a check
        // performed before that work looks correct and covers nothing.
        self::assertSame(
            1,
            preg_match('/before writing anything/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer re-checks for an existing verdict immediately "
            . 'before it writes. Moved any earlier, that check spans the minutes the triage itself '
            . 'takes, and a verdict landing inside that window still produces a second comment on '
            . "somebody's report.",
        );
    }

    /**
     * The lines of the block scalar holding the prompt — what the model is
     * actually told, with the file's own commentary about it left out.
     *
     * Two spellings, because the two workflows differ in one respect that
     * is not cosmetic. The backlog scan runs the agent once and writes its
     * prompt inline under `prompt: |`. The per-issue workflow runs the
     * agent TWICE — an attempt and a retry — and both must be given the
     * identical string, so it defines it once under the job's
     * `TRIAGE_PROMPT: |` and references that from each step. GitHub
     * Actions supports no YAML anchors, so a job-level `env:` entry is the
     * only way one prompt reaches two steps; testBothTriageAttemptsAre\
     * GivenTheSamePrompt() is what holds that shape in place.
     *
     * @return array<int, string>
     */
    private function promptLines(string $workflow): array
    {
        $prompt = [];
        $indent = null;

        foreach ($this->lines($workflow) as $line) {
            if ($indent === null) {
                if (preg_match('/^(\s+)(?:prompt|TRIAGE_PROMPT):\s*\|/', $line, $match) === 1) {
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
            $workflow . ' has no `prompt: |` or `TRIAGE_PROMPT: |` block. The workflow runs in '
            . 'automation mode, so without one it tells the model nothing — and this test would '
            . 'silently check an empty set.',
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
     * Every `run:` block scalar in the file — the shell the job executes,
     * with the YAML around it and the file's own commentary left out.
     *
     * Scoped the same way promptLines() is, and for the same reason: the
     * header of issue-triage.yml discusses `triage:pending` at length, so
     * a test scanning every line for that string would keep passing after
     * the check that reads it had been deleted. An audit with that hole is
     * worse than no audit — it states a guarantee it is not making.
     *
     * @return array<int, string>
     */
    private function runScripts(string $workflow): array
    {
        $scripts = [];
        $indent = null;
        $current = [];

        foreach ($this->lines($workflow) as $line) {
            if ($indent !== null) {
                // A blank line belongs to the scalar; the block ends at
                // the first non-blank line no deeper than the `run:` key.
                if (trim($line) === '') {
                    $current[] = $line;

                    continue;
                }

                if (preg_match('/^(\s*)\S/', $line, $depth) === 1 && strlen($depth[1]) > $indent) {
                    $current[] = $line;

                    continue;
                }

                $scripts[] = implode("\n", $current);
                $indent = null;
                // Fall through: the line that ended this block may itself
                // open the next one.
            }

            if (preg_match('/^(\s+)run:\s*\|\s*$/', $line, $match) === 1) {
                $indent = strlen($match[1]);
                $current = [];
            }
        }

        if ($indent !== null) {
            $scripts[] = implode("\n", $current);
        }

        return $scripts;
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
