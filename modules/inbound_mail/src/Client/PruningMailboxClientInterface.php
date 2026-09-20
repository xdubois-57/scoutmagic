<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * The one way this module may remove a message from a remote mailbox
 * (roadmap IT-07).
 *
 * **A SEPARATE contract, and the separation is the whole design.**
 * {@see IncomingMailboxClientInterface} says of itself:
 *
 * > Every method on this interface is a read. There is no `markSeen()`,
 * > no `move()`, no `delete()` and no `createFolder()`, and that is the
 * > point: ScoutMagic is a gateway onto somebody's mailbox, not a mail
 * > client, and the way to guarantee it never touches their mail is to
 * > give it no vocabulary for doing so (§7.5).
 *
 * That sentence is worth keeping true. Adding `delete()` to that
 * interface would have made it false for every mailbox this site reads —
 * a treasurer's inbox, a unit's public address — in order to serve one
 * consumer whose boxes exist only to be measured. The general contract
 * therefore keeps its guarantee, and this one exists beside it, narrow
 * and named, so that « who may delete » is a question with a written
 * answer rather than an assumption.
 *
 * **Two locks, not one.** A client implementing this can delete; it does
 * so only for a consumer that declares
 * {@see \Modules\InboundMail\Api\PruningConsumerInterface} and says yes
 * about that particular message. Neither half alone removes anything, and
 * a consumer that never heard of either — which is every consumer but
 * one — cannot remove anything however it answers.
 *
 * **Why a seed mailbox needs it at all.** A box that receives a copy of
 * every mailing fills up for ever with messages nobody will read: it is
 * not somebody's correspondence, it is a measuring instrument, and an
 * instrument that never resets stops working. The verdict is recorded
 * first and the message dropped after, so what survives is the
 * measurement rather than the mail.
 */
interface PruningMailboxClientInterface
{
    /**
     * Remove one message, by the UID it was fetched under.
     *
     * By UID and not by any other handle, because a UID is the one
     * identifier IMAP promises is stable within a folder for as long as
     * `UIDVALIDITY` holds — and the sync already tracks that. Deleting by
     * sequence number would race with every arrival.
     *
     * Returns whether the server accepted it. False is an ordinary
     * answer, never an exception: a seed copy that could not be removed
     * has still been measured, and the measurement is what the caller
     * came for.
     */
    public function deleteMessage(string $folder, int $uid): bool;
}
