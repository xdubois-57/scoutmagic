<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * THE FIX FOR A REVIEW THAT STOPS PART-WAY THROUGH, and the only one of the
 * three layers that removes the cause rather than reporting it.
 *
 * `.github/workflows/claude-review.yml` has told the reviewer to "pass
 * run_in_background false" since 2026-09-08. Between 2026-09-10 and
 * 2026-09-20 the review still went red on 35 of 300 runs, every sampled one
 * of them on agents launched and never collected — because the instruction
 * is obeyed only when a model remembers to type an extra field, and the
 * value it gets when nobody types it is the unsafe one:
 *
 *     run 35514703160   requested {background 0, foreground 0, unset 2}
 *                       started_in_background 2, completed 0
 *
 * `.claude/hooks/subagents-in-foreground.sh` answers that by rewriting the
 * call before it runs. This test is what keeps it answering: the hook is a
 * shell script nothing else executes, in a directory no other test reads,
 * and a silent edit to it would restore the defect without failing anything.
 *
 * Every case below is a shape taken from a real run of this repository.
 */
final class SubagentsStartInForegroundTest extends TestCase
{
    private const HOOK = '.claude/hooks/subagents-in-foreground.sh';
    private const SETTINGS = '.claude/settings.json';

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    /**
     * Runs the hook the way Claude Code runs it: the payload on stdin, the
     * decision on stdout.
     *
     * @param  array<string, mixed> $payload
     * @return array{stdout: string, status: int}
     */
    private static function hookFor(array $payload, bool $inWorkflowRun = true, bool $optedIn = true): array
    {
        $input = tempnam(sys_get_temp_dir(), 'subagent-hook-payload-');
        self::assertIsString($input);

        try {
            file_put_contents($input, (string) json_encode($payload));

            $command = ($inWorkflowRun ? 'GITHUB_ACTIONS=true ' : 'GITHUB_ACTIONS= ')
                . ($optedIn ? 'CLAUDE_SUBAGENTS_FOREGROUND=true ' : 'CLAUDE_SUBAGENTS_FOREGROUND= ')
                . 'bash ' . escapeshellarg(self::path(self::HOOK))
                . ' < ' . escapeshellarg($input) . ' 2>/dev/null';

            $output = [];
            $status = 0;
            exec($command, $output, $status);

            return ['stdout' => implode("\n", $output), 'status' => $status];
        } finally {
            unlink($input);
        }
    }

    /**
     * The floor. A test running a file that is not there passes vacuously,
     * and a hook Claude Code cannot execute is a hook that does nothing.
     */
    public function testTheHookIsThereAndRunnable(): void
    {
        $this->assertFileExists(self::path(self::HOOK), 'The foreground hook is gone.');

        $this->assertTrue(
            is_executable(self::path(self::HOOK)),
            'The foreground hook has lost its executable bit, so Claude Code cannot run it and every '
            . 'subagent goes back to starting in the background.',
        );
    }

    /**
     * THE CASE THE WHOLE HOOK EXISTS FOR. Run 35514703160 launched its two
     * setup agents without mentioning `run_in_background` at all, both
     * started in the background, and neither was ever collected — the run
     * ended 38 seconds in on "I'll wait for both to complete".
     */
    public function testALaunchThatSaysNothingIsAnsweredWithTheForeground(): void
    {
        $result = self::hookFor([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Agent',
            'tool_input' => [
                'description' => 'Find relevant CLAUDE.md files for PR 404',
                'subagent_type' => 'general-purpose',
                'model' => 'haiku',
                'prompt' => 'All tools are functional…',
            ],
        ]);

        $this->assertSame(0, $result['status'], 'The hook failed instead of deciding.');

        $decided = json_decode($result['stdout'], true);

        self::assertIsArray($decided, 'The hook wrote nothing on the launch it exists to correct.');
        self::assertIsArray($decided['hookSpecificOutput'] ?? null);

        $output = $decided['hookSpecificOutput'];

        $this->assertSame('PreToolUse', $output['hookEventName'] ?? null);

        $this->assertSame(
            'allow',
            $output['permissionDecision'] ?? null,
            'Claude Code applies `updatedInput` only on an `allow` decision, so anything else here makes '
            . 'the rewrite silently ineffective while the hook still looks like it ran.',
        );

        $this->assertFalse(
            $output['updatedInput']['run_in_background'] ?? null,
            'A launch that named no `run_in_background` was left with the background default, which is '
            . 'the exact shape that truncated the review 35 times.',
        );
    }

