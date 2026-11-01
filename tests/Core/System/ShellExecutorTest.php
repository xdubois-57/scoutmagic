<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\System\ShellExecutor;
use PHPUnit\Framework\TestCase;

class ShellExecutorTest extends TestCase
{
    protected function tearDown(): void
    {
        ShellExecutor::forceTimeoutUtility(null);
    }

    private static function hostHasTimeoutUtility(): bool
    {
        exec('command -v timeout 2>/dev/null', $unused, $code);

        return $code === 0;
    }

    /** Seconds the callable took, measured on a monotonic clock. */
    private static function secondsTaken(callable $work): float
    {
        $started = hrtime(true);
        $work();

        return (hrtime(true) - $started) / 1e9;
    }

    public function testIsAvailableReturnsTrueWhenAShellFunctionIsUsable(): void
    {
        $this->assertTrue(ShellExecutor::isAvailable());
    }

    public function testRunViaExecCapturesOutputAndReturnCode(): void
    {
        $result = ShellExecutor::run('echo hello');

        $this->assertSame(0, $result['returnCode']);
        $this->assertSame('hello', trim($result['output']));
    }

    public function testRunCapturesANonZeroReturnCode(): void
    {
        $result = ShellExecutor::run('exit 3');

        $this->assertSame(3, $result['returnCode']);
    }

    /**
     * disable_functions is PHP_INI_SYSTEM (fixed at process startup), so
     * the actual multi-function fallback (exec disabled but system()
     * still usable, as observed on at least one real LWS-hosted account)
     * can't be exercised end-to-end here — this locks in the pure
     * output-parsing logic each backend depends on instead, via
     * reflection on the private per-function methods.
     */
    public function testShellExecBackendParsesTrailingReturnCodeMarker(): void
    {
        $reflection = new \ReflectionClass(ShellExecutor::class);
        $method = $reflection->getMethod('runViaShellExec');
        $method->setAccessible(true);

        // A subshell, not a bare `exit 5` — the latter would terminate the
        // whole shell_exec() invocation before the appended marker-echo
        // ever ran.
        $result = $method->invoke(null, 'echo hello; (exit 5)');

        $this->assertSame(5, $result['returnCode']);
        $this->assertSame('hello', trim($result['output']));
    }

    public function testSystemBackendBuffersOutputInsteadOfLeakingItToTheResponse(): void
    {
        $reflection = new \ReflectionClass(ShellExecutor::class);
        $method = $reflection->getMethod('runViaSystem');
        $method->setAccessible(true);

        ob_start();
        $result = $method->invoke(null, 'echo hello');
        $leaked = ob_get_clean();

        $this->assertSame('', $leaked);
        $this->assertSame(0, $result['returnCode']);
        $this->assertSame('hello', trim($result['output']));
    }

    public function testACommandThatOutlastsItsTimeoutIsInterrupted(): void
    {
        if (!self::hostHasTimeoutUtility()) {
            $this->markTestSkipped('This host has no timeout utility, which is the case the bound cannot cover.');
        }

        $result = ['returnCode' => 0, 'output' => ''];
        $seconds = self::secondsTaken(function () use (&$result): void {
            $result = ShellExecutor::run('sleep 5', 1);
        });

        $this->assertLessThan(3.0, $seconds, 'A command given one second must not run for five.');
        $this->assertSame(ShellExecutor::TIMED_OUT_RETURN_CODE, $result['returnCode']);
    }

    /**
     * A command that ignores the polite stop is killed once the grace period
     * is over, and `timeout -k` then answers 137. That is still "it reached
     * its limit", and is reported as such.
     */
    public function testACommandThatIgnoresTheStopIsKilledAndReportedAsInterrupted(): void
    {
        if (!self::hostHasTimeoutUtility()) {
            $this->markTestSkipped('This host has no timeout utility, which is the case the bound cannot cover.');
        }

        $result = ['returnCode' => 0, 'output' => ''];
        $seconds = self::secondsTaken(function () use (&$result): void {
            $result = ShellExecutor::run('trap "" TERM; sleep 30', 1);
        });

        $this->assertGreaterThanOrEqual(1.0, $seconds, 'It was given its second before anything was done to it.');
        $this->assertLessThan(8.0, $seconds, 'The kill grace is two seconds, not the thirty the command asked for.');
        $this->assertSame(ShellExecutor::TIMED_OUT_RETURN_CODE, $result['returnCode']);
    }

    /**
     * 137 alone proves nothing: anything may kill the command. Well inside
     * its limit it keeps its own code, so a crash is not read as a timeout.
     */
    public function testACommandKilledEarlyKeepsItsOwnCodeRatherThanLookingLikeATimeout(): void
    {
        if (!self::hostHasTimeoutUtility()) {
            $this->markTestSkipped('This host has no timeout utility, which is the case the bound cannot cover.');
        }

        $result = ShellExecutor::run('kill -9 $$', 30);

        $this->assertSame(137, $result['returnCode']);
        $this->assertNotSame(ShellExecutor::TIMED_OUT_RETURN_CODE, $result['returnCode']);
    }

    public function testAFastCommandKeepsItsOutputAndItsCodeUnderATimeout(): void
    {
        $result = ShellExecutor::run("echo 'two words' 2>&1; (exit 3)", 5);

        $this->assertSame(3, $result['returnCode']);
        $this->assertSame('two words', trim($result['output']));
    }

