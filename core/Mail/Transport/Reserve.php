<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * A reserve and the reasoning that produced it (D8).
 *
 * The second half is not decoration. « 42 » on a screen is a number
 * somebody will eventually decide is wrong, and they will be unable to
 * check. « 42 messages — votre pointe hors publipostage des 30 derniers
 * jours, plus une marge » is a number they can argue with, which is the
 * point: the reserve is calculated from their own traffic, so the
 * explanation is also the invitation to look.
 */
final class Reserve
{
    public function __construct(
        public readonly int $messages,
        public readonly int $peak,
        public readonly bool $fromFloor,
        public readonly bool $cappedByQuota,
        public readonly string $inapplicableBecause = ''
    ) {
    }

    /**
     * No reserve, and the French sentence saying why not — the screen
     * prints it in place of the number.
     */
    public static function none(string $because): self
    {
        return new self(0, 0, false, false, $because);
    }

    public function applies(): bool
    {
        return $this->inapplicableBecause === '';
    }

    /**
     * Where the number comes from, in one sentence a volunteer can check
     * against their own month.
     */
    public function provenance(): string
    {
        if (!$this->applies()) {
            return $this->inapplicableBecause;
        }

        $sentence = $this->fromFloor
            ? sprintf(
                '%d messages — le minimum retenu tant que ce site n’a pas d’historique hors publipostage.',
                $this->messages
            )
            : sprintf(
                '%d messages — votre pointe hors publipostage des %d derniers jours (%d), plus une marge de %d.',
                $this->messages,
                MailReserve::WINDOW_DAYS,
                $this->peak,
                MailReserve::MARGIN
            );

        if ($this->cappedByQuota) {
            $sentence .= ' Ramené à la moitié du quota de ce fournisseur : au-delà,'
                . ' le publipostage ne partirait plus du tout.';
        }

        return $sentence;
    }
}
