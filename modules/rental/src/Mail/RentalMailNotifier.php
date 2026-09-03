<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Core\Import\MemberYearRepository;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;

/**
 * Tells the managers of an asset that a message is waiting for their
 * decision on one of its bookings (`rental.mail_proposition`).
 *
 * **Per asset, and only that asset's bookings.** A message proposed
 * towards two bookings of two assets tells each asset's managers about
 * their own booking and nothing about the other: a manager reads the
 * references of the assets they manage, not the unit's.
 *
 * **Nothing personal travels.** The reference, the asset's name and the
 * dates — never the sender, never the subject, never a line of the
 * message: a notification is copied to a phone and to an inbox, and the
 * message itself is one click away for the people allowed to read it
 * (§6.29, the same stance as every rental reminder).
 */
class RentalMailNotifier
{
    public const TYPE_PROPOSITION = 'rental.mail_proposition';

    public function __construct(
        private NotificationService $notifications,
        private RentalAssetManagerRepository $managers,
        private MemberYearRepository $memberYears,
        private UserAccountRepository $userAccounts,
        private RentalAssetRepository $assets
    ) {
    }

    /**
     * @param RentalBooking[] $bookings the bookings proposed, in the order the consumer ranked them
     * @param array<string, string> $labels reference => how a manager names the booking
     * @param array<string, string> $urls reference => the booking's page, when known
     */
    public function proposed(array $bookings, array $labels, array $urls): void
    {
        $byAsset = [];
        foreach ($bookings as $booking) {
            $byAsset[$booking->assetId][] = $booking;
        }

        foreach ($byAsset as $assetId => $assetBookings) {
            $recipients = $this->managersOf((int) $assetId);
            if ($recipients === []) {
                continue;
            }

            $asset = $this->assets->findById((int) $assetId);
            $named = array_map(
                static fn(RentalBooking $b): string => $labels[$b->reference] ?? $b->reference,
                $assetBookings
            );

            $this->notifications->dispatch(
                self::TYPE_PROPOSITION,
                $recipients,
                [
                    'title' => 'Un message attend votre décision'
                        . ($asset !== null ? ' — ' . $asset->name : ''),
                    'body' => count($named) === 1
                        ? 'Un e-mail reçu pourrait concerner la réservation ' . $named[0]
                            . '. Confirmez ou écartez la proposition sur la réservation.'
                        : 'Un e-mail reçu pourrait concerner l\'une de ces réservations : '
                            . implode(' ; ', $named)
                            . '. Le site n\'a choisi aucune : confirmez ou écartez la proposition sur la bonne.',
                    'url' => $urls[$assetBookings[0]->reference] ?? null,
                ]
            );
        }
    }

    /**
     * The active managers of an asset who hold an account on this site —
     * the same resolution as the rental reminders (§6.29).
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function managersOf(int $assetId): array
    {
        $recipients = [];
        foreach ($this->managers->findAllByAsset($assetId, true) as $manager) {
            $blindIndex = $this->memberYears->findMostRecentEmailBlindIndexForMember($manager->memberId);
            if ($blindIndex === null || $blindIndex === '') {
                continue;
            }
            $account = $this->userAccounts->findByBlindIndex($blindIndex);
            if ($account === null) {
                continue;
            }
            $recipients[$account->id] ??= ['userAccountId' => $account->id, 'memberId' => $manager->memberId];
        }

        return array_values($recipients);
    }
}
