<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RosterMembershipFactsRgpdCoverageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function disclosures(): iterable
    {
        foreach ([
            'première date de début de fonction connue parmi toutes les fonctions',
            'indication que toutes les dates de début sont connues',
            'présence dans l’année précédente',
            'conservés en clair',
            'même si le module Cotisations est désactivé',
            'anciennes photographies restent inconnues',
            'jamais reconstruites à partir des données actuelles',
            'aucun nom ni aucune date de naissance',
            'deux années scoutes par défaut',
        ] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('disclosures')]
    public function testBothTheDefaultNoticeAndTheGenerationPromptDiscloseEachFact(string $text): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            'core/View/rgpd_default.html' => '/<p><strong>Photographies d’effectifs<\/strong>(.*?)<\/p>/su',
            'core/View/RgpdContentService.php' => '/Photographies d’effectifs :(.*?)4quinquies\./su',
        ] as $path => $pattern) {
            $source = (string) file_get_contents($root . '/' . $path);
            preg_match($pattern, $source, $match);
            $paragraph = html_entity_decode(strip_tags($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $paragraph = (string) preg_replace('/\s+/u', ' ', $paragraph);
            self::assertStringContainsString($text, $paragraph, $path);
        }
    }
    public function testTheScaleNoticeSeparatesMoneyFromGuestEligibility(): void
    {
        foreach (['core/View/rgpd_default.html', 'core/View/RgpdContentService.php'] as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
            $pattern = str_ends_with($file, '.html')
                ? '/<p><strong>Recherche des montants de cotisation.*?<\/p>/su'
                : '/32\. \*\*Module Cotisations.*?33\./su';
            self::assertSame(1, preg_match($pattern, $source, $match), $file);
            $notice = $match[0];
            self::assertStringContainsString('les montants chiffrent les écarts en euros', $notice, $file);
            self::assertStringContainsString(
                'les dates déterminent le tarif Invités et le décompte retenu pour Couple/Familial', $notice, $file
            );
        }
    }

}
