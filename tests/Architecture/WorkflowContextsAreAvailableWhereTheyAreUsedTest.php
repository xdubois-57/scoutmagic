<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A workflow that names a context it may not use is not a workflow with
 * a wrong value in it. It is an INVALID FILE, and GitHub says so in a way
 * that looks like nothing.
 *
 * `.github/workflows/issue-backlog-scan.yml` interpolated
 * `${{ steps.before.outputs.selected }}` into a JOB-LEVEL `env:`. The
 * context-availability table grants `jobs.<job_id>.env` only github,
 * needs, strategy, matrix, vars, secrets and inputs — `steps` is not
 * among them, and cannot be: the job's environment is built before its
 * first step runs.
 *
 * WHAT THAT COST, AND WHY NOTHING POINTED AT IT. GitHub reports an
 * unusable workflow as a FAILED RUN ATTRIBUTED TO THE PUSH — even for a
 * file that declares no `push:` trigger at all — with zero jobs, no log
 * to open, and the run named by its file path instead of by its `name:`.
 * That produced 124 red runs across every branch of this repository in
 * two days, on a workflow whose own header says it runs nightly and on
 * demand, and issue #256 could establish everything about it EXCEPT the
 * mechanism: no `push:` had ever been committed to the file, the file
 * parsed, and the same file succeeded on `schedule`. The tell was the
 * name — every `push` run was called
 * `.github/workflows/issue-backlog-scan.yml` and every `schedule` run was
 * called `Issue backlog scan`.
 *
 * And the red runs were the *visible* half. An invalid workflow does not
 * run at all, so the nightly backlog triage made zero scheduled runs for
 * the two days the bad line was on `main` — silently, which is the half
 * that mattered.
 *
 * So this test reads every workflow and refuses the `steps` context
 * anywhere outside a step. It is deliberately narrower than the whole
 * availability table: this is the violation that has actually happened
 * here, it is the one an editor makes while moving a string into a
 * job-level `env:` to stop two copies drifting, and a test that fails for
 * one clear reason is worth more than one that tries to be a YAML
 * validator.
 */
final class WorkflowContextsAreAvailableWhereTheyAreUsedTest extends TestCase
{
    /**
     * One GitHub expression, `${{ … }}`, naming the `steps` context
     * somewhere inside it.
     *
     * `}(?!})` RATHER THAN `[^}]`, and that is not pedantry: an
     * expression may contain a single `}` of its own, and the shortest
     * realistic way to write one is the very alternative that was
     * considered and rejected when fixing #256 —
     *
     *     SELECTED: ${{ format('{0}', steps.before.outputs.selected) }}
     *
     * A `[^}]*` reach stops dead at the `}` of `{0}` and can never get to
     * `steps.`, so that line reads as clean while being the same invalid
     * workflow. The expression ends at `}}`, so that is where the reach
     * has to stop — and nowhere earlier.
     */
    private const STEPS_IN_AN_EXPRESSION = '/\$\{\{(?:[^}]|\}(?!\}))*?\bsteps\./';

