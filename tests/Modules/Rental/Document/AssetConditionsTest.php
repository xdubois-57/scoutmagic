<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Rental\Document\AssetConditions;
use Modules\Rental\Document\StandardTemplates;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The conditions a renter ticks on the public request form (§22.5).
 *
 * The first test is the one that matters: the tick-box is mandatory, and
 * until §22.5 an asset whose managers had written nothing showed it over an
 * empty block. The renter accepted nothing, and `conditions_hash` attested
 * to it faithfully.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AssetConditionsTest extends TestCase
{
    private \PDO $pdo;
    private EditableContentService $store;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->store = new EditableContentService(new EditableContentRepository($this->pdo));
    }

    public function testAnAssetThatWroteNothingStillHasCompleteConditions(): void
    {
        $text = AssetConditions::textFor($this->store, 42);

        $this->assertNotSame('', trim($text));
        $this->assertSame(StandardTemplates::conditions(), $text);
    }

    public function testTheUnitsOwnWordingTakesOver(): void
    {
        $this->store->set(AssetConditions::key(42), '<p>Le local est rendu balayé.</p>', 'rich_text', 1);

        $this->assertSame('<p>Le local est rendu balayé.</p>', AssetConditions::textFor($this->store, 42));
    }

    /**
     * Whitespace only is not a text. It is what a manager leaves behind by
     * emptying the editor, and treating it as one would put the tick-box
     * back over nothing.
     *
     * **`<p>&nbsp;</p>` is the case that matters** and the one a bare
     * `trim()` on the raw HTML lets through: it is a non-empty string,
     * carrying a tag, that renders as an empty box — and it is exactly what
     * a `contenteditable` hands back for a paragraph somebody blanked.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blankTexts')]
    public function testATextThatRendersAsNothingFallsBackToTheStandard(string $stored): void
    {
        $this->store->set(AssetConditions::key(42), $stored, 'rich_text', 1);

        $this->assertSame(StandardTemplates::conditions(), AssetConditions::textFor($this->store, 42));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankTexts(): array
    {
        return [
            'espaces' => ['   '],
            'paragraphe vide' => ['<p></p>'],
            'paragraphe d\'espaces' => ['<p>  </p>'],
            'espace insécable en entité' => ['<p>&nbsp;</p>'],
            'espace insécable numérique' => ['<p>&#160;</p>'],
            'espace insécable littéral' => ["<p>\u{00A0}</p>"],
            'saut de ligne seul' => ['<p><br></p>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankTexts')]
    public function testIsBlankRecognisesEveryShapeOfNothing(string $html): void
    {
        $this->assertTrue(AssetConditions::isBlank($html));
    }

    public function testIsBlankLeavesRealTextAlone(): void
    {
        $this->assertFalse(AssetConditions::isBlank('<p>Le local est rendu balayé.</p>'));
        // A single real character is a text, non-breaking space or not.
        $this->assertFalse(AssetConditions::isBlank("<p>\u{00A0}x\u{00A0}</p>"));
    }

    /**
     * An image is content. `strip_tags()` erases it, so asking the text-only
     * question alone would read a conditions block made of one scanned page
     * as nothing at all — and serve the shipped standard over somebody's own
     * terms without a word.
     */
    public function testAnImageIsNotNothing(): void
    {
        $image = '<p><img src="/files/12" alt="Nos conditions"></p>';

        $this->assertFalse(AssetConditions::isBlank($image));

        $this->store->set(AssetConditions::key(42), $image, 'rich_text', 1);
        $this->assertStringContainsString('<img', AssetConditions::textFor($this->store, 42));
    }

    public function testConditionsArePerAsset(): void
    {
        $this->store->set(AssetConditions::key(42), '<p>Celles du local.</p>', 'rich_text', 1);

        $this->assertSame('<p>Celles du local.</p>', AssetConditions::textFor($this->store, 42));
        $this->assertSame(StandardTemplates::conditions(), AssetConditions::textFor($this->store, 43));
    }

    /**
     * The key is the one the public page has always read, so a unit that
     * already wrote its conditions keeps them and nothing is migrated.
     */
    public function testTheStorageKeyIsUnchanged(): void
    {
        $this->assertSame('rental_asset_42_conditions', AssetConditions::key(42));
    }

    public function testTheStandardIsRecognisedAsSuchEvenAfterTheSanitizerReflowedIt(): void
    {
        $this->store->set(AssetConditions::key(42), StandardTemplates::conditions(), 'rich_text', 1);

        $this->assertTrue(AssetConditions::isStandard(AssetConditions::textFor($this->store, 42)));
    }

    public function testACustomisedTextIsNotTheStandard(): void
    {
        $this->assertFalse(AssetConditions::isStandard('<p>Le local est rendu balayé.</p>'));
    }

    /**
     * **The guarantee is carried on the way out, not on the way in.**
     *
     * `RentalPricingController::saveConditions()` is the managers' write
     * path, not the only one: `POST /api/editable-content` is keyed by
     * nothing, so a superadmin in configuration mode reaches this content
     * like any other and can write a blank body straight past that guard.
     *
     * This writes exactly what that endpoint would — `set()` on the key,
     * no rental code in the way — and asserts that what a renter is shown
     * is still a complete text. The checkbox cannot stand over nothing
     * however the row was written, which is the property worth having; a
     * registry of protected keys in core would only defend the one door
     * that already has a lock.
     */
    public function testAConditionsRowBlankedOutsideTheModuleStillReadsAsTheStandard(): void
    {
        $this->store->set(AssetConditions::key(42), '<p>Le local est rendu balayé.</p>', 'rich_text', 1);

        // The generic admin endpoint, doing the one thing the module's own
        // route refuses.
        $this->store->set(AssetConditions::key(42), '<p>&nbsp;</p>', 'rich_text', 1);

        $this->assertSame(StandardTemplates::conditions(), AssetConditions::textFor($this->store, 42));
    }
}
