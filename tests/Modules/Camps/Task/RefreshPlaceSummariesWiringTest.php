<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Task;

use PHPUnit\Framework\TestCase;

/**
 * The camps/llm_connector boundary, pinned at the source level.
 *
 * `RefreshPlaceSummariesHandler` used to build its own
 * `LlmConnectorService` out of that module's repositories — which is a
 * hard coupling wearing an optional dependency's clothes (ARCHITECTURE.md
 * §7.5): the classes it named stop existing the moment `llm_connector` is
 * removed from an install, so "degrades gracefully when the other module
 * is absent" was not true of the one code path that ran unattended.
 *
 * The connector now arrives as a CAPABILITY —
 * `TaskContext::getOptional(LlmConnectorInterface::class)`, registered in
 * public/scheduler-bootstrap.php identically for both entry points — so
 * the handler is auto-resolved from the manifest like any other task and
 * the hand-registration (with the per-entry-point drift it invited,
 * §8.17/§8.20) is gone. What stays pinned: the handler still names none
 * of llm_connector's internals, really asks the capability, and no entry
 * point re-grows a private construction.
 */
class RefreshPlaceSummariesWiringTest extends TestCase
{
    private static function source(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/' . $relativePath);
        self::assertNotFalse($contents);

        return $contents;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return ['web' => ['public/index.php'], 'cron' => ['public/cron.php']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testNoEntryPointHandRegistersTheHandlerAnyMore(string $file): void
    {
        // A hand registration re-grown in one entry point would shadow the
        // capability-fed auto-resolution with whatever that one file wired
        // — the per-entry-point drift this iteration removed.
        $this->assertStringNotContainsString(
            'new \\Modules\\Camps\\Task\\RefreshPlaceSummariesHandler(',
            self::source($file),
            $file . ' must leave the camps summary handler to manifest auto-resolution.'
        );
    }

    public function testTheHandlerAsksTheCapabilityAtRunTime(): void
    {
        // The enablement decision belongs to the capability resolution
        // (re-read live), never to the handler naming another module's
        // internals to find out.
        $this->assertStringContainsString(
            '$this->llm ?? $context->getOptional(LlmConnectorInterface::class)',
            self::source('modules/camps/src/Task/RefreshPlaceSummariesHandler.php')
        );
    }

    public function testTheSharedBootstrapRegistersTheLlmCapability(): void
    {
        $bootstrap = self::source('public/scheduler-bootstrap.php');

        $this->assertStringContainsString('Modules\\LlmConnector\\Api\\LlmConnectorInterface::class', $bootstrap);
        $this->assertStringContainsString("'llm_connector',", $bootstrap);
    }

    /**
     * The three imports that made this a hard coupling. Their absence is
     * the guarantee — a handler that cannot name those classes cannot
     * fatal when they are gone.
     */
    public function testTheHandlerNamesNoneOfLlmConnectorsInternals(): void
    {
        $handler = self::source('modules/camps/src/Task/RefreshPlaceSummariesHandler.php');

        foreach ([
            'Modules\\LlmConnector\\Repository\\ProviderRepository',
            'Modules\\LlmConnector\\Repository\\ProviderModelRepository',
            'Modules\\LlmConnector\\Service\\LlmConnectorService',
        ] as $internal) {
            $this->assertStringNotContainsString(
                $internal,
                $handler,
                'The handler must reach llm_connector only through its Api namespace.'
            );
        }

        $this->assertStringContainsString('Modules\\LlmConnector\\Api\\LlmConnectorInterface', $handler);
    }

    /**
     * Same rule for the module as a whole: `Api` is the published surface
     * and the only one camps may name.
     */
    public function testNoCampsFileReachesIntoLlmConnectorsInternals(): void
    {
        $offenders = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 4) . '/modules/camps/src')
        );

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/Modules\\\\LlmConnector\\\\(?!Api\\\\)/', $contents) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }
}