    /**
     * Every workflow this repository ships.
     *
     * @return array<string, array{0: string}>
     */
    public static function workflows(): array
    {
        $directory = dirname(__DIR__, 2) . '/.github/workflows';
        $files = glob($directory . '/*.yml');

        self::assertIsArray($files, 'The workflow directory could not be listed.');
        self::assertNotEmpty($files, 'No workflow files were found, so this test would pass over anything.');

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    /**
     * THE `steps` CONTEXT BELONGS TO A STEP, and everything a job
     * declares before `steps:` — its `env:`, its `if:`, its
     * `concurrency:`, its `outputs:` keys — is read before any step has
     * run.
     *
     * `outputs:` is the one place a job legitimately names
     * `steps.<id>.outputs.<name>`, so it is allowed by name below rather
     * than by accident.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workflows')]
    public function testNoWorkflowNamesTheStepsContextOutsideAStep(string $file): void
    {
        $contents = file_get_contents($file);

        self::assertIsString($contents, $file . ' is unreadable');

        $lines = explode("\n", $contents);
        $name = basename($file);

        // The region this test reads is, in each job, everything from the
        // job's header down to its `steps:`. PER JOB, and the difference
        // is the whole test: a flag that latches at the first `steps:` of
        // a file skips every later job in it, and `checks.yml` has eight.
        // A `${{ steps.… }}` reintroduced into the second job's `env:` —
        // the exact defect this file exists for — would then pass green.
        // So a job header resets the reading.
        //
        // `outputs:` is the one block in that region where the `steps`
        // context is legal, and it is tracked rather than assumed: a key
        // at its own indentation or shallower ends it.
        $inJobs = false;
        $inSteps = false;
        $inOutputs = false;

        // THE ANTI-VACUITY MEASURE, and it has to come from somewhere
        // other than this loop's own triggers. Every GitHub job carries
        // either `steps:` or a `uses:` calling a reusable workflow —
        // that is a property of the FILE, not of the reading, so a
        // reading that has drifted disagrees with it: a job header this
        // loop invents has neither, and a `steps:` it fails to see
        // leaves a real job with neither.
        //
        // Counting `steps:` lines and comparing that to the number of
        // `steps:` lines is what this used to do, and it could not fail
        // for the reason its message gave.
        /** @var array<string, bool> $jobHasABody */
        $jobHasABody = [];
        $currentJob = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^jobs:\s*$/', $line) === 1) {
                $inJobs = true;
                continue;
            }

            // Back out at any top-level key: `jobs:` is last in these
            // files today, and a reader that assumes so is a reader that
            // breaks silently when it stops being true.
            if ($inJobs && preg_match('/^\S/', $line) === 1) {
                $inJobs = false;
                $inSteps = false;
                $inOutputs = false;
            }

            // A job header — two spaces, a name, a colon, nothing after
            // it. This is where the next job's configuration begins, so
            // it is where the previous job's `steps:` stops counting.
            if ($inJobs && preg_match('/^ {2}([A-Za-z0-9_-]+):\s*$/', $line, $job) === 1) {
                $currentJob = $job[1];
                $jobHasABody[$currentJob] = false;
                $inSteps = false;
                $inOutputs = false;
                continue;
            }

            if (preg_match('/^ {4}steps:\s*$/', $line) === 1) {
                $inSteps = true;
                if ($currentJob !== null) {
                    $jobHasABody[$currentJob] = true;
                }
                continue;
            }

            // The other shape a job takes: calling a reusable workflow
            // instead of listing steps. At four spaces it can only be a
            // job key — a step's own `uses:` is six spaces in, behind a
            // `- `, and is not reached here anyway.
            if (!$inSteps && preg_match('/^ {4}uses:\s/', $line) === 1 && $currentJob !== null) {
                $jobHasABody[$currentJob] = true;
            }

            if ($inSteps) {
                // Inside a step, where the `steps` context is exactly
                // what a step is allowed to read.
                continue;
            }

            if (preg_match('/^ {4}outputs:\s*$/', $line) === 1) {
                $inOutputs = true;
                continue;
            }

            if ($inOutputs && preg_match('/^ {0,4}\S/', $line) === 1) {
                $inOutputs = false;
            }

            if ($inOutputs) {
                continue;
            }

