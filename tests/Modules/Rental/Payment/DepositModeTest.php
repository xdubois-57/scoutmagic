<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Payment;

use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Payment\SecurityDepositStatus;
use PHPUnit\Framework\TestCase;

/**
 * How a deposit and a security deposit are worked out (§6.19, §6.20).
 *
 * Pure arithmetic, so pinned exhaustively: these are the numbers a renter is
 * asked to transfer, and a rounding slip here is a threshold that never quite
 * gets reached.
 */
class DepositModeTest extends TestCase
{
    public function testNoDepositMeansNoThreshold(): void
    {
        $this->assertNull(DepositMode::NONE->amountFor(46750, 10000, 30));
    }

    public function testAFixedDepositIsTakenAsTyped(): void
    {
        $this->assertSame(10000, DepositMode::FIXED->amountFor(46750, 10000, null));
    }

    public function testAPercentageIsComputedOnTheTotal(): void
    {
        $this->assertSame(14025, DepositMode::PERCENTAGE->amountFor(46750, null, 30));
    }

    public function testAPercentageRoundsUpSoTheThresholdIsAlwaysReachable(): void
    {
        // Rounding down would leave a threshold a renter can reach while
        // still owing a cent, and "acompte reçu" would flip on a payment
        // that is a cent short.
        $this->assertSame(3301, DepositMode::PERCENTAGE->amountFor(10001, null, 33));
    }

    public function testADepositNeverExceedsTheTotal(): void
    {
        // A 100 % deposit is the whole rental; a fixed amount typed above
        // the total must not ask for more than the rental is worth.
        $this->assertSame(46750, DepositMode::FIXED->amountFor(46750, 99999, null));
        $this->assertSame(46750, DepositMode::PERCENTAGE->amountFor(46750, null, 100));
    }

    public function testAnOutOfRangePercentageIsClampedRatherThanTrusted(): void
    {
        $this->assertSame(46750, DepositMode::PERCENTAGE->amountFor(46750, null, 250));
        $this->assertSame(0, DepositMode::PERCENTAGE->amountFor(46750, null, -10));
    }

    public function testAModeWithNothingConfiguredYieldsNothing(): void
    {
        $this->assertNull(DepositMode::FIXED->amountFor(46750, null, null));
        $this->assertNull(DepositMode::PERCENTAGE->amountFor(46750, 10000, null));
    }

    public function testEveryModeHasAFrenchLabel(): void
    {
        foreach (DepositMode::cases() as $mode) {
            $this->assertNotSame('', $mode->label());
        }
    }

    // ── PaymentSettings ─────────────────────────────────────────────────

    public function testReceivablesNeedBothTheSwitchAndAnAccount(): void
    {
        // Half-configured is worse than off: an asset that says "payments
        // on" but can raise nothing looks like it is working.
        $this->assertFalse((new PaymentSettings(enabled: true))->canRaiseReceivables());
        $this->assertFalse((new PaymentSettings(financeAccountId: 3))->canRaiseReceivables());
        $this->assertTrue((new PaymentSettings(enabled: true, financeAccountId: 3))->canRaiseReceivables());
    }

    public function testASecurityDepositOfZeroIsNoSecurityDeposit(): void
    {
        $settings = new PaymentSettings(securityDepositEnabled: true, securityDepositAmountCents: 0);

        $this->assertNull($settings->securityDepositAmount());
    }

    public function testASecurityDepositIsIndependentOfTheRentalTotal(): void
    {
        // It covers what a group might break, which has nothing to do with
        // what they are paying to be there.
        $settings = new PaymentSettings(securityDepositEnabled: true, securityDepositAmountCents: 50000);

        $this->assertSame(50000, $settings->securityDepositAmount());
    }

    public function testADueDateIsCountedInDaysFromTheGivenMoment(): void
    {
        $this->assertSame(
            '2027-03-15',
            PaymentSettings::dueDate(14, new \DateTimeImmutable('2027-03-01'))
        );
        $this->assertNull(PaymentSettings::dueDate(null, new \DateTimeImmutable('2027-03-01')));
    }

    // ── SecurityDepositStatus ───────────────────────────────────────────

    public function testTheReturnStatusIsDerivedFromTheAmountsRatherThanChosen(): void
    {
        // A manager who typed the amounts has already said what happened;
        // asking them to also pick the label is how the two disagree.
        $this->assertSame(SecurityDepositStatus::RETURNED, SecurityDepositStatus::afterReturn(50000, 50000));
        $this->assertSame(SecurityDepositStatus::PARTIALLY_WITHHELD, SecurityDepositStatus::afterReturn(50000, 30000));
        $this->assertSame(SecurityDepositStatus::FULLY_WITHHELD, SecurityDepositStatus::afterReturn(50000, 0));
    }

    public function testReturningMoreThanHeldStillCountsAsFullyReturned(): void
    {
        $this->assertSame(SecurityDepositStatus::RETURNED, SecurityDepositStatus::afterReturn(50000, 60000));
    }

    public function testHoldingAndSettlingAreDistinctQuestions(): void
    {
        $this->assertTrue(SecurityDepositStatus::RECEIVED->isHeld());
        $this->assertTrue(SecurityDepositStatus::TO_RETURN->isHeld());
        $this->assertFalse(SecurityDepositStatus::TO_RECEIVE->isHeld());
        $this->assertFalse(SecurityDepositStatus::RETURNED->isHeld());

        $this->assertTrue(SecurityDepositStatus::RETURNED->isSettled());
        $this->assertTrue(SecurityDepositStatus::PARTIALLY_WITHHELD->isSettled());
        $this->assertTrue(SecurityDepositStatus::FULLY_WITHHELD->isSettled());
        $this->assertFalse(SecurityDepositStatus::RECEIVED->isSettled());
    }

    public function testEveryStatusHasAFrenchLabel(): void
    {
        foreach (SecurityDepositStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }
}
