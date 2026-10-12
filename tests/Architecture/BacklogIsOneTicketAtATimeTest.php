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
 * - the ABSENCE of a merge lock, and the written reason for it. « Fusionne
 *   les PR une par une, jamais en même temps » was asked for twice and cannot
 *   be honoured by an agent that does not merge: it arms auto-merge, and
 *   GitHub merges when the ruleset is satisfied, at a moment no agent
 *   chooses. Four attempts to bridge that with a git ref each produced a new
 *   hole instead of a mutex, and `docs/quality-pipeline.md` § Branch ruleset
 *   had already decided to accept the window and named the maintainer as the
 *   one who answers for it. So what this file pins is that the EXPLANATION
 *   stays written down — a section that merely lacked a lock would read as an
 *   oversight worth correcting.
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
    public function testTheWorkIsOneReadyTicketAtATime(): void
    {
        $this->assertStringContainsString(
            '**one Ready ticket at a time,',
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
     * Why there is NO merge lock, which is the part a future edit will want
     * to "fix" — the maintainer asked for serialised merges twice, and the
     * section does not deliver them.
     *
     * It cannot: an agent here arms auto-merge and GitHub merges when the
     * ruleset is satisfied, at a moment no agent chooses, so two agents
     * cannot serialise what neither performs. Four attempts to bridge that
     * with a git ref each produced a new hole instead of a mutex — a
     * creation date refs do not have, a threshold the prescribed work
     * exceeded, an unconditional delete that destroyed a peer's fresh lock.
     *
     * And it does not need to: `docs/quality-pipeline.md` § Branch ruleset
     * already decided to accept the window and named the maintainer as the
     * one who answers for it. What this file pins is that the REASON stays
     * written down, because a section that merely lacked a lock would read
     * as an oversight worth correcting.
     */
    public function testTheAbsenceOfAMergeLockIsExplainedRatherThanSilent(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**There is no merge lock, and « fusionne les PR une par une » cannot be',
            $rules,
            'AGENTS.md no longer says that merges are not serialised, nor why. The maintainer asked '
            . 'for it twice, so a section that is simply silent about it invites the next agent to '
            . 'build the mutex that four attempts here failed to build.',
        );

        $this->assertStringContainsString(
            'An agent here does not merge: it **arms**',
            $rules,
            'AGENTS.md no longer says WHY serialising is out of an agent\'s hands. Without it the '
            . 'absence of a lock looks like laziness rather than a property of arming auto-merge.',
        );

        $this->assertStringContainsString(
            'never arm against a `main` that has moved into **your**',
            $rules,
            'AGENTS.md no longer states the half an agent CAN see and is answerable for. That '
            . 'paragraph is the whole of what replaces the lock.',
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
     * And the branch of the loop that a ticket delivered in several pull
     * requests needs, which the eight steps did not have.
     *
     * Read without it, step 7 strips the label from a ticket still in flight
     * and step 8 sends the agent back to step 1, where its OWN surviving
     * branch answers « Reference already exists » — so it walks away from its
     * own half-delivered work and reports it as one somebody else held.
     */
    public function testTheLoopHasABranchForATicketDeliveredInSeveralPullRequests(): void
    {
        $this->assertStringContainsString(
            'Between the others, go back to step 4 and **keep the claim and the',
            self::agentRules(),
            'AGENTS.md no longer says what to do between the pull requests of one oversized ticket. '
            . 'Following steps 7 and 8 after a sub-pull-request makes an agent abandon its own work.',
        );
    }

    /**
     * What Ready authorizes, and what an unclear ticket costs.
     *
     * Both halves were given in one breath, and the second is the one an
     * eager agent drops: a ticket taken and guessed wrong costs a pull
     * request, a review round and a revert, where a ticket left alone costs
     * the maintainer a sentence.
     */
    public function testReadyMeansImplementDirectlyAndUnclearMeansSkip(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**Ready also means the analysis is done.**',
            $rules,
            'AGENTS.md no longer says that a Ready ticket is implemented as it stands, so the next '
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
     * The weekly external-sources workflow owns source monitoring; a
     * development session must not repeat the network check on each wake-up.
     */
    public function testFixingTheBacklogDoesNotRunExternalSourcesCheck(): void
    {
        $section = self::backlogSection();
        $this->assertStringContainsString(
            '**External sources are NOT checked when this command begins.**',
            $section,
        );
        $this->assertStringNotContainsString(
            'php scripts/check-external-sources.php',
            $section,
        );
        $this->assertFileExists(
            dirname(__DIR__, 2) . '/.github/workflows/external-sources-check.yml',
        );
    }

    /** Only the Project's Ready status authorizes development and merging. */
    public function testProjectReadyReplacesAcceptedLabel(): void
    {
        $section = self::backlogSection();
        foreach ([
            'Status = `Ready`',
            'No `status:accepted` label is required',
            'never fall back to repository labels as the queue',
            'bash scripts/backlog-ready-queue.sh',
        ] as $required) {
            $this->assertStringContainsString($required, $section);
        }
        $this->assertStringNotContainsString(
            'List the OPEN issues carrying `status:accepted`',
            $section,
        );
    }

    /** A prerequisite must be dealt with before its dependent ticket. */
    public function testNativeBlockedByChainsControlPriority(): void
    {
        $rules = self::backlogSection();
        foreach ([
            'GitHub `blocked by`',
            'any **open**',
            'A prerequisite of a higher-priority Ready',
            'inherits that higher urgency, transitively',
            'P0 → P3',
        ] as $required) {
            $this->assertStringContainsString($required, $rules);
        }
    }

    /** No one-shot stop when the queue becomes empty. */
    public function testBacklogResumesOnHourlyWakeupsUntilMaintainerStopsIt(): void
    {
        $rules = self::backlogSection();
        foreach ([
            '/loop 1h fix the backlog',
            'Run the first backlog pass **immediately**',
            'When there is no executable Ready issue, become idle for one hour',
            'Only an explicit instruction from the maintainer cancels it.',
            'expires automatically after',
            'seven days',
        ] as $required) {
            $this->assertStringContainsString($required, $rules);
        }
        $this->assertStringContainsString('Never `sleep 3600` in Bash', $rules);
    }

    /** The queue contract test exercises an entire blocked-by chain offline. */
    public function testReadyQueueScriptHonoursDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $shellTest = $root . '/scripts/backlog-ready-queue.test.sh';
        $this->assertFileExists($shellTest);
        $this->assertFileExists($root . '/scripts/backlog-ready-queue.sh');
        $output = [];
        $status = -1;
        exec('bash ' . escapeshellarg($shellTest) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }

    private static function backlogSection(): string
    {
        $rules = self::agentRules();
        $start = strpos($rules, '## "Fix the backlog"');
        self::assertIsInt($start, 'AGENTS.md § "Fix the backlog" is missing.');
        $end = strpos($rules, "\n## ", $start + 1);

        return substr($rules, $start, $end === false ? null : $end - $start);
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
