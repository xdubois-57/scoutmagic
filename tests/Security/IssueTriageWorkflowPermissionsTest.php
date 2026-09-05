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
     * A run of either workflow that triaged nothing at all used to end
     * `success`, and that was the loudest signal anybody got.
     *
     * On 2026-09-05 four issues opened within nine minutes (#172–#175)
     * produced four green per-issue runs and four issues still carrying
     * `triage:pending`. #172 got its verdict comment and never got its
     * labels; the other three got neither, inside their turn budget, with
     * no timeout and no permission denial. The backlog scan — the safety
     * net for exactly that — then met the same failure on its own first
     * run: four candidates, one handled, 29 turns of a 180-turn budget,
     * `success`. `claude-code-action` exits 0 whenever the agent's turn
     * ends normally, and "ended normally having written nothing" is a
     * normal end, so neither job could tell the two apart and neither
     * could anybody reading the Actions tab.
     *
     * What tells them apart is GitHub's own state — the labels on the
     * issue, the size of the backlog — read back after the agent. Hence
     * the two properties asserted here for both workflows: a shell step
     * that asks GitHub what actually happened, and a way for the job to
     * fail when the answer is "nothing". What each one reads is asserted
     * per workflow below, because the two measure different things.
     *
     * This is the habit at the end of docs/quality-pipeline.md, applied
     * to the two workflows that most needed it: ask what a green result
     * would look like if the thing had not run at all. Delete these
     * checks and the answer goes back to "the same".
     */
    #[DataProvider('issueWorkflows')]
    public function testItChecksItsOwnOutcomeAndCanFailForIt(string $workflow): void
    {
        $scripts = $this->runScripts($workflow);

        self::assertNotEmpty(
            $scripts,
            $workflow . ' runs no shell script of its own. The agent step cannot report a triage '
            . 'that did not happen — it exits 0 on an agent that wrote nothing — so without a step '
            . 'that reads the outcome back, the job cannot fail for the one reason it exists.',
        );

        self::assertNotEmpty(
            array_filter($scripts, static fn (string $script): bool => str_contains($script, 'gh api')),
            $workflow . ' no longer asks GitHub what actually happened. Its own step outcomes cannot '
            . 'answer that question: they are `success` either way.',
        );

        self::assertNotEmpty(
            array_filter($scripts, static fn (string $script): bool => str_contains($script, 'exit 1')),
            $workflow . ' checks its outcome and does nothing with the answer. A job that knows the '
            . 'reporter was never answered and still reports success is the defect this check was '
            . 'added for, one step further along.',
        );
    }

    /**
     * What the per-issue job reads back, and why both halves of it.
     *
     * `triage:done` present is the agent saying it reached a verdict; the
     * ABSENCE of `triage:pending` is what stops the nightly scan triaging
     * the issue all over again. An issue carrying both is the
     * half-finished state #172 was left in — a careful, correct, published
     * verdict comment that every other part of this pipeline reads as an
     * issue nobody opened — and a check testing only the first half would
     * have called it triaged.
     *
     * Asserted as the expression that decides, never as the label names
     * somewhere in the file. An earlier version searched the scripts for
     * the two names and passed a mutation that gutted the predicate but
     * left `triage:pending` in an error message: the exact hole the header
     * of promptLines() describes, met again three tests later.
     */
    public function testThePerIssueTriageReadsBothHalvesOfTheVerdictBack(): void
    {
        $checks = array_filter(
            $this->runScripts(self::TRIAGE),
            static fn (string $script): bool => str_contains($script, '/labels'),
        );

        self::assertNotEmpty(
            $checks,
            self::TRIAGE . ' no longer reads the issue\'s labels back from GitHub. Nothing else in '
            . 'this pipeline can distinguish an issue that was triaged from one the agent silently '
            . 'gave up on.',
        );

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
    }

    /**
     * What the backlog scan reads back, which cannot be "the backlog is
     * empty": the cap is five a night by design, so a backlog of two
     * hundred leaves a hundred and ninety-five behind and that is a
     * COMPLETE run. The only honest measure is what the run cleared
     * against what it selected, which is why the candidates are counted
     * before the agent as well as after.
     *
     * Two details in that counting are load-bearing, and both fail
     * silently when wrong:
     *
     * `/issues` returns PULL REQUESTS as well as issues. They carry no
     * triage label, so without `.pull_request == null` every open pull
     * request counts as an untriaged issue — the job would then demand the
     * agent triage things that are not issues and fail every night for it.
     *
     * The cutoff is captured ONCE, before the agent, and reused after. It
     * has to be: candidates exclude issues opened in the last hour, so an
     * issue fifty-nine minutes old when the run starts is sixty-five
     * minutes old when it ends. Recomputing "an hour ago" afterwards would
     * let that issue join the set mid-run and read as work the agent
     * failed to do — a red run for an issue that was never selected.
     */
    public function testTheBacklogScanMeasuresItselfAgainstWhatItSelected(): void
    {
        $scripts = $this->runScripts(self::BACKLOG_SCAN);

        // The step that counts BEFORE the agent, bound to its id rather
        // than to a count of scripts that mention a label. Counting was
        // not enough: deleting this step left the two later counts in
        // place, so `>= 2` still held while every output it feeds —
        // `cutoff`, `candidates`, `expected` — became the empty string.
        $before = array_filter(
            $this->stepBlocks(self::BACKLOG_SCAN),
            static fn (string $step): bool => preg_match('/^\s+id:\s*before\s*$/m', $step) === 1,
        );

        self::assertCount(
            1,
            $before,
            self::BACKLOG_SCAN . ' has no single step with `id: before`. The backlog has to be '
            . 'counted before the agent as well as after: the cap is five a night, so "issues '
            . 'remain" is the normal outcome and only the difference says whether the run did what '
            . 'it selected.',
        );

        // The WRITE to `$GITHUB_OUTPUT`, not the name appearing anywhere
        // in the script. Every one of these is also a shell variable in
        // that same step (`cutoff="$(date …)"`), so a substring match
        // found the assignment and passed while the output it publishes
        // had been deleted.
        foreach (['cutoff', 'candidates', 'expected'] as $output) {
            self::assertSame(
                1,
                preg_match('/^\s*echo\s+"' . $output . '=.*GITHUB_OUTPUT/m', (string) reset($before)),
                self::BACKLOG_SCAN . ' no longer publishes `' . $output . '` to $GITHUB_OUTPUT from '
                . 'its `before` step. Every later step reads it through `steps.before.outputs`, and '
                . 'a missing output is the empty string in GitHub Actions, not an error — so the '
                . 'arithmetic that decides the job\'s verdict would silently be done against '
                . 'nothing.',
            );
        }

        $counts = array_filter(
            $scripts,
            static fn (string $script): bool => str_contains($script, 'triage:pending'),
        );

        self::assertGreaterThanOrEqual(
            2,
            count($counts),
            self::BACKLOG_SCAN . ' counts the backlog fewer than twice — before the agent and '
            . 'after it are both needed, since only the difference is meaningful.',
        );

        foreach ($counts as $script) {
            self::assertStringContainsString(
                '.pull_request == null',
                $script,
                self::BACKLOG_SCAN . ' counts untriaged issues without excluding pull requests. '
                . 'GitHub\'s `/issues` endpoint returns both, and a pull request carries no triage '
                . 'label — so every open one counts as an untriaged issue and the job fails every '
                . 'night demanding the agent triage things that are not issues.',
            );
        }

        self::assertNotEmpty(
            array_filter(
                $this->lines(self::BACKLOG_SCAN),
                static fn (string $line): bool =>
                    preg_match('/^\s+CUTOFF:\s*\$\{\{\s*steps\.before\.outputs\.cutoff\s*\}\}\s*$/', $line) === 1,
            ),
            self::BACKLOG_SCAN . ' no longer reuses the cutoff captured before the agent ran. '
            . 'Recomputing "an hour ago" afterwards lets an issue that was fifty-nine minutes old at '
            . 'the start join the candidate set at the end, and the job goes red over an issue it '
            . 'never selected.',
        );

        self::assertNotEmpty(
            array_filter($scripts, static fn (string $script): bool => str_contains($script, 'EXPECTED')),
            self::BACKLOG_SCAN . ' no longer compares what it cleared against what it selected. '
            . 'Comparing against an empty backlog instead would fail every run on a repository with '
            . 'more than five untriaged issues, which is the state the cap exists to produce.',
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
    #[DataProvider('issueWorkflows')]
    public function testItTriesAgainBeforeGivingUp(string $workflow): void
    {
        $attempts = array_filter(
            $this->lines($workflow),
            static fn (string $line): bool =>
                preg_match('/^\s+uses:\s*anthropics\/claude-code-action@/', $line) === 1,
        );

        self::assertCount(
            2,
            $attempts,
            $workflow . ' invokes the agent ' . count($attempts) . ' time(s); it must be twice — '
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
        $lines = $this->lines($workflow);

        self::assertNotEmpty(
            array_filter(
                $lines,
                static fn (string $line): bool => preg_match('/^\s+id:\s*first_check\s*$/', $line) === 1,
            ),
            $workflow . ' has no step with `id: first_check`. Deleting the step that reads the '
            . 'labels back leaves the retry gated on an output nothing produces — which in GitHub '
            . 'Actions is an empty string, not an error, so every run would retry and the job would '
            . 'never fail for an untriaged issue.',
        );

        // Each gate is bound to the STEP IT GUARDS, never counted. The
        // workflow carries three such lines — the wait, the retry, the
        // final check — so an assertion that merely counted two of them
        // survived deleting the one that matters: the retry's. Ungated,
        // the retry runs on every issue, and the suite stayed green while
        // it did. A review found exactly that hole in the first version
        // of this test, which is this file's own subject one level up —
        // an audit stating a guarantee it is not making.
        $steps = $this->stepBlocks($workflow);
        $gate = '/^\s+if:\s*steps\.first_check\.outputs\.landed\s*!=\s*.true./m';

        $retry = array_filter(
            $steps,
            static fn (string $step): bool => preg_match('/^\s+id:\s*second_attempt\s*$/m', $step) === 1,
        );

        self::assertCount(
            1,
            $retry,
            $workflow . ' has no single step with `id: second_attempt`. That id is how the '
            . 'retry is identified — here, and by the conditions that gate it on the check.',
        );

        self::assertSame(
            1,
            preg_match($gate, (string) reset($retry)),
            $workflow . ' does not gate the retry on `steps.first_check.outputs.landed`. '
            . 'Ungated, it runs a second full triage of every issue, including every issue the '
            . 'first attempt got right, and posts no second verdict only because the prompt '
            . 'happens to re-check for one.',
        );

        // The step that can fail the job, found by what it does rather
        // than by what it is called. Ungated it runs on every issue and
        // fails the job on the ones the first attempt triaged perfectly
        // well — and a red run that means nothing is read as no run at
        // all within the week.
        $failures = array_filter(
            $steps,
            static fn (string $step): bool => str_contains($step, 'exit 1'),
        );

        self::assertNotEmpty(
            $failures,
            $workflow . ' has no step that can fail the job — see '
            . 'testThePerIssueTriageChecksWhetherTheVerdictActuallyLanded().',
        );

        foreach ($failures as $step) {
            self::assertSame(
                1,
                preg_match($gate, $step),
                $workflow . ' has a step that can fail the job without gating it on '
                . '`steps.first_check.outputs.landed`. Every issue the first attempt triaged '
                . 'correctly would end in a red run.',
            );
        }
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
    #[DataProvider('issueWorkflows')]
    public function testBothAttemptsAreGivenTheSamePrompt(string $workflow): void
    {
        $inputs = array_values(array_filter(
            $this->lines($workflow),
            static fn (string $line): bool => preg_match('/^\s+prompt:\s*\S/', $line) === 1,
        ));

        self::assertCount(
            2,
            $inputs,
            $workflow . ' passes ' . count($inputs) . ' `prompt:` input(s); one per agent step is '
            . 'two. A step given no prompt runs in mention mode and triages nothing at all.',
        );

        foreach ($inputs as $line) {
            self::assertSame(
                1,
                preg_match('/^\s+prompt:\s*\$\{\{\s*env\.[A-Z][A-Z_]*_PROMPT\s*\}\}\s*$/', $line),
                $workflow . ' writes a prompt inline on an agent step instead of referencing the '
                . 'job-level `${{ env.…_PROMPT }}` entry. Two inline copies drift, and the one that '
                . 'drifts is the retry — the attempt nobody reads, because it only runs when '
                . 'something has already gone wrong.',
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
     * The scan's own half-finished failure, in the prompt rather than in
     * the job. On its first run it selected four candidates, triaged one,
     * and ended its turn — 29 turns of a 180-turn budget, so nothing
     * stopped it; it simply treated one issue as the task.
     *
     * The verification step catches that afterwards and the retry repairs
     * it, but both cost a full second pass over the backlog. This clause
     * is the cheap half of the fix: say that the list is the task, and
     * that an issue analysed but left carrying `triage:pending` is
     * indistinguishable from one never opened — which is exactly what the
     * next scan will pay to analyse again.
     */
    public function testTheBacklogScanPromptRefusesToStopHalfway(): void
    {
        $prompt = implode(' ', array_map('trim', $this->promptLines(self::BACKLOG_SCAN)));

        self::assertSame(
            1,
            preg_match('/finish the list/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer tells the agent to finish the issues it "
            . 'selected. Its first run triaged one candidate of four and ended successfully; '
            . 'without this clause the job relies entirely on the verification step noticing, and '
            . 'pays for a whole second pass every time it does.',
        );

        self::assertSame(
            1,
            preg_match('/do not end your turn/i', $prompt),
            self::BACKLOG_SCAN . "'s prompt no longer forbids ending the turn on an issue still "
            . 'carrying `triage:pending`. The labels are the state: an issue analysed and left '
            . 'unlabelled reads exactly like one nobody opened, to the maintainer and to the next '
            . 'scan alike.',
        );
    }

    /**
     * The lines of the block scalar holding the prompt — what the model is
     * actually told, with the file's own commentary about it left out.
     *
     * Two spellings. Both workflows run the agent TWICE — an attempt and
     * a retry — and both attempts must be given the identical string, so
     * each defines its prompt once under a job-level `env:` entry
     * (`TRIAGE_PROMPT`, `SCAN_PROMPT`) and references it from each step.
     * GitHub Actions supports no YAML anchors, so that is the only way
     * one prompt reaches two steps; testBothAttemptsAreGivenTheSame\
     * Prompt() is what holds the shape in place. `prompt: |` is still
     * accepted for a workflow that invokes the agent once.
     *
     * @return array<int, string>
     */
    private function promptLines(string $workflow): array
    {
        $prompt = [];
        $indent = null;

        foreach ($this->lines($workflow) as $line) {
            if ($indent === null) {
                if (preg_match('/^(\s+)(?:prompt|[A-Z][A-Z_]*_PROMPT):\s*\|/', $line, $match) === 1) {
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
     * The job's steps, one string each, split on the `- ` that opens a
     * step in the `steps:` list.
     *
     * What it exists for: an assertion about a step's `if:` has to be
     * bound to THAT step. issue-triage.yml gates three steps on the same
     * condition, so counting the gates tolerates losing any one of them —
     * including the retry's, whose loss costs a second full triage of
     * every issue that never needed one, silently.
     *
     * @return array<int, string>
     */
    private function stepBlocks(string $workflow): array
    {
        $blocks = [];
        $current = null;
        $indent = null;

        foreach ($this->lines($workflow) as $line) {
            // Everything before `steps:` is out of scope — `on:`'s own
            // `- cron:` entries are list items too, and reading them as
            // steps would bind an assertion to a trigger.
            if ($current === null) {
                if (preg_match('/^\s+steps:\s*$/', $line) === 1) {
                    $current = [];
                }

                continue;
            }

            if (preg_match('/^(\s+)-\s+\S/', $line, $match) === 1
                && ($indent === null || strlen($match[1]) === $indent)) {
                $indent ??= strlen($match[1]);

                if ($current !== []) {
                    $blocks[] = implode("\n", $current);
                }

                $current = [$line];

                continue;
            }

            $current[] = $line;
        }

        if ($current !== null && $current !== []) {
            $blocks[] = implode("\n", $current);
        }

        self::assertNotEmpty(
            $blocks,
            $workflow . ' has no readable `steps:` list. Every assertion built on this helper '
            . 'would check an empty set and pass over a workflow it never read.',
        );

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
