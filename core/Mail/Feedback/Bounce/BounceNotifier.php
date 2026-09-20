<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

/**
 * Tells whoever owns a bounced address that it bounced (roadmap IT-05).
 *
 * **An interface, because the decision and the telling fail differently.**
 * {@see BounceService} decides — is this news, does it block — from a
 * report and a counter, and that decision is worth testing on its own
 * without a notification stack underneath it. The telling needs the
 * member registry, the notification types and their channels, and it is
 * allowed to fail without the bounce being lost.
 */
interface BounceNotifier
{
    /**
     * @param bool $blocking whether this bounce is the one that stopped
     *                       the address being written to — the two cases
     *                       are different notification types, because
     *                       « un message n'est pas arrivé » and « cette
     *                       adresse ne reçoit plus rien » ask for
     *                       different actions
     *
     * @return bool whether somebody was actually told. **False is not a
     *              failure** — it is the ordinary case of an address the
     *              site holds but that belongs to nobody who can sign in,
     *              a parent's secondary address without a login among
     *              them. The caller needs to know, because « déjà dit »
     *              must never be recorded against a silence.
     */
    public function notify(BounceState $state, bool $blocking): bool;
}