            // A comment naming the context is prose, and this file's own
            // comments name it at length — including the line that
            // explains why the placeholder below is a placeholder. Only
            // an EXPRESSION counts, so only `${{ … steps.… }}` is read.
            if (preg_match('/^\s*#/', $line) === 1) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                self::STEPS_IN_AN_EXPRESSION,
                $line,
                $name . ' line ' . ($number + 1) . ' names the `steps` context before any step exists: '
                . trim($line) . "\n\n"
                . 'That is not a value GitHub resolves to an empty string — it makes the whole file an '
                . 'invalid workflow, which GitHub reports as a zero-job failed run on every push and which '
                . 'stops the workflow running for its own triggers. See issue #256: 124 red runs, and two '
                . 'days with no nightly triage at all. Resolve it inside a step (`GITHUB_ENV`) and leave a '
                . 'placeholder here.',
            );
        }

        // ANTI-VACUITY. A file whose jobs this loop never recognised
        // reads as one long block of something, and whichever way that
        // block falls the assertion above stops meaning what it says.
        $this->assertNotEmpty(
            $jobHasABody,
            $name . ' has no job header at the indentation this test reads, so the loop above never told '
            . 'one job from the next — and the reading it claims to make is not the one it made.',
        );

        foreach ($jobHasABody as $job => $hasABody) {
            $this->assertTrue(
                $hasABody,
                $name . ': the loop above read `' . $job . '` as a job and then found neither `steps:` nor '
                . 'a `uses:` in it. Every GitHub job has one or the other, so the reading has drifted from '
                . 'the file — either that is not a job, or its steps were read as job configuration. Both '
                . 'silently narrow what the assertion above examines.',
            );
        }
    }

    /**
     * Lines the detector above must and must not fire on.
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function expressions(): array
    {
        return [
            'the defect itself' =>
                [true, "      SCAN_PROMPT: \${{ steps.before.outputs.selected }}"],
            'the same defect written through format()' =>
                [true, "      SELECTED: \${{ format('{0}', steps.before.outputs.selected) }}"],
            'two expressions, the second one guilty' =>
                [true, "      X: \${{ github.repository }}-\${{ steps.before.outputs.selected }}"],
            'a context that is available here' =>
                [false, "      REPO: \${{ github.repository }}"],
            'a brace of its own and no steps' =>
                [false, "      GREETING: \${{ format('{0}', github.repository) }}"],
            'the word outside any expression' =>
                [false, '      # the steps context is not available here'],
        ];
    }

    /**
     * THE DETECTOR IS ITSELF A PLACE THE DEFECT CAN HIDE, and it hid
     * there once: the first spelling of this pattern reached with
     * `[^}]*`, which stops at any `}` — so an expression carrying a brace
     * of its own, like `format('{0}', steps.…)`, passed as clean while
     * being exactly the invalid workflow of #256. A regex nobody exercises
     * is an assertion nobody has read.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('expressions')]
    public function testTheDetectorFiresOnEverySpellingOfTheDefectAndOnNothingElse(
        bool $shouldFire,
        string $line,
    ): void {
        if ($shouldFire) {
            $this->assertMatchesRegularExpression(
                self::STEPS_IN_AN_EXPRESSION,
                $line,
                'This line names the `steps` context in an expression and the detector does not see it, '
                . 'so the workflow scan above passes over it.',
            );

            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            self::STEPS_IN_AN_EXPRESSION,
            $line,
            'The detector fires on a line that names no `steps` context, which would make every workflow '
            . 'here unfixable rather than merely wrong.',
        );
    }

    /**
     * The one this test was written for, named outright.
     *
     * The general assertion above passes the moment somebody deletes the
     * placeholder along with the prompt; this one says what the file is
     * supposed to do instead, so the substitution cannot quietly become
     * two inline copies of a string whose whole point is that there is
     * one of it.
     */
    public function testTheBacklogScanResolvesItsPromptInsideAStep(): void
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/issue-backlog-scan.yml';
        $contents = file_get_contents($path);

        self::assertIsString($contents, $path . ' is unreadable');

        $this->assertStringContainsString(
            '__SELECTED_ISSUES__',
            $contents,
            'The backlog scan no longer carries the placeholder its prompt is built around. If the issue '
            . 'numbers went back into the job-level `env:`, the file is invalid again (#256); if they were '
            . 'inlined into each attempt instead, the two attempts now have two prompts that can drift — '
            . 'and the one that drifts is the retry, which only runs when something has already gone wrong.',
        );

        $this->assertStringContainsString(
            'RESOLVED_SCAN_PROMPT<<',
            $contents,
            'Nothing resolves the placeholder into the environment any more, so the agent is handed the '
            . 'literal `__SELECTED_ISSUES__` and told to triage it.',
        );

        $this->assertSame(
            2,
            substr_count($contents, 'prompt: ${{ env.RESOLVED_SCAN_PROMPT }}'),
            'The two attempts no longer read the same resolved prompt. They must be given identical '
            . 'instructions: the job measures what it cleared against what it selected, and two prompts '
            . 'are two populations.',
        );
    }
}
