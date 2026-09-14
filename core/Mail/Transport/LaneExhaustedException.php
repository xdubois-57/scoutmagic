<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * Every provider of one lane was tried and none of them took the message
 * (D9, ARCHITECTURE.md §8.106).
 *
 * It exists to be **told apart**, and that is its whole job. A message
 * that failed because the address is wrong is finished; a message that
 * failed because an entire lane is spent or down is a message nobody has
 * refused yet, and `MailService` defers it rather than losing it —
 * except on the authentication lane, where a link delivered tomorrow is
 * not a link at all (D9).
 *
 * Carrying the lane is what lets that decision be made one level up
 * without re-deriving it from the purpose.
 */
final class LaneExhaustedException extends \RuntimeException
{
    public function __construct(
        public readonly MailLane $lane,
        public readonly string $reason
    ) {
        parent::__construct(sprintf(
            'Tous les fournisseurs de la voie « %s » ont échoué. Dernière raison : %s',
            $lane->label(),
            $reason !== '' ? $reason : 'inconnue'
        ));
    }
}
