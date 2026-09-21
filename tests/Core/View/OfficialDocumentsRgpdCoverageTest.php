<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Modules\OfficialDocuments\Service\MemberDocumentsSummaryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the site tells a family about the official documents it pre-fills
 * for them.
 *
 * AGENTS.md § RGPD page maintenance is blunt about it — « A PR that adds
 * personal data processing without updating the RGPD documentation is
 * incomplete » — and this module writes a member's legal name, their
 * section responsable's name and postal address, and the signing parent's
 * own name onto a document. So the facts have to reach two places: the
 * default privacy notice (`core/View/rgpd_default.html`, which
 * `RgpdContentService::getDefaultContent()` serves) and the prompt that
 * regenerates a tailored one (`buildSystemPrompt()`, rule 33bis).
 *
 * **Deliberately about the words.** What a family reads is not enforced by
 * a unit test of a service; it is enforced by the sentence being there and
 * being true. What is mechanical — and what this catches — is the sentence
 * deleted in a refactor, or the one a later iteration quietly made false.
 * Three of the four facts below are promises the module's own design makes
 * (§44.1), so the day one stops holding, this test is where the notice and
 * the code are forced back into agreement.
 */
final class OfficialDocumentsRgpdCoverageTest extends TestCase
{
    private const NOTICE = 'core/View/rgpd_default.html';
    private const PROMPT = 'core/View/RgpdContentService.php';

    private static function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /**
     * Only this module's own words, cut out of a document that runs to
     * several hundred paragraphs.
     *
     * Searching the whole file would be a test that cannot fail: « signée »,
     * « chef d'unité » and « aucun sous-traitant » all appear in the
     * Attestations section a few lines above, so deleting the official
     * documents paragraph outright would leave every assertion green. What
     * this pins is that the sentence is in the section a reader looking for
     * THIS module would land on.
     */
    private static function officialDocumentsSectionOf(string $path): string
    {
        $content = self::read($path);
        [$start, $end] = $path === self::NOTICE
            ? ['<h4>Module Documents officiels</h4>', '<h4>Module Trombinoscope</h4>']
            : ['33bis. **Module Documents officiels', "\n\n34. **Assistant d'aide"];

        $from = strpos($content, $start);
        self::assertNotFalse($from, $path . ' : la section « Documents officiels » a disparu.');

        $to = strpos($content, $end, $from);
        self::assertNotFalse($to, $path . ' : la borne de fin de section a bougé, ce test ne délimite plus rien.');

        // Whitespace collapsed: both files wrap their prose, so « un chef
        // d'unité » is split across two lines in the prompt and across none
        // in the notice. Where a paragraph happens to break is not one of
        // the facts this test is about.
        return (string) preg_replace('/\s+/u', ' ', substr($content, $from, $to - $from));
    }

    /**
     * The facts a family reads, which therefore belong in BOTH documents.
     *
     * @return array<string, array{string}>
     */
    public static function factProvider(): array
    {
        return [
            'only the signed paper counts' => ['signée'],
            'nothing the parent types is stored' => ['enregistré'],
            'no staff view, no chief bypass' => ['chef d\'unité'],
            'no sub-processor and no AI call' => ['aucun sous-traitant'],
        ];
    }

