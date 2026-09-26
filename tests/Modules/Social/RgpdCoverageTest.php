<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use PHPUnit\Framework\TestCase;

/**
 * A new recipient of data: the RGPD page and the prompt that regenerates
 * it say so (AGENTS.md § RGPD page maintenance) — in the same change.
 */
final class RgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    public function testTheDefaultPageDescribesTheModuleAndMeta(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString('<h4>Module Réseaux sociaux</h4>', $page);
        // Publishing is a chief's decision, public, and a gallery cover leaves blurred (IT-03).
        $this->assertStringContainsString('<strong>ne publie jamais de lui-même</strong>', $page);
        $this->assertStringContainsString('<strong>systématiquement floutée</strong>', $page);
        $this->assertStringContainsString('<strong>Meta Platforms Ireland Limited</strong>', $page);
        $this->assertStringContainsString('<li><strong>Raccordement à Facebook et Instagram</strong>', $page);
    }

    public function testThePromptTellsTheModelWhenToKeepItAndWhenToRemoveIt(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('**Module Réseaux sociaux (module social)**', $prompt);
        $this->assertStringContainsString('retire entièrement la sous-section "Module Réseaux sociaux"', $prompt);
        $this->assertStringContainsString('- Réseaux sociaux raccordés : {$socialPublishing}', $prompt);
        $this->assertStringContainsString('**ne publie jamais de lui-même**', $prompt);
        $this->assertStringContainsString('**systématiquement floutée**', $prompt);
    }
}
