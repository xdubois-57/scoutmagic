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
 *   thread, a partner copied by the renter. They never type one: what the
 *   booking's mail has not shown them, they have no business reading;
 * - **Staff d'U keeps the free field**: they may already read the unit's
 *   mail, and adding the renter's address before the box is opened is how
 *   its first message is filed straight away;
 * - **nobody adds one of the unit's own addresses** — the site's sending,
 *   reply and contact addresses and the boxes this module reads, tag included
 *   (`+…`): matched as « the renter », they would file the unit's whole
 *   correspondence on one booking.
 *
 * A typed address also counts only inside the booking's window
 * (`RentalMessageConsumer::addressMatch()`).
 */
final class OtherRenterEmailRule
{
    /** @var list<string>|null the unit's own addresses, read once */
    private ?array $unitAddresses = null;

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private ?InboundMailInterface $inboundMail,
        /** The site's sending, reply and contact addresses; null: none known. */
        private ?SettingService $settings = null
    ) {
    }

    /**
     * The addresses on the booking's own mail that may still be added:
     * the senders and recipients of the messages filed under it, minus the
     * renter's, the ones already added and the unit's own.
     *
     * @return list<string>
     */
    public function seenAddresses(RentalBooking $booking): array
    {
        if ($this->inboundMail === null) {
            return [];
        }

        $seen = [];
        $messages = $this->inboundMail->findForReference(RentalMessageConsumer::CONSUMER_ID, $booking->reference);
        foreach ($messages as $message) {
            foreach ([$message->fromEmail, ...$message->toEmails] as $address) {
                $normalized = RentalBookingRepository::normalizeEmail($address);
                if ($normalized !== '' && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false) {
                    $seen[$normalized] = true;
                }
            }
        }

        $choices = [];
        foreach (array_keys($seen) as $address) {
            if (!$this->isUnitAddress($address) && !$this->bookingRepository->isAddressOfBooking($booking, $address)) {
                $choices[] = $address;
            }
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

    /** Whether $address is one of the unit's own, `+tag` ignored on both sides. */
    private function isUnitAddress(string $address): bool
    {
        if ($this->unitAddresses === null) {
            $addresses = [];
            if ($this->settings !== null) {
                $identity = MailIdentity::fromSettings($this->settings);
                $addresses = [
                    $identity->fromAddress,
                    $identity->replyAddress(),
                    (string) ($this->settings->get('contact_email') ?? ''),
                ];
            }
            foreach ($this->inboundMail?->probeAddressesFor(RentalMessageConsumer::CONSUMER_ID) ?? [] as $box) {
                $addresses[] = $box;
            }
            $this->unitAddresses = array_values(array_filter(array_map(
                static fn(string $unit): string => self::withoutTag(RentalBookingRepository::normalizeEmail($unit)),
                $addresses
            )));
        }

        return in_array(self::withoutTag($address), $this->unitAddresses, true);
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
