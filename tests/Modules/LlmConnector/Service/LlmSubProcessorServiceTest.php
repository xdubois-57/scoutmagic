<?php

declare(strict_types=1);

namespace Tests\Modules\LlmConnector\Service;

use Core\Module\SubProcessorView;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LlmSubProcessorService;
use PHPUnit\Framework\TestCase;

/**
 * The AI provider as a declared sub-processor (Core\Module\
 * SubProcessorProvider, chantier IT-05). The hook is dynamic: only an
 * ACTIVE provider is a sub-processor, and the wording is the exact
 * wording the RGPD prompt carried when core read these tables itself.
 */
class LlmSubProcessorServiceTest extends TestCase
{
    /**
     * @param array{id: int, driver: string, name: string}|null $provider
     * @param list<array<string, mixed>> $models
     */
    private function service(?array $provider, array $models = []): LlmSubProcessorService
    {
        $providerRepository = $this->createMock(ProviderRepository::class);
        $providerRepository->method('findFirstActive')->willReturn($provider);
        $modelRepository = $this->createMock(ProviderModelRepository::class);
        $modelRepository->method('findByProvider')->willReturn($models);

        return new LlmSubProcessorService($providerRepository, $modelRepository);
    }

    public function testNoActiveProviderMeansNoSubProcessorAtAll(): void
    {
        // An AI integration nothing is configured to call processes
        // nobody's data — the empty list, never a placeholder view.
        $this->assertSame([], $this->service(null)->getSubProcessors());
    }

    public function testAKnownDriverIsWordedWithItsLocationExactlyAsTheRgpdPromptAlwaysWasIt(): void
    {
        $views = $this->service(
            ['id' => 1, 'driver' => 'anthropic', 'name' => 'Mon fournisseur'],
            [
                ['display_name' => 'Claude Haiku', 'is_tier_cheap' => true, 'is_tier_capable' => false, 'is_tier_ocr' => true],
                ['display_name' => 'Claude Sonnet', 'is_tier_cheap' => false, 'is_tier_capable' => true, 'is_tier_ocr' => false],
                // No tier assigned: not part of any processing, not listed.
                ['display_name' => 'Claude Opus', 'is_tier_cheap' => false, 'is_tier_capable' => false, 'is_tier_ocr' => false],
            ]
        )->getSubProcessors();

        $this->assertCount(1, $views);
        $this->assertSame(SubProcessorView::CATEGORY_AI, $views[0]->category);
        $this->assertSame('Anthropic (États-Unis, hors UE)', $views[0]->name);
        $this->assertSame('Claude Haiku (économique, OCR); Claude Sonnet (performant)', $views[0]->details);
    }

    public function testAnUnknownDriverFallsBackToTheProvidersOwnConfiguredName(): void
    {
        $views = $this->service(['id' => 1, 'driver' => 'homebrew', 'name' => 'Mon LLM local'])->getSubProcessors();

        $this->assertSame('Mon LLM local', $views[0]->name);
        $this->assertSame('Aucun modèle assigné', $views[0]->details);
    }
}
