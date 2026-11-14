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
}
