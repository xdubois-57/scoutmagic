<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Value;

/**
 * The four states a sheet records, and nothing else.
 *
 * UNSET is a real member of the enum rather than a null: it is what an
 * animateur taps to undo a mistake, and what a row carries when somebody
 * wrote a comment about an animé they have not pointed yet. Read back, it
 * is indistinguishable from the absence of a row — which is the ordinary
 * shape of « non renseigné » — and Repository\PresenceRepository is what
 * keeps the two in step by deleting a row that ends up carrying neither a
 * state nor a comment.
 */
enum PresenceStatus: string
{
    case PRESENT = 'present';
    case EXCUSED = 'excused';
    case ABSENT = 'absent';
    case UNSET = 'unset';

    /**
     * The French label the interface writes, everywhere — the sheet's
     * buttons, the counters, the animé's history, the export's cells.
     * One source, so a state cannot be worded two ways on two screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Présent',
            self::EXCUSED => 'Excusé',
            self::ABSENT => 'Absent',
            self::UNSET => 'Non renseigné',
        };
    }

    /**
     * The Bootstrap semantic colour this state is drawn in — never a fixed
     * hex, so both themes follow (design.md §7.8).
     */
    public function tone(): string
    {
        return match ($this) {
            self::PRESENT => 'success',
            self::EXCUSED => 'warning',
            self::ABSENT => 'danger',
            self::UNSET => 'secondary',
        };
    }

    /**
     * The Bootstrap Icons class beside the state. Icon-only buttons are
     * what make four targets fit on one row of a phone (design.md §7.2),
     * so every state needs one; each is present in the vendored release.
     */
    public function icon(): string
    {
        return match ($this) {
            self::PRESENT => 'bi-check-lg',
            self::EXCUSED => 'bi-chat-left-text',
            self::ABSENT => 'bi-x-lg',
            self::UNSET => 'bi-dash-lg',
        };
    }

    /**
     * Whether this state counts as attendance for a participation rate.
     * « Excusé » does not: the question a rate answers is how many animés
     * were there, and an excused absence is still an absence — it is the
     * comment beside it that says it was announced.
     */
    public function countsAsAttending(): bool
    {
        return $this === self::PRESENT;
    }

    /**
     * The four states in the order every screen draws them: the three
     * decisions first, « non renseigné » last, because it is the state
     * one leaves rather than the state one chooses.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::PRESENT, self::EXCUSED, self::ABSENT, self::UNSET];
    }

    /**
     * A stored or submitted value, or null when it is not one of the four
     * — an unknown string is never quietly read as a state.
     */
    public static function tryParse(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
