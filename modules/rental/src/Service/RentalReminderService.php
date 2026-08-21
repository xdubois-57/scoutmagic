<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Reminder\DueReminder;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Reminder\ReminderPlanner;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalReminderRepository;

/**
 * Sending what `ReminderPlanner` says is due (§6.29).
 *
 * **Two channels that must never be confused**, and this class is the only
 * place that knows which is which:
 *
 * - The unit's own people get a `NotificationService::dispatch()`, which
 *   targets `user_accounts` and honours each person's channel settings.
 * - **The renter has no `user_account`**, so their one reminder — the
 *   practical-info email — goes out through `MailService`. Dispatching it
 *   as a notification would not fail; it would silently reach nobody, which
 *   is far worse.
 *
 * **Addressed, not broadcast.** A reminder about a hall goes to the people
 * who manage *that* hall, not to every manager in the unit — the ones who
 * cannot act on it would learn to ignore the channel, and the ones who can
 * would lose the signal in the noise.
 *
 * **No personal data leaves this class.** Titles and bodies name a booking
 * by its reference and an asset by its name, never a renter: a notification
 * lands in a push payload, a notification centre and possibly an email
 * subject, and none of those is a place for somebody's name (SECURITY.md
 * §5). The journal entries follow the same rule.
 */
class RentalReminderService
{
    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalAssetRepository $assetRepository,
        private RentalAssetManagerRepository $managerRepository,
        private RentalComplianceService $complianceService,
        private RentalReminderRepository $reminderRepository,
        private ReminderPlanner $planner,
        private MemberYearRepository $memberYearRepository,
        private UserAccountRepository $userAccountRepository,
        private JournalService $journal,
        private ?NotificationService $notificationService = null,
        private ?RentalPaymentService $paymentService = null,
        private ?RentalDocumentService $documentService = null,
        private ?RentalStayService $stayService = null,
        private ?RentalBookingMailService $mailService = null
    ) {
    }

    /**
     * One pass over everything that could be due.
     *
     * @return int how many reminders actually went out
     */
    public function run(\DateTimeImmutable $today): int
    {
        return $this->runForBookings($today) + $this->runForCompliance($today);
    }

    private function runForBookings(\DateTimeImmutable $today): int
    {
        $assets = [];
        foreach ($this->assetRepository->findAll() as $asset) {
            $assets[$asset->id] = $asset;
        }

        if ($assets === []) {
            return 0;
        }

        $sent = 0;
        foreach ($this->bookingRepository->findAllForAssets(array_keys($assets)) as $booking) {
            $asset = $assets[$booking->assetId] ?? null;
            if ($asset === null) {
                continue;
            }

            $due = $this->planner->forBooking(
                $booking,
                $asset,
                $this->paymentStatus($booking, $asset),
                $this->inventoryState($booking),
                $this->documentService?->latest($booking->id, \Modules\Rental\Document\DocumentType::CONTRACT) !== null,
                ($this->stayService?->settlementsFor($booking->id) ?? []) !== [],
                $today
            );

            foreach ($due as $reminder) {
                if ($this->send($reminder, $booking->renterEmail, $today)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function runForCompliance(\DateTimeImmutable $today): int
    {
        $sent = 0;

        foreach ($this->complianceService->dueForReminder($today) as $item) {
            $asset = $this->assetRepository->findById($item->assetId);
            if ($asset === null) {
                continue;
            }

            $reminder = $this->planner->forComplianceItem($item, $asset, $today);
            if ($reminder === null) {
                continue;
            }

            if ($this->send($reminder, null, $today)) {
                // Stamped on the entry as well as in the sent table: the
                // entry's own stamp is what a manager sees on the page
                // ("last warned on…"), and the table is what stops a repeat.
                $this->complianceService->markReminded($item->id, $today);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Claim the reminder, then send it down the right channel.
     *
     * Claiming first is deliberate: two scheduler ticks overlapping — which
     * shared hosting makes entirely possible when one run is slow — would
     * otherwise both find "not sent yet" and both send. The insert is the
     * compare-and-set, and the loser simply does nothing.
     */
    private function send(DueReminder $reminder, ?string $renterEmail, \DateTimeImmutable $today): bool
    {
        if (!$this->reminderRepository->claim($reminder->subjectType(), $reminder->subjectId, $reminder->kind, $today)) {
            return false;
        }

        $delivered = $reminder->kind->isInternal()
            ? $this->dispatchInternal($reminder)
            : $this->mailRenter($reminder, $renterEmail);

        if (!$delivered) {
            // Nothing went out, so nothing has been said: release the claim
            // rather than leaving the reminder permanently suppressed by a
            // failure nobody saw.
            $this->reminderRepository->forget($reminder->subjectType(), $reminder->subjectId, $reminder->kind);

            return false;
        }

        $this->journal->log(
            'rental',
            'rental_reminder_sent',
            'info',
            'Rappel envoyé : ' . $reminder->kind->label(),
            [
                'reminder' => $reminder->kind->value,
                'subject_type' => $reminder->subjectType(),
                'subject_id' => $reminder->subjectId,
            ]
        );

        return true;
    }

    private function dispatchInternal(DueReminder $reminder): bool
    {
        if ($this->notificationService === null || $reminder->assetId === null) {
            return false;
        }

        $recipients = $this->managersOf($reminder->assetId);
        if ($recipients === []) {
            // An asset with no manager who has ever logged in. Not an
            // error — a unit may run entirely on email — but there is
            // nobody to notify.
            return false;
        }

        $this->notificationService->dispatch(
            $reminder->kind->notificationTypeId(),
            $recipients,
            [
                'title' => $reminder->title,
                'body' => $reminder->body,
                'url' => $reminder->url,
            ]
        );

        return true;
    }

    /**
     * The renter's own reminder — email, and only email (§6.29).
     */
    private function mailRenter(DueReminder $reminder, ?string $renterEmail): bool
    {
        if ($this->mailService === null || $renterEmail === null || $renterEmail === '') {
            return false;
        }

        $booking = $this->bookingRepository->findById($reminder->subjectId);
        $asset = $reminder->assetId !== null ? $this->assetRepository->findById($reminder->assetId) : null;
        if ($booking === null || $asset === null) {
            return false;
        }

        return $this->mailService->sendPracticalInfo($booking, $asset);
    }

    /**
     * The user accounts of the people who manage this asset.
     *
     * A manager with no account is skipped rather than guessed at: there is
     * no notification to deliver to somebody who has never logged in, and
     * inventing an email channel for them here would duplicate what the
     * unit's own mail already does.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function managersOf(int $assetId): array
    {
        $recipients = [];

        foreach ($this->managerRepository->findAllByAsset($assetId, true) as $manager) {
            $blindIndex = $this->memberYearRepository->findMostRecentEmailBlindIndexForMember($manager->memberId);
            if ($blindIndex === null || $blindIndex === '') {
                continue;
            }

            $account = $this->userAccountRepository->findByBlindIndex($blindIndex);
            if ($account === null) {
                continue;
            }

            $recipients[$account->id] ??= ['userAccountId' => $account->id, 'memberId' => $manager->memberId];
        }

        return array_values($recipients);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentStatus(\Modules\Rental\Booking\RentalBooking $booking, RentalAsset $asset): array
    {
        // **Without Finance, nothing is said about money at all.** The
        // stored due dates survive its absence but the received amounts do
        // not, so a deposit that was paid would read as unpaid — and
        // telling a unit their renter has not paid when they have is worse
        // than saying nothing.
        if ($this->paymentService === null || !$this->paymentService->isAvailable()) {
            return ['enabled' => false];
        }

        return $this->paymentService->statusFor($booking, $this->paymentService->settingsFor($asset->id));
    }

    /**
     * @return array{arrival: bool, departure: bool}
     */
    private function inventoryState(\Modules\Rental\Booking\RentalBooking $booking): array
    {
        if ($this->stayService === null) {
            // Without the stay features there is no inventory to be
            // missing, so nothing is ever chased.
            return ['arrival' => true, 'departure' => true];
        }

        $entries = $this->stayService->inventoryFor($booking->id);
        if ($entries === []) {
            // No checklist was snapshotted for this booking — an asset with
            // an empty inventory template snapshots legitimately into zero
            // rows (§6.23). There is nothing to record, so nothing to chase.
            return ['arrival' => true, 'departure' => true];
        }

        // `NOT_CHECKED` is a real state, distinct from `OK`: "nobody looked"
        // and "somebody looked and it was fine" are different facts (§6.23).
        // An inventory counts as done once anything at all has been looked
        // at — chasing a manager who filled in nine items out of ten would
        // be pedantry, and the tenth is visible on the page.
        $arrival = false;
        $departure = false;
        foreach ($entries as $entry) {
            $arrival = $arrival || $entry['arrival_state'] !== InventoryState::NOT_CHECKED;
            $departure = $departure || $entry['departure_state'] !== InventoryState::NOT_CHECKED;
        }

        return ['arrival' => $arrival, 'departure' => $departure];
    }

    /**
     * Every notification type this module declares, for the manifest test
     * to check against — a reminder whose type is not declared throws at
     * dispatch time, which is a runtime failure for a code-level mistake.
     *
     * @return string[]
     */
    public static function declaredNotificationTypeIds(): array
    {
        return array_map(
            static fn(ReminderKind $kind) => $kind->notificationTypeId(),
            ReminderKind::internalCases()
        );
    }
}
