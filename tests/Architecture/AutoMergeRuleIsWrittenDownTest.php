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
            'only authorization to merge',
            $rules,
            'the sentence that keeps auto-merge from becoming a self-service merge button is gone'
        );
    }

    /**
     * The command itself. "Enable auto-merge" without it sends the next
     * agent to the web UI, which is where the polling started.
     */
    public function testTheRuleNamesTheCommandThatArmsIt(): void
    {
        foreach (['AGENTS.md', '.claude/skills/steward/SKILL.md'] as $file) {
            $this->assertStringContainsString(
                'gh pr merge',
                self::read($file),
                $file . ' no longer names the command that arms auto-merge'
            );
            $this->assertStringContainsString('--auto', self::read($file), $file);
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
            $this->assertStringContainsString(
                'base branch',
                $contents,
                $file . ' no longer names what disarms it'
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
    }
}
