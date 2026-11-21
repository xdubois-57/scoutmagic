<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Core\Config\SettingService;
use Core\Mail\MailIdentity;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalBookingRepository;

/**
 * Which address a manager may add to a booking's « Autres adresses du
 * locataire » (issue #1106).
 *
 * An address added there makes the mail matching file the messages from
 * and to it on the booking, and the booking's managers read them,
 * attachments included. A manager is often not of the unit's staff, and
 * a free field let one type in any correspondent of the unit — the
 * federation, a supplier, a parent — and read the unit's mail with them.
 * `SECURITY.md` § 33 rules that out: a manager who may open a booking
 * does not thereby gain a window onto the unit's correspondence.
 *
 * So:
 * - **a manager who is not Staff d'U chooses among the addresses already
 *   on the booking's own mail** — the treasurer who answered in the
 *   thread, or whom the unit wrote to. They never type one: what the
 *   booking's mail has not shown them, they have no business reading;
 * - **Staff d'U keeps the free field**: they may already read the unit's
 *   mail, and adding the renter's address before the box is opened is how
 *   its first message is filed straight away;
 * - **nobody adds one of the unit's own addresses** — the site's sending,
 *   reply and contact addresses and the boxes this module reads, tag included
 *   (`+…`): matched as « the renter », they would file the unit's whole
 *   correspondence on one booking. **Nor any address on the unit's own
 *   domain** (issue #1253) — the domain the site sends from, the one it
 *   signs for: `tresorier@<the unit's domain>`, seen in the `To:` of a
 *   message the unit sent, is a colleague, not the renter. The domain of a
 *   box this module reads proves nothing by itself — a box is as often on
 *   a webmail as on the unit's own domain —, and a domain everybody shares
 *   (`gmail.com`, `skynet.be`…) is never the unit's even when the site
 *   sends from it: there only the exact addresses count;
 * - **a received message whose sender nobody vouched for shows nobody**
 *   (issue #1253, after #1121): the booking's signed reply address files
 *   whatever reaches it, so a manager could otherwise send it a message
 *   with a forged `From:` — the federation, a supplier — and then pick that
 *   correspondent from the list. Staff d'U is not concerned: it may type
 *   any address anyway.
 *
 * A typed address also counts only inside the booking's window
 * (`RentalMessageConsumer::addressMatch()`).
 */
