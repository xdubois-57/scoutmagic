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
 * The connector is now injected, which means only a composition root can
 * supply it — and a handler registered in one entry point only fails
 * unconditionally under the other with "No handler registered"
 * (§8.17/§8.20). That is the bug this codebase has already shipped once
 * (`create_backup`), so both call sites are pinned here, exactly as
 * `Tests\Modules\InboundMail\CompositionRootWiringTest` pins the polling
 * task's.
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
    public function testTheHandlerIsRegisteredByHandInBothEntryPoints(string $file): void
    {
        $this->assertStringContainsString(
            'new \\Modules\\Camps\\Task\\RefreshPlaceSummariesHandler(',
            self::source($file),
            $file . ' must construct the camps summary handler itself, not leave it to manifest auto-resolution.'
        );
        $this->assertStringContainsString(
            'Modules\\Camps\\Task\\RefreshPlaceSummariesHandler::TASK_KEY',
            self::source($file),
            $file . ' must register the camps summary handler.'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsGuardTheConnectorOnTheModuleBeingEnabled(string $file): void
    {
        // Either the shared `$llmConnectorForRgpd` handle (web, which is
        // already null when the module is off) or an explicit
        // getEnabledModuleIds() check (cron). What must never appear is an
        // unconditional construction.
        $this->assertMatchesRegularExpression(
            '/\\$llmConnectorForRgpd|in_array\\(\'llm_connector\', \\$moduleManager->getEnabledModuleIds\\(\\), true\\)/',
            self::source($file),
            $file . ' must only reach llm_connector when that module is enabled.'
        );
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
