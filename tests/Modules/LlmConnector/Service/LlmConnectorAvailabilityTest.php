<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Journal\JournalService;
use Core\Security\DecryptionException;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LlmConnectorService;
use PHPUnit\Framework\TestCase;

/**
 * Availability semantics, without a database — the repositories are stubbed
 * so the awkward cases (an undecryptable key, a tier with no model) can be
 * set up directly.
 */
class LlmConnectorAvailabilityTest extends TestCase
{
    /**
     * @return array{id: int, name: string, driver: string, api_endpoint: string, api_key: string}
     */
    private function provider(): array
    {
        return [
            'id' => 1,
            'name' => 'Anthropic',
            'driver' => 'anthropic',
            'api_endpoint' => 'https://api.anthropic.com',
            'api_key' => 'sk-test',
        ];
    }

    private function service(
        ProviderRepository $providerRepo,
        ProviderModelRepository $modelRepo
    ): LlmConnectorService {
        return new LlmConnectorService($providerRepo, $modelRepo, $this->createStub(JournalService::class));
    }

    /**
     * @param array<string, bool> $tiers tier value => has a model
     */
    private function modelRepoWith(array $tiers): ProviderModelRepository
    {
        $modelRepo = $this->createStub(ProviderModelRepository::class);
        $modelRepo->method('findByProviderAndTier')->willReturnCallback(
            fn(int $providerId, LlmTier $tier) => ($tiers[$tier->value] ?? false)
                ? ['id' => 10, 'provider_id' => $providerId, 'model_id' => 'model-' . $tier->value, 'display_name' => 'Model']
                : null
        );

        return $modelRepo;
    }

    private function providerRepoReturning(?array $provider): ProviderRepository
    {
        $providerRepo = $this->createStub(ProviderRepository::class);
        $providerRepo->method('findFirstActive')->willReturn($provider);

        return $providerRepo;
    }

    /**
     * The regression: ProviderRepository decrypts eagerly, so a rotated
     * encryption key made findFirstActive() throw — and isAvailable() is
     * called while rendering the PUBLIC retrospective board, so an unusable
     * API key took the whole page down with a 500.
     */
    public function testIsAvailableReportsFalseRatherThanThrowingWhenTheKeyCannotBeDecrypted(): void
    {
        $providerRepo = $this->createStub(ProviderRepository::class);
        $providerRepo->method('findFirstActive')
            ->willThrowException(new DecryptionException('Decryption failed.'));

        $service = $this->service($providerRepo, $this->modelRepoWith(['cheap' => true]));

        self::assertFalse($service->isAvailable());
        self::assertFalse($service->isTierAvailable(LlmTier::CHEAP));
    }

    public function testCompleteReportsNoProviderRatherThanThrowingWhenTheKeyCannotBeDecrypted(): void
    {
        $providerRepo = $this->createStub(ProviderRepository::class);
        $providerRepo->method('findFirstActive')
            ->willThrowException(new DecryptionException('Decryption failed.'));

        $service = $this->service($providerRepo, $this->modelRepoWith(['cheap' => true]));

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_PROVIDER);

        $service->complete(new LlmRequest(tier: LlmTier::CHEAP, prompt: 'Bonjour'));
    }

    public function testIsAvailableIsFalseWithoutAnActiveProvider(): void
    {
        $service = $this->service($this->providerRepoReturning(null), $this->modelRepoWith(['cheap' => true]));

        self::assertFalse($service->isAvailable());
        self::assertFalse($service->isTierAvailable(LlmTier::CHEAP));
    }

    public function testIsAvailableIsTrueAsSoonAsAnyTierHasAModel(): void
    {
        $service = $this->service(
            $this->providerRepoReturning($this->provider()),
            $this->modelRepoWith(['capable' => true])
        );

        self::assertTrue($service->isAvailable());
    }

    /**
     * isAvailable() answering "something is configured" is not the same
     * question as "the tier I am about to ask for exists" — RGPD generation
     * only ever uses CAPABLE and used to pass the first check, then fail
     * inside complete() after the button had already been offered.
     */
    public function testIsTierAvailableIsFalseForATierWithNoModelEvenWhenAnotherTierHasOne(): void
    {
        $service = $this->service(
            $this->providerRepoReturning($this->provider()),
            $this->modelRepoWith(['cheap' => true])
        );

        self::assertTrue($service->isAvailable());
        self::assertTrue($service->isTierAvailable(LlmTier::CHEAP));
        self::assertFalse($service->isTierAvailable(LlmTier::CAPABLE));
    }

    public function testIsTierAvailableAppliesTheOcrToCheapFallback(): void
    {
        $service = $this->service(
            $this->providerRepoReturning($this->provider()),
            $this->modelRepoWith(['cheap' => true])
        );

        self::assertTrue($service->isTierAvailable(LlmTier::OCR));
    }

    public function testIsTierAvailableIsFalseForOcrWhenNotEvenCheapExists(): void
    {
        $service = $this->service(
            $this->providerRepoReturning($this->provider()),
            $this->modelRepoWith(['capable' => true])
        );

        self::assertFalse($service->isTierAvailable(LlmTier::OCR));
    }

    /**
     * isTierAvailable() and complete() must never disagree: whenever the
     * former says yes, the latter must get past model resolution.
     */
    public function testIsTierAvailableAgreesWithCompleteAboutMissingModels(): void
    {
        $service = $this->service(
            $this->providerRepoReturning($this->provider()),
            $this->modelRepoWith(['cheap' => true])
        );

        self::assertFalse($service->isTierAvailable(LlmTier::CAPABLE));

        $this->expectException(LlmException::class);
        $this->expectExceptionCode(LlmException::NO_MODEL);

        $service->complete(new LlmRequest(tier: LlmTier::CAPABLE, prompt: 'Bonjour'));
    }
}
