<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Maintenance\Task\InstallUpdateHandler;
use PHPUnit\Framework\TestCase;

/**
 * The allowlisted redirect walk, exercised without a network.
 *
 * fetchFollowingAllowlistedRedirects() is the security-sensitive core of
 * both the artifact download and the "has CI published it yet?" probe:
 * every hop — the first URL and every Location a response points at — is
 * re-checked against the GitHub host allowlist, because a signed webhook
 * or a compromised release entry pointing the updater at an arbitrary
 * host is a full remote-code path (the archive is unpacked over the live
 * PHP tree). These tests drive the walk through a scripted
 * performHttpRequest() — the one seam that actually touches the network —
 * so the checks themselves are pinned, not the transport.
 */
class InstallUpdateHandlerHttpWalkTest extends TestCase
{
    /**
     * @param array<int, array{0: string|false, 1: array<int, string>}> $responses
     *        consumed in order, one per hop actually fetched
     */
    private function handlerAnswering(array $responses): object
    {
        return new class ($responses) extends InstallUpdateHandler {
            /** @var array<int, array{0: string, 1: string}> [url, method] per hop */
            public array $requests = [];

            /** @param array<int, array{0: string|false, 1: array<int, string>}> $responses */
            public function __construct(private array $responses)
            {
            }

            protected function performHttpRequest(string $url, string $method): array
            {
                $this->requests[] = [$url, $method];
                $response = array_shift($this->responses);
                if ($response === null) {
                    self::fail('the walk fetched more hops than the test scripted');
                }

                return $response;
            }

            public function probe(string $url): ?int
            {
                return $this->probeArtifactStatus($url);
            }

            /** @return array{0: bool, 1: int|null, 2: string} */
            public function download(string $url, string $destPath): array
            {
                return $this->attemptDownload($url, $destPath);
            }
        };
    }

    public function testAnOffGithubUrlIsRefusedBeforeAnyRequestIsSent(): void
    {
        $handler = $this->handlerAnswering([]);

        $this->assertNull($handler->probe('https://attacker.example/scoutmagic-dev-abc1234.zip'));
        $this->assertSame([], $handler->requests, 'nothing may be fetched from a host off the allowlist');
    }

    public function testPlainHttpIsRefusedEvenOnAGithubHost(): void
    {
        $handler = $this->handlerAnswering([]);

        $this->assertNull($handler->probe('http://github.com/x/y/releases/download/dev-build/a.zip'));
        $this->assertSame([], $handler->requests);
    }

    public function testALegitimateRedirectChainIsFollowedToItsFinalStatus(): void
    {
        // The real shape of a release-asset download: github.com answers
        // 302 to objects.githubusercontent.com, which serves the file.
        $handler = $this->handlerAnswering([
            ['', ['HTTP/1.1 302 Found', 'Location: https://objects.githubusercontent.com/asset/123']],
            ['', ['HTTP/1.1 200 OK']],
        ]);

        $this->assertSame(200, $handler->probe('https://github.com/o/r/releases/download/dev-build/scoutmagic-dev-abc1234.zip'));
        $this->assertCount(2, $handler->requests);
        $this->assertSame('https://objects.githubusercontent.com/asset/123', $handler->requests[1][0]);
    }

    public function testTheProbeUsesHeadNotGet(): void
    {
        $handler = $this->handlerAnswering([
            ['', ['HTTP/1.1 404 Not Found']],
        ]);

        $this->assertSame(404, $handler->probe('https://github.com/o/r/releases/download/dev-build/scoutmagic-dev-abc1234.zip'));
        $this->assertSame('HEAD', $handler->requests[0][1]);
    }

    public function testARedirectOffTheAllowlistAbortsTheWalk(): void
    {
        // The first hop is a real GitHub host; the redirect is not. The
        // second request must never be sent — following it blindly is the
        // SSRF/poisoned-artifact vector the per-hop check exists to close.
        $handler = $this->handlerAnswering([
            ['', ['HTTP/1.1 302 Found', 'Location: https://attacker.example/evil.zip']],
        ]);

        $this->assertNull($handler->probe('https://api.github.com/repos/o/r/zipball/abc'));
        $this->assertCount(1, $handler->requests, 'the off-allowlist redirect must not be fetched');
    }

