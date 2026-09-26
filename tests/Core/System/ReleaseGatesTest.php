<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

/**
 * `scripts/release.sh` runs six gates, and adding one means touching six
 * places that no compiler connects: the skip flag's variable, its
 * argument case, its documentation in the header, the `run_gate` call,
 * the line that reads the gate's report file, and the assembled Markdown
 * report.
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
        preg_match_all('/^\s*run_gate\s+([a-z_]+)\s/m', self::script(), $matches);

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

    /**
     * The six, by key and in order. Written out rather than counted:
     * the order is the documented one (a precondition about production,
     * then the verdict on the code, then what ships), and a gate silently
     * dropped from the sequence is exactly what this file exists to
     * catch.
     *
     * `deprecated_api` sits beside `dependency` because it asks the same
     * kind of question — has the ground moved under us upstream — rather
     * than anything about this commit. It was added for issue #379.
     */
    public function testTheSixGatesRunInTheDocumentedOrder(): void
    {
        $this->assertSame(
            ['deployment', 'ci', 'security', 'dependency', 'deprecated_api', 'sonar'],
            self::launchedKeys()
        );
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
            $wired = '/run_gate\s+\S+\s+"[^"]*"\s+check_'
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
            // The flags are not spelled uniformly — --skip-ci-gate
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
     * README.md's own numbered list of the gates, which is a SEVENTH place
     * nothing connects — and the one that drifted.
     *
     * The gate added for issue #379 was wired into all six places above and
     * into AGENTS.md, and the README was updated where it lists the
     * `--skip-*` flags. The enumeration four lines higher still said « cinq
     * verrous » and still jumped from « Fraîcheur des dépendances » to
     * « SonarQube Cloud », so the file advertised a bypass flag for a gate it
     * never documented. Nothing failed; a reviewer noticed.
     *
     * Counted rather than matched by name: the keys here are English and
     * that list is French, so anything finer would be a translation table
     * drifting beside the thing it describes. A count is enough to make a
     * missing entry impossible to merge.
     */
    public function testTheReadmeEnumeratesEveryGateItSaysTheScriptRuns(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');
        $expected = count(self::launchedKeys());

        $numerals = [
            4 => 'quatre', 5 => 'cinq', 6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf',
        ];
        $this->assertArrayHasKey($expected, $numerals, 'add the French numeral for this many gates');

        $sentence = "le script exécute {$numerals[$expected]} verrous";
        $this->assertStringContainsString(
            $sentence,
            $readme,
            "README.md § Releases must say « {$sentence} » — scripts/release.sh launches {$expected}"
        );

        // The list itself, bounded to the section that holds it — from the
        // count sentence to the next heading.
        //
        // Two narrower boundaries were tried and both read the « Installation
        // sur hébergement mutualisé » list further down: the highest number
        // in the rest of the file (7), and then a walk stopping at the first
        // number that did not continue the run — which also answered 7,
        // because that list's first BOLD item happens to be its seventh and
        // nothing before it matches. A section ends at a heading; that is the
        // only boundary here that means anything.
        $start = (int) strpos($readme, $sentence);
        $section = substr($readme, $start);
        $nextHeading = preg_match('/\n#{2,3} /', $section, $found, PREG_OFFSET_CAPTURE) === 1
            ? (int) $found[0][1]
            : strlen($section);
        $section = substr($section, 0, $nextHeading);

        $described = preg_match_all('/^(\d+)\. \*\*/m', $section);

        $this->assertSame(
            $expected,
            $described,
            'README.md § Releases numbers ' . $described . " gates while scripts/release.sh launches {$expected}"
            . ' — one of them has no entry, so the file describes a release nobody runs'
        );
    }

    /**
     * `docs/quality-pipeline.md`'s table of the gates — an EIGHTH place
     * nothing connects, and it drifted the same way the README did.
     *
     * Adding the gate for issue #379 put a paragraph about it in that file
     * while the sentence and the table four lines above still said « Five
     * gates, all fail-closed » and listed five rows. The section contradicted
     * itself, and a reviewer had to notice, having already had to notice the
     * identical thing in the README. Once is an oversight; twice in one hour
     * is a missing test.
     *
     * Rows rather than words here, because that file counts in English and
     * writes the number as a numeral nowhere: the table is the claim.
     */
    public function testThePipelineDocTabulatesEveryGateTheScriptRuns(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/quality-pipeline.md');
        $expected = count(self::launchedKeys());

        $anchor = '| Gate | What it checks |';
        $start = strpos($doc, $anchor);
        self::assertIsInt($start, 'the gate table is gone from docs/quality-pipeline.md § Releases');

        // The table runs to the first blank line after its header.
        $table = substr($doc, $start + strlen($anchor));
        $end = strpos($table, "\n\n");
        $table = $end === false ? $table : substr($table, 0, $end);

        // Its header separator is not a gate.
        $rows = preg_match_all('/^\| \*\*/m', $table);

        $this->assertSame(
            $expected,
            $rows,
            "docs/quality-pipeline.md tabulates {$rows} gates while scripts/release.sh launches "
            . "{$expected}. A gate described in that file's prose but missing from its table is a"
            . ' section that contradicts itself.'
        );
    }

    /**
     * `scripts/release.test.sh` pins the gate order too — a NINTH place
     * nothing connects, and the one that could stay broken longest, because
     * nothing in CI runs it.
     *
     * Adding the gate for issue #379 left its `expected_order` at five keys,
     * so the shell self-test failed for anyone who ran it, saying the order
     * was « deployment ci security dependency deprecated sonar » — a list no
     * line of release.sh contains, because its extraction pattern was
     * `[a-z]+` and truncated `deprecated_api`. A failure message naming the
     * wrong defect is worse than none.
     *
     * This test is not a second copy of that one: it asserts that the shell
     * script's expectation IS the order `run_gate` actually launches, so the
     * two cannot disagree. PHPUnit runs in CI; that self-test does not.
     */
    public function testTheShellSelfTestExpectsTheOrderTheScriptActuallyLaunches(): void
    {
        $selfTest = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/release.test.sh');

        $this->assertSame(
            1,
            preg_match('/^expected_order="([^"]*)"/m', $selfTest, $pinned),
            'scripts/release.test.sh no longer pins an expected_order'
        );

        $this->assertSame(
            implode(' ', self::launchedKeys()),
            $pinned[1],
            "scripts/release.test.sh expects a gate order release.sh does not run. Nothing in CI"
            . " runs that\nfile, so it stays wrong until somebody runs it by hand — which is why"
            . ' this assertion is here.'
        );

        // And the pattern it extracts with has to admit the keys that exist.
        foreach (self::launchedKeys() as $key) {
            if (!str_contains($key, '_')) {
                continue;
            }
            $this->assertStringContainsString(
                "run_gate [a-z_]+",
                $selfTest,
                "the gate key '{$key}' has an underscore, so release.test.sh's extraction pattern"
                . ' must accept one'
            );
        }
    }

    /**
     * What this script deliberately does NOT run, and what it reads
     * instead.
     *
     * PHPStan, both PHPUnit engines, the browser suite and both DAST
     * profiles used to be gates here, on the releaser's machine, taking
     * twenty-five minutes. They are the runner's now — twice over, on the
     * pull request and again on the tag — and the value of that move is
     * entirely in them not also being here: a local copy is the run on
     * one database engine, with a Chromium and a ZAP image somebody had
     * to install, whose verdict appears nowhere a reader of the Release
     * can check. Bringing one back would restore the confusion, not the
     * safety.
     *
     * Comments are stripped first, because the script's header explains
     * that history at length and a test that could not tell the
     * explanation from a call would forbid writing it down.
     */
    public function testTheSlowVerdictsAreTheRunnersAndNotThisScriptsAgain(): void
    {
        $code = (string) preg_replace('/^\s*#.*$/m', '', self::script());

        foreach ([
            'vendor/bin/phpstan',
            'vendor/bin/phpunit',
            'npm run e2e',
            'npm run test:coverage',
            './scripts/dast.sh',
        ] as $command) {
            $this->assertStringNotContainsString(
                $command,
                $code,
                $command . ' is back in scripts/release.sh — that verdict belongs to .github/workflows/checks.yml, '
                . 'where it runs on two engines and lands in the evidence pack'
            );
        }

        // What replaces them: one read of the verdict GitHub already
        // reached on the commit being released.
        $this->assertContains('ci', self::launchedKeys());
        $this->assertStringContainsString('CI_VERDICT_CHECK:-All checks', self::script());
        $this->assertStringContainsString('check-runs?check_name=', self::script());
        $this->assertStringContainsString(
            'git status --porcelain',
            self::script(),
            'a dirty tree makes that verdict describe something other than what the artifact ships'
        );
    }

    /**
     * The dynamic scan is still a release requirement — it just runs
     * where every other test does. Its two profiles are named in
     * checks.yml, and the active ones are still nowhere: they take the
     * better part of an hour and attack the instance.
     */
    public function testBothDynamicScanProfilesRunOnTheRunner(): void
    {
        $checks = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/checks.yml');

        $this->assertStringContainsString('./scripts/dast.sh --profile=standard', $checks);
        $this->assertStringContainsString('./scripts/dast.sh --profile=passive', $checks);
        $this->assertStringNotContainsString('./scripts/dast.sh --profile=deep', $checks);
        $this->assertStringNotContainsString('./scripts/dast.sh --profile=audit', $checks);
    }
}
