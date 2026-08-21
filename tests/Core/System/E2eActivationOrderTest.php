<?php

declare(strict_types=1);

namespace Tests\Core\System;

use PHPUnit\Framework\TestCase;

if (!defined('E2E_SUPPORT_TEST')) {
    define('E2E_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/e2e-support.php';

/**
 * scripts/e2e-support.php declares no namespace (it is a CLI script the
 * shell harness runs directly), so the function under test lives in the
 * global namespace — hence the leading backslash, same as
 * tests/Bootstrap/BootstrapTest.php.
 *
 * The order this computes decides whether `npm run e2e` can provision an
 * instance at all: Core\Module\ModuleManager::activate() refuses a module
 * whose hard dependencies are not already enabled, so getting it wrong
 * fails the whole end-to-end gate rather than one assertion. It is pure,
 * so it is tested here rather than through a browser run.
 */
class E2eActivationOrderTest extends TestCase
{
    public function testAModuleComesAfterTheOneItRequires(): void
    {
        $order = \e2e_module_activation_order([
            'groups' => ['gallery'],
            'gallery' => [],
        ]);

        $this->assertSame(['gallery', 'groups'], $order);
    }

    public function testIndependentModulesAreOrderedAlphabeticallySoARunIsReproducible(): void
    {
        $order = \e2e_module_activation_order([
            'trombinoscope' => [],
            'banner' => [],
            'news' => [],
        ]);

        $this->assertSame(['banner', 'news', 'trombinoscope'], $order);
    }

    public function testATransitiveChainIsOrderedFromItsRoot(): void
    {
        $order = \e2e_module_activation_order([
            'c' => ['b'],
            'b' => ['a'],
            'a' => [],
        ]);

        $this->assertSame(['a', 'b', 'c'], $order);
    }

    public function testTheRepositorysOwnModulesHaveAnInstallableOrder(): void
    {
        $requirements = [];
        foreach ((array) glob(dirname(__DIR__, 3) . '/modules/*/module.json') as $manifestPath) {
            $manifest = json_decode((string) file_get_contents((string) $manifestPath), true);
            $this->assertIsArray($manifest, "{$manifestPath} is not valid JSON");
            $requirements[$manifest['id']] = $manifest['requires'] ?? [];
        }

        $this->assertNotSame([], $requirements, 'the repository must ship at least one module');

        $order = \e2e_module_activation_order($requirements);

        $this->assertIsArray(
            $order,
            'every module this repository ships must be activatable in some order — '
            . 'otherwise `npm run e2e` cannot provision an instance at all'
        );
        $this->assertCount(count($requirements), $order);
    }

    public function testACycleHasNoOrderRatherThanAnArbitraryOne(): void
    {
        $this->assertNull(\e2e_module_activation_order([
            'a' => ['b'],
            'b' => ['a'],
        ]));
    }

    public function testAModuleRequiringOneThatIsNotOnDiskHasNoOrder(): void
    {
        $this->assertNull(\e2e_module_activation_order([
            'groups' => ['gallery'],
        ]));
    }

    public function testNoModulesIsAnEmptyOrder(): void
    {
        $this->assertSame([], \e2e_module_activation_order([]));
    }
}
