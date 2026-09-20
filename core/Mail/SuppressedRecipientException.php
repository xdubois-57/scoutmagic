<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

use Core\Exception\UserFacingException;

/**
 * The site declined to write to a suspended address (roadmap IT-05).
 *
 * **A non-delivery has to look like one.** {@see MailService::send()}
 * first answered a blocked recipient by returning normally, which every
 * caller reads as « parti » because that is what a void method returning
 * normally means. `Modules\Attestations\Service\BatchDistributionService`
 * then recorded `DeliveryState::Sent` — a settled state it never retries —
 * and the member page said « Document renvoyé par e-mail » to a chef
 * d'unité whose message had gone nowhere. Silence was the bug; this class
 * is the signal that replaces it.
 *
 * **A `MailException`, so that a caller who only knows about send failures
 * still does the right thing.** `Core\Notification\NotificationMailer`
 * catches `MailException` and answers false, which is exactly true here:
 * nobody was written to. A caller that wants to tell « suspendue » from
 * « refusée par le serveur » catches this class first, and the two that
 * have something different to say — the attestations batch and the member
 * page — do.
 *
 * **User-facing, and the message is the whole reason.** « L'envoi a
 * échoué, réessayez » is wrong twice over on this path: nothing failed,
 * and retrying suppresses again for as long as the suspension lasts. The
 * messages thrown here name no address and no server (SECURITY.md §11) —
 * the reader is already looking at the address, and the only thing they
 * are missing is what to do about it.
 *
 * **Both ways out are named, because the reader may not have either.** The
 * one screen that shows this sentence — resending a private document from
 * a member's sheet — floors at `admin`, while « Rebonds » is `superadmin`.
 * Sending an admin to a page they cannot open would be worse than saying
 * nothing, so the member's own address page is named alongside it.
 */
class SuppressedRecipientException extends MailException implements UserFacingException
{
    public static function blocked(): self
    {
        return new self(
            'Cette adresse est suspendue : le site a cessé de lui écrire '
            . 'après des refus répétés du serveur destinataire. Un nouvel '
            . 'essai donnera le même résultat tant que la suspension n\'est '
            . 'pas levée — le membre peut le faire depuis ses propres '
            . 'adresses, un super-administrateur depuis « Rebonds ».'
        );
    }
}
