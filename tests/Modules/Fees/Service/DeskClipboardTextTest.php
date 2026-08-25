<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Service\DeskClipboardText;
use Modules\Fees\Value\HouseholdReview;
use Modules\Fees\Value\HouseholdReviewMember;
use PHPUnit\Framework\TestCase;

/**
 * The block « Copier pour Desk » puts in the clipboard — plain text, one
 * line per person, no formatting decision.
 */
class DeskClipboardTextTest extends TestCase
{
    private function member(
        string $first,
        ?string $encodedLabel,
        ?HouseholdFeeCategory $encoded,
        bool $comparable = true
    ): HouseholdReviewMember {
        return new HouseholdReviewMember(
            1,
            1,
            $first,
            'DUPONT',
            null,
            $encoded === null ? null : 7,
            $encodedLabel,
            $encoded,
            $comparable,
            false,
            null
        );
    }

    /** @param HouseholdReviewMember[] $members */
    private function review(array $members, HouseholdFeeCategory $expected): HouseholdReview
    {
        return new HouseholdReview(
            'abc',
            'Rue de la Station 5, 1000 Bruxelles',
            $members,
            count($members),
            count($members),
            $expected,
            $expected,
            true,
            false,
            null,
            null,
            [],
            0
        );
    }

    public function testItLeadsWithTheAddressAndTheCountSentence(): void
    {
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('Jean', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
            $this->member('Marie', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
            $this->member('Léa', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
        ], HouseholdFeeCategory::FAMILY));

        $lines = explode("\n", $text);
        $this->assertSame('Rue de la Station 5, 1000 Bruxelles', $lines[0]);
        $this->assertSame('3 membre(s) dans Desk — tarif attendu : Famille', $lines[1]);
    }

    public function testAMemberToCorrectCarriesTheArrow(): void
    {
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('Jean', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
        ], HouseholdFeeCategory::COUPLE));

        $this->assertStringContainsString('Jean Dupont : Cotisation normale → Couple', $text);
    }

    public function testAConformingMemberIsMarkedAsSuchRatherThanOmitted(): void
    {
        // The whole household goes to the clipboard: a treasurer editing in
        // Desk needs to see who is already right, not guess at it.
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('Jean', 'Cotisation couple', HouseholdFeeCategory::COUPLE),
            $this->member('Marie', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
        ], HouseholdFeeCategory::COUPLE));

        $this->assertStringContainsString('Jean Dupont : Cotisation couple (conforme)', $text);
        $this->assertStringContainsString('Marie Dupont : Cotisation normale → Couple', $text);
    }

    public function testAMemberOutsideTheThreeTariffsIsSaidToBeOutsideThem(): void
    {
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('Sophie', 'Tarif animateur', null, comparable: false),
        ], HouseholdFeeCategory::NORMAL));

        $this->assertStringContainsString('Sophie Dupont : Tarif animateur (hors tarif de foyer, non comparé)', $text);
    }

    public function testAMemberWithNoTariffAtAllSaysSoRatherThanShowingNothing(): void
    {
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('Jean', null, null, comparable: false),
        ], HouseholdFeeCategory::NORMAL));

        $this->assertStringContainsString('Jean Dupont : aucun tarif', $text);
    }

    /**
     * Desk-imported names are frequently ALL CAPS; the site's own name
     * normalisation is what the rest of the interface shows, and the block
     * has no business disagreeing with it.
     */
    public function testNamesAreNormalisedTheWayTheRestOfTheSiteShowsThem(): void
    {
        $text = DeskClipboardText::forHousehold($this->review([
            $this->member('jean-pierre', 'Cotisation normale', HouseholdFeeCategory::NORMAL),
        ], HouseholdFeeCategory::NORMAL));

        $this->assertStringContainsString('Jean-Pierre Dupont', $text);
    }
}
