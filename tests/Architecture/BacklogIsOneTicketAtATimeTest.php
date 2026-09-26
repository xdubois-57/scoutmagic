<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * « Fixe le backlog » is one accepted ticket at a time, and several agents
 * may be given it at once (asked for on 2026-09-25).
 *
 * It replaces a scheme that cut the backlog into blocks of issues, with a
 * ceiling of ten issues per pull request, a parallel-versus-serialised rule
 * between blocks, and a queue of six open pull requests. All of that existed
 * to stop one failure — a pull request nobody can read, merging against a
 * base its green checks never saw — and one ticket per pull request stops it
 * without any of the machinery.
 *
 * What does NOT survive that simplification is the coordination. Blocks were
 * cut by one agent who therefore knew what every block contained; several
 * agents given the same instruction know nothing about each other. So two
 * things are load-bearing here and are what this file mostly pins:
 *
 * - the CLAIM, which must be a git ref because creating one is atomic
 *   server-side. A label or an assignee is not: two agents can apply either
 *   in the same second, both see success, and both do the ticket;
 * - the MERGE LOCK, because « fusionne les PR une par une, jamais en même
 *   temps » cannot be honoured by an agent that cannot see the others. It was
 *   asked for twice, and the reason survives the redesign unchanged: `main`
 *   moves under the second merge, « require branches up to date » is off
 *   here, and GitHub merges happily against a base that no longer exists.
 *
 * The 50-file ceiling stays too, as the one number #257 bought: 40 issues,
 * 188 files, `Claude review` cancelled at 20m20s and again at 20m21s with the
 * reviewer still working — and a cancelled REQUIRED check is unmergeable.
 *
 * Like Tests\Architecture\AutoMergeRuleIsWrittenDownTest, this proves only
 * that the rule is still there to obey. No test can check that anybody
 * obeyed it.
 */