    public function testARedirectWithoutALocationHeaderStopsWithThatStatus(): void
    {
        $handler = $this->handlerAnswering([
            ['', ['HTTP/1.1 302 Found']],
        ]);

        [$ok, $status, $reason] = $this->handlerAnswering([
            ['', ['HTTP/1.1 302 Found']],
        ])->download('https://github.com/o/r/releases/download/dev-build/a.zip', $this->tempPath());

        $this->assertFalse($ok);
        $this->assertSame(302, $status);
        $this->assertNotSame('', $reason);
    }

    public function testAnEndlessRedirectLoopHitsTheHopCeiling(): void
    {
        $bounce = ['', ['HTTP/1.1 302 Found', 'Location: https://codeload.github.com/again']];
        // One more scripted answer than the ceiling allows, so the test
        // fails loudly if the ceiling ever stops being enforced.
        $handler = $this->handlerAnswering(array_fill(0, 10, $bounce));

        $this->assertNull($handler->probe('https://api.github.com/repos/o/r/zipball/abc'));
        $this->assertLessThanOrEqual(6, count($handler->requests), 'the walk must stop at its hop ceiling');
    }

    public function testCaseInsensitiveLastLocationHeaderWins(): void
    {
        $handler = $this->handlerAnswering([
            ['', [
                'HTTP/1.1 301 Moved Permanently',
                'location: https://attacker.example/first',
                'LOCATION: https://objects.githubusercontent.com/real',
            ]],
            ['', ['HTTP/1.1 200 OK']],
        ]);

        $this->assertSame(200, $handler->probe('https://github.com/o/r/releases/download/dev-build/a.zip'));
        $this->assertSame('https://objects.githubusercontent.com/real', $handler->requests[1][0]);
    }

    public function testAConnectionFailureAnswersNullRatherThanAStatus(): void
    {
        $handler = $this->handlerAnswering([
            [false, []],
        ]);

        $this->assertNull($handler->probe('https://github.com/o/r/releases/download/dev-build/a.zip'));
    }

    public function testASuccessfulDownloadWritesTheArtifactToDisk(): void
    {
        $dest = $this->tempPath();
        $handler = $this->handlerAnswering([
            ['zip-bytes', ['HTTP/1.1 200 OK']],
        ]);

        [$ok, $status, $reason] = $handler->download('https://github.com/o/r/releases/download/dev-build/a.zip', $dest);

        $this->assertTrue($ok);
        $this->assertSame(200, $status);
        $this->assertSame('', $reason);
        $this->assertSame('zip-bytes', file_get_contents($dest));
        @unlink($dest);
    }

    public function testAnHttpErrorStatusFailsTheDownloadWithoutWritingAFile(): void
    {
        $dest = $this->tempPath();
        $handler = $this->handlerAnswering([
            ['Not Found', ['HTTP/1.1 404 Not Found']],
        ]);

        [$ok, $status, $reason] = $handler->download('https://github.com/o/r/releases/download/dev-build/a.zip', $dest);

        $this->assertFalse($ok);
        $this->assertSame(404, $status);
        $this->assertSame('HTTP 404', $reason);
        $this->assertFileDoesNotExist($dest);
    }

    public function testAnEmptyBodyIsARefusedDownloadNotAnEmptyArtifact(): void
    {
        // A zero-byte "artifact" unpacked over the live tree is worse than
        // a failed download; the walk treats it as a write failure.
        $dest = $this->tempPath();
        $handler = $this->handlerAnswering([
            ['', ['HTTP/1.1 200 OK']],
        ]);

        [$ok] = $handler->download('https://github.com/o/r/releases/download/dev-build/a.zip', $dest);

        $this->assertFalse($ok);
        $this->assertFileDoesNotExist($dest);
    }

    private function tempPath(): string
    {
        return sys_get_temp_dir() . '/install-walk-test-' . uniqid() . '.zip';
    }
}
