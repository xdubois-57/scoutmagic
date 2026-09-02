<?php

declare(strict_types=1);

namespace Tests\Modules\UsageStats;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest and the composition root, asserted at the source level —
 * same technique and same reason as
 * Tests\Modules\SupportDashboard\ModuleSchedulingTest: booting index.php
 * in-process would pull in the full service graph and a live database, and
 * every property worth pinning here is textual.
 *
 * Two of them matter more than the rest:
 *
 * - a declared scheduled task nobody SEEDS never runs at all, which is how
 *   two of support_dashboard's three purges had never fired on any
 *   receiver;
 * - the counting call has to sit AFTER `$response->send()`, or this
 *   feature silently reintroduces exactly the per-request background cost
 *   the poor man's cron was removed to get rid of.
 */
class ModuleWiringTest extends TestCase
{
    private const MODULE_ID = 'usage_stats';

    private string $index;

    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);

        $this->index = (string) file_get_contents($root . '/public/index.php');

        $manifest = json_decode(
            (string) file_get_contents($root . '/modules/' . self::MODULE_ID . '/module.json'),
            true
        );
        $this->assertIsArray($manifest);
        $this->manifest = $manifest;
    }

    public function testTheManifestLoads(): void
    {
        $manifest = ModuleManifest::fromArray($this->manifest);

        $this->assertSame(self::MODULE_ID, $manifest->id);
        $this->assertSame('Fréquentation du site', $manifest->name);
    }

    /**
     * No cookie, ever — and it is the whole reason this feature needs no
     * consent banner entry, no analytics category and no opt-out to
     * respect. A cookie appearing here later is a decision, not a detail.
     */
    public function testTheModuleDeclaresNoCookieAtAll(): void
    {
        $this->assertSame([], $this->manifest['cookies']);
    }

    public function testEveryDeclaredTaskHasItsFirstOccurrenceSeededInTheCompositionRoot(): void
    {
        $block = $this->moduleBlock();

        /** @var array<int, array{key: string, handler: string}> $tasks */
        $tasks = $this->manifest['scheduled_tasks'];
        $this->assertNotSame([], $tasks);

        foreach ($tasks as $task) {
            $shortName = substr((string) strrchr($task['handler'], '\\'), 1);

            $this->assertStringContainsString(
                $shortName . '::TASK_KEY',
                $block,
                sprintf(
                    'public/index.php never seeds a first occurrence of %s/%s, so the task can never run.',
                    self::MODULE_ID,
                    $task['key']
                )
            );
            $this->assertStringContainsString($shortName . '::REFERENCE', $block);
        }

        // rearm(), not schedule(): every page load runs this block, and an
        // unguarded seed would queue another copy of the chain each time.
        $this->assertStringContainsString('$schedulerService->rearm(', $block);
    }

    /**
     * The performance rule of IT-01, pinned where it can actually be
     * broken. `public/index.php` sends the response, closes the session,
     * and only then counts — if the call ever moves above `send()`, a
     * visitor starts paying for a database write before their page
     * arrives.
     */
    public function testTheCountingHappensAfterTheResponseHasBeenSent(): void
    {
        $send = strpos($this->index, '$response->send();');
        $this->assertIsInt($send, 'public/index.php no longer sends the response the way this test expects.');

        $record = strpos($this->index, '$usageStatsRecorder->record(');
        $this->assertIsInt($record, 'public/index.php never records a page view.');

        $this->assertGreaterThan(
            $send,
            $record,
            'The page-view counter must run AFTER the response is on the wire, never before it.'
        );
    }

    /**
     * What is counted is the route's declared PATTERN, taken from the
     * resolved route — never `$request->getPath()`, which carries the
     * identifier the pattern exists to keep out of the table.
     */
    public function testTheCountedValueIsTheRoutePatternAndNotTheRequestedPath(): void
    {
        $call = $this->recordCall();

        $this->assertStringContainsString('$usageStatsRoute?->path', $call);
        $this->assertStringNotContainsString('$request->getPath()', $call);
    }

    /**
     * The user agent is read to drop crawlers and never stored: the whole
     * difference with the `STATS_USER_AGENT` table this replaces. Nothing
     * identifying a person may be passed either — no account id, no member
     * id, no address.
     */
    public function testNoIdentifierIsPassedToTheRecorder(): void
    {
        $call = $this->recordCall();

        foreach (['getUserAccountId', 'getEmail', 'REMOTE_ADDR', 'memberId', 'member_id'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $call,
                'The page-view counter must never be handed anything identifying a person.'
            );
        }
    }

    /**
     * The report's per-module aggregate reaches BOTH paths, or it reaches
     * neither honestly: the daily send builds its payload from a
     * TaskContext (`Core\Statistics\StatisticsServiceFactory`) and the web
     * path from the composition root. Two wirings is two places for them
     * to drift, and the drift would be silent — a report that simply
     * stopped carrying the field on one of the two triggers.
     */
    public function testTheModuleUsageCapabilityIsWiredOnBothPaths(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertStringContainsString(
            '$usageStatsModuleUsageForOthers',
            $this->index,
            'public/index.php never hands the per-module aggregate to the payload builder.'
        );

        $bootstrap = (string) file_get_contents($root . '/public/scheduler-bootstrap.php');
        $this->assertStringContainsString(
            'Modules\\UsageStats\\Api\\ModuleUsageInterface::class',
            $bootstrap,
            'The scheduled path cannot resolve the aggregate, so the daily report would drop it.'
        );

        $factory = (string) file_get_contents($root . '/core/Statistics/StatisticsServiceFactory.php');
        $this->assertStringContainsString('ModuleUsageInterface::class', $factory);
    }

    /**
     * The block sits among the trunk's wiring rather than with the other
     * modules, and the reason is load-bearing: a CORE consumer built a few
     * lines below takes its published capability. Moved back down with its
     * siblings, the report silently stops carrying the aggregate.
     */
    public function testTheBlockStillRunsBeforeTheStatisticsPayloadBuilderIsBuilt(): void
    {
        $block = strpos($this->index, "\$isEnabled('" . self::MODULE_ID . "')");
        $builder = strpos($this->index, 'new \\Core\\Statistics\\StatisticsPayloadBuilder(');

        $this->assertIsInt($block);
        $this->assertIsInt($builder);
        $this->assertLessThan($builder, $block);
    }

    private function recordCall(): string
    {
        $start = strpos($this->index, '$usageStatsRecorder->record(');
        $this->assertIsInt($start, 'public/index.php never records a page view.');

        $end = strpos($this->index, ');', $start);
        $this->assertIsInt($end);

        return substr($this->index, $start, $end - $start);
    }

    private function moduleBlock(): string
    {
        $start = strpos($this->index, "\$isEnabled('" . self::MODULE_ID . "')");
        $this->assertIsInt($start, 'public/index.php no longer wires ' . self::MODULE_ID . ' at all.');

        // The block ends where the statistics payload builder begins —
        // the CORE consumer it exists this early for. Isolating it keeps a
        // TASK_KEY mentioned elsewhere in a 6000-line composition root from
        // satisfying the assertions above.
        $end = strpos($this->index, 'new \\Core\\Statistics\\StatisticsPayloadBuilder(', $start);
        $this->assertIsInt($end, 'The ' . self::MODULE_ID . ' block no longer precedes the statistics payload builder.');

        return substr($this->index, $start, $end - $start);
    }
}
