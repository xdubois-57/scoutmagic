<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

/**
 * What one asset's reminders are set to (§6.29, §22.11).
 *
 * Three levels, and the middle one is the only one anybody edits often:
 *
 * 1. the shipped value, `ReminderKind::defaultDays()`;
 * 2. the **unit's** default, a module setting — one per reminder;
 * 3. the **asset's** override, a row in `rental_asset_reminders`.
 *
 * **An empty field at asset level means "take the default", never "never".**
 * That is the reading the whole screen depends on: twelve fields on every
 * asset is twelve fields nobody fills in, so a blank one has to keep
 * working, and the form writes « (défaut : 14 jours) » under it rather than
 * a pre-filled number somebody would have to keep in step by hand. Turning
 * a reminder OFF is the `active` flag's job — a remorque has neither an
 * inventory nor a security deposit, and those two reminders on it are
 * guaranteed noise.
 *
 * Pure: it is handed the settings and the overrides already read, and
 * queries nothing.
 */
final class ReminderSchedule
{
    /**
     * @param array<string, int> $unitDefaults days, by `ReminderKind::value`
     * @param array<string, array{days: int|null, active: bool}> $assetOverrides
     *   by `ReminderKind::value`; a key absent means the asset says nothing
     */
    private function __construct(
        private readonly array $unitDefaults,
        private readonly array $assetOverrides
    ) {
    }

    /**
     * @param array<string, int> $unitDefaults
     * @param array<string, array{days: int|null, active: bool}> $assetOverrides
     */
    public static function of(array $unitDefaults = [], array $assetOverrides = []): self
    {
        return new self($unitDefaults, $assetOverrides);
    }

    /**
     * Everything at its shipped value — what an installation that has never
     * opened the screen behaves like, and what a test that is not about the
     * configuration should use.
     */
    public static function shipped(): self
    {
        return new self([], []);
    }

    /**
     * The delay in force, in days.
     *
     * Never null: a reminder that is off is off through `isActive()`, and
     * folding the two into one nullable number is how "0" and "never" end
     * up one typo apart.
     */
    public function daysFor(ReminderKind $kind): int
    {
        $override = $this->assetOverrides[$kind->value]['days'] ?? null;
        if ($override !== null) {
            return max(0, $override);
        }

        return max(0, $this->unitDefaults[$kind->value] ?? $kind->defaultDays());
    }

    /**
     * The unit-wide default this asset would fall back to — what the form
     * writes under an empty field, so the number a manager reads is the
     * number that would actually apply.
     */
    public function defaultDaysFor(ReminderKind $kind): int
    {
        return max(0, $this->unitDefaults[$kind->value] ?? $kind->defaultDays());
    }

    /** Whether this asset has its own value, rather than inheriting one. */
    public function isOverridden(ReminderKind $kind): bool
    {
        return ($this->assetOverrides[$kind->value]['days'] ?? null) !== null;
    }

    /**
     * Whether this reminder is sent for this asset at all.
     *
     * Active unless the asset says otherwise: a reminder that has to be
     * switched on before it works is a reminder a unit discovers it never
     * had, the week it needed it.
     */
    public function isActive(ReminderKind $kind): bool
    {
        return $this->assetOverrides[$kind->value]['active'] ?? true;
    }

    /**
     * How many days before this reminder may be said again, or null when it
     * is said once. Asked here rather than of the kind directly because the
     * contract's second chance is derived from the delay in force, which
     * only this object knows.
     */
    public function repeatAfterDaysFor(ReminderKind $kind): ?int
    {
        return $kind->repeatAfterDays($this->daysFor($kind));
    }
}
