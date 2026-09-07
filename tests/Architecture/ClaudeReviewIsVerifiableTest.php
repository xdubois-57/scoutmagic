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

        $outputs = ['evidence', 'turns', 'denials', 'denied_tools', 'agent_calls', 'subagents_spawned', 'subagents_completed'];

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
}
