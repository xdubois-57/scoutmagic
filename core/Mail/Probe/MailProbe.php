<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Mail\Transport\MailLane;

/**
 * One probe: where it went, by which road, and what came of it
 * (roadmap IT-04).
 *
 * **The provider is kept by NAME and not only by id**, and that is the
 * whole point of the history. Two lines saying « même destinataire,
 * réception via l'un, indésirables via l'autre » settle an argument no
 * amount of explaining settles — but only for as long as they can still
 * say which « un » and which « autre ». A relay deleted six months later
 * would take its own evidence with it if the row held nothing but a
 * foreign key, and the row would then read « indésirables via … » with a
 * blank where the answer was.
 *
 * The id is kept too, for as long as it resolves: it is what lets a
 * future screen link back to the provider that is still configured.
 */
final class MailProbe
{
    public function __construct(
        public readonly int $id,
        /** The short code in the subject line, e.g. `SM-7K2X`. */
        public readonly string $code,
        public readonly string $destination,
        /** Null once the provider it named has been deleted. */
        public readonly ?int $providerId,
        /** The provider's name AS IT WAS when the probe left. */
        public readonly string $providerName,
        public readonly MailLane $lane,
        public readonly \DateTimeImmutable $sentAt,
        public readonly ?MailProbeVerdict $verdict = null,
        public readonly ?\DateTimeImmutable $verdictAt = null
    ) {
    }
}
