<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Module\ModuleManager;
use Core\Scheduler\TaskCapabilities;
use PHPUnit\Framework\TestCase;

interface FakeCapabilityInterface
{
}

final class FakeCapability implements FakeCapabilityInterface
{
}

class TaskCapabilitiesTest extends TestCase
{
    /**
     * @param string[] $enabledModuleIds
     */
    private function capabilities(array $enabledModuleIds): TaskCapabilities
    {
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn($enabledModuleIds);

        return new TaskCapabilities($moduleManager);
    }

    public function testResolvesARegisteredCapabilityWhenItsModuleIsEnabled(): void
    {
        $capabilities = $this->capabilities(['some_module']);
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static fn (): object => new FakeCapability());

        $this->assertInstanceOf(FakeCapability::class, $capabilities->resolve(FakeCapabilityInterface::class));
    }

    public function testResolvesToNullWhenTheProvidingModuleIsDisabled(): void
    {
        $capabilities = $this->capabilities([]);
        $built = false;
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static function () use (&$built): object {
            $built = true;

            return new FakeCapability();
        });

        $this->assertNull($capabilities->resolve(FakeCapabilityInterface::class));
        $this->assertFalse($built, 'The factory must never run for a disabled module.');
    }

    public function testResolvesToNullForAnInterfaceNobodyRegistered(): void
    {
        $this->assertNull($this->capabilities(['some_module'])->resolve(FakeCapabilityInterface::class));
    }

    public function testTheInstanceIsBuiltOnceAndReused(): void
    {
        $capabilities = $this->capabilities(['some_module']);
        $builds = 0;
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static function () use (&$builds): object {
            $builds++;

            return new FakeCapability();
        });

        $first = $capabilities->resolve(FakeCapabilityInterface::class);
        $second = $capabilities->resolve(FakeCapabilityInterface::class);

        $this->assertSame($first, $second);
        $this->assertSame(1, $builds);
    }

    public function testEnablementIsReReadOnEveryResolve(): void
    {
        // The enabled set can change mid-request on the Modules page, so a
        // snapshot taken at registration time would answer for a state
        // that no longer holds.
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturnOnConsecutiveCalls(['some_module'], []);
        $capabilities = new TaskCapabilities($moduleManager);
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static fn (): object => new FakeCapability());

        $this->assertNotNull($capabilities->resolve(FakeCapabilityInterface::class));
        $this->assertNull($capabilities->resolve(FakeCapabilityInterface::class));
    }

    public function testADuplicateRegistrationIsRefusedLoudly(): void
    {
        $capabilities = $this->capabilities(['some_module']);
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static fn (): object => new FakeCapability());

        $this->expectException(\LogicException::class);
        $capabilities->register(FakeCapabilityInterface::class, 'other_module', static fn (): object => new FakeCapability());
    }

    public function testAFactoryReturningTheWrongTypeIsRefusedLoudly(): void
    {
        $capabilities = $this->capabilities(['some_module']);
        $capabilities->register(FakeCapabilityInterface::class, 'some_module', static fn (): object => new \stdClass());

        $this->expectException(\LogicException::class);
        $capabilities->resolve(FakeCapabilityInterface::class);
    }

    public function testIsModuleEnabledAsksTheModuleManager(): void
    {
        $capabilities = $this->capabilities(['some_module']);

        $this->assertTrue($capabilities->isModuleEnabled('some_module'));
        $this->assertFalse($capabilities->isModuleEnabled('other_module'));
    }
}
