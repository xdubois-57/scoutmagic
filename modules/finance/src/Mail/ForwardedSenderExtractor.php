<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Mail;

/**
 * The address of whoever wrote the message **before** it was forwarded,
 * read out of the forwarded text.
 *
 * **This is untrusted text, and it is treated as a hint and never as
 * proof.** A `From:` header is already unauthenticated; a line inside a
 * body is written by the sender outright, so anybody could paste
 * « De : tresorier@unite.be » into a message and aim a document at the
 * account of their choice. Two things bound the damage, and neither is in
 * this class:
 *
 *  - what an address can win here is where a **document** gets filed —
 *    never an amount, never a movement, never a euro;
 *  - the address still has to resolve to a member who animates exactly one
 *    staff (`SenderStaffAccountResolver`), so an invented address wins
 *    nothing at all.
 *
 * **Only consulted when the real `From:` resolved to nothing.** An
 * animateur forwarding a supplier's receipt to the unit's treasury address
 * *is* the `From:`, and that case is already answered before this class is
 * reached — which is also the case where the answer is trustworthy.
 *
 * Every mail client writes the forwarded header its own way, so this reads
 * the shapes rather than one format: a line opening with the French or
 * English label, and the first address on it. Anything else is null, which
 * is a perfectly good answer — the message simply goes to the sorting pile.
 */
class ForwardedSenderExtractor
{
    /**
     * How far into the body the header block is looked for.
     *
     * A forwarded header sits at the top, under a separator line. Reading
     * the whole body instead would find the address in the supplier's own
     * signature at the bottom, which is a different person entirely — and
     * on a long thread it would find the oldest one rather than the
     * nearest.
     */
    private const MAX_SCANNED_LINES = 40;

    /**
     * `De`, `From`, `Expéditeur` — with or without the accent, since a
     * body that lost its charset on the way arrives as `Exp?diteur`.
     */
    private const LABEL_PATTERN = '/^\s*(?:de|from|exp[ée\?]diteur|absender|van)\s*:\s*(.+)$/iu';

    /**
     * An address as RFC 5322 lets one be written, kept deliberately
     * narrower than the RFC: nothing exotic ever appears in the header a
     * mail client generates, and a permissive pattern here would match
     * halves of URLs.
     */
    private const ADDRESS_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * @param string $bodyText the sanitised plain-text part
     * @param string $bodyHtml the sanitised HTML part, used only when there
     *                         is no text part — a phone forwarding a photo
     *                         often sends HTML alone
     */
    public function extract(string $bodyText, string $bodyHtml = ''): ?string
    {
        $body = trim($bodyText) !== '' ? $bodyText : self::asText($bodyHtml);

        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];

        foreach (array_slice($lines, 0, self::MAX_SCANNED_LINES) as $line) {
            if (preg_match(self::LABEL_PATTERN, $line, $labelled) !== 1) {
                continue;
            }

            if (preg_match(self::ADDRESS_PATTERN, $labelled[1], $address) === 1) {
                return strtolower($address[0]);
            }
        }

        return null;
    }

    /**
     * The HTML part as something the line patterns can read.
     *
     * `<br>` and the block tags become newlines first: stripping them
     * outright would run the whole forwarded header into one line, where
     * « De : » no longer opens anything and the anchored pattern stops
     * matching. Entities are decoded after, because a client writes
     * `De&nbsp;:` at least as often as `De :`.
     */
    private static function asText(string $html): string
    {
        $withBreaks = preg_replace('#<(?:br\s*/?|/p|/div|/tr|/li|/h[1-6])\s*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A non-breaking space is what a client leaves between the label
        // and its colon, and `\s` in a non-Unicode class does not match it.
        return str_replace("\u{00A0}", ' ', $text);
    }
}
