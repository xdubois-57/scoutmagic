<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Service\ReenrollmentSavePlan;
use Modules\Registration\Service\ReenrollmentSavePlanPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The words of the confirmation (issue #796): when no e-mail leaves, the
 * dialogue says so and says why — whichever the reason.
 */
class ReenrollmentSavePlanPresenterTest extends TestCase
{
    private function planWithout(string $reason, bool $closing = false): ReenrollmentSavePlan
    {
        return new ReenrollmentSavePlan(
            [['setting' => 'is_open', 'from' => '1', 'to' => '0']],
            null,
            $closing ? ['campaign' => '2027-05-15'] : null,
            [],
            $reason
        );
    }

    public function testNobodyToWriteToIsNotAnnouncedAsAnEmail(): void
    {
        $dialog = (new ReenrollmentSavePlanPresenter())->dialog(
            $this->planWithout(ReenrollmentSavePlan::REASON_NO_FAMILIES, true),
            new \DateTimeImmutable('2027-04-20')
        );

        $this->assertNull($dialog['mail']);
        $this->assertFalse($dialog['sends']);
        $this->assertSame('Aucun e-mail ne partira.', $dialog['none']['headline'] ?? null);
        $this->assertStringContainsString('Aucune famille', $dialog['none']['reason'] ?? '');
        $this->assertSame('Enregistrer', $dialog['confirm_label']);
    }

    public function testAnAlreadySentEmailOfNoParticularKindIsNotCalledTheOpening(): void
    {
        // A reminder due today that has already left: neither the opening
        // nor the closing is in play.
        $reason = (new ReenrollmentSavePlanPresenter())->dialog(
            $this->planWithout(ReenrollmentSavePlan::REASON_ALREADY_SENT),
            new \DateTimeImmutable('2027-04-20')
        )['none']['reason'] ?? '';

        $this->assertStringContainsString('déjà parti', $reason);
        $this->assertStringNotContainsString("d'ouverture", $reason);
        $this->assertStringNotContainsString('de clôture', $reason);
    }
}