    /**
     * The rest of the call has to survive the rewrite. A hook that returned
     * only the field it changed would drop the prompt, the model and the
     * agent type — the review would still be launched, at the wrong model,
     * with no instructions.
     */
    public function testEverythingElseAboutTheLaunchIsLeftAlone(): void
    {
        $input = [
            'description' => 'Bug/security scan on diff, agent 2',
            'subagent_type' => 'claude',
            'model' => 'opus',
            'prompt' => 'Review the introduced lines…',
            'run_in_background' => true,
        ];

        $result = self::hookFor([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Agent',
            'tool_input' => $input,
        ]);

        $updated = json_decode($result['stdout'], true)['hookSpecificOutput']['updatedInput'] ?? null;

        self::assertIsArray($updated);

        $this->assertFalse(
            $updated['run_in_background'],
            'An explicit `run_in_background: true` — run 35515224252 asked for it on five of eight agents '
            . '— was left standing.',
        );

        foreach (['description', 'subagent_type', 'model', 'prompt'] as $key) {
            $this->assertSame(
                $input[$key],
                $updated[$key] ?? null,
                'The rewrite dropped `' . $key . '`. It must return the whole input with one field '
                . 'overridden, never just the field it changed.',
            );
        }
    }

    /**
     * `Task` is the older spelling of the same tool and both appear in this
     * repository's transcripts, so answering only one of them would leave
     * half the launches on the background default.
     */
    public function testBothSpellingsOfTheLaunchToolAreAnswered(): void
    {
        foreach (['Agent', 'Task'] as $tool) {
            $result = self::hookFor([
                'hook_event_name' => 'PreToolUse',
                'tool_name' => $tool,
                'tool_input' => ['description' => 'x'],
            ]);

            $this->assertFalse(
                json_decode($result['stdout'], true)['hookSpecificOutput']['updatedInput']['run_in_background'] ?? null,
                'A `' . $tool . '` launch was not corrected.',
            );
        }
    }

