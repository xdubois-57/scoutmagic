<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Provider\AnthropicProvider;
use Modules\LlmConnector\Provider\LlmProviderInterface;
use Modules\LlmConnector\Provider\ModelProbeCache;
use Modules\LlmConnector\Provider\ModelProbeStatus;
use Modules\LlmConnector\Provider\ProviderModelCatalog;
use Modules\LlmConnector\Provider\ScalewayProvider;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Service\OcrModelSelector;
use Modules\LlmConnector\Service\TierModelResolver;
use PHPUnit\Framework\TestCase;
use Tests\Modules\LlmConnector\StubHttpServer;

class CapabilityReviewRegressionsTest extends TestCase
{
    public function testAnIndeterminateRenewalPreservesTheOriginalFreshProofAndItsDeadline(): void
    {
        $verified = new ModelProbeCache([], 'scope', 1800000000);
        $verified->record('image', ModelProbeStatus::VISION_VERIFIED);
        $cache = new ModelProbeCache($verified->toArray(), 'scope', 1800000060);
        $cache->record('image', ModelProbeStatus::UNVERIFIED);
        $expired = new ModelProbeCache($cache->toArray(), 'scope', 1800604800);
        self::assertSame([ModelProbeStatus::VISION_VERIFIED, 1800000000, null], [
            $cache->resultFor('image'), $cache->toArray()['results']['image']['checked_at'], $expired->resultFor('image'),
        ]);
    }

    public function testMergingATransientFailureAlsoPreservesAFreshConcurrentProof(): void
    {
        $verified = new ModelProbeCache([], 'scope', 1800000000);
        $verified->record('image', ModelProbeStatus::VISION_VERIFIED);
        $failure = new ModelProbeCache([], 'scope', 1800000060);
        $failure->record('image', ModelProbeStatus::UNVERIFIED);
        $merged = ModelProbeCache::mergeForStorage($verified->toArray(), $failure->toArray());
        self::assertSame(['status' => 'vision_verified', 'checked_at' => 1800000000], $merged['results']['image']);
    }

    public function testFallbackFiltersTheCheapTierByCostEvenWithoutAMatchingNamePattern(): void
    {
        $catalog = new ProviderModelCatalog([
            ['id' => 'llama-3-70b-instruct', 'display_name' => 'Llama', 'chat' => true, 'vision' => false],
            ['id' => 'gemma-3-27b-it', 'display_name' => 'Gemma', 'chat' => true, 'vision' => true],
        ]);
        $tiers = (new OcrModelSelector())->fallbackTiers($catalog);
        self::assertSame(['gemma-3-27b-it', 'gemma-3-27b-it'], [$tiers['cheap'], $tiers['ocr']]);
    }

    public function testALegacyMistralTextModelCannotReceiveAnOcrRequestWithoutVisionEvidence(): void
    {
        $models = $this->createStub(ProviderModelRepository::class);
        $models->method('findByProviderAndTier')->willReturn([
            'id' => 1, 'provider_id' => 1, 'model_id' => 'text-only-model', 'display_name' => 'Text',
        ]);
        $models->method('modelProbeCache')->willReturn([]);
        self::assertNull((new TierModelResolver($models))->resolve(1, 'mistral', LlmTier::OCR, 'scope'));
    }

    public function testAnExpensiveOnlyCatalogDoesNotTriggerAPaidClassificationOrBecomeCheap(): void
    {
        $catalog = new ProviderModelCatalog([
            ['id' => 'mistral-medium-latest', 'display_name' => 'Medium', 'chat' => true, 'vision' => true],
        ]);
        $driver = $this->createMock(LlmProviderInterface::class);
        $driver->expects($this->never())->method('complete');
        self::assertSame(['cheap' => null, 'capable' => 'mistral-medium-latest', 'ocr' => null],
            (new OcrModelSelector())->selectTiers($driver, $catalog));
    }

    public function testAnExpiredProofIsReplacedByTheTransientResult(): void
    {
        $positive = new ModelProbeCache([], 'scope', 1800000000);
        $positive->record('image', ModelProbeStatus::VISION_VERIFIED);
        $failure = new ModelProbeCache($positive->toArray(), 'scope', 1800604800);
        $failure->record('image', ModelProbeStatus::UNVERIFIED);
        $merged = ModelProbeCache::mergeForStorage($positive->toArray(), $failure->toArray());
        self::assertSame(['status' => 'unverified', 'checked_at' => 1800604800], $merged['results']['image']);
    }

    public function testAnHttp200ErrorDetailDoesNotContainPartialBusinessChoices(): void
    {
        $error = ['type' => 'route_not_supported', 'message' => 'Unsupported route'];
        $server = StubHttpServer::start([['body' => json_encode([
            'error' => $error, 'choices' => [['message' => ['content' => 'Private receipt contents']]],
        ], JSON_THROW_ON_ERROR)]]);
        try {
            $caught = null;
            try {
                (new ScalewayProvider($server->baseUrl(), 'test-key'))->complete('mistral-small-24b-instruct-2501', 'Test');
            } catch (LlmException $exception) {
                $caught = $exception;
            }
            self::assertSame([true, ['error' => $error]], [
                $caught !== null && ScalewayProvider::isUnsupportedRoute($caught),
                json_decode($caught?->detail ?? 'null', true, 512, JSON_THROW_ON_ERROR),
            ]);
        } finally {
            $server->stop();
        }
    }

    public function testAnAnthropicCatalogWithMorePagesAndNoCursorIsRejected(): void
    {
        $server = StubHttpServer::start([['body' => '{"data":[{"id":"claude-haiku"}],"has_more":true}']]);
        try {
            $this->expectException(LlmException::class);
            (new AnthropicProvider($server->baseUrl(), 'test-key'))->listModels();
        } finally {
            $server->stop();
        }
    }
}
