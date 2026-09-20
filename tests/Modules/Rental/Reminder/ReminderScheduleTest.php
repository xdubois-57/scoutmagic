<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Reminder;

use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Reminder\ReminderSchedule;
use PHPUnit\Framework\TestCase;

/**
 * The three levels, and which one wins (§6.29, §22.11).
 *
 * Shipped value, then the unit's default, then the asset's own — and the
 * distinction this class exists to keep apart: **an empty delay means
 * « prends le défaut », never « jamais »**. Folding the two into one
 * nullable number is how 0 — the day itself — and "never" end up one typo
 * apart, so "off" is a separate flag and tested as one.
 */
class ReminderScheduleTest extends TestCase
{
    public function testTheShippedScheduleIsWhatTheEnumSays(): void
    {
        $schedule = ReminderSchedule::shipped();

        foreach (ReminderKind::cases() as $kind) {
            $this->assertSame($kind->defaultDays(), $schedule->daysFor($kind), $kind->value);
            $this->assertTrue($schedule->isActive($kind), $kind->value);
            $this->assertFalse($schedule->isOverridden($kind), $kind->value);
        }
    }

    public function testTheUnitsDefaultReplacesTheShippedValue(): void
    {
        $schedule = ReminderSchedule::of([ReminderKind::UNANSWERED_REQUEST->value => 10]);

        $this->assertSame(10, $schedule->daysFor(ReminderKind::UNANSWERED_REQUEST));
        $this->assertSame(10, $schedule->defaultDaysFor(ReminderKind::UNANSWERED_REQUEST));
    }

    /**
     * The unit's number is still the one the form writes under an empty
     * field, so what a manager reads is what would actually apply.
     */
    public function testAnAssetsOwnValueWinsWithoutHidingWhatItOverrides(): void
    {
        $schedule = ReminderSchedule::of(
            [ReminderKind::UNANSWERED_REQUEST->value => 10],
            [ReminderKind::UNANSWERED_REQUEST->value => ['days' => 2, 'active' => true]]
        );

        $this->assertSame(2, $schedule->daysFor(ReminderKind::UNANSWERED_REQUEST));
        $this->assertSame(10, $schedule->defaultDaysFor(ReminderKind::UNANSWERED_REQUEST));
        $this->assertTrue($schedule->isOverridden(ReminderKind::UNANSWERED_REQUEST));
    }

    public function testAnEmptyDelayInheritsRatherThanSwitchingOff(): void
    {
        $schedule = ReminderSchedule::of(
            [ReminderKind::UNANSWERED_REQUEST->value => 10],
            [ReminderKind::UNANSWERED_REQUEST->value => ['days' => null, 'active' => true]]
        );

        $this->assertSame(10, $schedule->daysFor(ReminderKind::UNANSWERED_REQUEST));
        $this->assertFalse($schedule->isOverridden(ReminderKind::UNANSWERED_REQUEST));
        $this->assertTrue($schedule->isActive(ReminderKind::UNANSWERED_REQUEST));
    }

    /**
     * A reminder off with no delay of its own still inherits a delay, which
     * is what lets switching it back on restore the unit's value rather
     * than whatever number happened to be in the field.
     */
    public function testSwitchingAReminderOffLeavesItsDelayAlone(): void
    {
        $schedule = ReminderSchedule::of([], [
            ReminderKind::ARRIVAL_INVENTORY->value => ['days' => null, 'active' => false],
        ]);

        $this->assertFalse($schedule->isActive(ReminderKind::ARRIVAL_INVENTORY));
        $this->assertSame(
            ReminderKind::ARRIVAL_INVENTORY->defaultDays(),
            $schedule->daysFor(ReminderKind::ARRIVAL_INVENTORY)
        );
    }

    public function testZeroIsADelayAndNotAnAbsentOne(): void
    {
        $schedule = ReminderSchedule::of([], [
            ReminderKind::UNANSWERED_REQUEST->value => ['days' => 0, 'active' => true],
        ]);

        $this->assertSame(0, $schedule->daysFor(ReminderKind::UNANSWERED_REQUEST));
        $this->assertTrue($schedule->isOverridden(ReminderKind::UNANSWERED_REQUEST));
    }

    public function testANegativeDelayIsReadAsTheDayItself(): void
    {
        $schedule = ReminderSchedule::of(
            [ReminderKind::SETTLEMENT_DUE->value => -3],
            [ReminderKind::BALANCE_MISSING->value => ['days' => -1, 'active' => true]]
        );

        $this->assertSame(0, $schedule->daysFor(ReminderKind::SETTLEMENT_DUE));
        $this->assertSame(0, $schedule->daysFor(ReminderKind::BALANCE_MISSING));
    }

    /**
     * Only the money reminders repeat, and the contract's second chance is
     * derived from the delay in force rather than fixed — which is what
     * keeps a unit that shortened its lead time to five days from getting
     * the two sends on top of each other.
     */
    public function testOnlyTheMoneyRemindersRepeatOnACadence(): void
    {
        $schedule = ReminderSchedule::shipped();

        $this->assertSame(7, $schedule->repeatAfterDaysFor(ReminderKind::DEPOSIT_MISSING));
        $this->assertSame(7, $schedule->repeatAfterDaysFor(ReminderKind::BALANCE_MISSING));
        $this->assertSame(7, $schedule->repeatAfterDaysFor(ReminderKind::SECURITY_DEPOSIT_MISSING));
        $this->assertNull($schedule->repeatAfterDaysFor(ReminderKind::ARRIVAL_INVENTORY));
        $this->assertNull($schedule->repeatAfterDaysFor(ReminderKind::PRACTICAL_INFO));
    }

    public function testTheContractsSecondChanceFollowsTheDelayInForce(): void
    {
        $shipped = ReminderSchedule::shipped();
        $this->assertSame(
            ReminderKind::CONTRACT_MISSING->defaultDays() - ReminderKind::CONTRACT_SECOND_CHANCE_DAYS,
            $shipped->repeatAfterDaysFor(ReminderKind::CONTRACT_MISSING)
        );

        // Five days out, the two sends would otherwise land on top of each
        // other; the derivation is what keeps them apart.
        $short = ReminderSchedule::of([], [
            ReminderKind::CONTRACT_MISSING->value => ['days' => 5, 'active' => true],
        ]);
        $this->assertSame(2, $short->repeatAfterDaysFor(ReminderKind::CONTRACT_MISSING));

        // And never zero, which would mean "again tomorrow, for ever".
        $sameDay = ReminderSchedule::of([], [
            ReminderKind::CONTRACT_MISSING->value => ['days' => 1, 'active' => true],
        ]);
        $this->assertSame(1, $sameDay->repeatAfterDaysFor(ReminderKind::CONTRACT_MISSING));
    }

    /**
     * Every kind has a setting key, and no two share one: a unit that wants
     * to hear about unpaid deposits but not about inventories can only say
     * so if the two are separate settings.
     */
    public function testEveryReminderHasItsOwnSettingKey(): void
    {
        $keys = array_map(static fn(ReminderKind $kind): string => $kind->settingKey(), ReminderKind::cases());

        $this->assertSame($keys, array_unique($keys));
        $this->assertSame('reminder_unanswered_request_days', ReminderKind::UNANSWERED_REQUEST->settingKey());
    }
}
