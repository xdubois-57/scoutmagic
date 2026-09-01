<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/release.sh` runs its gates in two waves — the ones that take
 * seconds first and in order (`run_fast_gate`), the long ones in parallel
 * afterwards (`launch_gate`) — and adding one means touching six places
 * that no compiler connects: the skip flag's variable, its argument case,
 * its documentation in the header, the call that starts it, the line that
 * reads the gate's report file, and the assembled Markdown report.
 *
 * **Which of the two launchers a gate uses is a scheduling decision, and
 * this test deliberately does not have an opinion on it.** What it holds
 * is that every gate function is started by ONE of them: a gate moved from
 * one wave to the other must not fall out of the checks below, and when
 * the fast wave was introduced it did exactly that — four gates stopped
 * being seen as launched at all, and the test that exists to catch a gate
 * nobody runs went red for gates that run perfectly well.
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
     * Either launcher, because either one actually runs the gate.
     *
     * @see LAUNCHERS
     */
    private const LAUNCHERS = '(?:launch_gate|run_fast_gate)';

    /**
     * The gate keys, taken from the launch calls — the one place that
     * decides what actually runs.
     *
     * @return list<string>
     */
    private static function launchedKeys(): array
    {
        preg_match_all('/^\s*' . self::LAUNCHERS . '\s+([a-z_]+)\s/m', self::script(), $matches);

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
            if (preg_match(
                '/' . self::LAUNCHERS . '\s+\S+\s+"[^"]*"\s+check_' . preg_quote($name, '/') . '_gate\b/',
                $script
            ) !== 1) {
                $orphans[] = "check_{$name}_gate";
            }
        }

        $this->assertSame([], $orphans, 'these gate functions are passed to neither launcher — they never run');
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
