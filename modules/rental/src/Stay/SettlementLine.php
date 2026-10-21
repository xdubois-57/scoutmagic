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
    /**
     * A goodwill gesture granted after the stay (issue #927): a discount on
     * the settlement, never on the agreed price, and with no new contract.
     */
    public const ORIGIN_GESTURE = 'gesture';
    /**
     * What the unit gave up recovering when it closed the booking anyway
     * (#980, CHANTIER-locations-incidents.md §2.9): always negative, and only ever on a version
     * written by `RentalClosureService::closeUnrecovered()`.
     */
    public const ORIGIN_UNRECOVERED = 'unrecovered';

    /** What a gesture is called when the manager names it nothing else. */
    public const GESTURE_LABEL = 'Geste commercial';

    /** The line an unrecovered closure adds. */
    public const UNRECOVERED_LABEL = 'Montant non recouvré';

    public function __construct(
        public readonly string $label,
        public readonly int $amountCents,
        public readonly string $origin,
        /** Free detail — a consumption, a head count. Never a calculation to redo. */
        public readonly ?string $detail = null
    ) {
    }

    /**
     * The « Geste commercial » line for a discount of $amountCents, or null
     * when there is none.
     *
     * Always negative, whichever sign was typed: a gesture lowers what the
     * stay comes to, and a manager typing « 50 » into a field labelled as a
     * discount means fifty euros off, not fifty more.
     */
    public static function gesture(?string $label, ?int $amountCents): ?self
    {
        if ($amountCents === null || $amountCents === 0) {
            return null;
        }

        $label = $label !== null ? trim($label) : '';

        return new self(
            $label !== '' ? mb_substr($label, 0, 120) : self::GESTURE_LABEL,
            -abs($amountCents),
            self::ORIGIN_GESTURE
        );
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
