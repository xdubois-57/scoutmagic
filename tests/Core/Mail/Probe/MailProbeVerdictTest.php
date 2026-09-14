<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Probe;

use Core\Mail\Probe\MailProbeVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The three answers a probe can come back with (roadmap IT-04).
 *
 * A small enum, and the one place in this feature where a wrong word
 * does real damage: the sentence shown next to « Indésirables » asks the
 * operator NOT to rescue the message, and somebody tidying up the copy
 * would delete it as a redundancy. It is not one. Marking a message as
 * legitimate is a positive engagement signal, so it teaches the receiver
 * something about the next message — the instrument would then be
 * causing what it measures, and the following reading would be worth
 * nothing while looking exactly as trustworthy.
 */
class MailProbeVerdictTest extends TestCase
{
    public function testEveryVerdictSaysSomethingOfItsOwn(): void
    {
        $labels = [];
        $guidance = [];

        foreach (MailProbeVerdict::cases() as $verdict) {
            $this->assertNotSame('', $verdict->label());
            $this->assertNotSame('', $verdict->guidance());
            $labels[] = $verdict->label();
            $guidance[] = $verdict->guidance();
        }

        $this->assertSame($labels, array_unique($labels), 'two verdicts reading the same are one verdict.');
        $this->assertSame($guidance, array_unique($guidance));
    }

    /**
     * The one sentence this enum exists to carry. « Jamais reçu » and
     * « Réception » are self-evident; « laissez-le où il est » is the
     * opposite of what anybody's instinct says, which is why it is
     * written down and why it is pinned here.
     */
    public function testTheSpamVerdictAsksTheOperatorToLeaveTheMessageWhereItIs(): void
    {
        $guidance = MailProbeVerdict::Spam->guidance();

        $this->assertStringContainsString('Laissez le message dans les indésirables', $guidance);
        $this->assertStringContainsString('fausserait la mesure', $guidance);
    }

    /**
     * `text-bg-*`, never `bg-danger` or a hand-picked colour: the layout
     * maps these and `tests/Core/View/UxConventionsTest.php` fails the
     * build on anything else.
     */
    public function testEveryBadgeIsASemanticBootstrapClass(): void
    {
        foreach (MailProbeVerdict::cases() as $verdict) {
            $this->assertStringStartsWith('text-bg-', $verdict->badge());
        }

        // Green for the one outcome that needs no action, and red for
        // the one where nothing arrived at all: a reader scanning the
        // history column reads the colour before the word.
        $this->assertSame('text-bg-success', MailProbeVerdict::Inbox->badge());
        $this->assertSame('text-bg-danger', MailProbeVerdict::Never->badge());
    }

    public function testTheScreenOffersEveryVerdictExactlyOnce(): void
    {
        $ordered = MailProbeVerdict::ordered();

        $this->assertSame(MailProbeVerdict::cases(), $ordered, 'a case missing here is a case nobody can record.');
        $this->assertSame(MailProbeVerdict::Inbox, $ordered[0], 'the answer we hope for comes first.');
    }

    /**
     * A form field, so the value arrives as whatever the browser sent —
     * and a stray space is a typing accident, not a different answer.
     */
    public function testAVerdictIsReadThroughItsSurroundingSpace(): void
    {
        $this->assertSame(MailProbeVerdict::Spam, MailProbeVerdict::tryFromInput('  spam '));
        $this->assertSame(MailProbeVerdict::Inbox, MailProbeVerdict::tryFromInput("inbox\n"));
    }

    /**
     * Null, and never a default. A verdict the site chose on the
     * operator's behalf would sit in the history looking exactly like
     * one somebody went and checked.
     */
    public function testAnythingElseIsNoVerdictAtAll(): void
    {
        foreach (['', ' ', 'perdu', 'INBOX', 'inbox2', '0', 'null'] as $value) {
            $this->assertNull(
                MailProbeVerdict::tryFromInput($value),
                "« {$value} » must not resolve to a verdict."
            );
        }
    }
}
