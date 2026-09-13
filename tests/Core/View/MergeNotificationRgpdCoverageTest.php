<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * A promise with an exception has to state the exception where the
 * promise is made.
 *
 * The site tells families, in two places, that an unread notification is
 * kept for good. Issue #292 made that untrue for one kind: the « Nouvel
 * email » notification of a publipostage is erased with the imported data
 * whose personalised subject it may repeat, read or not. AGENTS.md is
 * explicit that changing retention logic without updating the RGPD
 * documentation leaves the change incomplete — and the failure mode is
 * not a missing feature but a **false statement to a family**, which no
 * test of the purge itself would ever catch.
 *
 * Three surfaces, because a reader meets the claim in three places and
 * they are maintained by different hands: the default privacy notice, the
 * notification centre that repeats it in one sentence, and the prompt that
 * regenerates a tailored notice for a unit — a prompt that did not know
 * about the exception would quietly reintroduce the false version on the
 * next regeneration.
 *
 * Deliberately about the words, like Tests\Core\View\
 * RemoteBackupRgpdCoverageTest. What is mechanical, and what this catches,
 * is the sentence deleted in a refactor or left behind by a change that
 * made it false.
 */
final class MergeNotificationRgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        $contents = @file_get_contents(dirname(__DIR__, 3) . '/' . $path);

        self::assertIsString($contents, $path . ' must be readable');

        return $contents;
    }

    public function testThePrivacyNoticeStatesTheExceptionBesideThePromise(): void
    {
        $notice = self::read('core/View/rgpd_default.html');

        // The promise itself must still be there — the exception only
        // makes sense next to it.
        $this->assertStringContainsString(
            "Une notification non lue n'est jamais supprimée automatiquement",
            $notice
        );
        $this->assertStringContainsString('à une exception près', $notice);
        $this->assertStringContainsString('« Nouvel email » d\'un publipostage', $notice);

        // And the reason, which is what makes it a rule rather than a
        // quirk: the value has been erased everywhere else.
        $this->assertStringContainsString('sujet personnalisé', $notice);

        // Ordinary sends keep the promise; saying so is what stops a
        // reader concluding every mass mail notification is temporary.
        $this->assertStringContainsString('envoi groupé ordinaire', $notice);
    }

    public function testTheNotificationCentreRepeatsTheSameQualification(): void
    {
        $page = self::read('core/View/templates/notifications/index.html.twig');

        $this->assertStringContainsString('gardée sans limite', $page);
        $this->assertStringContainsString('publipostage', $page);
    }

    /**
     * A unit that regenerates its notice gets one written from this
     * prompt. Without the rule, the regenerated page states the
     * unqualified promise — and is wrong the day it is published.
     */
    public function testTheRegenerationPromptCarriesTheRule(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('10bis', $prompt);
        $this->assertStringContainsString('« Nouvel email » d\'un', $prompt);
        $this->assertStringContainsString('lue ou non', $prompt);
    }
}
