<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Service\ScalewayVisionVerifier;
use PHPUnit\Framework\TestCase;

class ScalewayVisionVerifierTest extends TestCase
{
    private function catalog(): ProviderModelCatalog
    {
        $ids = ['gemma-3-27b-it', 'mistral-small-3.1-24b-instruct-2503', 'mistral-small-3.2-24b-instruct-2506',
            'pixtral-12b-2409', 'mistral-small-24b-instruct-2501'];
        return new ProviderModelCatalog(array_map(static fn (string $id): array => [
            'id' => $id, 'display_name' => $id, 'chat' => true, 'vision' => null,
        ], $ids));
    }

    private function driver(): ScalewayProvider
    {
        return $this->getMockBuilder(ScalewayProvider::class)
            ->setConstructorArgs(['https://provider.example', 'test-key'])->onlyMethods(['probeVision'])->getMock();
    }

    public function testThreeFailedCandidatesAreTheLimitAndRepeatedRefreshesReuseTheirEvidence(): void
    {
        $driver = $this->driver();
        $driver->expects($this->exactly(3))->method('probeVision')->willReturn(ModelProbeStatus::UNVERIFIED);
        $stored = [];
        $models = $this->createStub(ProviderModelRepository::class);
        $models->method('modelProbeCache')->willReturnCallback(static function () use (&$stored): array {
            return $stored;
        });
        $verifier = new ScalewayVisionVerifier($models);
        $first = $verifier->verify(1, $driver, $this->catalog());
        $stored = $first['cache'];
        $second = $verifier->verify(1, $driver, $this->catalog());
        self::assertSame([3, [], 0, []], [
            $first['attempts'], $first['catalog']->visionModelIds(), $second['attempts'], $second['catalog']->visionModelIds(),
        ]);
    }

    public function testTheFirstVerifiedImageStopsFurtherPaidCallsAndIsReused(): void
    {
        $driver = $this->driver();
        $driver->expects($this->once())->method('probeVision')->with('gemma-3-27b-it', $this->anything())
            ->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $models = $this->createStub(ProviderModelRepository::class);
        $stored = [];
        $models->method('modelProbeCache')->willReturnCallback(static function () use (&$stored): array { return $stored; });
        $verifier = new ScalewayVisionVerifier($models);
        $first = $verifier->verify(1, $driver, $this->catalog());
        $stored = $first['cache'];
        $second = $verifier->verify(1, $driver, $this->catalog());
        self::assertSame([1, ['gemma-3-27b-it'], 0, ['gemma-3-27b-it']], [
            $first['attempts'], $first['catalog']->visionModelIds(), $second['attempts'], $second['catalog']->visionModelIds(),
        ]);
    }

    public function testChangingTheKeyInvalidatesOldPositiveEvidence(): void
    {
        $driver = $this->driver();
        $cache = new ModelProbeCache([], ScalewayProvider::capabilityScope('https://provider.example', 'old-key'));
        $cache->record('gemma-3-27b-it', ModelProbeStatus::VISION_VERIFIED);
        $models = $this->createStub(ProviderModelRepository::class);
        $models->method('modelProbeCache')->willReturn($cache->toArray());
        $driver->expects($this->exactly(3))->method('probeVision')->willReturn(ModelProbeStatus::UNVERIFIED);
        $result = (new ScalewayVisionVerifier($models))->verify(1, $driver, $this->catalog());
        self::assertSame([3, []], [$result['attempts'], $result['catalog']->visionModelIds()]);
    }

    public function testANewShortlistDoesNotCausePaidCallsWhenAnotherModelIsAlreadyVerified(): void
    {
        $driver = $this->driver();
        $driver->expects($this->never())->method('probeVision');
        $cache = new ModelProbeCache([], $driver->probeScope());
        $cache->record('pixtral-12b-2409', ModelProbeStatus::VISION_VERIFIED);
        $models = $this->createStub(ProviderModelRepository::class);
        $models->method('modelProbeCache')->willReturn($cache->toArray());
        $result = (new ScalewayVisionVerifier($models))->verify(1, $driver, $this->catalog());
        self::assertSame([0, ['pixtral-12b-2409']], [$result['attempts'], $result['catalog']->visionModelIds()]);
    }

    public function testWeeklyDiscoveryRenewsFreshEvidenceBeforeItCanExpireBetweenTasks(): void
    {
        $driver = $this->driver();
        $driver->expects($this->once())->method('probeVision')->with('pixtral-12b-2409', $this->anything())
            ->willReturn(ModelProbeStatus::VISION_VERIFIED);
        $cache = new ModelProbeCache([], $driver->probeScope());
        $cache->record('pixtral-12b-2409', ModelProbeStatus::VISION_VERIFIED);
        $models = $this->createStub(ProviderModelRepository::class);
        $models->method('modelProbeCache')->willReturn($cache->toArray());
        $result = (new ScalewayVisionVerifier($models))->verify(1, $driver, $this->catalog(), true);
        self::assertSame([1, ['pixtral-12b-2409']], [$result['attempts'], $result['catalog']->visionModelIds()]);
    }

    public function testAnObservedRouteRefusalOverridesDocumentationAndCannotBecomeAChatTier(): void
    {
        $driver = $this->driver();
        $driver->expects($this->once())->method('probeVision')->willReturn(ModelProbeStatus::CHAT_UNSUPPORTED);
        $models = $this->createStub(ProviderModelRepository::class);
        $catalog = new ProviderModelCatalog([
            ['id' => 'gemma-3-27b-it', 'display_name' => 'Gemma', 'chat' => true, 'vision' => true],
        ]);
        $result = (new ScalewayVisionVerifier($models))->verify(1, $driver, $catalog);
        self::assertSame([[], [], ['gemma-3-27b-it' => 'chat_endpoint_unsupported']], [
            $result['catalog']->chatModelIds(), $result['catalog']->visionModelIds(), $result['catalog']->exclusions(),
        ]);
    }
}
