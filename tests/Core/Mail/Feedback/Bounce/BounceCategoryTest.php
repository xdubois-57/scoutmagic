<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback\Bounce;

use Core\Mail\Feedback\Bounce\BounceCategory;
use PHPUnit\Framework\TestCase;

/**
 * The words the member reads, and the one promise they must not make.
 */
class BounceCategoryTest extends TestCase
{
    /**
     * **« Ci-dessous » names a button that is usually not there.**
     *
     * Three of these used to end in « réactivez l'adresse ci-dessous »,
     * while the control that reactivates renders only once the address is
     * blocked. So a first permanent failure — and *every* transient one,
     * which by design can never block, since only `Permanent` increments
     * the counter — pointed the member at something absent. The same
     * sentence also travelled in the non-blocking push notification, whose
     * payload carries `'url' => null`: there is no « below » on a lock
     * screen.
     */
    public function testNoGuidancePointsAtAControlThatMayNotBeThere(): void
    {
        foreach (BounceCategory::cases() as $category) {
            $this->assertStringNotContainsString(
                'ci-dessous',
                $category->guidance(),
                $category->name . ': guidance is shown whether or not the address is blocked, '
                . 'so it cannot name the reactivate button.'
            );
            $this->assertNotSame('', $category->guidance(), $category->name . ' still has to say something.');
        }
    }

    /**
     * The invitation exists, and lives where the button does.
     */
    public function testTheReactivationHintIsOfferedWhereverRetryingCanWork(): void
    {
        foreach ([BounceCategory::MailboxFull, BounceCategory::NoSuchAddress, BounceCategory::Refused] as $category) {
            $hint = $category->reactivationHint();
            $this->assertNotNull($hint, $category->name . ' is something the member can fix, then retry.');
            $this->assertStringContainsString('ci-dessous', $hint);
        }
    }

    /**
     * **And not for `Unreachable`**, which is not an omission: nothing the
     * member does fixes their provider's server being unreachable, so
     * inviting them to retry would be inviting them to fail again. Its
     * guidance already sends them to the one place that can help.
     */
    public function testAnUnreachableServerInvitesNoRetryTheMemberCannotWin(): void
    {
        $this->assertNull(BounceCategory::Unreachable->reactivationHint());
        $this->assertStringContainsString(
            'fournisseur',
            BounceCategory::Unreachable->guidance()
        );
    }
}
