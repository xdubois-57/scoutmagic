<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use PHPUnit\Framework\TestCase;

/**
 * A new table of personal data and a new processing: the RGPD page and
 * the prompt that regenerates it say so (AGENTS.md § RGPD page
 * maintenance) — in the same change, or the change is incomplete.
 */
final class RgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    public function testTheDefaultPageDescribesTheModuleAndItsRetention(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString('<h4>Module Covoiturage</h4>', $page);
        $this->assertStringContainsString('chiffrés en base', $page);
        $this->assertStringContainsString('<strong>acceptée</strong>', $page);
        $this->assertStringContainsString('<li><strong>Covoiturages</strong> : Un covoiturage est <strong>effacé automatiquement</strong>', $page);
        $this->assertStringContainsString('modules camps et covoiturage', $page);
    }

    public function testThePromptTellsTheModelWhatToKeepAndWhenToRemoveIt(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('**Module Covoiturage (module covoiturage)**', $prompt);
        $this->assertStringContainsString('retire entièrement la sous-section "Module Covoiturage"', $prompt);
        $this->assertStringContainsString('NI le module camps NI le module covoiturage', $prompt);
    }
}
