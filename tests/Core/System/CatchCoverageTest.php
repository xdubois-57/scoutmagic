<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('CATCH_COVERAGE_TEST')) {
    define('CATCH_COVERAGE_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/catch-coverage.php';

/**
 * scripts/catch-coverage.php is the measure issue #449 is driven by: which
 * `catch` blocks of the product the suite never runs. It declares no
 * namespace (a CLI script), so its functions live in the global namespace —
 * hence the leading backslashes.
 *
 * What is pinned here is what a wrong measure would hide: an empty `catch`
 * judged on its own line rather than dropped, a marker that needs a reason,
 * the tokenizer not fooled by the word in a string, and the report's
 * relative paths resolved — the trap that once made a lookup report almost
 * every branch as never executed.
 */
final class CatchCoverageTest extends TestCase
{
    public function testABodyIsReadFromItsFirstStatementLine(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                work();
            } catch (\RuntimeException $e) {
                // why we carry on
                log($e);
                return null;
            }
            PHP);

        $this->assertCount(1, $catches);
        $this->assertSame(4, $catches[0]['line']);
        $this->assertSame([6, 7], $catches[0]['bodyLines']);
        $this->assertFalse($catches[0]['empty']);
        $this->assertNull($catches[0]['marker']);
    }

    public function testACommentOnlyBodyIsEmptyAndKeepsItsCatchLine(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                work();
            } catch (\Throwable) {
                // Same posture as every other journal call on this path.
            }
            try { other(); } catch (\Throwable) {}
            PHP);

        $this->assertCount(2, $catches);
        $this->assertTrue($catches[0]['empty']);
        $this->assertSame([], $catches[0]['bodyLines']);
        $this->assertSame(4, $catches[0]['line']);
        $this->assertTrue($catches[1]['empty']);
        $this->assertSame(7, $catches[1]['line']);
    }

    public function testAOneLineBodyCountsTheStatementOnTheBraceLine(): void
    {
        $catches = \catchCoverageScan("<?php\ntry { a(); } catch (\\Exception) { return 1; }\n");

        $this->assertSame([2], $catches[0]['bodyLines']);
        $this->assertFalse($catches[0]['empty']);
        $this->assertTrue($catches[0]['sharedLine'], 'a(); runs on the catch line too');
    }

    public function testOnlyTheClosingBraceOfTheTryLeavesTheCatchLineItsOwn(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (FooException) {
            } catch (BarException) {
                b();
            }
            try { c(); } catch (\Throwable) {}
            try {
                if (d()) {
                    e();
                }} catch (\Error) {
                f();
            }
            try { g(); } catch (A) {} catch (B) {}
            PHP);

        $this->assertSame([false, false, true, false, true, true], array_column($catches, 'sharedLine'));
    }

    public function testCodeAfterABodyClosingOnTheCatchLineSharesItToo(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (FooException) {} finally { cleanup(); }
            try {
                b();
            } catch (BarException) {} catch (BazException) { handle(); }
            try {
                c();
            } catch (QuxException) {} // a comment changes nothing
            try {
                d();
            } catch (QuuxException) {
            } finally {
                e();
            }
            PHP);

        $this->assertSame(
            [true, true, true, false, false],
            array_column($catches, 'sharedLine'),
            'finally, a chained catch: shared; a comment after, or a body closing on a later line: not'
        );
    }

    public function testASharedCatchLineIsNeverTakenAsEvidence(): void
    {
        // try { x(); } catch (T) {} — x() running marks the line, not the catch.
        $empty = ['line' => 2, 'bodyLines' => [], 'empty' => true, 'marker' => null, 'sharedLine' => true];
        $this->assertSame('unknown', \catchCoverageJudge($empty, [2 => ['T1']]));

        // try { a(); } catch (T) { b();
        //     c(); } — judged on line 3 alone, and only a pass there is proof:
        // the lines left may sit in a branch the catch did not take (below).
        $body = ['line' => 2, 'bodyLines' => [2, 3], 'empty' => false, 'marker' => null, 'sharedLine' => true];
        $this->assertSame('unknown', \catchCoverageJudge($body, [2 => ['T1'], 3 => []]));
        $this->assertSame('executed', \catchCoverageJudge($body, [2 => ['T1'], 3 => ['T1']]));

        $oneLine = ['line' => 2, 'bodyLines' => [2], 'empty' => false, 'marker' => null, 'sharedLine' => true];
        $this->assertSame('unknown', \catchCoverageJudge($oneLine, [2 => ['T1']]));
    }

    /**
     * With the catch's own line set aside, the first line left may be the
     * inside of an `if` the catch did not take: its silence says nothing
     * about whether the catch ran. Only a pass on a remaining line proves.
     */
    public function testAConditionalChildOfASharedCatchLineIsNeverProofThatTheCatchDidNotRun(): void
    {
        $source = <<<'PHP'
            <?php
            try { a(); } catch (E) { if ($x) {
                b();
            } }
            PHP;
        $catch = \catchCoverageScan($source)[0];

        $this->assertTrue($catch['sharedLine']);
        $this->assertSame('unknown', \catchCoverageJudge($catch, [2 => ['T1'], 3 => []]));
        $this->assertSame('executed', \catchCoverageJudge($catch, [2 => ['T1'], 3 => ['T1']]));
    }

    /**
     * `recover(); } after();` — the line of the closing brace holds code
     * that runs whether or not the catch did, so it proves nothing, exactly
     * like the catch line shared with the try.
     */
    public function testABodyClosingOnALineFollowedByCodeDoesNotProveItself(): void
    {
        $source = <<<'PHP'
            <?php
            try {
                a();
            } catch (E) {
                recover(); } after();
            PHP;
        $catch = \catchCoverageScan($source)[0];

        $this->assertSame(5, $catch['closingLine']);
        $this->assertSame('unknown', \catchCoverageJudge($catch, [4 => ['T1'], 5 => ['T1']]));
    }

    /**
     * The closing brace's line is set aside AFTER the lines above it, so it
     * weakens none of them: an earlier unconditional line that never ran
     * still proves `never`, and one that ran still proves `executed`.
     */
    public function testAClosingLineSetAsideDoesNotWeakenTheLinesAboveIt(): void
    {
        $catch = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (E) {
                log_it();
                recover(); } after();
            PHP)[0];

        $this->assertSame([5, 6], $catch['bodyLines']);
        $this->assertSame(6, $catch['closingLine']);
        $this->assertSame('never', \catchCoverageJudge($catch, [5 => [], 6 => ['T1']]));
        $this->assertSame('executed', \catchCoverageJudge($catch, [5 => ['T1'], 6 => ['T1']]));
        $this->assertSame('unknown', \catchCoverageJudge($catch, [6 => ['T1']]), 'only the shared line is recorded');
    }

    public function testABodyClosingOnItsOwnLineOrBeforeOnlyACommentLeavesTheLineAlone(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (E) {
                recover();
            }
            try {
                b();
            } catch (F) {
                recover(); } // nothing runs after the brace
            PHP);

        $this->assertSame([null, null], array_column($catches, 'closingLine'));
    }

    /**
     * The text of a token is not its identity: the tail of an interpolated
     * string can be exactly `}`, which is no brace and closes nothing.
     */
    public function testAStringFragmentThatIsLiterallyABraceDoesNotCloseTheBody(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (\Exception $e) {
                $m = "$e}";
                after();
            }
            PHP);

        $this->assertSame([5, 6], $catches[0]['bodyLines']);
    }

    public function testTheWordInAStringOrACommentIsNotACatch(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            // } catch (Nothing) {
            $s = 'try { } catch (Nope) { }';
            $t = "catch {$s}";
            PHP);

        $this->assertSame([], $catches);
    }

    public function testInterpolationBracesInsideTheBodyDoNotCloseItEarly(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (\Exception $e) {
                $m = "failed: {$e->getMessage()}";
                if ($m !== '') {
                    report($m);
                }
                after();
            }
            PHP);

        $this->assertSame([5, 6, 7, 8, 9], $catches[0]['bodyLines']);
    }

    public function testADollarBraceInterpolationDoesNotCloseTheBodyEarly(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (\Exception $e) {
                $m = "failed: ${e}";
                after();
            }
            PHP);

        $this->assertSame([5, 6], $catches[0]['bodyLines']);
    }

    public function testNestedAndMultiTypeCatchesAreEachFound(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (FooException | BarException $e) {
                try {
                    b();
                } catch (\Throwable) {
                }
            } catch (\Error $e) {
                c();
            }
            PHP);

        $this->assertSame([4, 7, 9], array_column($catches, 'line'));
        $this->assertSame([true], array_values(array_unique([$catches[1]['empty']])));
    }

    public function testTheMarkerCarriesItsReasonAndARemovalLeavesNone(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try { a(); } catch (FooException $e) {
                // @catch-unreachable Re-read of the row the request just checked.
                return $this->notFound();
            }
            try { b(); } catch (BarException $e) {
                /* @catch-unreachable */
                return null;
            }
            PHP);

        $this->assertSame('Re-read of the row the request just checked.', $catches[0]['marker']);
        $this->assertSame('', $catches[1]['marker'], 'a marker with no reason is recorded as such');
    }

    public function testAMarkerInANestedCatchNamesThatCatchAloneNotTheOneEnclosingIt(): void
    {
        $catches = \catchCoverageScan(<<<'PHP'
            <?php
            try {
                a();
            } catch (OuterException $e) {
                try {
                    // @catch-unreachable Not this try's catch either.
                    b();
                } catch (InnerException $f) {
                    // @catch-unreachable Inner only: row just read.
                    return null;
                }
                return fail();
            }
            PHP);

        $this->assertSame([4, 8], array_column($catches, 'line'));
        $this->assertNull($catches[0]['marker'], 'the outer catch carries no marker of its own');
        $this->assertSame('Inner only: row just read.', $catches[1]['marker']);
    }

    public function testExecutedIsReadOnTheFirstExecutableBodyLine(): void
    {
        $catch = ['line' => 4, 'bodyLines' => [6, 7], 'empty' => false, 'marker' => null];

        $this->assertSame('executed', \catchCoverageJudge($catch, [4 => [], 6 => ['T1'], 7 => ['T1']]));
        $this->assertSame('never', \catchCoverageJudge($catch, [4 => ['T1'], 6 => [], 7 => []]));
        // Line 6 not executable (a multi-line call's continuation, say): line 7 decides.
        $this->assertSame('executed', \catchCoverageJudge($catch, [6 => null, 7 => ['T1']]));
        $this->assertSame('unknown', \catchCoverageJudge($catch, [4 => ['T1']]));
    }

    public function testAnEmptyBodyIsJudgedOnTheCatchLineItself(): void
    {
        $catch = ['line' => 4, 'bodyLines' => [], 'empty' => true, 'marker' => null];

        $this->assertSame('never-empty', \catchCoverageJudge($catch, [4 => []]));
        $this->assertSame('executed', \catchCoverageJudge($catch, [4 => ['T1']]));
        $this->assertSame('unknown', \catchCoverageJudge($catch, [3 => ['T1']]));
    }

    public function testAFileTheReportNeverSawHasEveryCatchNeverExecuted(): void
    {
        $this->assertSame('never', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [5], 'empty' => false, 'marker' => null],
            null
        ));
        $this->assertSame('never-empty', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [], 'empty' => true, 'marker' => null],
            null
        ));
    }

    public function testAMarkerWithAReasonIsUnreachableAndOneWithoutIsNot(): void
    {
        $lines = [4 => [], 5 => []];

        $this->assertSame('unreachable', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [5], 'empty' => false, 'marker' => 'TOCTOU window.'],
            $lines
        ));
        $this->assertSame('never', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [5], 'empty' => false, 'marker' => ''],
            $lines
        ));
        // Even on a catch the suite does run: the bare marker is the finding.
        $this->assertSame('never', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [5], 'empty' => false, 'marker' => ''],
            [4 => ['T1'], 5 => ['T1']]
        ));
        $this->assertSame('never-empty', \catchCoverageJudge(
            ['line' => 4, 'bodyLines' => [], 'empty' => true, 'marker' => ''],
            [4 => ['T1']]
        ));
    }

    public function testReportKeysAreResolvedAgainstTheirBasePath(): void
    {
        $this->assertSame(
            'core/Mail/Transport/MailTransportChain.php',
            \catchCoverageRelative('Mail/Transport/MailTransportChain.php', '/repo/core', '/repo')
        );
        $this->assertSame(
            'modules/finance/src/X.php',
            \catchCoverageRelative('/repo/modules/finance/src/X.php', '', '/repo')
        );
    }

    public function testTheSummaryCountsEveryVerdictDomainAndInvalidMarker(): void
    {
        $summary = \catchCoverageSummarise([
            ['file' => 'core/Mail/A.php', 'line' => 1, 'verdict' => 'executed', 'marker' => null],
            ['file' => 'core/Mail/A.php', 'line' => 2, 'verdict' => 'never', 'marker' => null],
            ['file' => 'core/Mail/B.php', 'line' => 3, 'verdict' => 'never-empty', 'marker' => null],
            ['file' => 'modules/rental/src/C.php', 'line' => 4, 'verdict' => 'unreachable', 'marker' => 'why'],
            ['file' => 'modules/rental/src/C.php', 'line' => 5, 'verdict' => 'never', 'marker' => ''],
        ]);

        $this->assertSame(5, $summary['total']);
        $this->assertSame(
            ['executed' => 1, 'never' => 2, 'never-empty' => 1, 'unreachable' => 1, 'unknown' => 0],
            $summary['verdicts']
        );
        $this->assertSame(1, $summary['invalidMarkers']);
        $this->assertSame(['core/Mail', 'modules/rental'], array_keys($summary['byDomain']));
        $this->assertSame(
            ['total' => 3, 'never' => 1, 'never-empty' => 1, 'unreachable' => 0],
            $summary['byDomain']['core/Mail']
        );
        $this->assertSame(
            ['core/Mail/A.php' => 1, 'core/Mail/B.php' => 1, 'modules/rental/src/C.php' => 1],
            $summary['byFile']
        );
    }

    public function testEveryProductFileIsScannedAndNoTestOrVendorFile(): void
    {
        $files = \catchCoverageSourceFiles(dirname(__DIR__, 3));

        $this->assertContains('core/Mail/Transport/MailTransportChain.php', $files);
        $this->assertContains('modules/mass_mail/src/Controller/MassMailController.php', $files);
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('#^(core|modules)/.+\.php$#', $file);
        }
    }

    public function testTheRealProductParsesIntoCatchesWithALineEach(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/core/Mail/Transport/MailTransportChain.php');

        $catches = \catchCoverageScan($source);

        $this->assertNotEmpty($catches);
        foreach ($catches as $catch) {
            $this->assertStringContainsString('catch', explode("\n", $source)[$catch['line'] - 1]);
        }
    }

    public function testTheCommandReportsAFakeRepositoryInEveryFormat(): void
    {
        $root = $this->fakeRepository();
        $report = $this->fakeReport($root, 'payload');

        [$code, $out, $err] = \catchCoverageRun([$report], $root);
        $this->assertSame([0, ''], [$code, $err]);
        $this->assertStringContainsString('catch blocks under core/ and modules/: 2', $out);
        $this->assertStringContainsString('never executed:           1 (50 %)', $out);
        $this->assertStringContainsString('core/Thing', $out);
        $this->assertStringNotContainsString('core/Thing/Service.php:', $out, 'no list without --list');

        [, $listed] = \catchCoverageRun([$report, '--list'], $root);
        $this->assertStringContainsString('core/Thing/Service.php:7 never', $listed);
        $this->assertStringNotContainsString('core/Thing/Service.php:4', $listed, 'an executed catch is not listed');

        [, $json] = \catchCoverageRun([$report, '--json'], $root);
        $decoded = json_decode($json, true);
        $this->assertSame(2, $decoded['summary']['total']);
        $this->assertSame(['executed', 'never'], array_column($decoded['catches'], 'verdict'));
    }

    public function testAReportWrittenAsACoverageObjectReadsTheSame(): void
    {
        $root = $this->fakeRepository();

        $lines = \catchCoverageLoadReport($this->fakeReport($root, 'object'), $root);

        $this->assertSame(['T1'], $lines['core/Thing/Service.php'][5]);
    }

    public function testTheCommandRefusesAMissingOrSurplusArgument(): void
    {
        [$code, $out, $err] = \catchCoverageRun(['--list'], '/nowhere');

        $this->assertSame([2, ''], [$code, $out]);
        $this->assertStringContainsString('usage:', $err);
        $this->assertSame(2, \catchCoverageRun(['a.php', 'b.php'], '/nowhere')[0]);
    }

    public function testSomethingThatIsNotACoverageReportIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/catch-coverage-not-a-report-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, "<?php return 42;\n");

        try {
            $this->expectException(\RuntimeException::class);
            \catchCoverageLoadReport($path, '/repo');
        } finally {
            unlink($path);
        }
    }

    public function testTheTextReportSaysWhenAMarkerHasNoReason(): void
    {
        $catches = [
            ['file' => 'core/A/B.php', 'line' => 3, 'verdict' => 'never', 'marker' => ''],
            ['file' => 'core/A/B.php', 'line' => 9, 'verdict' => 'unknown', 'marker' => null],
        ];

        $out = \catchCoverageRender(\catchCoverageSummarise($catches), $catches);

        $this->assertStringContainsString('invalid markers (no reason, counted as never): 1', $out);
        $this->assertStringContainsString('core/A/B.php:9 unknown', $out);
    }

    /** A repository holding one product file with an executed catch (line 4) and a never-executed one (line 7). */
    private function fakeRepository(): string
    {
        $root = sys_get_temp_dir() . '/catch-coverage-repo-' . bin2hex(random_bytes(4));
        mkdir($root . '/core/Thing', 0777, true);
        mkdir($root . '/modules', 0777, true);
        file_put_contents($root . '/core/Thing/Service.php', implode("\n", [
            '<?php',
            'try {',
            '    a();',
            '} catch (\\RuntimeException $e) {',
            '    b();',
            '} try {',
            '} catch (\\Exception $e) {',
            '    e();',
            '}',
            '',
        ]));
        $this->tempPaths[] = $root;

        return $root;
    }

    /**
     * A --coverage-php report for the fake repository, in either of the two
     * shapes catchCoverageLoadReport() accepts: the payload array (files
     * relative to basePath) or an object answering getData().
     */
    private function fakeReport(string $root, string $shape): string
    {
        $lines = var_export(['Thing/Service.php' => [4 => [], 5 => ['T1'], 7 => [], 8 => []]], true);
        $absolute = var_export([$root . '/core/Thing/Service.php' => [5 => ['T1'], 8 => []]], true);
        $body = $shape === 'payload'
            ? "return ['basePath' => " . var_export($root . '/core', true) . ", 'codeCoverage' => new class {\n"
                . "    public function lineCoverage(): array { return {$lines}; }\n}];\n"
            : "return new class {\n"
                . "    public function getData(): object { return new class {\n"
                . "        public function lineCoverage(): array { return {$absolute}; }\n"
                . "    }; }\n};\n";
        $path = $root . '/report-' . $shape . '.php';
        file_put_contents($path, "<?php\n" . $body);

        return $path;
    }

    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            exec('rm -rf ' . escapeshellarg($path));
        }
    }
}
