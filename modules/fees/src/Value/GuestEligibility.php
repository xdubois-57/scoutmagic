<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/** A three-state decision; unknown is never an ordinary tariff by default. */
final readonly class GuestEligibility
{
    public const UNKNOWN_STARTS = 'function_starts_unknown';
    public const UNKNOWN_PREVIOUS_YEAR = 'previous_year_unknown';
    public const UNKNOWN_PERIOD = 'guest_period_unknown';

    public function __construct(public ?bool $qualifies, public ?string $reason = null)
    {
    }

    public function reasonLabel(): ?string
    {
        return match ($this->reason) {
            self::UNKNOWN_STARTS => 'Dates de début de fonction absentes ou incomplètes.',
            self::UNKNOWN_PREVIOUS_YEAR => 'Présence dans l’année précédente non vérifiable.',
            self::UNKNOWN_PERIOD => 'Période Invités non confirmée pour cette année.',
            null => null,
            default => 'Contrôle non déterminable.',
        };
    }
}
