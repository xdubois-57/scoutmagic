<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Three ways this container reports green while reading something else.
 *
 * All three were learnt here, all three are silent, and none of them
 * announces itself as a setup problem — which is what makes them worth a
 * test of their own. A wrong verdict from a broken harness looks exactly
 * like a right one, so the only defence is that the next agent has read the
 * warning before trusting a run.
 *
 * They lived inside § "Fix the backlog" until 2026-09-25, under the step
 * that ordered the work into blocks, because that is where they happened to
 * be found. They have nothing to do with how the backlog is ordered: an
 * agent fixing a single ticket falls into all three. So they moved to their
 * own section, and this file moved with them out of
 * Tests\Architecture\BacklogIsCutIntoBlocksTest, which was deleted with the
 * scheme it pinned.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves only
 * that the warning is still there to read. No test can check that anybody
 * read it.
 */
final class WorkingTreeTrapsAreWrittenDownTest extends TestCase
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
    public function testTheTrapsStillHaveTheirOwnSection(): void
    {
        $this->assertStringContainsString(
            '## The working tree, and two ways it reports green while lying',
            self::agentRules(),
            'AGENTS.md no longer gathers the traps that make a local run read the wrong files, so each '
            . 'of them is back to being found the hard way.',
        );
    }

    /**
     * The worktree. `vendor/` there is a symlink, so the autoloader resolves
     * `$baseDir` to the main checkout: the branch under test is never read,
     * and a mutation proof taken that way is worth nothing and looks green.
     */
    public function testTheWorktreeTrapIsWrittenDown(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Do not run the local checks in a `git worktree`',
            $rules,
            'AGENTS.md no longer warns that a `git worktree` run reads the other checkout. An agent '
            . 'reaching for one to work on two branches at once gets a green that means nothing.',
        );

        $this->assertStringContainsString(
            'believe you are testing is never read',
            $rules,
            'AGENTS.md no longer says WHAT goes wrong in a worktree. Without the consequence spelled '
            . 'out, the warning reads as a preference about tooling.',
        );
    }

    /**
     * The stale local ref. `git checkout claude/x` silently prefers a LOCAL
     * ref of that name, however far behind; merging `main` into it produces
     * a plausible commit whose first parent is hours old, and pushing it
     * would revert the pull request with its review fixes inside.
     */
    public function testTheStaleLocalBranchTrapIsWrittenDown(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'Never merge into a local branch that merely shares a name with the remote',
            $rules,
            'AGENTS.md no longer warns that a local branch can shadow the remote one it is named '
            . 'after. Merging into it and pushing reverts every commit made since it went stale.',
        );

        $this->assertStringContainsString(
            'git merge-base --is-ancestor',
            $rules,
            'AGENTS.md no longer gives the assertion that CATCHES the stale-ref merge. The warning '
            . 'alone asks an agent to be careful; this is the command that makes carefulness checkable.',
        );
    }

    /**
     * And the one that comes from this container having exactly one
     * database: two suites at once write over each other's fixtures, and
     * either verdict can then be wrong in either direction.
     */
    public function testOneSuiteAtATimeIsWrittenDownWithItsReason(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'One full suite at a time, and do not touch the working tree while it',
            $rules,
            'AGENTS.md no longer says to run one suite at a time. Two runs share the single `test_db` '
            . 'this container has, which is how a green that had no right to be gets reported.',
        );

        $this->assertStringContainsString(
            'the two runs write over each other',
            $rules,
            'AGENTS.md no longer says why two suites cannot run together, so the rule reads as advice '
            . 'about machine load rather than about a shared database.',
        );
    }
}