    public function testTheDefaultTimeoutLeavesAnOrdinaryCommandAlone(): void
    {
        $result = ShellExecutor::run('echo hello');

        $this->assertSame(0, $result['returnCode']);
        $this->assertSame('hello', trim($result['output']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commandsToBound')]
    public function testEveryExecutionFunctionIsBoundedNotJustTheFirstChoice(string $source, float $maximum, int $expectedCode): void
    {
        if (!self::hostHasTimeoutUtility()) {
            $this->markTestSkipped('This host has no timeout utility, which is the case the bound cannot cover.');
        }

        $reflection = new \ReflectionClass(ShellExecutor::class);
        $bounded = $reflection->getMethod('bounded');
        $command = $bounded->invoke(null, $source, 1);

        foreach (['runViaExec', 'runViaShellExec', 'runViaSystem', 'runViaPassthru'] as $backend) {
            $method = $reflection->getMethod($backend);
            $result = ['returnCode' => 0, 'output' => ''];
            $seconds = self::secondsTaken(function () use ($method, $command, &$result): void {
                $result = $method->invoke(null, $command);
            });

            $this->assertLessThan($maximum, $seconds, $backend . ' was not bounded.');
            $this->assertSame($expectedCode, $result['returnCode'], $backend);
        }
    }

    /** @return array<string, array{string, float, int}> */
    public static function commandsToBound(): array
    {
        return [
            'polite stop' => ['sleep 5', 3.0, ShellExecutor::TIMED_OUT_RETURN_CODE],
            // Raw backends return the utility's signal code. run() alone normalises it to 124.
            'forced stop also kills descendants' => ['trap "" TERM; sleep 30', 8.0, 137],
        ];
    }

    public function testAHostWithoutTheTimeoutUtilityKeepsTheUnboundedBehaviour(): void
    {
        ShellExecutor::forceTimeoutUtility(false);

        $result = ['returnCode' => -1, 'output' => ''];
        $seconds = self::secondsTaken(function () use (&$result): void {
            $result = ShellExecutor::run('sleep 2', 1);
        });

        $this->assertSame(0, $result['returnCode'], 'Without the utility the command runs to its end, as before.');
        $this->assertGreaterThanOrEqual(2.0, $seconds);
    }

    public function testAHostWithoutTheTimeoutUtilityStillReturnsOutputAndCode(): void
    {
        ShellExecutor::forceTimeoutUtility(false);

        $result = ShellExecutor::run('echo plain; (exit 4)', 5);

        $this->assertSame(4, $result['returnCode']);
        $this->assertSame('plain', trim($result['output']));
    }

    /**
     * A broken $PATH is the case ExecutableLocator's fallback exists for.
     * Neither `timeout` nor `sh` may then be looked up by name, or every
     * command would come back "not found" instead of running.
     */
    public function testACommandStillRunsWhenPathIsBrokenAfterTheBoundWasMeasured(): void
    {
        ShellExecutor::run('echo warm-up');
        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent-path-for-test');

        try {
            $result = ShellExecutor::run('echo still-runs');
        } finally {
            putenv('PATH=' . $originalPath);
        }

        $this->assertSame(0, $result['returnCode']);
        $this->assertSame('still-runs', trim($result['output']));
    }

    public function testTheBoundIsFoundEvenWhenPathIsBrokenFromTheStart(): void
    {
        if (!self::hostHasTimeoutUtility()) {
            $this->markTestSkipped('This host has no timeout utility, which is the case the bound cannot cover.');
        }

        exec('command -v sleep', $sleepLines);
        $sleep = $sleepLines[0] ?? '';
        $this->assertNotSame('', $sleep);

        ShellExecutor::forceTimeoutUtility(null);
        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent-path-for-test');

        try {
            $result = ['returnCode' => 0, 'output' => ''];
            $seconds = self::secondsTaken(function () use (&$result, $sleep): void {
                $result = ShellExecutor::run(escapeshellarg($sleep) . ' 5', 1);
            });
        } finally {
            putenv('PATH=' . $originalPath);
        }

        $this->assertLessThan(3.0, $seconds, 'The bound was not applied without a usable $PATH.');
        $this->assertSame(ShellExecutor::TIMED_OUT_RETURN_CODE, $result['returnCode']);
    }

    public function testATimeoutBelowOneSecondIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShellExecutor::run('echo never', 0);
    }

    public function testAProbeSaysItWasInterruptedRatherThanReportingNoOutput(): void
    {
        $verdict = new \ReflectionMethod(ShellExecutor::class, 'probeVerdict');

        $probe = $verdict->invoke(
            null,
            'exec',
            ['returnCode' => ShellExecutor::TIMED_OUT_RETURN_CODE, 'output' => ''],
            10
        );

        $this->assertFalse($probe['works']);
        $this->assertTrue($probe['declared']);
        $this->assertSame('exec', $probe['function']);
        $this->assertSame('commande interrompue après 10 s sans réponse', $probe['detail']);
    }

    public function testAProbeThatAnswersStillWorks(): void
    {
        $probe = ShellExecutor::probe();

        $this->assertTrue($probe['works']);
        $this->assertSame('code 0', $probe['detail']);
    }
}
