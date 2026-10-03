<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * The three lines under a message on a card — « Vu par … », the reaction
 * row, and the « Commenter » disclosure — and the vertical rhythm between
 * them on a phone (issue #710).
 *
 * Each of the three already stands in a 44px touch target on a coarse
 * pointer: app.css's `pointer: coarse` block gives it to the two buttons,
 * `.groups-thread-summary`'s own min-height to the third. A 44px box
 * around a 0.75rem line IS the breathing room, and the `mt-2` each row
 * used to carry stacked on top of it — three rows of about 52px, which is
 * what the reporter described as « un espace vertical excessif » on a
 * phone against a compact desktop.
 *
 * What this pins is that the fix stayed a SPACING fix. The margin moved
 * out of the Bootstrap utility and onto one class, so a media query can
 * set it to zero; nothing anywhere was made shorter, which is the
 * regression a « c'est plus compact maintenant » screenshot would never
 * catch. Reads the raw files, same precedent as
 * Tests\Core\View\GroupsReactionToggleCssTest and
 * Tests\Core\View\TouchTargetCssTest: there is no browser in PHPUnit, and
 * what has to keep agreeing here lives in four files at once.
 */
final class GroupsPostFooterSpacingCssTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $components;
    private string $app;
    private string $card;
    private string $reactions;

    protected function setUp(): void
    {
        $this->components = (string) file_get_contents(self::ROOT . '/public/assets/css/components.css');
        $this->app = (string) file_get_contents(self::ROOT . '/public/assets/css/app.css');
        $this->card = (string) file_get_contents(
            self::ROOT . '/modules/groups/views/partials/post_card.html.twig'
        );
        $this->reactions = (string) file_get_contents(
            self::ROOT . '/modules/groups/views/partials/reactions.html.twig'
        );
    }

    /**
     * One class carries the half-rem, so one media query can take it
     * back. A `mt-2` is `margin-top: .5rem !important`, which no ordinary
     * rule can undo — overriding it would have meant a second
     * `!important`, which is why the utility had to go rather than be
     * beaten.
     */
    public function testTheFooterRhythmIsOwnedByOneClassAndIsZeroOnACoarsePointer(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.groups-post-action-line \{\s*margin-top:\s*0\.5rem;\s*\}/',
            $this->components,
            '.groups-post-action-line no longer declares the half-rem the three footer rows used to '
            . 'get from `mt-2`, so the desktop card lost the spacing issue #710 asked to keep.',
        );

        $this->assertMatchesRegularExpression(
            '/@media \(pointer: coarse\) \{\s*\.groups-post-action-line \{\s*margin-top:\s*0;\s*\}\s*\}/',
            $this->components,
            'the coarse-pointer rule that zeroes the footer margins is gone, so the three 44px rows '
            . 'stack their half-rems again — issue #710 as reported.',
        );
    }

    /**
     * Each row's class list is pinned WHOLE, not merely searched for the
     * new class. Both halves matter and a containment check would only
     * see one of them: the row has to carry `.groups-post-action-line`,
     * and it must not carry `mt-2` beside it — the utility is
     * `margin-top: .5rem !important`, so a row keeping both would beat
     * the media query outright and the fix would silently do nothing.
     */
    public function testEachFooterRowCarriesTheSpacingClassAndNoMarginUtility(): void
    {
        $this->assertMatchesRegularExpression(
            '/<p class="mb-0 groups-post-action-line"/',
            $this->card,
            'the « Vu par » line\'s classes are no longer exactly `mb-0 groups-post-action-line`: it '
            . 'either lost .groups-post-action-line, or gained a margin utility beside it that '
            . '`!important` would make the coarse-pointer rule powerless against.',
        );
        $this->assertMatchesRegularExpression(
            '/<details class="groups-thread groups-post-action-line"/',
            $this->card,
            'the « Commenter » disclosure\'s classes are no longer exactly `groups-thread '
            . 'groups-post-action-line` — same two possibilities, same consequence.',
        );
        $this->assertStringContainsString(
            "compact ? ' groups-reactions-compact' : ' groups-post-action-line'",
            $this->reactions,
            'the reaction row no longer picks .groups-post-action-line for a card and '
            . '.groups-reactions-compact for a reply, so one of the two lost its spacing.',
        );
    }

    /**
     * The part a spacing change must not quietly pay for. AGENTS.md
     * treats 44px as a comfort goal for small controls, implemented
     * centrally in app.css's `pointer: coarse` block — never by an inline
     * style and never, here, by shrinking a row to close a gap.
     */
    public function testTheThreeRowsKeepTheirFortyFourPixelTouchTargets(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(pointer: coarse\) \{.*\.btn \{\s*min-height:\s*44px;/s',
            $this->app,
            'app.css no longer gives a .btn its 44px on a coarse pointer, which is where both the '
            . '« Vu par » line and the reaction tally get theirs.',
        );
        $this->assertMatchesRegularExpression(
            '/\.groups-thread-summary \{[^}]*min-height:\s*44px;/',
            $this->components,
            'the « Commenter » line lost the min-height that makes the whole row tappable.',
        );
        $this->assertMatchesRegularExpression(
            '/\.groups-js \.groups-reaction-toggle \{[^}]*min-height:\s*44px;/',
            $this->components,
            'the « Réagir » toggle lost its 44px, so compacting the footer shortened a target '
            . 'instead of closing a margin.',
        );
    }

    /**
     * A 44px box only reads as deliberate when the line sits in the
     * middle of it. The button is `p-0`, so without an explicit
     * cross-axis centring its text rides the top of the box and the gap
     * it was supposed to close reappears underneath the text.
     */
    public function testTheSeenByLineIsCentredInsideItsTouchTarget(): void
    {
        $this->assertMatchesRegularExpression(
            '/class="btn btn-link p-0 border-0 d-inline-flex align-items-center[^"]*groups-seen-by"/',
            $this->card,
            'the « Vu par » button is not centred in its own touch target any more, so its text sits '
            . 'at the top of a 44px box — the alignment half of issue #710.',
        );
    }

    /**
     * A reply has no « Vu par » and no thread line, so its reaction row
     * has nothing to stack against and keeps its half-rem at every
     * pointer. Issue #710 asked for the card, and only the card.
     */
    public function testAReplysReactionRowKeepsItsSpacingAtEveryPointer(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.groups-reactions-compact \{\s*margin-top:\s*0\.5rem;\s*\}/',
            $this->components,
            'the reply variant lost the half-rem it used to get from `mt-2` on the shared partial, so '
            . 'removing the utility moved a reply as well as a card.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/@media \(pointer: coarse\) \{\s*\.groups-reactions-compact \{/',
            $this->components,
            'a reply\'s reaction row is now compacted on a phone too, which issue #710 did not ask '
            . 'for and which no reporter described.',
        );
    }
}
