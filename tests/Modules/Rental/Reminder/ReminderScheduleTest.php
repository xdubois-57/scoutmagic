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

        // Five days out, `$days − 3` alone would give 2 — and an interval
        // of 2 across a five-day window is three sends, not two. This
        // assertion asserted that 2 before the send count was counted
        // rather than assumed; the floor at `intdiv($days, 2) + 1` is what
        // actually keeps the two apart.
        $short = ReminderSchedule::of([], [
            ReminderKind::CONTRACT_MISSING->value => ['days' => 5, 'active' => true],
        ]);
        $this->assertSame(3, $short->repeatAfterDaysFor(ReminderKind::CONTRACT_MISSING));

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

    /**
     * **And the manifest declares exactly those keys, no more and no less.**
     *
     * `settingKey()` spells each name out instead of composing it from the
     * case, which is what makes a renamed case a visible change rather than
     * a silently orphaned row — but spelling it out is also what makes the
     * two lists able to drift apart. A key the enum reads and the manifest
     * never declares is a setting no unit can set; a key the manifest
     * declares and no enum case claims is a field that saves and changes
     * nothing, which is the failure `DeclaredSettingsAreReadTest` was
     * written for. Both directions are asserted, because only one of them
     * is visible from the configuration page.
     */
    public function testTheManifestDeclaresEveryReminderSettingAndNoOthers(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/rental/module.json'),
            true
        );
        $this->assertIsArray($manifest);

        // This manifest carries `settings` as a list of objects, each with
        // its own `key` — not as a map keyed by setting name. Both shapes
        // exist in the tree.
        $declared = array_values(array_filter(
            array_map(
                static fn (array $setting): string => (string) $setting['key'],
                $manifest['settings'] ?? []
            ),
            static fn (string $key): bool => str_starts_with($key, 'reminder_')
        ));
        $fromEnum = array_map(
            static fn (ReminderKind $kind): string => $kind->settingKey(),
            ReminderKind::cases()
        );

        sort($declared);
        sort($fromEnum);
        $this->assertSame($fromEnum, $declared);
    }

    /**
     * **Two sends, whatever the delay configured — counted, not promised.**
     *
     * `repeatAfterDays()` returns a *minimum interval*, and `claim()`
     * re-sends every time it has elapsed. So what the docblock calls « one
     * extra nudge » is really `1 + intdiv($days, $interval)` sends across
     * the window, and asserting the interval — which is all the test above
     * this one ever did — says nothing about that number. With the first
     * derivation, `$days − 3`, a four-day lead time sent five times: a
     * daily nag on a setting this screen offers, and the fastest way to
     * teach a unit to ignore the channel that carries the other eleven.
     *
     * The floor at `intdiv($days, 2) + 1` is exactly the threshold at which
     * a third send stops fitting. Above six days — the default fourteen
     * included — the derived value already clears it and nothing changes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('contractLeadTimes')]
    public function testTheContractIsSaidExactlyTwiceWhateverTheLeadTime(int $days): void
    {
        $interval = ReminderKind::CONTRACT_MISSING->repeatAfterDays($days);
        $this->assertNotNull($interval);
        $this->assertGreaterThan(0, $interval);

        // One send when the stay comes into range, then one per elapsed
        // interval until the arrival — which is what `claim()` does.
        $this->assertSame(2, 1 + intdiv($days, $interval), sprintf('%d jours', $days));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function contractLeadTimes(): array
    {
        $cases = [];
        foreach ([1, 2, 3, 4, 5, 6, 7, 10, 14, 21, 30, 60] as $days) {
            $cases[$days . ' jours'] = [$days];
        }

        return $cases;
    }

    /**
     * The default is untouched by the floor: fourteen days still means the
     * second chance three days out, which is what `module.json` describes
     * to a unit that has never changed it.
     */
    public function testTheDefaultLeadTimeStillPutsTheSecondChanceThreeDaysOut(): void
    {
        $this->assertSame(
            11,
            ReminderKind::CONTRACT_MISSING->repeatAfterDays(ReminderKind::CONTRACT_MISSING->defaultDays())
        );
    }

    /**
     * A window of zero days — « le jour même » — is the one case with a
     * single send, because there is no second day to say it on.
     */
    public function testASameDayContractReminderIsSaidOnce(): void
    {
        $interval = ReminderKind::CONTRACT_MISSING->repeatAfterDays(0);

        $this->assertSame(1, $interval);
        $this->assertSame(1, 1 + intdiv(0, $interval));
    }
}
