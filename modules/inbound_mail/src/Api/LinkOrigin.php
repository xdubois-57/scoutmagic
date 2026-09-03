<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * How a message came to be attached to a business object (§7.6), from the
 * most reliable to the weakest.
 *
 * **Shown to the manager, never hidden.** A message attached because its
 * subject carried an explicit reference is a near-certainty; one attached
 * because the sender's address matched inside a time window is a guess, and
 * a manager reading a thread deserves to know which of the two they are
 * looking at.
 */
enum LinkOrigin: string
{
    /** An explicit business reference in the subject. */
    case REFERENCE = 'reference';

    /** RFC 5322 threading: In-Reply-To / References pointing at a known message. */
    case THREAD = 'thread';

    /** The sender's address, bounded by a time window around the object. */
    case SENDER = 'sender';

    /**
     * The message announces a period, and the consumer knows exactly one
     * business object covering it.
     *
     * Weaker than a thread and stronger than nothing, which is the gap it
     * was written for: two messages about one booking are rarely replies
     * to each other — a confirmation and a covering note carry different
     * subjects and no common `References` — and their sender is as often
     * the unit itself as the site. Dates stated to the day, matching one
     * object and one only, are what remains of the evidence.
     *
     * Not certain, and the interface says so: a quotation from a second
     * site for the same weekend states the same period truthfully.
     */
    case PERIOD = 'period';

    /**
     * The message names the IBAN of exactly one of the unit's accounts, on
     * a box the unit declared to be its treasury's — or the IBAN and the
     * sender's own section agree. Two independent statements about the
     * money, which is what makes it an association rather than a
     * proposition; still not certain, and the interface says so.
     */
    case IBAN = 'iban';

    /** A model's suggestion, used only where 1-3 failed or were ambiguous. */
    case AI = 'ai';

    /**
     * The message carried a document of a type this consumer files, and
     * nothing else said where it belongs.
     *
     * The weakest origin there is, and deliberately expressible: a
     * consumer reading a box the unit declared to be **its own** knows the
     * document is its business without knowing whose. Refusing to record
     * that would mean either dropping the document or filing it somewhere
     * it does not belong, and both are worse than keeping it where
     * somebody can sort it.
     */
    case ATTACHMENT = 'attachment';

    /**
     * Somebody decided. The strongest origin of all — and the only one that
     * names an author, since a screen saying "manual association" without
     * being able to say by whom helps nobody settle a disputed filing.
     */
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::REFERENCE => 'Référence dans le sujet',
            self::THREAD => 'Réponse dans la conversation',
            self::SENDER => 'Adresse de l\'expéditeur',
            self::PERIOD => 'Période annoncée dans le message',
            self::IBAN => 'IBAN du compte cité dans le message',
            self::AI => 'Suggestion automatique',
            self::ATTACHMENT => 'Pièce jointe, destinataire inconnu',
            self::MANUAL => 'Association manuelle',
        };
    }

    /**
     * Whether this origin is certain enough to be presented without a
     * caveat. Sender matching, an announced period, AI and a bare
     * attachment are not: each can attach a message to the wrong file, and
     * the interface says so.
     */
    public function isCertain(): bool
    {
        return $this === self::REFERENCE || $this === self::THREAD || $this === self::MANUAL;
    }
}
