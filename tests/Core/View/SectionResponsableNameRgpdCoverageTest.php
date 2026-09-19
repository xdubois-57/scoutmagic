<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * What the site tells a family about where a section responsable's
 * surname is shown, now that `/sections` shows it too.
 *
 * Issue #359 asked for the responsable to be named in full on the
 * Sections page, and the first attempt did it unconditionally — on a page
 * registered `role_min: 'public'`, which published an animateur's legal
 * name to anonymous visitors and to search engines. Review caught it
 * against the site's own notice, which said that name « n'est jamais
 * publique ».
 *
 * The behaviour chosen is narrower than that attempt and wider than the
 * sentence the notice already carried: anonymous visitors see the totem
 * alone, and **every signed-in account** sees the full name, whether or
 * not it is linked to that section. The clause the notice carried
 * described only the member page, where the audience is the accounts
 * linked to that member — so it was true, and incomplete, and a notice
 * that is incomplete about who can see a surname is the kind of thing
 * this project treats as a defect rather than as wording.
 *
 * So both halves say it now, and this test is about the words, in the
 * manner of Tests\Core\View\RemoteBackupRgpdCoverageTest: the default
 * notice a family reads (`core/View/rgpd_default.html`) and the prompt
 * that regenerates a tailored one
 * (`Core\View\RgpdContentService::buildSystemPrompt()`). What is
 * mechanical here — and what this catches — is the sentence deleted in a
 * refactor, or a later change to the page that leaves it false.
 *
 * It is the pair to Tests\Core\Http\Controller\PageControllerTest's
 * `testSectionsPageWithholdsTheResponsableSurnameFromThePublic`, which
 * holds the behaviour. One without the other is either an undocumented
 * disclosure or a promise nothing keeps.
 */
final class SectionResponsableNameRgpdCoverageTest extends TestCase
{
    private const NOTICE = 'core/View/rgpd_default.html';
    private const PROMPT = 'core/View/RgpdContentService.php';

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /**
     * Collapse the line wrapping both files use, so an assertion about a
     * sentence is not really an assertion about where it was wrapped.
     */
    private static function flattened(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', self::read($path));
    }

    /**
     * The audience, which is the whole of what changed: not « les comptes
     * liés », which is the member page's rule, but every signed-in
     * visitor.
     */
    public function testTheNoticeSaysASignedInVisitorSeesTheFullName(): void
    {
        $notice = self::flattened(self::NOTICE);

        $this->assertStringContainsString('Sections', $notice);
        $this->assertStringContainsString('tout visiteur connecté', $notice);
        $this->assertStringContainsString(
            'qu\'il soit ou non lié à cette section',
            $notice,
            'the RGPD notice no longer says that the Sections page shows the responsable\'s full name '
            . 'to signed-in visitors who are NOT linked to that section. That is wider than the member '
            . 'page rule it sits next to, and the difference is the entire reason this sentence exists.',
        );
    }

    /**
     * And the other half of the same fact, which is the one an anonymous
     * visitor cares about — stated to the letter of what the page does.
     *
     * « n'y voit que le totem » would have been wrong, and wrong in the
     * direction that flatters us: `display_name` is `totem ?? firstName`
     * (Core\Member\MemberProfile::getDisplayName()), `totem_encrypted`
     * is nullable, and an adult chef frequently has none — so what an
     * anonymous visitor reads is the first name, not nothing. The
     * surname is what is actually withheld, and that is what the
     * sentence has to say.
     *
     * Tests\Core\Http\Controller\PageControllerTest::
     * testAResponsableWithNoTotemIsNamedByFirstNameAloneToThePublic
     * exercises that case; this one is why it has to exist.
     */
    public function testTheNoticeIsAccurateAboutWhatAnAnonymousVisitorSees(): void
    {
        $notice = self::flattened(self::NOTICE);

        $this->assertStringContainsString('non connecté', $notice);
        $this->assertStringContainsString(
            'le totem, ou le prénom à défaut',
            $notice,
            'the RGPD notice claims an anonymous visitor sees only the totem. A responsable without '
            . 'one is named by their FIRST NAME on that page, so the sentence has to say so — a '
            . 'privacy notice that overstates the protection is worse than one that says nothing.',
        );
        $this->assertStringContainsString(
            'jamais le nom de famille',
            $notice,
            'the RGPD notice no longer names what is actually withheld from an anonymous visitor, '
            . 'which is the surname — the only part of this the code really guarantees.',
        );
    }

    /**
     * The address is not part of this. It stays on the member page, for
     * linked accounts, and a notice that let the two facts blur would be
     * describing a disclosure the code does not make.
     */
    public function testTheNoticeStillKeepsThePostalAddressOffThatPage(): void
    {
        $this->assertStringContainsString(
            'jamais son adresse',
            self::flattened(self::NOTICE),
            'the RGPD notice no longer distinguishes the responsable\'s NAME, now shown on the Sections '
            . 'page, from their postal ADDRESS, which is not — and the paragraph is titled after the '
            . 'address, so the two are one careless edit apart.',
        );
    }

    /**
     * A tailored notice is generated from the prompt rather than from the
     * default file, so a fact written in only one of the two survives
     * exactly until an installation regenerates its page.
     */
    public function testTheGenerationPromptCarriesTheSameFact(): void
    {
        $prompt = self::flattened(self::PROMPT);

        $this->assertStringContainsString('la page « Sections »', $prompt);
        $this->assertStringContainsString('le totem, ou le prénom à défaut', $prompt);
        $this->assertStringContainsString(
            'à tout visiteur connecté, lié ou non à cette section',
            $prompt,
            'buildSystemPrompt() no longer tells the model that the Sections page shows the '
            . 'responsable\'s full name to any signed-in visitor, so a regenerated notice would omit a '
            . 'disclosure the default one makes.',
        );
    }
}
