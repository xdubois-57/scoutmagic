<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The merge rule has two halves, and dropping either one is a real failure.
 *
 * On 2026-09-05 the ruleset on `main` still required a pull request to be
 * even with the base branch at the moment of merging. Five pull requests
 * landed within the hour, so #152 went green, fell behind, went green
 * again — four times — and each recovery needed a human to be told, because
 * the agent holding the instruction to merge was polling the pull request
 * rather than arming anything. GitHub reports that state as
 * `Required status check "Claude review" is expected`, which reads like a
 * check that never ran rather than a branch that fell behind, so the hour
 * went into diagnosing the wrong thing.
 *
 * The fix was a repository setting (auto-merge, now enabled) plus an
 * instruction, and the instruction is the fragile half: a setting that
 * nothing tells an agent to use buys nothing, and `gh pr merge --auto` is
 * exactly the line an editing pass shortens away as an implementation
 * detail.
 *
 * The other half is the sentence that keeps auto-merge from becoming a
 * self-service merge button. Arming it IS merging — GitHub lands the pull
 * request without asking again — so the rule that only the maintainer's
 * instruction authorises a merge has to survive next to it, in the same
 * file, or the convenience quietly repeals the guard.
 *
 * Like Tests\Architecture\CodeQlRuleIsWrittenDownTest, this checks only
 * that the rule is still there to obey. No test can check that anybody
 * obeyed it.
 */
final class AutoMergeRuleIsWrittenDownTest extends TestCase
{
    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        self::assertIsString($contents, $relativePath . ' is unreadable');