final class BacklogIsOneTicketAtATimeTest extends TestCase
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

    /** THE SHAPE. Without it an agent plans, batches, and asks before starting. */
    public function testTheWorkIsOneAcceptedTicketAtATime(): void
    {
        $this->assertStringContainsString(
            '**one accepted ticket at a time, carried from end to end**',
            self::agentRules(),
            'AGENTS.md no longer says the backlog is fixed one ticket at a time. Without it the next '
            . 'agent batches the work again, and the ceiling below becomes the only thing standing '
            . 'between it and a pull request nobody can read.',
        );
    }

    /**
     * The claim, and the reason it cannot be a label.
     *
     * This is the part a well-meaning edit breaks: a label is the obvious
     * way to say « somebody is on this », it is visible in the issue list,
     * and it silently does not work. Two agents apply it in the same second
     * and both believe they won.
     */
    public function testTheClaimIsAGitRefBecauseALabelIsNotAtomic(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '« Reference already exists »',
            $rules,
            'AGENTS.md no longer names the refusal that makes two agents pick different tickets. It is '
            . 'the whole coordination mechanism between agents that cannot see each other.',
        );

        $this->assertStringContainsString(
            'two agents can apply the same label or assignee in the same',
            $rules,
            'AGENTS.md no longer says WHY the claim has to be a git ref. Without the reason, the next '
            . 'edit replaces it with a label — which is visible, obvious, and does not exclude.',
        );
    }

    /**
     * The merge lock, and the sentence it serves.
     *
     * « Development is parallel; merging never is » was written for blocks
     * cut by one agent. It matters more now, not less: nothing else stops
     * two agents merging in the same second.
     */
    public function testMergingIsSerialisedByALockWithAStalenessRule(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**Development is parallel; merging never is.**',
            $rules,
            'AGENTS.md no longer says that only development runs in parallel — the half the maintainer '
            . 'asked for twice, and the one that costs wall-clock time to obey.',
        );

        $this->assertStringContainsString(
            'claude/merge-lock',
            $rules,
            'AGENTS.md no longer names the ref that serialises merges between agents. The rule above '
            . 'then has no mechanism, and an agent that cannot see the others cannot obey it.',
        );

        $this->assertStringContainsString(
            '**Time your OWN wait, never the lock\'s age.**',
            $rules,
            'AGENTS.md no longer says where the staleness clock comes from. A ref carries no creation '
            . 'date, so an agent reading the lock\'s "age" reads when `main` last moved — and deletes a '
            . 'lock taken seconds ago to merge on top of its holder.',
        );

        $this->assertStringContainsString(
            'is still held after ten minutes of your waiting, it is stuck',
            $rules,
            'AGENTS.md no longer bounds how long an agent waits on a stuck lock, so one agent dying '
            . 'mid-merge stops every other one for good.',
        );

        $this->assertStringContainsString(
            '**None of that happens under the lock, and the reason is arithmetic.**',
            $rules,
            'AGENTS.md no longer keeps the re-merge and its push OUTSIDE the lock. Held across a push, '
            . 'the lock lasts thirty to forty-five minutes by this repository\'s own review and CI '
            . 'numbers — longer than any threshold can tell from an agent that died — so a waiting '
            . 'agent breaks a valid lock and merges beside its holder.',
        );

        $this->assertStringContainsString(
            '**Breaking it is a delete AND a create, and the create decides.**',
            $rules,
            'AGENTS.md no longer says that breaking the lock ends with a create whose refusal is '
            . 'honoured. Delete-then-take is not atomic, so two agents reaching the threshold together '
            . 'would both believe they won.',
        );
    }

    /**
     * The authorization, which this PR nearly dropped a guard for: the
     * sentence was pinned by the deleted blocks test and by nothing else.
     * Read literally without « not the first », every ticket after the first
     * waits for a permission the maintainer has already given — which is how
     * a backlog stays a backlog.
     */
    public function testTheMergeAuthorizationCoversEveryTicketRatherThanTheFirst(): void
    {
        $this->assertStringContainsString(
            '**is** that instruction, standing, for **every** pull',
            self::agentRules(),
            '§ Merging a pull request no longer says that « fixe le backlog » authorises every pull '
            . 'request in the set. One ticket per pull request and read literally, that leaves every '
            . 'ticket after the first waiting for an authorization already given.',
        );
    }

    /**
     * And the rule that says a claim is never taken, because from outside a
     * live claim and an abandoned one are the same thing: a branch with no
     * commit and no pull request, which is what step 4 looks like all the
     * way through.
     */
    public function testAClaimIsNeverTakenFromAnotherAgent(): void
    {
        $this->assertStringContainsString(
            '**Never take a ticket somebody else has claimed, even when the claim looks',
            self::agentRules(),
            'AGENTS.md no longer forbids taking a claimed ticket. An earlier version called any branch '
            . 'with no commit a dead claim — which is exactly what a claim looks like while its agent '
            . 'is reading the issue — so a second agent could delete a live one and fix the same '
            . 'ticket differently.',
        );
    }

    /**
     * The number. "Keep pull requests small" is advice nobody can be held
     * to; a ceiling is a thing you can be over. One ticket per pull request
     * usually stays under it by itself — this is for the ticket that does
     * not, which is where #257 repeats itself.
     */
    public function testTheCeilingIsANumberRatherThanAnAdjective(): void
    {
        $this->assertStringContainsString(
            'about **50 changed files**',
            self::agentRules(),
            'the size ceiling on a backlog pull request is no longer stated as a number, so nothing '
            . 'distinguishes a pull request that is too big from one that merely feels big.',
        );
    }

    /**
     * What `status:accepted` authorises, and what an unclear ticket costs.
     *
     * Both halves were given in one breath, and the second is the one an
     * eager agent drops: a ticket taken and guessed wrong costs a pull
     * request, a review round and a revert, where a ticket left alone costs
     * the maintainer a sentence.
     */
    public function testAcceptedMeansImplementDirectlyAndUnclearMeansSkip(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**`status:accepted` also means the analysis is done.**',
            $rules,
            'AGENTS.md no longer says that an accepted ticket is implemented as it stands, so the next '
            . 'agent goes back to ask for a design the maintainer has already done.',
        );

        $this->assertStringContainsString(
            'ignore le ticket et continue',
            $rules,
            'AGENTS.md no longer says to skip a ticket that is not clear enough to implement.',
        );

        $this->assertStringContainsString(
            'A choice the ticket genuinely leaves open is asked in the',
            $rules,
            'AGENTS.md no longer says where a question goes, so an agent either guesses or stops.',
        );
    }

    /**
     * `Corrige` rather than a closing keyword, and the reason — without
     * which it reads as a mistake somebody will helpfully "fix".
     */
    public function testTheIssueIsNamedWithAWordThatDoesNotCloseIt(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            "deliberately **not** one of GitHub's closing",
            $rules,
            'AGENTS.md no longer says that `Corrige` avoids GitHub closing the issue itself. A closing '
            . 'keyword fires seconds before `issue-fixed-comment.yml` can write, so the reporter\'s '
            . 'first notification is a bare closure.',
        );
    }

    /**
     * The separation, which is the thing a tidying edit undoes: the release
     * gates read like part of the same job and are not, and folding them
     * back puts a dependency bump in a pull request opened for a ticket.
     */
    public function testTheReleaseGatesAreNotPartOfFixingTheBacklog(): void
    {
        $this->assertStringContainsString(
            '**The release gates are not part of this.**',
            self::agentRules(),
            'AGENTS.md no longer separates the release gates from « fixe le backlog ». They were part '
            . 'of it once, which made one instruction two jobs and landed dependency bumps in pull '
            . 'requests a reviewer was holding for a ticket.',
        );
    }
}
