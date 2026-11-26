<?php

declare(strict_types=1);

namespace Tests\Modules\Camps;

use PHPUnit\Framework\TestCase;

/**
 * The camps place forms now look an address up while it is typed (#1353):
 * a new moment at which the address of a field leaves for Nominatim. The
 * RGPD page and the prompt that regenerates it say so, in the same change
 * (AGENTS.md § RGPD page maintenance).
 */
final class RgpdAddressLookupTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    public function testTheDefaultPageSaysTheLookupAlsoRunsWhileAPlaceIsTyped(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString(
            'aussi pendant qu\'un animateur saisit l\'adresse d\'un lieu de camp',
            $page
        );
        $this->assertStringContainsString('ce géocodage — en tâche de fond comme pendant la saisie — peut être désactivé', $page);
    }

    public function testThePromptKeepsIt(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString(
            'ce géocodage a lieu en tâche de fond ET pendant qu\'un animateur saisit l\'adresse d\'un lieu',
            $prompt
        );
    }

    public function testTheSettingThatTurnsItOffSaysItCoversBoth(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/modules/camps/module.json'), true);
        $this->assertIsArray($manifest);

        $description = '';
        foreach ($manifest['settings'] as $setting) {
            if ($setting['key'] === 'camps_geocoding_enabled') {
                $description = $setting['description'];
            }
        }

        $this->assertStringContainsString('pendant la saisie d\'un lieu', $description);
    }
}
