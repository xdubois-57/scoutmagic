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
 * work into blocks, and this file pins the three sentences that make that
 * real: the ceiling, the parallel-vs-serialised rule, and the merge
 * authorization covering every pull request in the set rather than the
 * first one.
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
