<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/release.sh` runs its gates in parallel, and adding one means
 * touching six places that no compiler connects: the skip flag's
 * variable, its argument case, its documentation in the header, the
 * `launch_gate` call, the line that reads the gate's report file, and
 * the assembled Markdown report.
 *
 * Miss one and the release still works, which is the problem. Forget the
 * report line and the release notes silently omit a gate that ran.
 * Forget the skip flag and a releaser has no way past a gate whose
 * prerequisite they lack, so they reach for a different `--skip` and
 * drop something else with it. Forget the `launch_gate` call and the
 * gate simply never runs while its function sits there looking
 * reassuring.
 *
 * None of those fail anything. This does.
 */
class ReleaseGatesTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/release.sh');
    }

    /**
     * The gate keys, taken from the `launch_gate` calls — the one place
     * that decides what actually runs.
     *
     * @return list<string>
     */
    private static function launchedKeys(): array
    {
        // Two launchers, one meaning. `run_fast_gate` runs the gates that
        // finish in seconds first and in the foreground, `launch_gate`
        // forks the long ones; a gate wired to either one runs, and a gate
        // wired to neither does not. Every assertion below is about that
        // second thing, so both spellings count here.
        preg_match_all(
            '/^\s*(?:launch_gate|run_fast_gate)\s+([a-z_]+)\s/m',
            self::script(),
            $matches
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * The gate keys `run_fast_gate` runs, in the order the script runs
     * them.
     *
     * @return list<string>
     */
    private static function fastKeys(): array
    {
        preg_match_all('/^\s*run_fast_gate\s+([a-z_]+)\s/m', self::script(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private static function gateFunctions(): array
    {
        preg_match_all('/^check_([a-z_]+)_gate\(\)\s*\{/m', self::script(), $matches);

        return array_values(array_unique($matches[1]));
    }

    public function testThereAreGatesAtAll(): void
    {
        $this->assertGreaterThanOrEqual(6, count(self::launchedKeys()));
    }

    /**
     * A gate function nothing launches is a gate that does not run, and
     * it reads exactly like one that does.
     */
    public function testEveryGateFunctionIsActuallyLaunched(): void
    {
        $script = self::script();
        $orphans = [];

        foreach (self::gateFunctions() as $name) {
            $wired = '/(?:launch_gate|run_fast_gate)\s+\S+\s+"[^"]*"\s+check_'
                . preg_quote($name, '/') . '_gate\b/';
            if (preg_match($wired, $script) !== 1) {
                $orphans[] = "check_{$name}_gate";
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'these gate functions are passed to neither launch_gate nor run_fast_gate — they never run'
        );
    }

    /**
     * The fast gates exist to fail before anything expensive starts, and
     * that is a property of ORDER rather than of speed: a `run_fast_gate`
     * call sitting after the first `launch_gate` would still run, still
     * pass its own assertions, and still cost the full twenty-five
     * minutes it was written to save.
     *
     * This is how that regression was possible in the first place — the
     * dependency and SonarQube blocks were declared after the long gates,
     * so switching them to run_fast_gate was not enough on its own and
     * the blocks had to be moved.
     */
    public function testEveryFastGateRunsBeforeTheFirstLongOne(): void
    {
        $script = self::script();

        $this->assertNotSame([], self::fastKeys(), 'no gate runs in the fast lane any more');

        $firstLaunch = null;
        if (preg_match('/^\s*launch_gate\s/m', $script, $m, PREG_OFFSET_CAPTURE) === 1) {
            $firstLaunch = $m[0][1];
        }
        $this->assertNotNull($firstLaunch, 'nothing is launched in parallel any more');

        preg_match_all('/^\s*run_fast_gate\s+([a-z_]+)\s/m', $script, $fast, PREG_OFFSET_CAPTURE);
        $late = [];
        foreach ($fast[0] as $i => $match) {
            if ($match[1] > $firstLaunch) {
                $late[] = $fast[1][$i][0];
            }
        }

        $this->assertSame(
            [],
            $late,
            'these fast gates are declared after a long gate is launched, so they no longer run first'
        );
    }

    /**
     * Each launched gate must be readable from its report file and must
     * reach the assembled report, or a gate runs and the release notes
     * never mention it.
     */
    public function testEveryLaunchedGateReportsItself(): void
    {
        $script = self::script();
        $missing = [];

        foreach (self::launchedKeys() as $key) {
            $variable = strtoupper($key) . '_GATE_REPORT_LINE';

            if (!str_contains($script, '${GATE_TMP_DIR}/' . $key . '.report')) {
                $missing[] = "{$key}: nothing reads \${GATE_TMP_DIR}/{$key}.report";
            }
            if (!str_contains($script, '${' . $variable . '}')) {
                $missing[] = "{$key}: \${$variable} never reaches the assembled report";
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Every gate has a way past it, and it is documented.
     *
     * Not a convenience: a gate with no escape hatch is one a releaser
     * without its prerequisite gets past by reaching for some OTHER
     * `--skip`, dropping a check nobody meant to drop.
     */
    public function testEveryLaunchedGateHasADocumentedSkipFlag(): void
    {
        $script = self::script();
        $missing = [];

        foreach (self::launchedKeys() as $key) {
            $variable = 'SKIP_' . strtoupper($key);
            // The flags are not spelled uniformly — --skip-tests-gate
            // beside --skip-dependency-check — so the variable is what is
            // matched, and the flag is required to exist in the same line
            // of the argument loop.
            if (preg_match('/^' . $variable . '(_GATE|_CHECK)?=0$/m', $script) !== 1) {
                $missing[] = "{$key}: no {$variable}[_GATE|_CHECK]=0 default";
                continue;
            }

            if (preg_match('/--skip-[a-z-]+\)\s*' . $variable . '(_GATE|_CHECK)?=1;/', $script) !== 1) {
                $missing[] = "{$key}: no --skip-… argument sets {$variable}";
                continue;
            }

            preg_match('/(--skip-[a-z-]+)\)\s*' . $variable . '(_GATE|_CHECK)?=1;/', $script, $flag);
            $usage = substr($script, 0, (int) strpos($script, 'BUMP="patch"'));
            $this->assertStringContainsString(
                $flag[1],
                $usage,
                "{$flag[1]} exists but is not documented in the header block a releaser reads"
            );
        }

        $this->assertSame([], $missing);
    }

    /**
     * The gate that runs the dynamic scan, pinned by name because
     * SECURITY.md §§ 35-36 and the CI workflow both point at it.
     */
    public function testTheDynamicSecurityGateIsWiredIn(): void
    {
        $script = self::script();

        $this->assertContains('dast', self::launchedKeys());
        $this->assertStringContainsString('./scripts/dast.sh --profile=standard', $script);
        $this->assertStringContainsString('./scripts/dast.sh --profile=passive', $script);
        $this->assertStringNotContainsString(
            './scripts/dast.sh --profile=deep',
            $script,
            'the active profiles take the better part of an hour and attack the instance — '
            . 'a release gate has to be something a releaser will actually wait for'
        );
    }
}
