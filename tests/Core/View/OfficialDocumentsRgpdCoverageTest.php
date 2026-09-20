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
                self::read($path),
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
            self::read(self::NOTICE)
        );
    }

    /**
     * The claim that would be the easiest to leave behind and the worst to
     * get wrong: the notice must not promise that the site keeps a copy of
     * anything, because it keeps none — not the PDF, not what was typed.
     */
    public function testTheNoticeSaysNothingIsKept(): void
    {
        $notice = self::read(self::NOTICE);

        $this->assertStringContainsString('sans jamais toucher le disque', $notice);
        $this->assertStringContainsString('Rien de ce que le parent tape n\'est enregistré', $notice);
    }
}
