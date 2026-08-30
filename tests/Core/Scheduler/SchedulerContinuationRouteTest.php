<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Scheduler\SchedulerContinuationRoute;
use PHPUnit\Framework\TestCase;

/**
 * The continuation path exists twice on purpose: as a literal in
 * public/index.php, because tests/Security/AuthorizationMatrixInventoryTest
 * parses that file for route literals and refuses to audit a list it could
 * not fully parse; and as a constant, because SchedulerContinuation builds
 * the hop URL from it.
 *
 * Two copies that disagree would fail in the worst possible way — the hop
 * would reach a 404, and nothing reads a hop's response, so the queue would
 * simply stop draining with no error anywhere. This pins them together.
 */
class SchedulerContinuationRouteTest extends TestCase
{
    public function testTheRegisteredRouteMatchesTheConstantTheHopUrlIsBuiltFrom(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');

        $this->assertMatchesRegularExpression(
            '/->addRoute\(\s*\'POST\'\s*,\s*\'' . preg_quote(SchedulerContinuationRoute::PATH, '/') . '\'/',
            $index,
            'public/index.php no longer registers the path SchedulerContinuation hops to'
        );
    }

    public function testTheRouteIsRegisteredAsPublicBecauseTheCallerHasNoSession(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');

        $this->assertMatchesRegularExpression(
            '/->addRoute\(\s*\'POST\'\s*,\s*\'' . preg_quote(SchedulerContinuationRoute::PATH, '/') . '\'[^;]*\'public\'\s*\)/',
            $index
        );
    }
}
