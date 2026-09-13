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
 * enum carries is narrower: a delivery category, for a transport that
 * has to treat one category differently from another.
 *
 * There are two such transports now, and the second one is why a third
 * case exists.
 *
 * The first is the **mail sandbox**, and its exception is a deadlock
 * rather than a preference: with capture armed, the sign-in e-mail lands
 * in the sandbox instead of an inbox, so nobody can sign in to the
 * installation being tested — including to disarm the capture.
 * `MagicLink` is what lets an operator exempt that one e-mail, and
 * nothing else, from the capture (`Modules\TestTools`).
 *
 * The second is the **provider chain** (`Core\Mail\Transport`), which
 * carries three ordered lists of relays — one per lane — so that a relay
 * that has fallen over, or spent its daily quota on a 400-recipient
 * mailing, cannot take the sign-in links down with it. A lane is chosen
 * from this enum and nothing else: `Core\Mail\Transport\MailLane::
 * fromPurpose()` is the whole mapping.
 *
 * `Bulk` was added for that chain, and adding it was a design decision
 * rather than a routine one: it is the category a unit's publipostage
 * sends under, so it is also the only category a cadence applies to and
 * the only one a daily reserve protects the others FROM. Anything but a
 * mailing to a whole audience is `Ordinary`. A fourth case would mean a
 * fourth lane and a third chain to configure, which is a screen and a
 * decision, not an addition.
 */
enum MailPurpose: string
{
    /**
     * Every e-mail the site sends of its own accord to one person — a
     * notification, a confirmation, a receipt. The default on
     * `MailService::send()`, so none of its ~95 call sites names this
     * enum at all.
     */
    case Ordinary = 'ordinary';

    /**
     * The passwordless sign-in link (`Core\Security\AuthService`, e-mail
     * template `magic_link`), and every message carrying a token that
     * expires with it.
     */
    case MagicLink = 'magic_link';

    /**
     * One copy of a mailing sent to an audience — `modules/mass_mail`'s
     * publipostage, and any later sender of the same shape. Stated by
     * the sender, never guessed: a transport cannot tell a mailing from
     * a notification by looking at the message.
     */
    case Bulk = 'bulk';
}
