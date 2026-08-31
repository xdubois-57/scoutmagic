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
     * @param CandidateAttachment[] $attachments metadata only — see that
     *   class for why there are no bytes here
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
        public readonly string $bodyHtml,
        /**
         * What the message carried, as names, types and sizes. The
         * interface has always said a candidate carries attachment
         * metadata; until this existed it did not, and a consumer whose
         * signal is « il y a une pièce jointe » had no way to ask.
         */
        public readonly array $attachments = [],
        /**
         * The raw header block, for the consumers whose whole signal is
         * in it (roadmap IT-22/IT-27: an authentication verdict, a relay
         * chain).
         *
         * Carried on every candidate, like the bodies above: what a
         * consumer may *analyse* is the message, and what is *kept* is
         * the separate question `Api\MessageRetentionPreference` answers.
         * Null when the client could not supply it.
         */
        public readonly ?string $rawHeaders = null,
        /**
         * The consumer id this box was declared **dedicated** to, or null
         * for a shared one — `Api\MailboxPurpose`, as the configuration
         * screen recorded it.
         *
         * One value rather than a per-consumer flag, because ONE candidate
         * is built and offered to every consumer in turn: each compares it
         * with its own id.
         *
         * What it is for: a signal that means nothing on a shared box can
         * mean something on a dedicated one. « Ce message porte une pièce
         * jointe » is a parent's holiday photo in the unit's public
         * mailbox and an expense receipt in the box the unit created for
         * its treasury — and only the operator's answer on the scope
         * screen tells the two apart.
         *
         * Last and defaulted so a test building a candidate says nothing
         * about mailbox purpose unless it means to; null reads as "shared",
         * which is the answer that grants a consumer nothing extra.
         */
        public readonly ?string $mailboxDedicatedTo = null
    ) {
    }

    /**
     * Whether the unit declared this box to be this consumer's own.
     *
     * Asked rather than compared inline, so a consumer cannot accidentally
     * test another module's id — which would be a module claiming a box
     * that was opened to somebody else.
     */
    public function mailboxIsDedicatedTo(string $consumerId): bool
    {
        return $this->mailboxDedicatedTo !== null && $this->mailboxDedicatedTo === $consumerId;
    }

    /**
     * Whether the message carried a file of one of these real types.
     *
     * A helper rather than a loop in every consumer, because the loop is
     * where somebody eventually compares against the *declared* type
     * instead of the sniffed one.
     *
     * @param string[] $mimeTypes
     */
    public function hasAttachmentOfType(array $mimeTypes): bool
    {
        foreach ($this->attachments as $attachment) {
            if (in_array($attachment->mimeType, $mimeTypes, true)) {
                return true;
            }
        }

        return false;
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
