<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\SafeRedirect;
use PHPUnit\Framework\TestCase;

class SafeRedirectTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function safeInternalPaths(): array
    {
        return [
            'root' => ['/'],
            'simple path' => ['/trombinoscope'],
            'nested path' => ['/config/functions'],
            'path with query' => ['/upload?context=member&key=42'],
            'path with fragment' => ['/page#section'],
        ];
    }

    /**
     * @dataProvider safeInternalPaths
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('safeInternalPaths')]
    public function testInternalPathPassesGenuineLocalPathsThrough(string $path): void
    {
        $this->assertSame($path, SafeRedirect::internalPath($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeTargets(): array
    {
        return [
            'absolute http url' => ['http://evil.example/x'],
            'absolute https url' => ['https://evil.example/x'],
            'scheme-relative' => ['//evil.example/x'],
            'backslash scheme-relative' => ['/\\evil.example/x'],
            'double backslash' => ['/\\\\evil.example'],
            'javascript scheme' => ['javascript:alert(1)'],
            'empty' => [''],
            'relative without leading slash' => ['evil.example'],
            'protocol-relative with creds' => ['//user:pass@evil.example'],
            'CRLF header smuggling' => ["/ok\r\nSet-Cookie: x=1"],
            'tab' => ["/ok\tstuff"],
        ];
    }

    /**
     * @dataProvider unsafeTargets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeTargets')]
    public function testInternalPathRejectsAnythingThatCouldLeaveTheSite(string $target): void
    {
        $this->assertSame('/', SafeRedirect::internalPath($target));
    }

    public function testInternalPathHonoursACustomFallback(): void
    {
        $this->assertSame('/login', SafeRedirect::internalPath('https://evil.example', '/login'));
    }

    public function testInternalPathFromUrlKeepsTheSameSiteRefererPath(): void
    {
        $this->assertSame(
            '/config/functions',
            SafeRedirect::internalPathFromUrl('https://myscoutunit.example/config/functions')
        );
        $this->assertSame(
            '/config/functions?tab=roles',
            SafeRedirect::internalPathFromUrl('https://myscoutunit.example/config/functions?tab=roles')
        );
    }

    public function testInternalPathFromUrlDiscardsAForeignHostKeepingOnlyThePath(): void
    {
        // Even a cross-origin referer can at most redirect within our own site
        // — its host is dropped — never off it.
        $this->assertSame('/x', SafeRedirect::internalPathFromUrl('https://evil.example/x'));
    }

    public function testInternalPathFromUrlFallsBackWhenThereIsNoPath(): void
    {
        $this->assertSame('/', SafeRedirect::internalPathFromUrl('https://evil.example'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loginReturnRefusals(): array
    {
        return [
            'everything internalPath refuses' => ['https://evil.example/x'],
            'scheme-relative' => ['//evil.example'],
            'double slash further in' => ['/r//evil.example'],
            'backslash further in' => ['/r/\\evil.example'],
            'colon before the query' => ['/javascript:alert(1)'],
            'control character' => ["/r/abc\n"],
        ];
    }

    /**
     * Issue #833: the address a login returns to arrives in a link anyone
     * can send, so it is held to a stricter rule than internalPath().
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('loginReturnRefusals')]
    public function testLoginReturnPathRefusesMoreThanInternalPath(string $target): void
    {
        $this->assertSame('/', SafeRedirect::loginReturnPath($target));
    }

    public function testLoginReturnPathKeepsAnOrdinaryPathAndItsQuery(): void
    {
        $this->assertSame('/r/abc123', SafeRedirect::loginReturnPath('/r/abc123'));
        $this->assertSame('/retro?tab=open', SafeRedirect::loginReturnPath('/retro?tab=open'));
        // A colon is only refused before the query: a query value may hold one.
        $this->assertSame('/agenda?at=10:30', SafeRedirect::loginReturnPath('/agenda?at=10:30'));
    }
}