        return $contents;
    }

    private static function agentRules(): string
    {
        return self::read('AGENTS.md');
    }

    public function testTheRuleHasItsOwnSection(): void
    {
        $this->assertStringContainsString(
            '## Merging a pull request',
            self::agentRules(),
            'AGENTS.md no longer tells an agent how to carry out an instruction to merge'
        );
    }

    /**
     * The guard, kept verbatim. Without it the section below reads as
     * permission to merge whatever is green.
     */
    public function testOnlyTheMaintainersInstructionAuthorisesAMerge(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            "The maintainer's instruction is the only authorization to merge",
            $rules,
            'the sentence that keeps auto-merge from becoming a self-service merge button is gone'
        );
    }

    /**
     * THE ONE STANDING EXCEPTION THAT IS ABOUT THIS FILE ITSELF.
     *
     * A rule agreed in a conversation and left unmerged is a rule the next
     * session never sees — the gap between "we decided this" and "it is on
     * `main`" is where an instruction dies, and asking a second time to
     * close a gap the maintainer just asked you to close is how a decided
     * rule stays undecided. So a change to the instruction files, when the
     * maintainer asked for it, carries its own authorization to merge.
     *
     * Both halves are pinned, and the second is the one that matters. The
     * grant is narrow on purpose: it authorises landing a change the
     * maintainer asked for, never writing one you thought of yourself.
     * Drop that sentence and what remains reads as a licence to rewrite
     * the rules an agent is bound by and merge it unasked, which is the
     * one shape this repository must never let a convenience take.
     */
    public function testAnInstructionChangeMayBeMergedWithoutAskingTwice(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            'A change to these instructions themselves carries the same standing',
            $rules,
            'AGENTS.md no longer says that a maintainer-requested change to the instruction files may '
            . 'be merged without a second confirmation, so the next agent stops at green and waits — '
            . 'and the rule that was agreed stays off `main`, where nothing reads it.'
        );

        $this->assertStringContainsString(
            'It authorises the *merge*, nothing else.',
            $rules,
            'the sentence bounding that authorization is gone. Without it, a grant meant to let a '
            . 'decided rule land reads as permission to rewrite the rules unasked and merge the result.'
        );
    }

    /**
     * The command itself. "Enable auto-merge" without it sends the next
     * agent to the web UI, which is where the polling started.
     */
    public function testTheRuleNamesTheCommandThatArmsIt(): void
    {
        foreach (['AGENTS.md', '.claude/skills/steward/SKILL.md'] as $file) {
            // The whole line, not its pieces: `gh pr merge` without
            // `--auto` is the command that merges on the spot, and an
            // assertion satisfied by either one would not notice the
            // difference.
            $this->assertStringContainsString(
                'gh pr merge <number> --squash --auto',
                self::read($file),
                $file . ' no longer names the command that arms auto-merge'
            );
            // The trap the same files warn about, pinned here because it
            // is the mistake an agent makes when the tool is missing.
            $this->assertStringContainsString(
                'merge_pull_request',
                self::read($file),
                $file . ' no longer warns that merge_pull_request merges instead of arming'
            );
        }
    }

    /**
     * Both are silent failures, which is why they are written down rather
     * than left to be rediscovered: an armed pull request that stops being
     * on its way to `main` looks exactly like one nobody has merged yet.
     */
    public function testTheTwoSilentFailuresAreRecorded(): void
    {
        foreach (['AGENTS.md', 'docs/quality-pipeline.md'] as $file) {
            $contents = self::read($file);

            $this->assertStringContainsString(
                'disarmed in silence',
                $contents,
                $file . ' no longer records that auto-merge can be turned off with no notice'
            );
            // Both halves. A push from an account without write access is
            // the one nobody expects, so an assertion that only covered
            // the base-branch change would let it go.
            $this->assertStringContainsString(
                'base branch',
                $contents,
                $file . ' no longer names the base-branch change as disarming it'
            );
            $this->assertStringContainsString(
                'without write access',
                $contents,
                $file . ' no longer names the non-write push as disarming it'
            );
        }
    }

    /**
     * The step that stops auto-merge from landing a red pull request.
     *
     * `All checks` became a required context on 2026-09-06, so the ruleset
     * does now refuse a pull request whose `database-mariadb`,
     * `Authorization matrix` or `Dynamic scan (passive)` is red — that was
     * issue #170. It still does not refuse one whose pipeline has not
     * finished: a required check that has not reported is displayed like
     * one that is merely waiting. A first draft of this rule listed the
     * thread and checklist items and left CI out, which would have let an
     * agent arm on a half-run pipeline and walk away, and that is the part
     * only the instruction catches.
     */
    public function testTheRuleRequiresGreenChecksBeforeArming(): void
    {
        foreach (['AGENTS.md', '.claude/skills/steward/SKILL.md'] as $file) {
            $contents = self::read($file);

            $this->assertStringContainsString(
                'every check green on the current head',
                strtolower($contents),
                $file . ' no longer tells an agent to confirm CI before arming auto-merge'
            );
            // The reason, without which the step reads as belt-and-braces
            // and gets dropped by the next person tightening the prose.
            $this->assertStringContainsString(
                'database-mariadb',
                $contents,
                $file . ' no longer names the checks this step exists to have read'
            );
        }
    }

    /**
     * WHEN to arm: as soon as the pull request is open (the maintainer's
     * decision of 2026-10-18). Waiting for green asked the MCP tool at the
     * one moment it refuses — a pull request already mergeable — and sent
     * the last click back to the maintainer, which is the interruption
     * auto-merge exists to remove.
     *
     * Three things are pinned with it, because arming early is only safe
     * while all three are written down: the ruleset holds the checks and
     * the threads (so the reason it is safe must stay next to the rule),
     * the checklist is the agent's alone and settled before opening, and a
     * pull request that must not land is disarmed — by name, so the next
     * agent is not left closing a pull request to stop a merge.
     */
    public function testAutoMergeIsArmedAsSoonAsThePullRequestIsOpen(): void
    {
        $rules = self::agentRules();

        $this->assertStringContainsString(
            '**Arm it as soon as the pull request is open**',
            $rules,
            'AGENTS.md no longer says to arm auto-merge when the pull request opens, so the next agent '
            . 'waits for green, the tool refuses a mergeable pull request, and the maintainer merges by hand'
        );
        $this->assertStringContainsString(
            '6. **Arm auto-merge as soon as the pull request is open.**',
            $rules,
            'the backlog procedure no longer arms auto-merge when the pull request opens'
        );
        $this->assertStringContainsString(
            'The ruleset requires conversation resolution',
            $rules,
            'AGENTS.md no longer says what holds an early-armed pull request back from merging over an '
            . 'open review thread'
        );
        $this->assertStringContainsString(
            'Nothing but you holds this one',
            $rules,
            'AGENTS.md no longer says that the checklist — the one thing no ruleset holds — is settled '
            . 'before the pull request is opened'
        );

        foreach (['AGENTS.md', '.claude/skills/steward/SKILL.md'] as $file) {
            $contents = self::read($file);
            $this->assertStringContainsString(
                'gh pr merge <number> --disable-auto',
                $contents,
                $file . ' no longer names how to disarm a pull request that must not land'
            );
            $this->assertStringContainsString(
                'Arming early is never walking away',
                $contents,
                $file . ' no longer says that an armed pull request is still the agent\'s until it merges'
            );
        }
    }

    /**
     * The setting lives in the repository's settings, where no diff shows
     * it — the category docs/quality-pipeline.md exists to carry.
     */
    public function testThePipelineMapCarriesTheSetting(): void
    {
        $map = self::read('docs/quality-pipeline.md');

        $this->assertStringContainsString('### Auto-merge', $map);
        $this->assertStringContainsString('Settings → General → Pull Requests', $map);
        // The state, not just the location: the section is worth nothing
        // to a reader who cannot tell whether the box is meant to be on.
        $this->assertStringContainsString('Allow auto-merge — enabled', $map);
    }

    /**
     * The sub-option is off on purpose, and "on purpose" is the whole
     * content: it looks like an obvious safety improvement to anyone who
     * finds it off and does not know what it cost.
     */
    public function testTheMapSaysWhyBranchesNeedNotBeUpToDate(): void
    {
        $map = self::read('docs/quality-pipeline.md');

        $this->assertStringContainsString('Require branches to be up to date before merging', $map);
        $this->assertStringContainsString('deliberately off', $map);
        // What replaces the guarantee, without which the entry is just a
        // preference rather than a trade somebody made.
        $this->assertStringContainsString('every push to `main`', $map);
        // And who answers when that later run goes red, without which the
        // trade names a safety net nobody is holding.
        $this->assertStringContainsString('**The maintainer does**', $map);
    }
}
