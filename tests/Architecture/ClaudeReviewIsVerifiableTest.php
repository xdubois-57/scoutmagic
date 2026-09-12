<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `Claude review` was green 105 times without ever reviewing anything.
 *
 * The reviewer really ran — real turns, real subscription usage, a real
 * model — and it never got past the first step of the command it was
 * given. Every run spent five turns and was refused exactly one tool, on a
 * one-line pull request and on a twenty-five-file one alike, and posted no
 * inline comment on any of them, while CodeRabbit was finding real defects
 * in the same diffs. Two things made that possible and this file pins
 * both.
 *
 * FIRST, THE REVIEWER COULD NOT DO THE WORK. `claude_args` named one tool,
 * the one that posts a finding, on the reasoning that the action installs
 * the inline-comment MCP server only when a tool from it is named. True,
 * and incomplete: the same list is the permission allowlist. The
 * code-review command is built entirely on subagents — every one of its
 * steps begins "launch an agent" — and `Task` was not on the list.
 *
 * SECOND, NOTHING COULD SEE THAT. The status job read the action's
 * `conclusion` output, which answers "did the action start Claude", and
 * reported "Reviewed, nothing to report" whenever it was set. An agent
 * that starts, is refused a tool and ends its turn normally sets it. That
 * was the third reading this check has had and the second one to be wrong
 * in the same shape — a proxy standing in for the fact — after the
 * duration heuristic of issue #159.
 *
 * So the assertions below are not a description of the file. Each one
 * names a single edit that would put the repository back where it was on
 * 2026-09-07, with a check that says everything is fine.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves the
 * mechanism is still wired, never that a given review was any good.
 */
final class ClaudeReviewIsVerifiableTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/claude-review.yml';

    private static function workflow(): string
    {
        $path = dirname(__DIR__, 2) . '/' . self::WORKFLOW;
        $contents = file_get_contents($path);

        self::assertIsString($contents, self::WORKFLOW . ' is unreadable');
        self::assertNotSame('', trim($contents), self::WORKFLOW . ' is empty');

        return $contents;
    }

    /**
     * Everything below reads one half of the file or the other, and a test
     * that splits on a heading which has been renamed would read the whole
     * file as the review job and pass over a permission it was meant to
     * catch.
     *
     * @return array{0: string, 1: string} the review job, then the status job
     */
    private static function jobs(): array
    {
        $workflow = self::workflow();
        $halves = explode("\n  status:\n", $workflow, 2);

        self::assertCount(
            2,
            $halves,
            'The two jobs can no longer be told apart: `status:` is not where this test expects the second '
            . 'one to start, so the permission assertions below would read the wrong job.',
        );

        return [$halves[0], $halves[1]];
    }

    /**
     * The refusals this file has decided, as the verdict reads them:
     * from `DELIBERATE_DENIALS` itself, never from the prose beside it.
     *
     * @return list<string>
     */
    private static function deliberateDenials(): array
    {
        $matched = preg_match('/^env:\n  DELIBERATE_DENIALS: "([^"]*)"$/m', self::workflow(), $found);

        self::assertSame(
            1,
            $matched,
            'The workflow no longer declares `DELIBERATE_DENIALS` as a single top-level env string. That '
            . 'declaration is the only place a refusal can be decided in a way the verdict reads, so '
            . 'without it every decision goes back into a comment nothing checks.',
        );

        return array_values(array_filter(explode(',', $found[1]), static fn (string $t): bool => $t !== ''));
    }

    private static function claudeArgs(): string
    {
        [$review] = self::jobs();

        $matched = preg_match('/claude_args:.*?(?=\n\n|\n      -|\n  [a-z])/s', $review, $found);

        self::assertSame(1, $matched, 'The review job passes no `claude_args:` at all.');

        return $found[0];
    }

    /**
     * The floor. A test reading a file that is not there passes
     * vacuously, and would report green over a reviewer nobody runs.
     */
    public function testTheReviewWorkflowIsStillThere(): void
    {
        $workflow = self::workflow();

        $this->assertStringContainsString('name: Claude review', $workflow);
        $this->assertStringContainsString('anthropics/claude-code-action@', $workflow);
        $this->assertStringContainsString('/code-review:code-review --comment', $workflow);
    }

    /**
     * A CEILING THAT BECAME A SIZE LIMIT ON PULL REQUESTS.
     *
     * `timeout-minutes` was 20, written when a review took a few minutes
     * and the number was a formality. Pull request #257 — 185 files — was
     * cancelled at 20m20s, and again at 20m21s on an independent run:
     * nothing was wrong with either, the reviewer was working when the
     * clock stopped it. `Claude review` is one of the two required
     * contexts on `main`, a cancelled required check is unmergeable, and
     * re-running cannot help because the second attempt is as long as the
     * first. So the guard against a runaway had quietly become a limit on
     * how large a change this repository can merge, and it announced
     * itself as a merge refusal rather than as anything about the
     * reviewer.
     *
     * The floor is what the largest real review needs, not what a typical
     * one costs. Raising the number is fine and lowering it below the
     * floor is the edit this catches.
     */
    public function testTheReviewerIsGivenTimeToFinishALargeDiff(): void
    {
        [$review] = self::jobs();

        $matched = preg_match('/^    timeout-minutes: (\d+)$/m', $review, $found);

        self::assertSame(
            1,
            $matched,
            'The review job declares no `timeout-minutes:`, so a review that never converges runs until '
            . "GitHub's own six-hour ceiling stops it.",
        );

        $this->assertGreaterThanOrEqual(
            60,
            (int) $found[1],
            'The review job is capped at ' . $found[1] . ' minutes. A review of the largest diff this '
            . 'repository has produced does not fit, it is cancelled rather than failed, and because '
            . '`Claude review` is required on `main` that shows up as an unmergeable pull request with '
            . 'nothing wrong in it — see docs/quality-pipeline.md § Code review.',
        );
    }

    /**
     * THE FIX ITSELF. Drop either name and the reviewing procedure is
     * refused on its first call again, which is the state this whole file
     * documents.
     */
    public function testTheReviewerMayLaunchItsAgents(): void
    {
        $args = self::claudeArgs();

        $this->assertStringContainsString(
            'Task',
            $args,
            'The code-review command launches a subagent at every step. Without `Task` granted, the first '
            . 'one is refused and the review ends having read nothing — five turns, one denial, no findings.',
        );
        $this->assertStringContainsString(
            'Agent',
            $args,
            'Both spellings are granted on purpose: naming a tool that does not exist costs nothing, and '
            . 'naming none of the ones that do costs the whole run.',
        );
    }

    /**
     * WHAT THE 106th RUN TURNED OUT TO BE. The first run whose transcript
     * named its refusals named `Skill` first, on #208, and the input said
     * what it wanted: `code-review:code-review`. The `prompt:` this
     * workflow passes is a slash command, and a slash command is invoked
     * through the `Skill` tool — so the reviewing procedure had never been
     * loaded at all, and every "review" so far was an agent improvising
     * from the diff with the tools it happened to have.
     *
     * The failure is silent by construction: a refused `Skill` call does
     * not stop the run, it just leaves the command unread.
     */
    public function testTheReviewerMayRunTheCommandItWasGiven(): void
    {
        $this->assertStringContainsString(
            'Skill',
            self::claudeArgs(),
            'The `prompt:` above is a slash command, which the agent invokes through the `Skill` tool. '
            . 'Ungranted, that call is refused, the code-review procedure is never loaded, and the run '
            . 'improvises a review instead of failing — which is exactly how the first 105 looked.',
        );
    }

    /**
     * The other three refusals on that run were one `git fetch` of the
     * pull request head, retried after each one. It reads and writes
     * nothing; a reviewer that cannot reach the commit it was asked to
     * read spends its turns working around that.
     */
    public function testTheReviewerCanFetchTheCommitItReviews(): void
    {
        $this->assertStringContainsString(
            'Bash(git fetch:*)',
            self::claudeArgs(),
            'The reviewer was refused `git fetch` of the pull request ref three times in a row on #208 '
            . 'before giving up on reading the commit locally.',
        );
    }

    /**
     * The tool that was already there, and the one thing the old list got
     * right: the action installs the inline-comment MCP server only when
     * `claude_args` names a tool from it. Remove it and findings have
     * nowhere to go — silently, since a reviewer with nothing to say and a
     * reviewer with no way to say it look identical from outside.
     */
    public function testTheReviewerCanStillPostAFinding(): void
    {
        $this->assertStringContainsString(
            'mcp__github_inline_comment__create_inline_comment',
            self::claudeArgs(),
            'Without this tool named, the action never starts the inline-comment server and no finding can '
            . 'reach the pull request.',
        );
    }

    /**
     * The command reads the pull request through `gh`, and its linking
     * rule needs a full commit sha, which is a `git rev-parse`.
     */
    public function testTheReviewerCanReadThePullRequest(): void
    {
        $args = self::claudeArgs();

        foreach (['Bash(gh pr view:*)', 'Bash(gh pr diff:*)', 'Bash(git rev-parse:*)'] as $tool) {
            $this->assertStringContainsString(
                $tool,
                $args,
                $tool . ' is declared by the code-review command itself; not granting it leaves the review '
                . 'guessing at the diff it was asked to read.',
            );
        }
    }

    /**
     * Step 1 of the command stops the whole review when it believes Claude
     * has already commented, and two things on every pull request here
     * look exactly like that: this workflow's own status comment, headed
     * "Claude review", and the maintainer's replies, which carry a Claude
     * Code signature. Neither is a review of the current diff.
     */
    public function testThePromptSaysTheStatusCommentIsNotAReview(): void
    {
        $args = self::claudeArgs();

        $this->assertStringContainsString(
            '--append-system-prompt',
            $args,
            'Nothing tells the reviewer that the status comment below is written by the workflow, so its '
            . 'first step can read it as "Claude has already commented" and stop.',
        );
        $this->assertStringContainsString(
            'not by Claude',
            $args,
            'The sentence that answers the already-commented gate has been reworded away.',
        );
        $this->assertStringContainsString(
            'fresh review of every push',
            $args,
            'The per-push guarantee `synchronize` exists for is no longer stated to the reviewer.',
        );
    }

    /**
     * A run that keeps no transcript cannot explain itself. That is not a
     * preference: three rounds of fixes to the issue triage could not find
     * its cause because no run kept one, and the first that did explained
     * it in a line. This review had the same hole for 105 runs.
     */
    public function testTheTranscriptSurvivesTheRun(): void
    {
        [$review] = self::jobs();

        $this->assertMatchesRegularExpression(
            '/^\s+show_full_output:\s*true\s*$/m',
            $review,
            'With the full output hidden, the only account of what the reviewer did is the summary line, '
            . 'and the summary line is what said "success" 105 times.',
        );
    }

    /**
     * The verdict rests on these, and a missing step output in GitHub
     * Actions is the empty string rather than an error — so an output
     * dropped here would not fail anything, it would quietly make the
     * status job's tests compare against nothing.
     */
    public function testTheRunPublishesWhatItActuallyDid(): void
    {
        [$review] = self::jobs();

        $outputs = [
            'evidence', 'turns', 'denials', 'denied_tools', 'denials_other',
            'agent_calls', 'subagents_spawned', 'subagents_completed',
        ];

        foreach ($outputs as $output) {
            $this->assertMatchesRegularExpression(
                '/^\s+' . preg_quote($output, '/') . ':\s*\$\{\{\s*steps\.evidence\.outputs\./m',
                $review,
                'The review job no longer publishes `' . $output . '`, which the status job reads to decide '
                . 'whether a review happened.',
            );
        }
    }

    /**
     * The jq program the evidence step reads the transcript with, lifted
     * out of the workflow so a test can run it. Every other assertion here
     * reads the file as text; this one runs the thing itself, because what
     * it is being asked is what the reader DOES with a shape, which no
     * amount of string matching answers.
     */
    private static function jqProgram(): string
    {
        $workflow = self::workflow();
        $opens = strpos($workflow, "if ! jq -r '");

        self::assertIsInt($opens, 'The evidence step no longer reads the transcript with jq.');

        $start = $opens + strlen("if ! jq -r '");
        // The program ends where its arguments begin, and the first of
        // those is the decided-refusal list — see
        // testTheDecidedRefusalsAreReadByTheVerdictRatherThanOnlyByAReader,
        // which is what fails first if that argument is dropped.
        $closes = strpos($workflow, "' --arg deliberate", $start);

        self::assertIsInt($closes, 'The jq program is no longer closed where this test expects to find its end.');

        return substr($workflow, $start, $closes - $start);
    }

    /**
     * Runs that program over a one-message transcript carrying
     * `$subagentStats`, and returns the `key=value` lines it wrote.
     *
     * @param  array<string, mixed>|null $subagentStats null omits the key entirely
     * @return array<string, string>
     */
    private static function evidenceFor(?array $subagentStats): array
    {
        $result = [
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => false,
            'num_turns' => 5,
            'permission_denials' => [],
            'total_cost_usd' => 0.1,
        ];

        if ($subagentStats !== null) {
            $result['subagent_stats'] = $subagentStats;
        }

        $transcript = tempnam(sys_get_temp_dir(), 'claude-review-transcript-');
        $program = tempnam(sys_get_temp_dir(), 'claude-review-filter-');

        self::assertIsString($transcript);
        self::assertIsString($program);

        try {
            file_put_contents($transcript, (string) json_encode([$result]));
            file_put_contents($program, self::jqProgram());

            $output = [];
            $status = 0;
            // The real declaration, not a stand-in: a program run without
            // the argument the workflow passes it is not the program the
            // workflow runs.
            exec(
                'jq -r -f ' . escapeshellarg($program)
                . ' --arg deliberate ' . escapeshellarg(implode(',', self::deliberateDenials()))
                . ' ' . escapeshellarg($transcript) . ' 2>&1',
                $output,
                $status,
            );

            self::assertSame(
                0,
                $status,
                "The evidence reader died on this transcript instead of describing it:\n" . implode("\n", $output),
            );

            $evidence = [];

            foreach ($output as $line) {
                [$key, $value] = explode('=', $line, 2);
                $evidence[$key] = $value;
            }

            return $evidence;
        } finally {
            unlink($transcript);
            unlink($program);
        }
    }

    /**
     * THE STATUS JOB DECIDES "DID THEY ALL COME BACK" BY COMPARING TWO
     * NUMBERS, so any pair that is equal reaches the branch that claims a
     * review happened — and `-2` equals `-2`, as does `1.5`. Testing the
     * transcript's counts for `number` alone would let a `subagent_stats`
     * this reader does not understand agree with itself into "Reviewed,
     * nothing to report", which is the failure the whole reader exists to
     * stop, arriving through the very field added to stop it. Anything
     * that is not a count has to become the `-1` the status job already
     * knows how to refuse. (CodeRabbit, PR #209.)
     */
    public function testAMalformedSubagentCountIsRefusedRatherThanBelieved(): void
    {
        if (shell_exec('command -v jq') === null) {
            self::markTestSkipped('jq is not installed, so the evidence reader cannot be run here.');
        }

        $real = self::evidenceFor(['spawned' => 3, 'completed' => 2]);

        $this->assertSame('3', $real['subagents_spawned'], 'A genuine count no longer survives the reader.');
        $this->assertSame('2', $real['subagents_completed'], 'A genuine count no longer survives the reader.');

        $malformed = [
            'equal negatives' => ['spawned' => -2, 'completed' => -2],
            'equal fractions' => ['spawned' => 1.5, 'completed' => 1.5],
            'counts as strings' => ['spawned' => '3', 'completed' => '3'],
            'counts as null' => ['spawned' => null, 'completed' => null],
            'no counts at all' => [],
            'no subagent_stats' => null,
        ];

        foreach ($malformed as $label => $stats) {
            $evidence = self::evidenceFor($stats);

            $this->assertSame(
                ['-1', '-1'],
                [$evidence['subagents_spawned'], $evidence['subagents_completed']],
                'With ' . $label . ' the reader published something other than "unreadable". Equal values '
                . 'agree with each other, and agreement is what the status job reads as a finished review.',
            );
        }
    }

    /**
     * A failed review is exactly the one whose numbers matter. Without
     * `if: always()` the evidence step is skipped on failure, every output
     * arrives empty, and the status job cannot tell "it broke" from "it
     * found nothing".
     */
    public function testTheEvidenceIsReadEvenWhenTheReviewFails(): void
    {
        [$review] = self::jobs();

        $matched = preg_match('/id: evidence\n(.*?)\n        env:/s', $review, $found);

        self::assertSame(1, $matched, 'The step that reads the run back is gone, or no longer carries `id: evidence`.');

        $this->assertStringContainsString(
            'if: always()',
            $found[1],
            'The evidence step is skipped when the review fails, so the run that most needs explaining is '
            . 'the one that explains nothing.',
        );
    }

    /**
     * A rename on one side of the two jobs is invisible: the reader
     * silently becomes the empty string. So every value the status job
     * reads back must be one the review job publishes.
     */
    public function testEveryValueTheStatusJobReadsIsOnePublishedByTheReview(): void
    {
        [$review, $status] = self::jobs();

        preg_match_all('/needs\.review\.outputs\.([a-z_]+)/', $status, $read);
        preg_match_all('/^      ([a-z_]+):\s*\$\{\{\s*steps\./m', $review, $published);

        $read = array_values(array_unique($read[1]));
        sort($read);
        $published = $published[1];

        $this->assertNotEmpty($read, 'The status job reads nothing back from the review job any more.');

        foreach ($read as $name) {
            $this->assertContains(
                $name,
                $published,
                'The status job reads `needs.review.outputs.' . $name . '`, which the review job does not '
                . 'publish. GitHub resolves that to the empty string and the verdict is decided against '
                . 'nothing.',
            );
        }
    }

    /**
     * THE OTHER HALF OF THE FIX. A reader that can only ever report good
     * news is the failure it was built to catch, one level up.
     */
    public function testTheStatusJobGoesRedWhenNoReviewCanBeShown(): void
    {
        [, $status] = self::jobs();

        $this->assertStringContainsString(
            'unreviewed=1',
            $status,
            'The status job no longer starts from "no review has been shown to have happened".',
        );
        $this->assertStringContainsString(
            'exit 1',
            $status,
            'The status job cannot fail any more, so a review that never ran is once again a green check '
            . 'and a comment saying so.',
        );
    }

    /**
     * REVIEWING A REPOSITORY MEANS COUNTING THINGS IN IT. The first run
     * that ever got past its opening call (#217) was refused nineteen
     * shell commands, and not one of them was a `gh` call or a diff: they
     * were `grep`, `ls`, `find`, `wc`, `jq` — checking a document's claims
     * against the code, which is the work. `Grep` and `Read` cover reading;
     * these cover counting, and refusing them only bought turns spent
     * rediscovering the gap.
     */
    public function testTheReviewerCanCountWhatItReads(): void
    {
        $args = self::claudeArgs();

        foreach (['grep', 'ls', 'find', 'wc', 'sort', 'head', 'cat', 'jq'] as $tool) {
            $this->assertStringContainsString(
                'Bash(' . $tool . ':*)',
                $args,
                'The reviewer was refused `' . $tool . '` on #217 while checking a document against the '
                . 'repository. It reads and writes nothing.',
            );
        }
    }

    /**
     * THE OTHER BRANCH THE STEWARD SKILL OFFERS: "grant it in
     * `claude_args`, or say in this file why it must stay denied."
     *
     * `python3 -c` and `php -r` are arbitrary code execution — granting
     * them is granting `Bash` whole under another spelling — and this job
     * holds the maintainer's subscription token and `id-token: write`
     * while reading the one input this repository does not control. So
     * they stay denied, and the file has to say so, or the next person to
     * see them in `denied_tools` grants them to make a red go away.
     */
    public function testWhatStaysDeniedIsWrittenDown(): void
    {
        [$review] = self::jobs();

        $this->assertStringNotContainsString(
            'Bash(python3',
            self::claudeArgs(),
            'The reviewer has been granted `python3`, which runs anything. That is `Bash` whole, in a job '
            . 'holding CLAUDE_CODE_OAUTH_TOKEN and reading an untrusted diff.',
        );
        $this->assertStringNotContainsString(
            'Bash(php',
            self::claudeArgs(),
            'The reviewer has been granted `php`, which runs anything through `php -r`.',
        );
        $this->assertStringContainsString(
            'CLAUDE_CODE_OAUTH_TOKEN',
            $review,
            'The reason the interpreters stay denied is no longer written next to the list. Without it the '
            . 'next refusal in `denied_tools` reads as an oversight to be granted.',
        );
    }

    /**
     * WHY THE VERDICT COUNTS REFUSALS OUTSIDE `Bash` RATHER THAN ALL OF
     * THEM. Every tool on the allowlist is granted whole except `Bash`,
     * which is granted command by command on purpose — so a refused shell
     * line is the allowlist working, and a refusal of anything else is a
     * gap in this file.
     *
     * #217 is what the old rule cost: 16 agents launched, 16 finished,
     * 6.46 USD, five findings posted on the diff — reported as "not a
     * review" because it had also tried nineteen exploratory one-liners
     * and then done without them. A check that cries wolf over its first
     * real review is a check people learn to skip, which is how one dies.
     * `Skill` on #208 is the case that must stay red, and does.
     */
    public function testOnlyARefusalOutsideBashDisqualifiesTheReview(): void
    {
        [, $status] = self::jobs();

        $matched = preg_match('/elif \[\[ "\$\{DENIALS_OTHER\}" != "0" \]\]; then/', $status);

        $this->assertSame(
            1,
            $matched,
            'The verdict no longer turns on refusals outside `Bash`. Reading `DENIALS` instead marks every '
            . 'review that explored with a shell one-liner as no review at all.',
        );

        $this->assertMatchesRegularExpression(
            '/\| Tool calls refused \|/',
            $status,
            'The refusal row is gone. It is what named `Skill` on #208, which is how the reviewer’s real '
            . 'defect was found — demoting it from the verdict must not remove it from the report.',
        );
    }

    /**
     * LAUNCHED IS NOT FINISHED, and the gap between them is a third way to
     * review nothing that the first two signals cannot see.
     *
     * On #208 the reviewer spawned three agents, collected two, and ended
     * its turn on "Waiting for the background diff-summary agent to
     * complete before proceeding to the parallel review step". A subagent
     * runs in the background unless the caller says otherwise, and waiting
     * for one by ending a turn works in a session somebody can resume;
     * nothing resumes a workflow run, so the SDK closed it `subtype:
     * success` with no comment posted. Agents launched said 3, and with
     * the tools that run had been refused now granted, refusals would say
     * 0 — the two signals of 2026-09-07 would both have passed it.
     */
    public function testAnUnfinishedReviewIsNotAReview(): void
    {
        [, $status] = self::jobs();

        $this->assertStringContainsString(
            'SUBAGENTS_COMPLETED',
            $status,
            'The status job no longer reads how many review agents came back, so a run that stopped '
            . 'half-way through the diff is once again indistinguishable from one that finished it.',
        );

        $matched = preg_match(
            '/elif \[\[ "\$\{SUBAGENTS_COMPLETED\}" != "\$\{SUBAGENTS_SPAWNED\}" \]\]; then\n(?:\s+#[^\n]*\n)*\s+verdict=/',
            $status,
            $found,
        );

        $this->assertSame(
            1,
            $matched,
            'Nothing compares the review agents launched against the ones that finished, so the verdict '
            . 'can again read "Reviewed, nothing to report" over a run that ended mid-review.',
        );
    }

    /**
     * The mitigation for the same failure, on the other side of the same
     * run. It asks a model to remember something, so it is not the guard —
     * the comparison above is — but a reviewer told to wait for its agents
     * does not reach that guard in the first place.
     */
    public function testTheReviewerIsToldNotToWaitOnABackgroundAgent(): void
    {
        $this->assertStringContainsString(
            'run_in_background',
            self::claudeArgs(),
            'The prompt no longer tells the reviewer to wait for the agents it launches. Subagents start '
            . 'in the background, and a turn ended waiting for one ends this run — nothing will wake it.',
        );
    }

    /**
     * Exactly one branch may clear the flag, and it is the one that just
     * said a review happened. A second `unreviewed=0` anywhere else is how
     * this guard gets quietly repealed by somebody making a red build go
     * away.
     */
    public function testOnlyAFinishedReviewClearsTheFlag(): void
    {
        [, $status] = self::jobs();

        $this->assertSame(
            1,
            substr_count($status, 'unreviewed=0'),
            'More than one branch of the verdict clears the unreviewed flag. Only the branch that can show '
            . 'agents ran and no tool was refused may do that.',
        );

        $verdict = strpos($status, 'Reviewed, nothing to report');
        $cleared = strpos($status, 'unreviewed=0');

        self::assertIsInt($verdict, 'The verdict for a review that found nothing is gone.');
        self::assertIsInt($cleared, 'Nothing clears the unreviewed flag, so the check can never be green.');

        $this->assertLessThan(
            $cleared,
            $verdict,
            'The flag is cleared before the branch that claims a review happened, so some other outcome '
            . 'clears it too.',
        );
    }

    /**
     * A red job whose comment never got written says only that something
     * went wrong here. The comment is the part that says what.
     */
    public function testTheCommentIsWrittenBeforeTheJobFails(): void
    {
        [, $status] = self::jobs();

        // The LAST write against the FIRST exit, in that order on purpose:
        // a second `exit 1` inserted above the comment would leave a later
        // one below it, and reading the last exit would call that fine.
        $posted = strrpos($status, 'issues/${PR_NUMBER}/comments');
        $failed = strpos($status, 'exit 1');

        self::assertIsInt($posted, 'The status job no longer posts a comment.');
        self::assertIsInt($failed, 'The status job no longer fails on an unreviewed pull request.');

        $this->assertLessThan(
            $failed,
            $posted,
            'The job fails before writing its comment, so the pull request carries a red check and no '
            . 'explanation of it.',
        );
    }

    /**
     * The reason the two jobs are separate at all. `pull-requests: write`
     * in the review job would hand the write permission to the step that
     * runs a model over a pull request head this repository does not
     * control.
     */
    public function testTheReviewerStillHoldsNoWritePermission(): void
    {
        // The two `permissions:` blocks, in file order, rather than the two
        // halves of self::jobs(): a job's documentation sits above its key,
        // so the prose introducing the status job — which quotes the very
        // permission being looked for — falls on the review job's side of
        // that split. Reading the block itself is what makes this assertion
        // about the grant rather than about a sentence describing it.
        $matched = preg_match_all(
            '/\n    permissions:\n((?:      [^\n]*\n|      #[^\n]*\n|\n)+)/',
            self::workflow(),
            $blocks,
        );

        $this->assertSame(
            2,
            $matched,
            'This workflow no longer declares exactly two `permissions:` blocks, one per job. Either a job '
            . 'lost its block — in which case it silently inherits the repository default — or a third job '
            . 'appeared that this test has never looked at.',
        );

        $review = $blocks[1][0];

        $this->assertStringContainsString(
            'pull-requests: read',
            $review,
            'The review job no longer declares its read-only access to pull requests.',
        );
        $this->assertStringNotContainsString(
            'pull-requests: write',
            $review,
            'The review job has been granted write access to pull requests. That permission belongs to the '
            . 'status job, which runs no model, and it is why there are two jobs.',
        );
        $this->assertStringContainsString(
            'pull-requests: write',
            $blocks[1][1],
            'The status job can no longer write the comment that says whether a review happened.',
        );
        $this->assertStringContainsString(
            'actions: read',
            $blocks[1][1],
            'The status job can no longer read the review job\'s timestamps. That endpoint answers without '
            . 'this permission on a public repository, so the job would keep working here and stop the day '
            . 'the repository turns private — before it has written its comment.',
        );
    }

    /**
     * `permission_denials_count` is the ACTION's console summary, computed
     * in base-action/src/run-claude-sdk.ts as
     * `resultMsg.permission_denials?.length ?? 0`. The execution file is
     * written from the RAW SDK messages a few lines later, and those carry
     * the `permission_denials` ARRAY and no count at all.
     *
     * Reading the console's field name out of the file yields null, which
     * this step turns into `-1`, and the status job would have called every
     * clean review a refusal — a check red on every pull request forever,
     * naming no tool. CodeRabbit caught it on the pull request that added
     * this file.
     */
    public function testTheRefusalCountComesFromTheArrayTheSdkActuallyWrites(): void
    {
        [$review] = self::jobs();

        $this->assertStringContainsString(
            'has("permission_denials")',
            $review,
            'The evidence step no longer reads the `permission_denials` array the SDK writes.',
        );
        $this->assertStringNotContainsString(
            '$r.permission_denials_count',
            $review,
            'The evidence step reads `permission_denials_count` off the execution file. That field exists '
            . 'only in the action\'s console summary; the file carries the array. The read returns null, '
            . 'and every clean review is reported as a refusal.',
        );
    }

    /**
     * The evidence step runs under `set -euo pipefail` inside the job that
     * owns the one required check on `main`. A jq that dies on an
     * unexpected shape would fail the step, fail the job, and block every
     * merge — over a reader that could not read a transcript.
     */
    public function testAnUnreadableTranscriptCannotFailTheRequiredCheck(): void
    {
        [$review] = self::jobs();

        $this->assertStringContainsString(
            'if ! jq -r',
            $review,
            'The evidence extraction is no longer guarded, so a transcript this step cannot parse fails the '
            . 'review job itself — turning the required check red over the reader rather than the review.',
        );

        $guarded = strpos($review, 'if ! jq -r');
        $appended = strpos($review, 'cat evidence.env');

        self::assertIsInt($appended, 'The evidence step no longer appends what jq produced.');
        $this->assertLessThan(
            $appended,
            $guarded,
            'The outputs are appended before jq has been shown to succeed, so a half-written extraction can '
            . 'still reach the status job.',
        );
    }

    /**
     * A REFUSAL IS RED UNLESS IT WAS DECIDED, and "decided" needs
     * somewhere to be written that the verdict actually reads.
     *
     * It did not have one. The refusal count excluded the single literal
     * `Bash`, and every other tool's reason lived in a comment — so each
     * tool decided after `Bash` was decided where the check could not see
     * it, and the check called three working reviews "not a review" over
     * three different tools in three pull requests: `Bash` on #224,
     * `Write` on #260, `WebFetch` on #259 (issue #261). Three in a row is
     * a series, and naming a fourth tool in the `jq` would only have
     * added a term to it.
     */
    public function testTheDecidedRefusalsAreReadByTheVerdictRatherThanOnlyByAReader(): void
    {
        [$review] = self::jobs();

        $this->assertNotEmpty(
            self::deliberateDenials(),
            'The list of decided refusals is empty, so every refusal counts as a gap — including the '
            . '`Bash` lines the allowlist refuses on purpose, on every review.',
        );

        $this->assertStringContainsString(
            '--arg deliberate "${DELIBERATE_DENIALS:-}"',
            $review,
            'The evidence step no longer passes the decided refusals to `jq`, so the count it publishes '
            . 'is taken against something other than what this file declared.',
        );

        $this->assertStringContainsString(
            '($deliberate | split(",")',
            $review,
            'The `jq` filter no longer reads the decided refusals as a list. A single hard-coded tool name '
            . 'in its place is what issue #261 was: correct for one tool and one pull request at a time.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/select\(\. != "Bash"\)/',
            $review,
            'The refusal count is filtering on a hard-coded `Bash` again. Every tool decided after it '
            . 'would be counted as a gap this file forgot, which is the defect, not the fix.',
        );
    }

    /**
     * `Write` AND `WebFetch` ARE REFUSED, NOT GRANTED, and that is a
     * decision rather than an omission.
     *
     * Issue #261 left the choice open and run 34260813534 settled it: the
     * two `Write` calls it was refused were both a throwaway script to
     * check string literals against a file, in a run of twenty-three
     * refusals that also included `python3`, a heredoc, a loop and a pipe.
     * Granting `Write` would have cleared two of twenty-three and left the
     * review red at the next loop — the reviewer did not need to write, it
     * needed to run code, and that is the thing this job will not grant.
     *
     * `WebFetch` is not the same call at all: a file in a throwaway
     * workspace is inert, an outbound request to a URL the agent chose,
     * from a job holding CLAUDE_CODE_OAUTH_TOKEN and `id-token: write`
     * while reading an untrusted diff, is an exfiltration channel.
     */
    public function testTheToolsThatStayRefusedAreNotQuietlyGrantedInstead(): void
    {
        $args = self::claudeArgs();
        $decided = self::deliberateDenials();

        foreach (['Write', 'WebFetch', 'ScheduleWakeup', 'Monitor'] as $tool) {
            $this->assertContains(
                $tool,
                $decided,
                'The refusal of `' . $tool . '` is no longer declared, so the next review that asks for it '
                . 'goes red as a gap in this file rather than as the decision it is.',
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/--allowedTools "[^"]*\bWebFetch\b/',
            $args,
            '`WebFetch` has been granted. This job holds the maintainer\'s subscription token and '
            . '`id-token: write` while reading a diff anybody can write, and an outbound request to a URL '
            . 'the agent chose is the one refusal in this file that cannot be traded for convenience.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/--allowedTools "[^"]*\bScheduleWakeup\b/',
            $args,
            '`ScheduleWakeup` has been granted. A workflow run is one-shot: there is no later turn to '
            . 'schedule, and a reviewer that reaches for one ends the run with its agents still reading.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/--allowedTools "[^"]*\bMonitor\b/',
            $args,
            '`Monitor` has been granted, and it is the same refusal as `ScheduleWakeup` under another '
            . 'name: arranging to be called back later, in a run nothing calls back. Issue #300 is what '
            . 'happens when only one of the two is closed.',
        );
    }

    /**
     * THE OTHER HALF OF ISSUE #262, and the cheap half. The guard that
     * catches a truncated review is the launched-against-finished
     * comparison above; this is what stops the reviewer reaching that
     * guard in the first place.
     *
     * `ScheduleWakeup` appeared in the tool list of every truncated run of
     * 2026-09-08 — 7 agents launched and 3 collected, twice on the same
     * commit — and in neither of the two complete reviews of the same day.
     * A reviewer that schedules a wake-up has ended its turn, and nothing
     * wakes a workflow up: the SDK closes the run a success with four
     * agents still reading. Telling it so costs a sentence.
     *
     * **It happened again on 2026-09-10, and that is why the sentence
     * names two tools.** Run 34447638775 stopped at 7 launched and 3
     * finished — the same figures — with `ScheduleWakeup` absent from its
     * tool list entirely and `Monitor` in its place, the only refusal of
     * that run outside the decided list (issue #300). Refusing a tool by
     * name had closed the door taken first rather than the behaviour, and
     * the behaviour is one sentence: nothing will wake this run up, so do
     * not stop. A THIRD name on a truncated pass means this assertion, and
     * the list it reads, should stop being an enumeration.
     */
    public function testTheReviewerIsToldWhyItCannotScheduleAWakeUp(): void
    {
        $args = self::claudeArgs();

        $this->assertStringContainsString(
            'ScheduleWakeup and Monitor are not granted and cannot help you',
            $args,
            'The prompt no longer tells the reviewer that arranging to be called back later cannot work '
            . 'here. That is what every truncated review of 2026-09-08 and 2026-09-10 did instead of '
            . 'waiting for its agents — under two different tool names.',
        );

        foreach (self::deliberateDenials() as $tool) {
            if ($tool === 'Bash') {
                // Granted command by command, and the prompt says which
                // shapes are refused rather than naming the tool itself.
                continue;
            }

            $this->assertStringContainsString(
                $tool,
                $args,
                'The prompt never mentions `' . $tool . '`, which this workflow refuses on purpose. A '
                . 'refusal the reviewer cannot anticipate costs it the turns it spends rediscovering it — '
                . 'six tries at one question, on run 34260813534.',
            );
        }
    }
}
