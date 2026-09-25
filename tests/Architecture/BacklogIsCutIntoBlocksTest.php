<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Fixe le backlog » once meant one pull request, and one pull request
 * meant #257: 40 issues, 188 files, 7 000 lines.
 *
 * Every defect of that day came from the size rather than from any of the
 * forty fixes.
 *
 * `Claude review` was cancelled at 20m20s and again at 20m21s with the
 * reviewer still working. That check is REQUIRED on `main` and a cancelled
 * required check is unmergeable, so a ceiling written to bound a runaway
 * had become an undeclared size limit on pull requests — and it announced
 * itself as a merge refusal rather than as anything about the reviewer.
 * The ceiling is an hour now (see .github/workflows/claude-review.yml),
 * which buys room and does not buy a reader: nobody reads 188 files well,
 * neither an agent nor a person.
 *
 * Then `main` moved under the branch. Both sides had fixed issue #226 —
 * one with a private method, one with a class — and `git merge` reported
 * no conflict at all: it kept one side's call and the other side's file.
 * Sixteen thousand tests were green on the branch before the merge and
 * twelve of them failed after it. Only `phpstan` on the merge result saw
 * it, and only in CI.
 *
 * And the composer's 500 ms debounce, harmless while it wrote to a
 * detached node, became a `ReferenceError` the moment #234 made it read
 * `document.cookie` first — 118 test files green, 2 047 tests green, exit
 * code 1 on an unhandled error nobody can find from the summary.
 *
 * A smaller pull request would have been reviewed, would have merged
 * before `main` moved, and would have shown each of these on its own
 * rather than three at once at the end. So the instruction now cuts the
 * work into blocks, and this file pins the sentences that make that real:
 * the ceiling, the parallel-vs-serialised rule, the merge authorization
 * covering every pull request in the set rather than the first one — and
 * the queue discipline the maintainer asked for in so many words once it
 * had been run for a day: parallel development, serialised merges, a cap
 * on how many pull requests are open at once, and the `git worktree` trap
 * that silently tests the wrong working tree.
 *
 * **These assertions pin line wrapping as well as wording**, because
 * `assertStringContainsString()` does. Reflowing a paragraph of § Fix the
 * backlog can break one of them without changing a word of the rule —
 * that happened while this very section was being rewritten. Re-wrap so
 * the pinned phrase stays on one line; do not delete the assertion.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves only
 * that the rule is still there to obey. No test can check that anybody
 * obeyed it.
 */
final class BacklogIsCutIntoBlocksTest extends TestCase
{
    private static function agentRules(): string
    {
        $path = dirname(__DIR__, 2) . '/AGENTS.md';
        $contents = file_get_contents($path);

        self::assertIsString($contents, 'AGENTS.md is unreadable');

        return $contents;
    }

    /**
     * The floor. Everything below reads one section, and a test reading a
     * section that has been renamed passes vacuously.
     */
    public function testTheBacklogInstructionStillHasItsOwnSection(): void
    {
        $this->assertStringContainsString(
            '## "Fix the backlog" — what that instruction asks for, exactly',
            self::agentRules(),
            'AGENTS.md no longer says what « fixe le backlog » asks for, so none of the rules below are '
            . 'anywhere an agent would read them.',
        );
    }

