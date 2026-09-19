<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/css/components.css's « Réagir » toggle, read against the
 * « Commenter » line it sits above — the two things you can do to a
 * message on a card, which a reader takes in together.
 *
 * Issue #361 reported them as visibly different, and both halves were
 * real: a font size nobody had chosen against the other, and Bootstrap's
 * `.btn-sm` left padding acting as the row's left edge whenever the
 * reaction tally that normally sits first was absent — which is why the
 * button looked indented on a message with no reactions and correct on
 * one with some. That conditional is exactly what a structural test can
 * hold and a screenshot cannot.
 *
 * Reads the raw CSS, same precedent as
 * Tests\Core\View\BreadcrumbBarCssTest: there is no JS and no server-side
 * branch here, only two declarations that have to keep agreeing with a
 * third one further down the file.
 */
final class GroupsReactionToggleCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(
            dirname(__DIR__, 3) . '/public/assets/css/components.css'
        );
    }

    /**
     * The mobile toggle's own block — the only place it is ever visible,
     * since the base rule is `display: none` and the media query is what
     * turns it on.
     */
    private function toggleRule(): string
    {
        $matched = preg_match(
            '/\.groups-js \.groups-reaction-toggle \{(?P<body>[^}]*)\}/',
            $this->css,
            $matches
        );

        $this->assertSame(1, $matched, '.groups-js .groups-reaction-toggle has no rule of its own any more');

        return $matches['body'];
    }

    /**
     * The size the summary line uses, rather than a second opinion about
     * what "small" means.
     */
    public function testTheToggleIsSetInTheSameSizeAsTheCommenterLine(): void
    {
        $this->assertMatchesRegularExpression(
            '/font-size:\s*0\.875rem;/',
            $this->toggleRule(),
            'the « Réagir » toggle no longer matches .groups-thread-summary\'s 0.875rem, which is the '
            . 'half of issue #361 a reader described as « écrit en plus petit ».',
        );

        $this->assertMatchesRegularExpression(
            '/\.groups-thread-summary \{[^}]*font-size:\s*0\.875rem;/',
            $this->css,
            '.groups-thread-summary is no longer 0.875rem, so the toggle above now matches nothing — '
            . 'whichever of the two moved, they have to move together.',
        );
    }

    /**
     * The alignment half. Bootstrap's `.btn-sm` brings `padding: 0.25rem
     * 0.5rem`, and that left half-rem is what has to be given back: with
     * no tally in the row the toggle IS the row's left edge, and the
     * summary line below has no padding at all.
     */
    public function testTheToggleGivesBackBootstrapsLeftPaddingSoItStartsFlush(): void
    {
        $this->assertMatchesRegularExpression(
            '/padding-left:\s*0;/',
            $this->toggleRule(),
            'the « Réagir » toggle keeps Bootstrap\'s .btn-sm left padding again, so on a message with '
            . 'no reactions yet it sits 8px right of « Commenter » — issue #361\'s « aligné plus à '
            . 'droite », which the reporter saw only when no reaction existed.',
        );
    }

    /**
     * The touch target must survive the padding going away. AGENTS.md
     * § CSS / frontend treats 44px as a comfort goal for small controls
     * — « never a universal minimum » — and this toggle is exactly the
     * kind it names: a `.btn-sm` on a phone. What matters here is which
     * declaration carries it, and it is `min-height`, never the
     * horizontal padding this test just gave back.
     */
    public function testTheTouchTargetStillHoldsWithoutThatPadding(): void
    {
        $this->assertMatchesRegularExpression(
            '/min-height:\s*44px;/',
            $this->toggleRule(),
            'the « Réagir » toggle lost its 44px comfort-goal touch target at the same time as its '
            . 'left padding, which turns a spacing fix into an accessibility regression.',
        );
    }
}
