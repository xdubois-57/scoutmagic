<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail;

/**
 * What a message is, **for delivery purposes only** (ARCHITECTURE.md §8.7).
 *
 * This is not "which feature sent this mail", and it must never become
 * that. The mail sandbox deliberately records no sender identity — the
 * subject and the timestamp are the identification (§8.63). What this
 * enum carries is narrower and has exactly one consumer: a transport that
 * has to treat one category of message differently from every other one.
 *
 * There is exactly one such category, and it exists because of a real
 * deadlock: with capture armed, the sign-in e-mail lands in the sandbox
 * instead of an inbox, so nobody can sign in to the installation being
 * tested — including to disarm the capture. `MagicLink` is what lets an
 * operator exempt that one e-mail, and nothing else, from the capture
 * (`Modules\TestTools`).
 *
 * A new case is therefore a design decision, not a routine addition: it
 * means the sandbox has grown a second exception, and every exception
 * makes "what did this feature actually send?" harder to answer.
 */
enum MailPurpose: string
{
    /**
     * Every e-mail the site sends. The default on `MailService::send()`,
     * so none of its ~95 call sites names this enum at all.
     */
    case Ordinary = 'ordinary';

    /**
     * The passwordless sign-in link (`Core\Security\AuthService`, e-mail
     * template `magic_link`). The **only** call site that states a purpose.
     */
    case MagicLink = 'magic_link';
}