    /**
     * THE STEP ITSELF. Without it the next agent does what the last one
     * did, which is open one pull request and put everything in it.
     */
    public function testTheWorkIsCutIntoBlocksBeforeAnyCodeIsWritten(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Cut the work into blocks, and one block is one pull request',
            $rules,
            'the step that splits the backlog into several pull requests is gone from AGENTS.md',
        );
    }

    /**
     * The number. "Keep pull requests small" is advice nobody can be held
     * to; a ceiling is a thing you can be over.
     */
    public function testTheCeilingIsANumberRatherThanAnAdjective(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'no more than 10 issues and about 50 changed files in one',
            $rules,
            'the size ceiling on a backlog pull request is no longer stated as a number, so nothing '
            . 'distinguishes a pull request that is too big from one that merely feels big.',
        );
    }

    /**
     * The half that is easy to drop, because it costs wall-clock time and
     * buys something invisible. Overlapping branches are how the same
     * issue gets fixed twice and merged without a conflict marker.
     */
    public function testBlocksTouchingTheSameFilesAreSerialised(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Blocks that touch the same files are **serialised**',
            $rules,
            'AGENTS.md no longer says that overlapping blocks wait for each other, which is the rule '
            . 'that stops a second branch from fixing an issue the first one already fixed differently.',
        );

        $this->assertStringContainsString(
            'bring
   `main` into every branch still open, then re-run the checks locally on
   the merged state',
            $rules,
            'AGENTS.md no longer says to merge `main` back into the branches still open after each '
            . 'block lands. Nothing else does it: « require branches up to date » is deliberately off '
            . 'on this repository, so a branch can be green against a base that no longer exists.',
        );
    }

    /**
     * The queue, and why it is not the same rule as the one above.
     *
     * Step 5 used to say that disjoint blocks are « each merged as it goes
     * green, no waiting on a sibling ». Read literally that authorises two
     * merges at once, which is the failure the serialisation rule exists
     * to stop arriving by a different door: `main` moves under the second
     * one, « require branches up to date » is off on this repository, and
     * GitHub merges it against a base that no longer exists with checks
     * that were computed against something else.
     *
     * The cap is the part no reasoning recovers if it is deleted, because
     * it is a measurement of THIS repository — a re-merge costs a CI round
     * of about nineteen minutes plus a review that prices itself near ten
     * dollars — and a number nobody wrote down becomes a taste.
     */
    public function testMergesAreSerialisedAndTheOpenQueueHasACeiling(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**Development is parallel; merging never is.**',
            $rules,
            'AGENTS.md no longer says that only development runs in parallel. Without it, the '
            . 'parallel-blocks rule above reads as permission to merge two pull requests at once, '
            . 'which is how a branch merges against a base its green checks never saw.',
        );

        $this->assertStringContainsString(
            'Hold the open pull requests as a queue, and cap it at four',
            $rules,
            'AGENTS.md no longer caps how many pull requests may be open at once. The number is a '
            . 'measurement of this repository, not a preference: below it the queue starves, above '
            . 'it the re-merges each merge forces cost more than the work they carry.',
        );

        $this->assertStringContainsString(
            'pushed only on the next one to merge',
            $rules,
            'AGENTS.md no longer distinguishes the local re-check on every open branch (seconds, and '
            . 'what actually catches a semantic conflict) from the push that spends a CI round the '
            . 'next merge will invalidate.',
        );
    }

    /**
     * The trap that nearly reverted a pull request.
     *
     * `git checkout claude/some-branch` resolves a LOCAL ref of that name
     * before the remote one, silently and however stale it is. A queue that
     * has run for hours is full of them, `main` included. Merging `main`
     * into one produces a merge whose first parent is the branch as it was
     * hours earlier — and pushing it reverts the pull request, review fixes
     * and all. It happened here on #541: the merge\'s first parent was the
     * pre-review commit, and only the non-fast-forward rejection stopped
     * the push. `--force` would not have been stopped.
     *
     * Written down because the recovery is not obvious under time pressure
     * and the failure is silent: the merge succeeds, the tests pass, and
     * the diff looks smaller than it should in a way nobody reads.
     */
    public function testTheStaleLocalBranchTrapIsWrittenDown(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Never re-merge into a local branch that merely shares a name with the',
            $rules,
            'AGENTS.md no longer warns that a local branch shadows the remote one of the same name. '
            . 'Nothing else does: the merge succeeds, the checks pass, and the push would revert the '
            . 'pull request.',
        );

        $this->assertStringContainsString(
            'git merge-base --is-ancestor',
            $rules,
            'AGENTS.md no longer names the check that catches the shadowed merge before it is pushed.',
        );
    }

    /**
     * The trap that makes a green run mean nothing.
     *
     * Running several branches\' checks at once invites a `git worktree`,
     * where `vendor/` is a symlink — so Composer resolves `$baseDir` to the
     * main checkout and loads `Core\` and `Tests\` from the other working
     * tree. The branch under test is never read. A mutation proof taken
     * that way is green for the wrong reason, which is the one shape of
     * wrong this repository cares about most.
     */
    public function testTheWorktreeTrapIsWrittenDownWhereTheChecksAreAskedFor(): void
    {
        $this->assertStringContainsString(
            'Do not run those local checks in a `git worktree`',
            self::agentRules(),
            'AGENTS.md no longer warns that a worktree run tests the main checkout\'s code. Nothing '
            . 'else does: the run is green, the output says nothing, and the branch was never read.',
        );
    }

    /**
     * The authorization. § Merging a pull request grants it per pull
     * request, so cutting one pull request into six without saying so
     * would leave five of them unauthorised — and an agent obeying this
     * file to the letter would stop after the first.
     */
    public function testTheMergeAuthorizationCoversEveryBlockRatherThanTheFirst(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**is** that instruction, standing, for **every** pull',
            $rules,
            '§ Merging a pull request no longer says that « fixe le backlog » authorises every pull '
            . 'request in the set. Cut into blocks and read literally, that leaves every block after '
            . 'the first waiting for an authorization the maintainer has already given.',
        );
    }

    /**
     * Announcing the blocks is not a question, and the one moment a
     * question is welcome is step 3. Without this sentence the new step 4
     * reads like a second checkpoint, which is the thing the whole section
     * exists to prevent.
     */
    public function testAnnouncingTheBlocksIsNotAnotherPlaceToStopAndWait(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Announcing
the blocks in step 4 is telling, not asking',
            $rules,
            'AGENTS.md no longer says that naming the blocks is not a request for approval, so the '
            . 'step added to make the work smaller reads as one more place to wait for the maintainer.',
        );
    }
}
