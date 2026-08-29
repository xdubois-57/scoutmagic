<?php

declare(strict_types=1);

namespace Tests\Core\Module;

use Core\Module\HomeBannerProvider;
use Core\Module\HomeNewsProvider;
use Core\Module\HookRegistry;
use PHPUnit\Framework\TestCase;

class HookRegistryTest extends TestCase
{
    private function bannerProvider(): HomeBannerProvider
    {
        return new class implements HomeBannerProvider {
            public function getRandomBannerHtml(string $viewerRole): ?string
            {
                return '<p>bannière</p>';
            }
        };
    }

    public function testAnUnregisteredHookResolvesToNull(): void
    {
        $this->assertNull((new HookRegistry())->getOptional(HomeBannerProvider::class));
    }

    public function testARegisteredHookResolvesToItsImplementation(): void
    {
        $registry = new HookRegistry();
        $provider = $this->bannerProvider();
        $registry->register(HomeBannerProvider::class, $provider);

        $this->assertSame($provider, $registry->getOptional(HomeBannerProvider::class));
        // Other hooks stay unaffected — keys are the interface names.
        $this->assertNull($registry->getOptional(HomeNewsProvider::class));
    }

    public function testAHookRegisteredAfterAConsumerTookTheRegistryStillResolves(): void
    {
        // The property that removed the null-then-re-register dance: a
        // controller constructed early holds the registry, not a snapshot.
        $registry = new HookRegistry();
        $heldByAController = $registry;

        $registry->register(HomeBannerProvider::class, $this->bannerProvider());

        $this->assertNotNull($heldByAController->getOptional(HomeBannerProvider::class));
    }

    public function testAnImplementationOfTheWrongInterfaceIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new HookRegistry())->register(HomeNewsProvider::class, $this->bannerProvider());
    }

    public function testANonInterfaceKeyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        (new HookRegistry())->register(\stdClass::class, new \stdClass());
    }

    public function testASecondImplementationForTheSameHookIsRefusedRatherThanSilentlyShadowed(): void
    {
        $registry = new HookRegistry();
        $registry->register(HomeBannerProvider::class, $this->bannerProvider());

        $this->expectException(\LogicException::class);
        $registry->register(HomeBannerProvider::class, $this->bannerProvider());
    }
}
