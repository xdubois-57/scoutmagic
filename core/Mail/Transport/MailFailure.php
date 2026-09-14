<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * Whose fault a refusal was (D15, ARCHITECTURE.md §8.106).
 *
 * The whole circuit breaker rests on this one distinction, and getting it
 * backwards would be worse than not having a breaker at all.
 *
 * **The provider's fault**: the connection never opened, the credentials
 * were refused, TLS would not negotiate, or the relay is telling us to
 * slow down (`421`, the `4.7.x` family). Nothing about the message would
 * change the outcome, so retrying it four hundred times — which one
 * publipostage does — is four hundred pointless attempts, and that is
 * what the breaker exists to stop.
 *
 * **Not the provider's fault**: a recipient the relay will not accept
 * (`550` and its neighbours). The provider is working perfectly; it is
 * answering a question about one address. Counting that as a strike would
 * shut out a relay because somebody mistyped an e-mail, and on the
 * mailing lane — where dead addresses accumulate — it would shut one out
 * almost every run.
 */
enum MailFailure
{
    case Provider;
    case Recipient;

    /**
     * Read the failure out of what the relay said.
     *
     * Matched on the reason text because that is what PHPMailer gives us:
     * `ErrorInfo` carries the SMTP reply, and the exception message
     * carries the local failure. Both arrive here already redacted of
     * addresses ({@see MailErrorRedaction}), which is also why this looks
     * for codes and phrases rather than parsing a structure — there is no
     * structure left to parse, and inventing one would mean keeping the
     * unredacted text around to feed it.
     *
     * Unknown reasons are the PROVIDER's. That direction is deliberate:
     * an unrecognised failure that is really the provider's, counted as a
     * recipient's, leaves the breaker shut while a dead relay is retried
     * without end — the exact harm. The other way round costs one
     * provider a few minutes out of a chain that never empties.
     */
    public static function classify(string $reason): self
    {
        $haystack = mb_strtolower($reason);

        // Permanent recipient rejections. `550` is the common one; the
        // rest are its neighbours in RFC 3463's 5.1.x «bad destination
        // address» family, plus the wording used by relays that answer in
        // prose rather than codes.
        $recipientMarkers = [
            '550', '551', '553', '554 5.7.1',
            '5.1.0', '5.1.1', '5.1.2', '5.1.3', '5.1.6',
            'recipient address rejected',
            'user unknown',
            'no such user',
            'mailbox unavailable',
            'address rejected',
            'does not exist',
            'invalid address',
            'recipients failed',
            'you must provide at least one recipient',
        ];

        foreach ($recipientMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return self::Recipient;
            }
        }

        return self::Provider;
    }
}
