<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\ManagerRecipientResolver;

/**
 * « Nouveau message du locataire » (#720): a message just filed under a
 * booking is announced to the people who run its asset.
 *
 * The same door as « Nouvelle demande de location »: the notification
 * system, and the asset's reachable managers — the Staff d'U when it has
 * none (`ManagerRecipientResolver`). So a manager who switched it off on
 * their account is not told, and nobody is told twice by the same
 * delivery.
 *
 * **The booking, never the sender or the subject.** A notification travels
 * as a push to a phone's lock screen; what the renter wrote stays behind
 * the booking's own permission check, one tap away.
 *
 * **It never throws.** It is called while a message is being filed, and
 * the filing — the association, the attachments becoming documents — must
 * not depend on whether anybody could be told. A failure is journaled
 * instead: the booking's reference and the kind of error, never its text,
 * which may quote an address.
 */
class NewMessageNotifier
{
    public const TYPE = 'rental.new_message';

    public function __construct(
        private NotificationService $notifications,
        private ManagerRecipientResolver $recipients,
        private RentalAssetRepository $assetRepository,
        private ?JournalService $journal = null
    ) {
    }

    public function messageFiled(RentalBooking $booking): void
    {
        try {
            $this->announce($booking);
        } catch (\Throwable $e) {
            $this->journal?->log(
                'rental',
                'rental_new_message_not_sent',
                'error',
                '« Nouveau message du locataire » non envoyé pour la réservation ' . $booking->reference
                    . ' (' . (new \ReflectionClass($e))->getShortName() . ').',
                ['booking_id' => $booking->id]
            );
        }
    }

    private function announce(RentalBooking $booking): void
    {
        $asset = $this->assetRepository->findById($booking->assetId);
        if ($asset === null) {
            return;
        }

        $recipients = $this->recipients->recipientsFor($asset->id, 'new_message');
        if ($recipients === []) {
            return;
        }

        $this->notifications->dispatch(self::TYPE, $recipients, [
            'title' => 'Nouveau message du locataire — ' . $asset->name,
            'body' => 'Un e-mail a été rattaché à la réservation ' . $booking->reference . '.',
            'url' => '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id . '/courrier',
        ]);
    }
}
