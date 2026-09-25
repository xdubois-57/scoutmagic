<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * The daily report carries the unit's Desk vocabulary, and the privacy
 * notice has to say so (issue #356).
 *
 * This is the failure mode AGENTS.md § RGPD names: not a missing feature,
 * but a **false statement to a reader**. The notice listed exactly what
 * leaves the installation — « uniquement des compteurs […] et des
 * informations techniques sur le logiciel et l'hébergement » — and
 * federal labels are neither. The omission predates this chantier, which
 * added the branches and the unresolved list to a flow that already
 * carried functions and fee categories.
 *
 * Two surfaces, maintained by different hands and both able to state it
 * wrongly on their own: the shipped default notice, and the prompt that
 * regenerates a tailored one for a unit. A prompt that did not know about
 * the vocabulary would quietly write the old, false version back on the
 * next regeneration — which is precisely how the omission would return.
 *
 * Deliberately about the words, like
 * `Tests\Core\View\MergeNotificationRgpdCoverageTest`.
 */
final class DeskVocabularyRgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        $contents = @file_get_contents(dirname(__DIR__, 3) . '/' . $path);

        self::assertIsString($contents, $path . ' must be readable');

        return $contents;
    }

    public function testTheDefaultNoticeSaysTheFederalLabelsTravel(): void
    {
        $notice = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString('vocabulaire Desk', $notice);
        $this->assertStringContainsString(
            "n'est transmis",
            $notice,
            'the notice must say what is NOT sent as plainly as what is'
        );
    }

    /**
     * The one that would be read as a mistake if it were missing: a
     * section's name is the unit's own word and identifies it, so the
     * notice says outright that it never travels.
     */
    public function testBothSurfacesSayASectionNameNeverTravels(): void
    {
        foreach (['core/View/rgpd_default.html', 'core/View/RgpdContentService.php'] as $path) {
            $this->assertStringContainsString(
                'nom de section',
                self::read($path),
                "{$path} no longer states that a section name is never transmitted."
            );
        }
    }

    /**
     * And the claim the report must never make about itself. The rule was
     * already there before this chantier — checked here because the same
     * paragraph now describes more content, and « anonyme » is the word a
     * well-meaning rewrite reaches for when a paragraph grows.
     */
    public function testTheReportIsStillNeverDescribedAsAnonymous(): void
    {
        $this->assertStringContainsString(
            "n'est pas anonyme",
            self::read('core/View/rgpd_default.html'),
            'the report carries the site address; calling it anonymous would be false'
        );
        $this->assertStringContainsString(
            'décris jamais comme anonyme ou anonymisé',
            self::read('core/View/RgpdContentService.php'),
            'the generation prompt must keep forbidding the word'
        );
    }

    public function testThePromptDescribesTheVocabularyItNowSends(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('libellés fédéraux', $prompt);
        $this->assertStringContainsString(
            'jamais une donnée de personne',
            $prompt,
            'the prompt must carry the reason those labels are not personal data'
        );
    }
}