    /**
     * EVERYTHING IT IS NOT ABOUT, and each of these silences matters as much
     * as the rewrite. A hook that speaks when it has nothing to say either
     * grants a tool nobody meant to grant, or breaks a session it was never
     * supposed to touch.
     */
    public function testTheHookSaysNothingOutsideItsOneJob(): void
    {
        $silent = [
            'another tool entirely' => [
                ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'ls']],
                true,
            ],
            'a Read the reviewer makes constantly' => [
                ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Read', 'tool_input' => ['file_path' => '/x']],
                true,
            ],
            'an interactive session' => [
                ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent', 'tool_input' => ['description' => 'x']],
                false,
            ],
            'a payload with no tool_input' => [
                ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent'],
                true,
            ],
            'a tool_input that is not an object' => [
                ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Agent', 'tool_input' => 'nonsense'],
                true,
            ],
        ];

        foreach ($silent as $label => [$payload, $inWorkflowRun]) {
            $result = self::hookFor($payload, $inWorkflowRun);

            $this->assertSame(
                0,
                $result['status'],
                'On ' . $label . ' the hook exited non-zero. A PreToolUse hook that fails can block the '
                . 'tool call it was only meant to observe.',
            );

            $this->assertSame(
                '',
                $result['stdout'],
                'On ' . $label . ' the hook returned a decision. Granting `allow` where it has no business '
                . 'deciding hands out a permission the allowlist never granted.',
            );
        }
    }

    /**
     * An interactive session has a later turn and a person to wake it, so
     * the defect cannot happen there — and blocking every `Agent` call would
     * make ordinary work slower for nothing.
     */
    public function testTheScopeIsTheRunThatCannotBeResumed(): void
    {
        $result = self::hookFor([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Agent',
            'tool_input' => ['description' => 'x'],
        ], inWorkflowRun: false);

        $this->assertSame(
            '',
            $result['stdout'],
            'The hook now decides in interactive sessions too, so every local `Agent` call is granted '
            . 'and forced to block — slower work, and a permission nobody asked for.',
        );
    }

    /**
     * THE HOLE THIS SCOPE WAS BUILT AGAINST, and it was found before the
     * hook shipped rather than after.
     *
     * Claude Code applies `updatedInput` only on an `allow` decision, so
     * this hook necessarily GRANTS the tool it rewrites. A hook that fired
     * on every workflow run would therefore have granted `Agent`/`Task`
     * inside `.github/workflows/issue-triage.yml`, which denies both on
     * purpose — that denial is the fix for 2026-09-05, where a backlog scan
     * spawned three subagents, scheduled a wake-up, ended its turn, and
     * three reporters got nothing.
     *
     * So a workflow has to opt in, and the two assertions below are the two
     * halves of that: the hook stays silent without the variable, and the
     * triage workflow does not set it while still denying the tools.
     */
    public function testAWorkflowThatDeniesSubagentsIsNotOverriddenByThisHook(): void
    {
        $result = self::hookFor([
            'hook_event_name' => 'PreToolUse',
            'tool_name' => 'Agent',
            'tool_input' => ['description' => 'x'],
        ], optedIn: false);

        $this->assertSame(
            '',
            $result['stdout'],
            'The hook decides without `CLAUDE_SUBAGENTS_FOREGROUND`, so it grants `Agent`/`Task` in every '
            . 'workflow that runs Claude — including the ones that deny them deliberately.',
        );

        $triage = (string) file_get_contents(self::path('.github/workflows/issue-triage.yml'));

        $this->assertStringNotContainsString(
            'CLAUDE_SUBAGENTS_FOREGROUND',
            $triage,
            'The issue-triage workflow now opts into the foreground hook, which also grants it the '
            . '`Agent` and `Task` it denies in `--disallowedTools`. Those two statements cannot both '
            . 'be true: one of them is going to win silently, and it will be this one.',
        );

        $this->assertMatchesRegularExpression(
            '/--disallowedTools "[^"]*\bAgent\b[^"]*"/',
            $triage,
            'The issue-triage workflow no longer denies `Agent`. If that is deliberate, this assertion '
            . 'is the wrong one to delete — read the 2026-09-05 incident in that file first.',
        );
    }

    /**
     * The other half of the opt-in: the review workflow has to actually
     * set it, or the hook is inert exactly where it is needed.
     */
    public function testTheReviewWorkflowOptsIn(): void
    {
        $this->assertMatchesRegularExpression(
            '/^      CLAUDE_SUBAGENTS_FOREGROUND: "true"$/m',
            (string) file_get_contents(self::path('.github/workflows/claude-review.yml')),
            'The review job no longer sets `CLAUDE_SUBAGENTS_FOREGROUND`, so the foreground hook does '
            . 'nothing there and every subagent goes back to starting in the background.',
        );
    }

    /**
     * A HOOK THAT IS NOT REGISTERED IS DEAD CODE. Everything above can pass
     * on a file Claude Code never runs: the behaviour is in the script, but
     * whether it runs at all is in `.claude/settings.json`. That file is the
     * one edit between a review that collects its agents and one that does
     * not, which makes it exactly the thing to pin.
     */
    public function testTheHookIsActuallyWiredUp(): void
    {
        $settings = json_decode((string) file_get_contents(self::path(self::SETTINGS)), true);

        self::assertIsArray($settings, self::SETTINGS . ' is not readable JSON.');

        $registered = false;

        foreach ($settings['hooks']['PreToolUse'] ?? [] as $entry) {
            foreach ($entry['hooks'] ?? [] as $hook) {
                if (str_contains((string) ($hook['command'] ?? ''), basename(self::HOOK))) {
                    $registered = true;

                    // Claude Code compiles the matcher with `new RegExp(…)`
                    // and does not anchor it, so this asserts what the
                    // pattern DOES rather than what it looks like: an
                    // unanchored `Agent|Task` also fires on `TaskCreate`,
                    // `TaskStop` and every other Task* tool in the harness.
                    // The script exits silently on those, so this is
                    // tidiness rather than a defect — but a matcher that
                    // stopped naming the two launch tools would be one.
                    $matcher = '/' . str_replace('/', '\/', (string) ($entry['matcher'] ?? '')) . '/';

                    foreach (['Agent', 'Task'] as $launcher) {
                        $this->assertSame(
                            1,
                            preg_match($matcher, $launcher),
                            'The foreground hook is registered against a matcher that does not match `'
                            . $launcher . '`, so it never sees the call it exists to rewrite.',
                        );
                    }

                    foreach (['TaskCreate', 'TaskStop', 'Read'] as $other) {
                        $this->assertSame(
                            0,
                            preg_match($matcher, $other),
                            'The matcher also fires on `' . $other . '`, which is not a subagent launch. '
                            . 'Anchor it (`^(Agent|Task)$`) so the hook is not run on every Task* call.',
                        );
                    }
                }
            }
        }

        $this->assertTrue(
            $registered,
            'The foreground hook is not registered as a `PreToolUse` hook in ' . self::SETTINGS . ', so '
            . 'Claude Code never runs it and every subagent goes back to starting in the background — the '
            . 'defect it was written to remove, with the file still in the tree to suggest otherwise.',
        );
    }
}
