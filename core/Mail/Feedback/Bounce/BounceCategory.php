<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

/**
 * Why a message came back, in the four kinds a parent can act on
 * (roadmap IT-05).
 *
 * **Four, and never the server's own sentence.** `550 5.1.1 recipient
 * rejected` means nothing to somebody collecting their child from a
 * meeting, and the full text a remote server returns has no business on a
 * screen at all: it quotes the address back (SECURITY.md §11) and it is
 * written by a stranger's software. So the diagnostic is read, mapped, and
 * dropped — what survives is one of these four, each with the gesture that
 * goes with it.
 *
 * **Mapped from the enhanced status code, not from the words.** RFC 3463
 * gives `class.subject.detail`, and the subject is what separates a full
 * mailbox from an address that never existed. Free text varies by vendor,
 * by version and by language; the code does not. Where a server sends no
 * code at all, {@see self::Refused} is the honest answer — « le serveur a
 * refusé » is true of every failure, and guessing a more specific one from
 * prose would put a wrong instruction in front of a parent.
 */
enum BounceCategory: string
{
    /** X.2.2 — the mailbox exists and cannot hold another message. */
    case MailboxFull = 'mailbox_full';

    /** X.1.1, X.1.6 — nothing of that name at that domain. */
    case NoSuchAddress = 'no_such_address';

    /** X.7.x and everything unclassified: the far end said no. */
    case Refused = 'refused';

    /** X.4.x — the far end could not be reached to ask. */
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::MailboxFull => 'Boîte pleine',
            self::NoSuchAddress => 'Adresse inexistante',
            self::Refused => 'Message refusé par le serveur destinataire',
            self::Unreachable => 'Serveur injoignable',
        };
    }

    /**
     * What the person who owns the address should do — written to them,
     * not about them.
     *
     * Each one ends in something doable. « Contactez votre fournisseur »
     * is the last resort and appears once: an instruction nobody can carry
     * out reads as a refusal to help.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::MailboxFull => 'Votre boîte de réception est pleine et ne peut plus rien recevoir. '
                . 'Faites-y de la place, puis réactivez l’adresse ci-dessous.',
            self::NoSuchAddress => 'Le serveur destinataire dit que cette adresse n’existe pas. '
                . 'Vérifiez l’orthographe : une lettre en trop suffit. Si l’adresse est bonne et que '
                . 'vous venez de la créer ou de la corriger chez votre fournisseur, réactivez-la ci-dessous.',
            self::Refused => 'Le serveur qui reçoit votre courrier a refusé nos messages. '
                . 'C’est souvent un filtre anti-spam un peu strict : ajoutez notre adresse d’expédition '
                . 'à vos contacts, puis réactivez l’adresse ci-dessous.',
            self::Unreachable => 'Nous n’avons pas réussi à joindre le serveur de votre fournisseur. '
                . 'C’est en général passager. Si cela dure, c’est chez votre fournisseur qu’il faut '
                . 'demander, pas ici.',
        };
    }

    /**
     * Read the category out of an RFC 3463 enhanced status code.
     *
     * The class digit (4 or 5) is deliberately ignored here: whether a
     * failure is temporary is {@see BounceSeverity}'s question, and « boîte
     * pleine » describes the same situation whichever way the far end
     * chose to report it. Keeping the two apart is what lets a mailbox
     * that fills up be retried and the same mailbox, reported permanent,
     * be blocked — without two mappings that could disagree.
     */
    public static function fromStatusCode(string $status): self
    {
        $parts = explode('.', trim($status));
        if (count($parts) !== 3) {
            return self::Refused;
        }

        $subject = $parts[1];
        $detail = $parts[2];

        return match (true) {
            $subject === '2' && $detail === '2' => self::MailboxFull,
            $subject === '1' && in_array($detail, ['1', '6'], true) => self::NoSuchAddress,
            $subject === '4' => self::Unreachable,
            default => self::Refused,
        };
    }
}
