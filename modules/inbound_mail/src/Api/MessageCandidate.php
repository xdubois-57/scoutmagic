<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A **proposition**: "this message looks like it belongs to that object,
 * and here is why" — offered to somebody, never acted on by itself.
 *
 * Called « proposition » at the screen, never « candidat ».
 *
 * The difference from a `MessageLink` is who decides. A link is a
 * certainty the consumer is willing to act on unattended; a proposition is
 * a guess it is not, and which therefore has to be readable by the person
 * asked to confirm it. That is what `$label` and `$explanation` are for,
 * and why both are French sentences rather than internal codes: an
 * interface that says "correspondance faible (sender_window)" tells the
 * reader nothing they can decide on.
 *
 * **No central rule says how strong a proposition must be.** Each consumer
 * decides for itself and publishes what it proposes on
 * (`MessageConsumerInterface::describeEvidence()`), so a superadmin reads
 * the module's own declaration before opening a mailbox to it rather than
 * trusting a threshold nobody can see.
 */
class MessageCandidate
{
    public function __construct(
        public readonly string $businessReference,
        /**
         * What the target IS, in French and readable — « Location du
         * 12 juillet, Groupe Saint-Michel ». The reference alone
         * (`LOC-2027-0042`) is an identifier, not something a chief can
         * recognise at a glance.
         */
        public readonly string $label,
        /**
         * The kind of signal, as a stable machine value
         * ('sender_window', 'amount_and_date', …). Stored so a screen can
         * group and filter; never shown raw.
         */
        public readonly string $evidenceType,
        /**
         * Why this consumer is proposing it, as a French sentence shown to
         * the user: « L'expéditeur est le contact du séjour, et le message
         * est arrivé pendant la fenêtre du camp. »
         *
         * May quote the message, so it is stored encrypted.
         */
        public readonly string $explanation,
        /**
         * Which attachment this proposition is about, or **0 for the whole
         * message** — never null, for the same reason as `MessageLink`.
         */
        public readonly int $attachmentId = 0,
        /**
         * Filled in on the way OUT of storage, never by the consumer that
         * proposed this.
         *
         * A consumer builds a proposition before it has been written down,
         * so it has no id to give and no business naming its own
         * `consumer_id` — `Service\AnalysisResultApplier` stamps the
         * answering consumer's id exactly as it does for a link, or a
         * module could file a proposition under another module's name.
         * Zero and empty string mean « pas encore écrit ».
         */
        public readonly int $id = 0,
        public readonly string $consumerId = ''
    ) {
    }

    public function isWholeMessage(): bool
    {
        return $this->attachmentId === 0;
    }
}
