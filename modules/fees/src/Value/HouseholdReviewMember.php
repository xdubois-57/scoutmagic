<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

use Modules\Fees\FeeTariff;

/** The encoded tariff and independently determined applicable tariff for one person. */
final class HouseholdReviewMember
{
    /** @param list<string> $expectedDeskCodes real codes only, never an invented guest code */
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?int $encodedFeeCategoryId,
        public readonly ?string $encodedFeeCategoryLabel,
        public readonly ?string $encodedDeskCode,
        public readonly ?FeeTariff $encodedCategory,
        public readonly ?FeeTariff $expectedCategory,
        public readonly GuestEligibility $guest,
        public readonly array $expectedDeskCodes,
        public readonly bool $leaving,
        public readonly ?string $leavingMarkedAt
    ) {
    }

    public function isIndeterminate(): bool
    {
        return $this->guest->qualifies !== true
            && ($this->guest->qualifies === null || $this->expectedCategory === null
                || $this->encodedCategory === null);
    }

    public function needsCorrection(): bool
    {
        return !$this->isIndeterminate() && $this->guest->qualifies !== true
            && $this->encodedCategory !== $this->expectedCategory;
    }

    public function statusLabel(): string
    {
        if ($this->isIndeterminate()) {
            return 'Non déterminable';
        }
        if ($this->guest->qualifies === true) {
            return 'Invités selon la période';
        }
        if ($this->encodedCategory === FeeTariff::IAMA) {
            return 'Tarif IAmA saisi dans Desk';
        }

        return $this->needsCorrection() ? 'À corriger' : 'Conforme';
    }

    public function reasonLabel(): ?string
    {
        if ($this->guest->qualifies === null) {
            return $this->guest->reasonLabel();
        }
        if ($this->expectedCategory === null) {
            return 'Nombre de membres retenus pour le tarif du foyer non déterminable.';
        }
        if ($this->encodedCategory === null && $this->guest->qualifies !== true) {
            return 'Tarif Desk absent ou non reconnu. Vérifiez sa correspondance dans le barème.';
        }

        return null;
    }

    /**
     * What `|display_name_full` needs, without this module inventing a name
     * rule of its own (AGENTS.md § Display name convention).
     *
     * @return array{first_name: string, last_name: string, totem: ?string}
     */
    public function nameParts(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'totem' => $this->totem,
        ];
    }
}
