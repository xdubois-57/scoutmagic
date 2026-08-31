<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mime;

/**
 * Is this message automatic — a newsletter, a bounce, an acknowledgement,
 * spam?
 *
 * **A technical observation, never a judgement.** Everything here reads a
 * header the sender's own software set; nothing looks at the subject, the
 * wording or the address book. That is why the answer is computed once, on
 * arrival, and is never editable by hand: there is nothing for a human to
 * correct, only headers to read.
 *
 * **The answer changes nothing about storage or analysis.** A flagged
 * message is stored like any other and offered to every consumer like any
 * other — which is indispensable rather than incidental: a great many
 * booking platforms send their notifications with `Precedence: bulk`, and a
 * flag that suppressed analysis would quietly lose a unit its rental
 * enquiries. All the flag does is hide the message from the general
 * mailbox's default view, behind a filter that shows it again.
 *
 * A message flagged wrongly is therefore never lost: it is one click away,
 * and it comes back into the ordinary view the moment it carries an
 * association or a proposition.
 */
class BulkMailDetector
{
    /**
     * Local parts that only ever belong to a mail system talking to
     * itself. RFC 5321 §4.5.1 reserves `postmaster`; `mailer-daemon` is
     * universal by convention.
     */
    private const SYSTEM_LOCAL_PARTS = ['mailer-daemon', 'postmaster'];

    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function detect(array $headers, string $fromEmail): bool
    {
        // 1. Precedence — the oldest of the signals, and still the most
        //    widely set. 'bulk', 'list' and 'junk' are the three values
        //    that mean "not a person writing to a person".
        $precedence = strtolower(trim($headers['precedence'] ?? ''));
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return true;
        }

        // 2. Auto-Submitted (RFC 3834). Anything other than 'no' is the
        //    sender's own software declaring it generated the message —
        //    'auto-replied', 'auto-generated', and whatever comes next.
        $autoSubmitted = strtolower(trim($headers['auto-submitted'] ?? ''));
        if ($autoSubmitted !== '' && !str_starts_with($autoSubmitted, 'no')) {
            return true;
        }

        // 3. List-Unsubscribe (RFC 2369). Its mere presence is the point:
        //    a message you can unsubscribe from is a message you were
        //    subscribed to.
        if (trim($headers['list-unsubscribe'] ?? '') !== '') {
            return true;
        }

        // 4. X-Spam-Flag, as set by SpamAssassin and the filters that
        //    imitate it. Only an explicit YES — the header is present and
        //    reads 'NO' on most of a unit's ordinary mail.
        if (strtoupper(trim($headers['x-spam-flag'] ?? '')) === 'YES') {
            return true;
        }

        // 5. The sender is a mail system rather than a person.
        return $this->isSystemSender($fromEmail);
    }

    private function isSystemSender(string $fromEmail): bool
    {
        $at = strpos($fromEmail, '@');
        $localPart = strtolower($at === false ? $fromEmail : substr($fromEmail, 0, $at));

        // A bare 'mailer-daemon' with no domain is a real thing some
        // servers send, which is why the whole string is the fallback.
        return in_array(trim($localPart), self::SYSTEM_LOCAL_PARTS, true);
    }
}