    #[DataProvider('factProvider')]
    public function testBothDocumentsCarryTheFact(string $needle): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                self::officialDocumentsSectionOf($path),
                $path . ' ne dit plus cela des documents officiels, et une famille qui le lit en saurait '
                . 'moins que ce qui arrive réellement à ses données.'
            );
        }
    }

    /**
     * The module's technical id belongs in the PROMPT and nowhere else: it
     * is what rule 33bis matches on to keep or drop the section, and a
     * notice a parent reads has no business carrying it.
     */
    public function testTheModuleIdSteersThePromptAndStaysOutOfTheNotice(): void
    {
        $this->assertStringContainsString('official_documents', self::read(self::PROMPT));
        $this->assertStringNotContainsString('official_documents', self::read(self::NOTICE));
    }

    /**
     * The section exists at all, under the name the module carries in the
     * interface — a fact the prompt's rule 33bis depends on to keep or drop
     * it.
     */
    public function testTheNoticeHasASectionForThisModule(): void
    {
        $this->assertStringContainsString('<h4>Module Documents officiels</h4>', self::read(self::NOTICE));
        $this->assertStringContainsString('33bis.', self::read(self::PROMPT));
    }

    /**
     * The one sentence that must never migrate onto the PDF, said on the
     * web page and in the notice alike. If the module's own wording and the
     * privacy notice ever disagree about it, one of them is wrong.
     */
    public function testTheNoticeAndTheModuleAgreeThatAnUnsignedDocumentIsWorthless(): void
    {
        $this->assertStringContainsString('signé', MemberDocumentsSummaryService::WARNING);
        $this->assertStringContainsString(
            'Seule la version signée sur papier a une valeur',
            self::officialDocumentsSectionOf(self::NOTICE)
        );
    }

    /**
     * The authorization keeps nothing, and the notice must keep saying so:
     * no stored answers, no stored PDF.
     *
     * The wording is deliberately narrow — « sur l'autorisation parentale »
     * — because it stopped being true of the module as a whole the day the
     * health sheet got a table. This test is what forced that sentence to
     * be corrected rather than left standing as a promise the site no
     * longer keeps.
     */
    public function testTheNoticeStillSaysTheAuthorizationKeepsNothing(): void
    {
        $section = self::officialDocumentsSectionOf(self::NOTICE);

        $this->assertStringContainsString('sans jamais toucher le disque', $section);
        $this->assertStringContainsString(
            'Rien de ce que le parent tape sur l\'autorisation parentale n\'est enregistré',
            $section
        );
    }

    /**
     * **The retention rule, in both documents.**
     *
     * A privacy notice that says data is kept and never says for how long
     * is missing the one fact a family asks about first, and art. 5.1.e is
     * about exactly that. It also has to say what restarts the clock —
     * printing counts — because a family that only ever prints would
     * otherwise read this as « erased in eighteen months whatever I do ».
     */
    public function testTheNoticeSaysHowLongTheHealthSheetIsKeptAndWhatRestartsTheClock(): void
    {
        $section = self::officialDocumentsSectionOf(self::NOTICE);

        $this->assertStringContainsString('dix-huit mois', $section);
        // The clock restarts on a print, not only on an edit.
        $this->assertStringContainsStringIgnoringCase('imprime', $section);
        // And it is the unit's setting, not a constant of the software.
        $this->assertStringContainsString('réglage', $section);
    }

    /**
     * And that the erasure is silent, which is a promise about what the
     * site will NOT do: no warning e-mail about a child's medical record.
     *
     * In the prompt too, with « n'invente pas » — a model asked to write a
     * privacy notice will otherwise reach for the reassuring sentence and
     * describe a notification that does not exist.
     */
    public function testBothDocumentsSayTheErasureIsSilentAndFinal(): void
    {
        foreach ([self::NOTICE, self::PROMPT] as $path) {
            $section = self::officialDocumentsSectionOf($path);

            $this->assertStringContainsString('silencieux', $section, $path);
            $this->assertStringContainsStringIgnoringCase('avertissement préalable', $section, $path);
        }

        $this->assertStringContainsString(
            "n'invente pas de notification",
            self::officialDocumentsSectionOf(self::PROMPT)
        );
    }

    /**
     * The purge writes the member id and nothing else — the same rule as
     * the « Tout effacer » button, and the notice must not describe one
     * without the other.
     */
    public function testTheNoticeSaysThePurgeIsJournalledWithoutItsContent(): void
    {
        $section = self::officialDocumentsSectionOf(self::NOTICE);

        $this->assertStringContainsString('jamais le contenu effacé', $section);
        $this->assertStringContainsString('identifiant du membre', $section);
    }

    /**
     * And the health sheet, which IS kept, must say so in the same breath.
     *
     * A privacy notice that described only the half keeping nothing would
     * be worse than one saying nothing at all: a family would read it and
     * conclude the site holds no health data about their child.
     */
    public function testTheNoticeSaysTheHealthSheetIsKeptAndHow(): void
    {
        $section = self::officialDocumentsSectionOf(self::NOTICE);

        $this->assertStringContainsString('La fiche santé, elle, est conservée', $section);
        $this->assertStringContainsString('chiffrées au repos', $section);
        // The two things a family most needs to know about data they
        // cannot see: nobody at the unit reads it, and they can destroy it.
        $this->assertStringContainsString('tout effacer', $section);
        $this->assertStringContainsStringIgnoringCase(
            'aucun animateur, aucun chef d\'unité et aucun administrateur',
            $section
        );
    }
}