final class OtherRenterEmailRule
{
    /**
     * Domains shared by people who have nothing to do with each other — the
     * public webmails and the Belgian and French access providers a unit's
     * box or a renter is most likely on. A unit whose box is on one of them
     * does not own the domain, and excluding it would refuse every renter
     * who writes from it.
     */
    private const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com',
        'outlook.com', 'outlook.be', 'outlook.fr', 'hotmail.com', 'hotmail.be', 'hotmail.fr',
        'live.com', 'live.be', 'live.fr', 'msn.com',
        'yahoo.com', 'yahoo.be', 'yahoo.fr', 'ymail.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'pm.me',
        'gmx.com', 'gmx.net', 'gmx.fr', 'gmx.de', 'aol.com',
        'mail.com', 'email.com', 'usa.com', 'post.com', 'europe.com', 'zoho.com', 'zohomail.eu',
        'tutanota.com', 'tuta.io', 'yandex.com', 'mailo.com',
        'ik.me', 'ikmail.com', 'etik.com',
        'skynet.be', 'proximus.be', 'telenet.be', 'voo.be', 'scarlet.be', 'base.be', 'swing.be',
        'orange.fr', 'wanadoo.fr', 'free.fr', 'laposte.net', 'sfr.fr',
    ];

    /** @var list<string>|null the unit's own addresses, read once */
    private ?array $unitAddresses = null;

    /** @var list<string>|null the unit's own domains, read once */
    private ?array $unitDomains = null;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private ?InboundMailInterface $inboundMail,
        /** The site's sending, reply and contact addresses; null: none known. */
        private ?SettingService $settings = null
    ) {
    }

    /**
     * The addresses on the booking's own mail that may still be added: the
     * person on the other side of each message filed under it — the sender
     * of a received one, the recipients of one the unit sent — minus the
     * renter's, the ones already added and the unit's own.
     *
     * **Only the other side.** The unit's side of a message — the
     * recipients of what it received, the sender of what it sent — is the
     * unit's own address, and it is very often an alias delivering into a
     * box with another name, so the box's own address does not catch it.
     * Taking only the other side keeps it out of the list whatever it is
     * called, and any address seen on the unit's side is left out even when
     * it also shows on the other.
     *
     * @return list<string>
     */
    public function seenAddresses(RentalBooking $booking): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        $otherSide = [];
        $unitSide = [];
        $messages = $this->inboundMail->findForReference(RentalMessageConsumer::CONSUMER_ID, $booking->reference);
        foreach ($messages as $message) {
            // A forged `From:` is not somebody the booking's mail showed
            // (issue #1253): the unit's side still counts below, as it is
            // the box's own `To:` whoever wrote the `From:`.
            $theirs = $message->isSent() ? $message->toEmails
                : ($message->senderUnverified() ? [] : [$message->fromEmail]);
            $ours = $message->isSent() ? [$message->fromEmail] : $message->toEmails;
            foreach ($theirs as $address) {
                $otherSide[RentalBookingRepository::normalizeEmail($address)] = true;
            }
            foreach ($ours as $address) {
                $unitSide[self::withoutTag(RentalBookingRepository::normalizeEmail($address))] = true;
            }
        }

        $choices = [];
        foreach (array_keys($otherSide) as $address) {
            $address = (string) $address;
            if ($address === ''
                || filter_var($address, FILTER_VALIDATE_EMAIL) === false
                || isset($unitSide[self::withoutTag($address)])
                || $this->isUnitAddress($address)
                || $this->bookingRepository->isAddressOfBooking($booking, $address)
            ) {
                continue;
            }
            $choices[] = $address;
        }
        sort($choices);

        return $choices;
    }

    /**
     * Why this address may not be added, in French, or null when it may.
     *
     * @param string $normalized already normalised and valid
     * @param bool $mayTypeAny whether the actor is Staff d'U
     */
    public function refusal(RentalBooking $booking, string $normalized, bool $mayTypeAny): ?string
    {
        if ($this->isUnitAddress($normalized)) {
            return "Cette adresse est l'une de celles de l'unité : elle ne peut pas être une adresse du locataire.";
        }

        if (!$mayTypeAny && !in_array($normalized, $this->seenAddresses($booking), true)) {
            return "Choisissez une adresse parmi celles du courrier de cette réservation. "
                . "Une autre adresse ne peut être ajoutée que par le staff d'unité.";
        }

        return null;
    }

    /**
     * Whether $address is one of the unit's own, `+tag` ignored on both
     * sides, or on one of the unit's own domains.
     */
    private function isUnitAddress(string $address): bool
    {
        if ($this->unitAddresses === null || $this->unitDomains === null) {
            $addresses = [];
            $owned = [];
            if ($this->settings !== null) {
                $identity = MailIdentity::fromSettings($this->settings);
                $addresses = [
                    $identity->fromAddress,
                    $identity->replyAddress(),
                    (string) ($this->settings->get('contact_email') ?? ''),
                ];
                // The domain the site sends from is the one it signs for,
                // and the only one taken as the unit's. Neither the contact
                // address nor a box this module reads is: either is often
                // on a webmail, or on a provider too small for the list
                // above, and owning its domain would refuse every renter
                // on it — Staff d'U included.
                $owned[] = $identity->fromAddress;
            }
            foreach ($this->inboundMail?->probeAddressesFor(RentalMessageConsumer::CONSUMER_ID) ?? [] as $box) {
                $addresses[] = $box;
            }
            $this->unitAddresses = array_values(array_filter(array_map(
                static fn(string $unit): string => self::withoutTag(RentalBookingRepository::normalizeEmail($unit)),
                $addresses
            )));
            $this->unitDomains = array_values(array_unique(array_filter(
                array_map(
                    static fn(string $unit): string => self::domainOf(RentalBookingRepository::normalizeEmail($unit)),
                    $owned
                ),
                static fn(string $domain): bool => $domain !== '' && !in_array($domain, self::SHARED_DOMAINS, true)
            )));
        }

        return in_array(self::withoutTag($address), $this->unitAddresses, true)
            || in_array(self::domainOf($address), $this->unitDomains, true);
    }

    /** `tresorier@unite.be` → `unite.be`; '' when there is no `@`. */
    private static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : substr($address, $at + 1);
    }

    /** `location+LOC-K7Q2M4@unite.be` → `location@unite.be`. */
    private static function withoutTag(string $address): string
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }
        $local = substr($address, 0, $at);
        $plus = strpos($local, '+');

        return ($plus === false ? $local : substr($local, 0, $plus)) . substr($address, $at);
    }
}
