<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use PHPUnit\Framework\TestCase;

/**
 * The member sheet's two columns carry comparable amounts of card.
 *
 * The page grew card by card, and every new one went into the right-hand
 * column because that is where the previous one was: it ended up holding
 * nine of the fourteen while the left held five, and the sheet read as one
 * long column beside a short one.
 *
 * A mechanical guard rather than a note in a docblock, on the precedent
 * Tests\Core\View\UxConventionsTest sets: the next card will be added by
 * somebody who has not read this file, and "put it in the emptier column"
 * is a rule only a test can actually apply.
 *
 * **It counts cards, not pixels.** Height is what a reader sees, and no
 * test can know it — a card's height depends on the member. What a count
 * does catch is the drift that produced the imbalance in the first place,
 * which is all it claims to.
 */
final class MemberSheetLayoutTest extends TestCase
{
    /**
     * The most one column may hold beyond the other.
     *
     * Not zero: several cards are conditional, so an exactly equal split
     * would be a coincidence rather than a rule, and a test demanding one
     * would be edited away the first time it failed.
     */
    private const MAX_DIFFERENCE = 1;

    private const COLUMN_OPEN = '<div class="col-12 col-lg-6">';

    /** The full-width card that closes the page, below both columns. */
    private const AFTER_COLUMNS = '<section class="card mt-3 border-2">';

    private static function template(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/admin/members/show.html.twig'
        );
        self::assertNotFalse($source);

        return $source;
    }

    /**
     * @return array{0: int, 1: int} cards in the left column, then the right
     */
    private static function cardsPerColumn(): array
    {
        $template = self::template();

        $left = strpos($template, self::COLUMN_OPEN);
        self::assertIsInt($left, 'the sheet no longer opens with a two-column row');
        $right = strpos($template, self::COLUMN_OPEN, $left + 1);
        self::assertIsInt($right, 'the sheet no longer has a second column');
        $end = strpos($template, self::AFTER_COLUMNS, $right);
        self::assertIsInt($end, 'the full-width card below the columns has moved or been renamed');

        return [
            substr_count(substr($template, $left, $right - $left), '<section class="card'),
            substr_count(substr($template, $right, $end - $right), '<section class="card'),
        ];
    }

    public function testNeitherColumnCarriesTheWholePage(): void
    {
        [$left, $right] = self::cardsPerColumn();

        $this->assertGreaterThan(0, $left);
        $this->assertGreaterThan(0, $right);
        $this->assertLessThanOrEqual(
            self::MAX_DIFFERENCE,
            abs($left - $right),
            "The member sheet's columns hold {$left} and {$right} cards. Put a new card in the emptier "
            . 'column rather than under the last one you wrote.'
        );
    }
}
