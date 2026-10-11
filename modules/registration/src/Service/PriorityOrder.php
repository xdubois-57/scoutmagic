<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * The « Priorités » of the recommendation (issue #843, D5): which criterion
 * sits in which of the three zones, and in which order.
 *
 * - **Toujours Oui** (`always`): a pending request matching one of these
 *   goes first, is always recommended « Oui » and may exceed the capacity.
 * - **Classement** (`rank`): the other requests are sorted by these,
 *   lexicographically, top to bottom — then by date of filing.
 * - **Désactivés** (`off`): not evaluated at all.
 *
 * Immutable, and only ever built VALID: every known criterion exactly once,
 * no unknown one, and « Ordre d'arrivée » never in « Toujours Oui » — it
 * says which request came first, which ranks requests and cannot make one
 * of them a « Oui » on its own. validate() is the one place that rule is
 * written; the settings page refuses what it refuses, and a stored value it
 * refuses is read as the defaults (Service\PriorityOrderService).
 *
 * Stored as JSON in the `registration_priority_order` setting:
 * `{"always": [...], "rank": [...], "off": [...]}`, the order inside a
 * zone being the rank.
 */
final class PriorityOrder
{
    public const CRITERION_SIBLING = 'sibling';
    public const CRITERION_PREVIOUS_NO_ROOM = 'previous_no_room';
    public const CRITERION_ARRIVAL = 'arrival';

    public const ZONE_ALWAYS = 'always';
    public const ZONE_RANK = 'rank';
    public const ZONE_OFF = 'off';

    /** @var array<int, string> */
    public const ZONES = [self::ZONE_ALWAYS, self::ZONE_RANK, self::ZONE_OFF];

    /**
     * Each criterion as the « Priorités » box words it (the mock-up's
     * `RULES`), and whether it may sit in « Toujours Oui ».
     *
     * @var array<string, array{label: string, help: string, can_always: bool}>
     */
    public const CRITERIA = [
        self::CRITERION_SIBLING => [
            'label' => "Fratrie déjà dans l'unité",
            'help' => "Un frère ou une sœur est déjà membre de l'unité.",
            'can_always' => true,
        ],
        self::CRITERION_PREVIOUS_NO_ROOM => [
            'label' => 'Ancienne demande restée sans place',
            'help' => 'Une demande d\'une année précédente refusée avec le motif « pas de place ».',
            'can_always' => true,
        ],
        self::CRITERION_ARRIVAL => [
            'label' => "Ordre d'arrivée de la demande",
            'help' => 'La demande déposée en premier passe en premier.',
            'can_always' => false,
        ],
    ];

    /** What validate() answers for a well-formed order. */
    public const VALID = 'valid';
    /** « Ordre d'arrivée » (or any rank-only criterion) put in « Toujours Oui ». */
    public const INVALID_RANK_ONLY_IN_ALWAYS = 'rank_only_in_always';
    /** Anything else: a missing, unknown or repeated criterion, a zone that is not a list. */
    public const INVALID_SHAPE = 'shape';

    /**
     * @param array<int, string> $always
     * @param array<int, string> $rank
     * @param array<int, string> $off
     */
    private function __construct(
        private readonly array $always,
        private readonly array $rank,
        private readonly array $off
    ) {
    }

    /**
     * The defaults of the chantier: *Fratrie* in « Toujours Oui »;
     * *Ancienne demande restée sans place*, then *Ordre d'arrivée*, in
     * « Classement »; nothing disabled.
     */
    public static function defaults(): self
    {
        return new self(
            [self::CRITERION_SIBLING],
            [self::CRITERION_PREVIOUS_NO_ROOM, self::CRITERION_ARRIVAL],
            []
        );
    }

    /**
     * Whether $zones is an order this site accepts — one of the three
     * constants above, so a caller can say WHY it refused.
     *
     * Expected shape: an array with the three zone keys, each a list of
     * criterion ids. A zone key may be absent (an empty zone that a form
     * did not submit), but no other key may be present.
     */
    public static function validate(mixed $zones): string
    {
        if (!is_array($zones)) {
            return self::INVALID_SHAPE;
        }

        $seen = [];
        foreach ($zones as $zone => $criteria) {
            if (!in_array($zone, self::ZONES, true) || !is_array($criteria) || !array_is_list($criteria)) {
                return self::INVALID_SHAPE;
            }
            foreach ($criteria as $criterion) {
                if (!is_string($criterion) || !isset(self::CRITERIA[$criterion]) || isset($seen[$criterion])) {
                    return self::INVALID_SHAPE;
                }
                $seen[$criterion] = $zone;
            }
        }
        if (count($seen) !== count(self::CRITERIA)) {
            return self::INVALID_SHAPE;
        }

        foreach ($seen as $criterion => $zone) {
            if ($zone === self::ZONE_ALWAYS && !self::CRITERIA[$criterion]['can_always']) {
                return self::INVALID_RANK_ONLY_IN_ALWAYS;
            }
        }

        return self::VALID;
    }

    /**
     * The order $zones describes, or null when validate() refuses it.
     */
    public static function fromZones(mixed $zones): ?self
    {
        if (self::validate($zones) !== self::VALID) {
            return null;
        }
        /** @var array<string, array<int, string>> $zones */

        return new self(
            $zones[self::ZONE_ALWAYS] ?? [],
            $zones[self::ZONE_RANK] ?? [],
            $zones[self::ZONE_OFF] ?? []
        );
    }

    /**
     * The order a stored setting value holds, or null when it is not valid
     * JSON of a valid order.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return self::fromZones($decoded);
    }

    public function toJson(): string
    {
        return json_encode($this->toZones(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{always: array<int, string>, rank: array<int, string>, off: array<int, string>}
     */
    public function toZones(): array
    {
        return [
            self::ZONE_ALWAYS => $this->always,
            self::ZONE_RANK => $this->rank,
            self::ZONE_OFF => $this->off,
        ];
    }

    /**
     * @return array<int, string> the « Toujours Oui » criteria, in order
     */
    public function always(): array
    {
        return $this->always;
    }

    /**
     * @return array<int, string> the « Classement » criteria, top first
     */
    public function rank(): array
    {
        return $this->rank;
    }

    /**
     * @return array<int, string> the disabled criteria
     */
    public function off(): array
    {
        return $this->off;
    }

    public function equals(self $other): bool
    {
        return $this->toZones() === $other->toZones();
    }

    /**
     * The French label of a criterion, for an explanation or a message.
     */
    public static function label(string $criterion): string
    {
        return self::CRITERIA[$criterion]['label'] ?? $criterion;
    }
}
