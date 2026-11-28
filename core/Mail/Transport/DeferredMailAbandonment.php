<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * Why the drain gave up on a deferred message — told to its sender
 * (`Core\Module\DeferredMailOutcomeListener`, issue #1454) as a reason it
 * can show, rather than as the relay's own words.
 *
 * **A case, not the transport's text.** What the drain writes on the queue
 * row (`last_reason`) is the relay's transcript, redacted of addresses but
 * English and technical — right for the superadmin's Relance screen, wrong
 * for the chief reading a mailing's tracking page. The sender gets the
 * category and a French sentence for it; the detail stays where it was.
 */
enum DeferredMailAbandonment: string
{
    /** Its deadline passed before any relay would take it (D9). */
    case Expired = 'expired';

    /** The recipient's server answered that the address does not exist. */
    case Refused = 'refused';

    /** The address was suspended after repeated bounces while it waited. */
    case Suppressed = 'suppressed';

    /** The stored message could not be read back (a damaged row, a changed key). */
    case Unreadable = 'unreadable';

    /**
     * The recipient asked its sender to stop writing while the message
     * waited — an unsubscribe the sender keeps itself, which the core's
     * bounce state knows nothing about. Its sender says so before the
     * replay (`DeferredMailOutcomeListener::deferredMailWithdrawn()`).
     */
    case Unsubscribed = 'unsubscribed';

    /**
     * Its sender no longer wants it sent: the message it stood for was
     * sent another way, or no longer exists.
     */
    case Withdrawn = 'withdrawn';

    /**
     * The reason as a person reads it — French words naming
     * nothing internal and no address, so a sender may store and show it
     * as it is.
     */
    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Abandonné : le fournisseur d\'envoi ne l\'a pas accepté dans le délai prévu',
            self::Refused => 'Refusé par le serveur du destinataire après la mise en attente',
            self::Suppressed => 'Adresse suspendue après des refus répétés',
            self::Unreadable => 'Abandonné : le message mis en attente n\'a pas pu être relu',
            self::Unsubscribed => 'Abandonné : le destinataire s\'est désinscrit pendant l\'attente',
            self::Withdrawn => 'Abandonné : retiré par l\'expéditeur pendant l\'attente',
        };
    }
}
