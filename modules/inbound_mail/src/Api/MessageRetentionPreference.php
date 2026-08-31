<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * What a consumer wants kept of the messages it claims (roadmap IT-22).
 *
 * An **optional** companion to `MessageConsumerInterface`, and optional on
 * purpose: that interface is a published contract three modules already
 * implement, and adding methods to it would force each of them to spell
 * out a default that belongs in one place. A consumer that does not
 * implement this one is treated exactly as before — body kept, headers
 * not — so "declares nothing" needs no code at all.
 *
 * ## Why raw headers are worth an option rather than a default
 *
 * `Authentication-Results`, `Received-SPF`, `DKIM-Signature` and the chain
 * of `Received` lines are where a mail diagnosis actually lives: they say
 * whether a message was authenticated, whether it was aligned, and what
 * path it took. None of that survives the parse today.
 *
 * They also carry **IP addresses and server names**, which is why keeping
 * them is a choice a consumer has to make out loud rather than something
 * this module does for everybody. What is kept is encrypted at rest like
 * the rest of the message, decrypted only in the repository (SECURITY.md
 * §5).
 *
 * ## Why a consumer would refuse the body
 *
 * A probe consumer — one watching whether the site's own mail comes back —
 * needs the envelope and the authentication verdict and has no business
 * keeping what anybody wrote. Refusing the body is the narrower position,
 * and a contract that can only ever add is not much of a contract.
 *
 * Both answers apply to a message **this** consumer claims. Where several
 * consumers claim the same message the wider answer wins: one module's
 * frugality cannot delete what another needs.
 */
interface MessageRetentionPreference
{
    /**
     * Keep the message's raw header block, truncated and encrypted.
     */
    public function wantsRawHeaders(): bool;

    /**
     * Keep the message's text and HTML bodies. False writes empty strings
     * rather than nulls — the columns are `NOT NULL`, and loosening a
     * constraint to record an absence would weaken the schema for every
     * message to serve one consumer.
     */
    public function wantsBody(): bool;
}
