<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * One line of a final settlement (§6.21).
 *
 * Deliberately **not** `Pricing\PriceLine`. A quote line answers "what does
 * this stay cost by the tariff?"; a settlement line answers "what is owed
 * now that it has happened?", and its origins — a meter actually read, a
 * damage a human decided to charge — have no place in a tariff. Sharing one
 * class would mean one of the two carrying fields that are always null.
 */
final class SettlementLine
{
    public const ORIGIN_AGREED = 'agreed';
    public const ORIGIN_METER = 'meter';
    public const ORIGIN_PER_PERSON = 'per_person';
    public const ORIGIN_INCIDENT = 'incident';
    public const ORIGIN_MANUAL = 'manual';

    public function __construct(
        public readonly string $label,
        public readonly int $amountCents,
        public readonly string $origin,
        /** Free detail — a consumption, a head count. Never a calculation to redo. */
        public readonly ?string $detail = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'amount_cents' => $this->amountCents,
            'origin' => $this->origin,
            'detail' => $this->detail,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) ($data['label'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
            origin: (string) ($data['origin'] ?? self::ORIGIN_MANUAL),
            detail: isset($data['detail']) ? (string) $data['detail'] : null
        );
    }
}
