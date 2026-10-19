<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\Step\Check\ConfirmRegistrationsStepCheck;
use Modules\Registration\Api\RegistrationRequestCounts;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 3: pending requests and accepted ones without an intended section
 * are points to check; the switch veto stays a separate reminder that
 * never makes this step a point or a block.
 */
class ConfirmRegistrationsStepCheckTest extends TestCase
{
    private function admin(int $blocking): ScoutYearAdminService
    {
        $admin = $this->createStub(ScoutYearAdminService::class);
        $admin->method('countBlockingRegistrationRequests')->willReturn($blocking);

        return $admin;
    }

    public function testPendingAndAcceptedWithoutSectionAreToCheck(): void
    {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('requestCounts')->willReturn(new RegistrationRequestCounts(3, 4, 2));

        $check = (new ConfirmRegistrationsStepCheck($this->admin(7), $review))->check(CheckFixtures::context());

        $this->assertSame(['3 demandes en attente', '2 demandes acceptées sans section prévue'], $check->pointsToCheck);
        $this->assertSame(
            ['blocking_request_count' => 7, 'pending' => 3, 'accepted' => 4, 'accepted_without_section' => 2],
            $check->details
        );
        $this->assertNull($check->markRefusal);
    }

    public function testNothingWaitingIsNoPointEvenWithTheVetoStanding(): void
    {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('requestCounts')->willReturn(new RegistrationRequestCounts(0, 1, 0));

        $check = (new ConfirmRegistrationsStepCheck($this->admin(1), $review))->check(CheckFixtures::context());

        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame(1, $check->details['blocking_request_count']);
    }

    public function testASingleRequestIsSaidInTheSingular(): void
    {
        $review = $this->createStub(ScoutYearRegistrationReviewProvider::class);
        $review->method('requestCounts')->willReturn(new RegistrationRequestCounts(1, 1, 1));

        $check = (new ConfirmRegistrationsStepCheck($this->admin(0), $review))->check(CheckFixtures::context());

        $this->assertSame(['1 demande en attente', '1 demande acceptée sans section prévue'], $check->pointsToCheck);
    }
}
