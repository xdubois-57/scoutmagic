<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Pdf;

use Core\Member\Movement\MemberMovementStatus;

/**
 * One name on the printed sheet — a line somebody ticks off while calling
 * the roll.
 *
 * Deliberately three fields and a status, and no contact of any kind. This
 * document circulates in a local on a rentrée day, in hands that are not
 * all the staff's; unlike the printable trombinoscope it carries no
 * setting for that, because it never carries the data in the first place.
 *
 * The names arrive already normalised (Core\Service\TextNormalizerService,
 * the same `normalize_name`/`normalize_totem` the screen applies) so the
 * sheet and the page it was printed from spell a name identically.
 *
 * The movement is carried as the core enum rather than as a colour: the
 * enum's own docblock is explicit that it never hardcodes a class or a
 * colour, and mapping its tone is Pdf\SectionRosterHtmlBuilder's job —
 * the same separation the screen makes with `movement_badge_classes`.
 */
final class RosterMemberView
{
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $totem,
        public readonly MemberMovementStatus $movement
    ) {
    }

    /**
     * « Grandjean, Antonin · Chacal » — surname first, because a roll is
     * called from a list read in that order, and the totem last because it
     * is what the animés answer to.
     */
    public function label(): string
    {
        $label = $this->lastName . ', ' . $this->firstName;

        return $this->totem !== null && $this->totem !== '' ? $label . ' · ' . $this->totem : $label;
    }
}
