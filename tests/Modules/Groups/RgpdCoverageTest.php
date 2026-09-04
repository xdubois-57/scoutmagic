<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

use Core\Config\SettingService;
use Core\Module\ModuleManager;
use Core\View\RgpdContentService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;

/**
 * This module processes free text written by members, their photos, and
 * reports — none of which the unit controls the content of. The privacy
 * policy therefore has to describe it, in both places a unit can end up
 * reading one: the shipped reference text, and the instructions the AI
 * generator works from.
 *
 * These assertions exist because the two are easy to forget: adding a
 * module is a code change, and updating a French legal document is not
 * the same reflex.
 */
class RgpdCoverageTest extends TestCase
{
    private ModuleManager $moduleManager;
    private SettingService $settingService;

    protected function setUp(): void
    {
        $this->moduleManager = $this->createStub(ModuleManager::class);
        $this->moduleManager->method('getEnabledModuleIds')->willReturn(['groups', 'gallery', 'llm_connector']);

        $this->settingService = $this->createStub(SettingService::class);
        $this->settingService->method('get')->willReturn('');
    }

    public function testTheDefaultContentDescribesTheModule(): void
    {
        $content = $this->service()->getDefaultContent();

        $this->assertStringContainsString('Module Groupes de discussion', $content);
    }

    /**
     * The specifics a unit's DPO actually needs, not just the heading.
     */
    public function testTheDefaultContentCoversWhatIsProcessedAndWhoSeesIt(): void
    {
        $content = $this->service()->getDefaultContent();

        // Free text written by members, which the unit does not control.
        $this->assertStringContainsString('rédigé librement par les membres', $content);
        // Private by construction.
        $this->assertStringContainsString('privé et invisible aux non-membres', $content);
        // A report is never revealed.
        $this->assertStringContainsString('signalement n\'est jamais révélé', $content);
        // Hiding is the maximum automatic consequence.
        $this->assertStringContainsString('Le masquage est la seule conséquence automatique', $content);
    }

    public function testTheDefaultContentStatesTheThreeRetentionDurations(): void
    {
        $content = $this->service()->getDefaultContent();

        foreach (['clôturé', 'supprimé définitivement', 'supprimé intégralement', 'épinglé'] as $needle) {
            $this->assertStringContainsString($needle, $content, "retention wording missing: {$needle}");
        }

        // Named in the retention section too, not only inside the module's
        // own sub-section — that section is where a reader looks first.
        $this->assertStringContainsString('<strong>Groupes de discussion</strong> : trois durées distinctes', $content);
    }

    public function testTheSystemPromptTellsTheModelAboutTheModule(): void
    {
        $prompt = $this->capturePrompt();

        $this->assertStringContainsString('Module Groupes de discussion (module groups)', $prompt);
        $this->assertStringContainsString('"groups"', $prompt);
    }

    /**
     * AGENTS.md's RGPD rule: a provider receiving member-written text is a
     * genuine sous-traitant, and that holds even though it is another
     * module (llm_connector) that makes the call. The prompt has to say
     * so, or generated text will quietly omit it.
     */
    public function testTheSystemPromptTreatsTheAiProviderAsASousTraitantForGroupText(): void
    {
        $prompt = $this->capturePrompt();

        $this->assertStringContainsString('sous-traitant à part entière pour le texte des messages', $prompt);
        $this->assertStringContainsString(
            'indépendamment du fait que ce soit un autre module qui déclenche l\'appel',
            $prompt
        );
    }

    public function testTheSystemPromptSaysToRemoveTheSectionWhenTheModuleIsOff(): void
    {
        $prompt = $this->capturePrompt();

        $this->assertStringContainsString(
            'Si "groups" ne figure PAS dans la liste des modules actifs',
            $prompt
        );
    }

    /**
     * The prompt is only reachable through generateWithAi(), so the call
     * is made with a connector that records what it was asked rather than
     * answering anything useful.
     */
    private function capturePrompt(): string
    {
        $captured = '';
        $connector = $this->createStub(LlmConnectorInterface::class);
        $connector->method('isAvailable')->willReturn(true);
        $connector->method('isTierAvailable')->willReturn(true);
        $connector->method('complete')->willReturnCallback(
            function ($request) use (&$captured): LlmResponse {
                $captured = (string) $request->systemPrompt . "\n" . $request->prompt;
                // Folded to one line: these tests assert what the prompt SAYS,
                // and a sentence must stay assertable across a re-wrap.
                $captured = (string) preg_replace('/\s+/u', ' ', $captured);
                return new LlmResponse('<p>ok</p>', null, 1, 1);
            }
        );

        try {
            $this->service($connector)->generateWithAi('Instructions');
        } catch (\RuntimeException) {
            // generateWithAi() refuses generated text that does not name
            // the unit as data controller. That rejection happens well
            // AFTER the request is built, so the prompt these tests are
            // about has already been captured — and producing text that
            // satisfies a check unrelated to this module would only make
            // the fixture harder to read.
        }

        return $captured;
    }

    private function service(?LlmConnectorInterface $connector = null): RgpdContentService
    {
        return new RgpdContentService($this->moduleManager, $this->settingService, $connector);
    }
}
