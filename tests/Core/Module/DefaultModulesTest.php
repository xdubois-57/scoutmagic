<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Which modules a new installation starts with (issue #718 § B).
 *
 * The decision lives in each module.json (`enabled_by_default`), so it is
 * spread over twenty-seven files and nothing else says what the whole of
 * it is. This test is that whole: adding a seventh default module, or
 * dropping one, is a decision to take on purpose and to write here.
 *
 * It also covers existing sites, deliberately: ModuleManager activates a
 * default module wherever it has no registry row yet, which is every site
 * that never activated or deactivated it — the maintainer chose that
 * those get the three new defaults at their next update too.
 */
class DefaultModulesTest extends TestCase
{
    private const DEFAULT_MODULES = [
        'calendar',
        'fees',
        'member_stats',
        'news',
        'official_documents',
        'trombinoscope',
    ];

    public function testANewInstallationStartsWithExactlyTheSixDefaultModules(): void
    {
        $defaults = array_keys(array_filter(
            $this->manifests(),
            static fn (ModuleManifest $manifest): bool => $manifest->enabledByDefault
        ));
        sort($defaults);

        $this->assertSame(self::DEFAULT_MODULES, $defaults);
    }

    /**
     * ModuleManager only auto-activates a default module whose requirements
     * are met, so a default that required a module left off would quietly
     * stay off on a new installation.
     */
    public function testEveryDefaultModuleOnlyRequiresOtherDefaultModules(): void
    {
        $manifests = $this->manifests();

        foreach (self::DEFAULT_MODULES as $id) {
            foreach ($manifests[$id]->requires as $required) {
                $this->assertContains(
                    $required,
                    self::DEFAULT_MODULES,
                    "« {$manifests[$id]->name} » is on by default but requires « {$required} », which is not"
                );
            }
        }
    }

    /**
     * @return array<string, ModuleManifest> keyed by module id
     */
    private function manifests(): array
    {
        $manifests = [];
        foreach (glob(dirname(__DIR__, 3) . '/modules/*/module.json') ?: [] as $path) {
            $manifest = ModuleManifest::fromFile($path);
            $manifests[$manifest->id] = $manifest;
        }
        $this->assertNotEmpty($manifests);

        return $manifests;
    }
}
