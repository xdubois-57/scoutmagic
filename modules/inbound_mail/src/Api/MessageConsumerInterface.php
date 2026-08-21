<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * How another module claims the mail that belongs to it (§7.6, and the
 * ARCHITECTURE.md §7.6 "module extended by a module" pattern).
 *
 * **This is what keeps `inbound_mail` free of any consumer's logic.** The
 * module knows how to reach a mailbox, parse MIME and store what somebody
 * wants; it has no idea what a booking reference looks like, and it must
 * not — a `[LOC-2027-0001]` in a subject means nothing here.
 *
 * It is also what keeps ScoutMagic from becoming an archive of the unit's
 * mailbox: the sync loop asks every registered consumer, and a message no
 * consumer claims is discarded without being written anywhere (§7.6). The
 * cursor still advances, so it is not offered again.
 *
 * **Order matters and is fixed by the module, not by the consumer.** A
 * consumer is asked to try the reliable identifications first and the
 * weak ones last; see `claim()`.
 */
interface MessageConsumerInterface
{
    /**
     * A stable id for this consumer, used to scope every later API call.
     * The module id is the obvious choice ('rental').
     */
    public function consumerId(): string;

    /**
     * Decide whether this message belongs to one of the consumer's business
     * objects, and say how that was decided.
     *
     * Returns `null` for "not mine" — which must also be the answer when
     * the message matches **several** objects (§7.6): attaching a renter's
     * email to whichever of their two bookings sorted first is worse than
     * not attaching it at all.
     *
     * Implementations are expected to try, in order: an explicit reference
     * in the subject, then the thread headers, then the sender's address
     * bounded by a time window. Anything weaker than that belongs behind an
     * explicit, optional step.
     */
    public function claim(CandidateMessage $message): ?MessageClaim;
}
