<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Mail\MailErrorRedaction;

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
     * The enhanced status codes that name the recipient's address or
     * mailbox — every other class-4 or class-5 code is the provider's.
     *
     * The reading, from RFC 3463 and the IANA registry, checked against
     * what relays are documented to send (issue #1488):
     *
     * | Code                    | Whose            | Why                                             |
     * |-------------------------|------------------|-------------------------------------------------|
     * | 4.x.x                   | provider         | class 4 is transient: « sending in the future   |
     * |                         |                  | may be successful », so the message waits       |
     * | 5.1.0-5.1.4, 5.1.6,     | recipient        | « bad destination mailbox / system / syntax »,  |
     * | 5.1.10                  |                  | ambiguous, moved, null MX: the address itself   |
     * | 5.1.7, 5.1.8, other     | provider         | 5.1.7/5.1.8 are the SENDER's address; Microsoft |
     * | 5.1.x                   |                  | answers « 5.1.8 Access denied, bad outbound     |
     * |                         |                  | sender » and « 5.1.90 … you've reached your     |
     * |                         |                  | daily limit for message recipients »            |
     * | 5.2.1                   | recipient        | « mailbox disabled »: Google's « 550 5.2.1 The  |
     * |                         |                  | email account that you tried to reach is        |
     * |                         |                  | inactive ». Google also sends it for a          |
     * |                         |                  | recipient receiving too fast — read as refused, |
     * |                         |                  | that one message is lost rather than a healthy  |
     * |                         |                  | relay struck out for one busy inbox             |
     * | 5.2.2                   | provider         | RFC 3463 makes « mailbox full » a persistent    |
     * |                         |                  | TRANSIENT failure, and Microsoft uses the code  |
     * |                         |                  | for « Submission quota exceeded » — the         |
     * |                         |                  | sender's quota. Waiting is right either way.    |
     * | other 5.2.x (5.2.121…)  | provider         | per-hour receive limits, size limits            |
     * | 5.3.x, 5.4.x, 5.5.x,    | provider         | the mail system, routing, protocol, content:    |
     * | 5.6.x                   |                  | Google's « 550 5.4.5 Daily user sending limit   |
     * |                         |                  | exceeded » is the case this list exists for     |
     * | 5.7.x, 4.7.x            | provider         | read first by {@see classify()}, unchanged      |
     * | 5.0.0, 5.5.0            | (no code)        | {@see UNINFORMATIVE_CODES}                      |
     *
     * Real responses this was read against — quoted from the providers'
     * public documentation, not observed from this site's relays:
     *
     * - Google Workspace, « Gmail SMTP errors and codes »
     *   (knowledge.workspace.google.com, admin/support/troubleshooting/
     *   gmail-smtp-errors-and-codes): « 550 5.4.5 Daily user sending
     *   limit exceeded. », « 550 5.4.5 Daily SMTP relay limit exceeded for
     *   user. », « 550 5.7.1 Daily SMTP relay sending limit exceeded for
     *   this customer. », « 550 5.1.1 The email account that you tried to
     *   reach does not exist. », « 550 5.2.1 The email account that you
     *   tried to reach is inactive. », « 552 5.2.2 The recipient's inbox
     *   is out of storage space and inactive. ».
     * - Microsoft, « Email nondelivery reports (NDRs) and SMTP errors in
     *   Exchange Online » (learn.microsoft.com): « 5.1.90 Your message
     *   can't be sent because you've reached your daily limit for message
     *   recipients », « 5.2.2 Submission quota exceeded », « 5.1.8 Access
     *   denied, bad outbound sender », « 5.2.122 Recipient's per hour
     *   message receive limit exceeded », « 5.1.10 Recipient not found ».
     *
     * The tests carry each of them (`Tests\Core\Mail\Transport\
     * MailFailureTest::responses()`).
     *
     * @var list<string>
     */
    private const RECIPIENT_CODES = ['5.1.0', '5.1.1', '5.1.2', '5.1.3', '5.1.4', '5.1.6', '5.1.10', '5.2.1'];

    /**
     * Enhanced codes that carry no more than the basic code does, and are
     * read past as if absent.
     *
     * `5.0.0` is RFC 3463's « other undefined status ». `5.5.0`, « other
     * or undefined protocol status », is what Microsoft puts in front of
     * an address nobody holds on outlook.com and hotmail.com: « 550 5.5.0
     * Requested action not taken: mailbox unavailable ». Letting either
     * decide would make those unknown addresses the provider's, retried
     * for a day and counted as strikes; read past, the 550 and the prose
     * decide, as they did before #1488.
     *
     * @var list<string>
     */
    private const UNINFORMATIVE_CODES = ['5.0.0', '5.5.0'];

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

        // **Policy refusals are read first, and they are the provider's.**
        // RFC 3463's 5.7.x family says « refused on principle » without
        // saying whose principle: « 550 5.7.1 Relay access denied » means
        // the credentials this site uses are not allowed to send AT ALL,
        // and « 550 5.7.60 Client does not have permissions to send as
        // this sender » is the same thing in Microsoft's words. Both
        // arrive with a 550 in front of them, so this test has to come
        // before the recipient markers below — reading them in the other
        // order matches `550` on the first pass and calls a relay that
        // refuses everything a bad address, which is exactly the case the
        // recipient list exists to keep out. Two harms at once: the
        // breaker never opens on a dead relay, and `MailService` discards
        // the message instead of queueing it.
        //
        // The cost of this direction is a message retried for a day
        // against a relay that will keep refusing it — the same cost as
        // any unknown failure, and the one this enum already chooses.
        //
        // Bounded on both sides like the codes below (#1571 review): no
        // letter, digit, underscore or dot before the class, and no digit
        // — nor a dot followed by one — after the detail, so neither
        // « server=v4.7.1 » (a version) nor « [4.7.1.9] » (an address)
        // turns a « 550 5.1.1 » into the relay's failure.
        if (preg_match('/(?<![\w.])[45]\.7\.\d{1,3}(?!\.?\d)/', $haystack) === 1) {
            return self::Provider;
        }

        // **An enhanced status code, when there is one, decides** (issue
        // #1488). The basic code cannot: `550` is RFC 5321's « mailbox
        // unavailable » and relays use it for whatever they refuse —
        // Google answers « 550 5.4.5 Daily user sending limit exceeded »
        // when the ACCOUNT has spent its quota. Read as a bad address,
        // that refusal failed the message instead of queueing it, never
        // counted against a relay that will refuse everything until
        // tomorrow, and abandoned an already-queued message at its first
        // retry. The enhanced code says what the 550 does not; the rule
        // it is read by is RECIPIENT_CODES below.
        $codes = self::enhancedCodes($reason);
        if ($codes !== []) {
            // Every code has to name the recipient. One that does not is
            // the provider's, the same direction as an unknown reason.
            foreach ($codes as $code) {
                if (!in_array($code, self::RECIPIENT_CODES, true)) {
                    return self::Provider;
                }
            }

            return self::Recipient;
        }

        // No enhanced code: the basic codes and the prose are all there
        // is. `550` stays a recipient refusal here, as it always was — a
        // relay that says only « 550 » has said nothing better, and the
        // common case of a bare 550 is still an address nobody holds.
        // `551` and `553` are RFC 5321's « user not local » and « mailbox
        // name not allowed »; the phrases are the wording of relays that
        // answer in prose rather than codes.
        $recipientMarkers = [
            '550', '551', '553',
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

    /**
     * The reason as the rest of the chain keeps it — without addresses and
     * bounded at 400 characters ({@see MailErrorRedaction::withoutAddresses()})
     * — but **still carrying the code it is classified by** (#1571 review).
     * PHPMailer appends the enhanced code at the very end of a long
     * message, so the cap alone can cut it off; the breaker, the queue and
     * the drain then read the bare « 550 » that is left and take a quota
     * refusal for a refused address. When the cut text no longer reads
     * like the whole, its last characters give way to the code, in
     * brackets.
     */
    public static function boundedReason(string $text): string
    {
        $whole = MailErrorRedaction::addressesRemoved($text);
        $bounded = MailErrorRedaction::withoutAddresses($text);
        $code = self::enhancedCode($whole);
        if ($code === null || ($bounded === $whole) || self::enhancedCode($bounded) === $code) {
            return $bounded;
        }

        $suffix = ' [' . $code . ']';

        return mb_substr($bounded, 0, max(0, mb_strlen($bounded) - mb_strlen($suffix))) . $suffix;
    }

    /**
     * The enhanced status code {@see classify()} read the reason by, or
     * null when the relay gave none — normalised (`5.04.005` reads
     * `5.4.5`).
     *
     * For the journal: a closed value somebody can filter on, where the
     * reason around it is free text. When there are several, it is the
     * one that decided — the first that is not a recipient's, since one is
     * enough to make the failure the provider's. A code that says nothing
     * ({@see UNINFORMATIVE_CODES}) is still returned when it is all there
     * is: it is what the relay said, even though the classification reads
     * past it.
     */
    public static function enhancedCode(string $reason): ?string
    {
        $informative = self::enhancedCodes($reason);

        foreach ($informative as $code) {
            if (!in_array($code, self::RECIPIENT_CODES, true)) {
                return $code;
            }
        }

        return $informative[0] ?? self::enhancedCodesIn($reason)[0] ?? null;
    }

    /**
     * Every informative enhanced status code in the reason, in order.
     *
     * @return list<string>
     */
    private static function enhancedCodes(string $reason): array
    {
        return array_values(array_filter(
            self::enhancedCodesIn($reason),
            static fn(string $code): bool => !in_array($code, self::UNINFORMATIVE_CODES, true)
        ));
    }

    /**
     * Every class-4 and class-5 enhanced status code in the reason
     * (RFC 3463 §2: `class.subject.detail`, the last two of one to three
     * digits).
     *
     * Found anywhere in the text, because that is where PHPMailer leaves
     * it: its SMTP client cuts « 550-5.4.5 » off the server's lines and
     * re-appends the code as « Additional SMTP info: 5.4.5 », except when
     * the detail has three digits (« 5.7.705 », « 5.2.121 »), which its
     * pattern does not take and which therefore stays in the text after
     * « 550 ». Both shapes, and the raw « 550 5.4.5 » and « 550-5.4.5 »,
     * are read the same.
     *
     * Bounded on both sides so that a dotted number is not mistaken for
     * one: no letter, digit, underscore or dot may come before the class
     * (#1571 review: « server=v4.97.1 » is a version, not a code), and no
     * digit — nor a dot followed by one — after the detail. « 10.5.1.2 »
     * (an address), « 5.1.1.4 », « 192.168.5.1 » and « v4.97.1 » therefore
     * yield nothing,
     * and « 5.1.1. » at the end of a sentence still yields 5.1.1. Class 2
     * is success and is never a reason, so it is not read.
     *
     * @return list<string>
     */
    private static function enhancedCodesIn(string $reason): array
    {
        $pattern = '/(?<![\w.])([45])\.(\d{1,3})\.(\d{1,3})(?!\.?\d)/';
        if (preg_match_all($pattern, $reason, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        return array_map(
            static fn(array $match): string => $match[1] . '.' . (int) $match[2] . '.' . (int) $match[3],
            $matches
        );
    }
}
