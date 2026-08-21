<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A message as a consumer sees it **while deciding whether to keep it** —
 * before anything is written.
 *
 * Deliberately not `InboundMessage`: that one has a database id and a
 * business reference, and a candidate has neither yet. Building one would
 * mean writing the message down to ask whether it should be written down.
 *
 * The bodies here are already sanitised, since a consumer looking for a
 * reference in the text must not be the place where raw attacker-supplied
 * HTML is first handled.
 */
class CandidateMessage
{
    /**
     * @param string[] $references the RFC 5322 References chain, oldest first
     * @param string[] $toEmails every recipient the message names, so a
     *   consumer can recognise a plus-address or a per-object alias of its
     *   own without this module knowing anything about it
     */
    public function __construct(
        /**
         * Which configured mailbox this arrived in. A consumer that is only
         * meant to listen to one of the unit's boxes needs it to say so —
         * without it, configuring `rental` to watch `locations@` would not
         * stop it claiming a reply that landed in `tresorerie@`.
         */
        public readonly int $mailboxId,
        public readonly string $subject,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $toEmails,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $bodyText,
        public readonly string $bodyHtml
    ) {
    }

    /**
     * The message ids this one answers, most specific first: In-Reply-To
     * names the direct parent, References the whole chain. A consumer that
     * knows one of them knows the thread.
     *
     * @return string[]
     */
    public function threadMessageIds(): array
    {
        $ids = $this->inReplyTo !== null && $this->inReplyTo !== '' ? [$this->inReplyTo] : [];

        foreach (array_reverse($this->references) as $reference) {
            if ($reference !== '' && !in_array($reference, $ids, true)) {
                $ids[] = $reference;
            }
        }

        return $ids;
    }
}
