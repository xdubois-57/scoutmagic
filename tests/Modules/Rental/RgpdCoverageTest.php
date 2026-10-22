<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental;

use PHPUnit\Framework\TestCase;

/**
 * What the RGPD page says of the stay's photos (#989) is what the code
 * does: re-encoded with no metadata by `Core\Image\PhotoNormalizer`,
 * refused rather than kept as they came, and deleted with the booking by
 * `RentalRetentionService` — and the prompt that regenerates the page
 * keeps it (AGENTS.md § RGPD page maintenance).
 */
final class RgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    public function testTheDefaultPageSaysWhatHappensToTheStaysPhotos(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString("<strong>réencodées par le site avant d'être enregistrées</strong>", $page);
        $this->assertStringContainsString('<strong>aucune métadonnée</strong> — ni position GPS', $page);
        $this->assertStringContainsString('est refusée, jamais enregistrée telle quelle', $page);
        $this->assertStringContainsString("jusqu'à cinq photos, traitées comme celles des relevés", $page);
        $this->assertStringContainsString('les photos des relevés et des incidents, son décompte', $page);
    }

    public function testThePromptKeepsIt(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('incidents, photos des relevés et des incidents, décompte', $prompt);
        $this->assertStringContainsString('**réencodées par le site**', $prompt);
        $this->assertStringContainsString('**sans aucune métadonnée** (ni position GPS', $prompt);
        $this->assertStringContainsString('est **refusée**, jamais enregistrée telle quelle', $prompt);
    }
}
