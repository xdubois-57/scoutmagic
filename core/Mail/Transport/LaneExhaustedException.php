<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * Every provider of one lane was tried and every attempt failed
 * (D9, ARCHITECTURE.md §8.106). A failure can leave delivery uncertain;
 * the last reason alone does not establish that no relay accepted it.
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
    /** @param list<string> $attemptFailures Redacted reasons, in delivery order. */
    public function __construct(
        public readonly MailLane $lane,
        public readonly string $reason,
        public readonly array $attemptFailures = []
    ) {
        parent::__construct(sprintf(
            'Tous les fournisseurs de la voie « %s » ont échoué. Dernière raison : %s',
            $lane->label(),
            $reason !== '' ? $reason : 'inconnue'
        ));
    }
}
