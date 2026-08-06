<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\System\ShellExecutor;
use PHPUnit\Framework\TestCase;

class ShellExecutorTest extends TestCase
{
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
}
