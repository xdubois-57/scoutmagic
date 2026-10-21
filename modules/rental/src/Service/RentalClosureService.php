<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\ClosureCheck;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\SecurityDepositStatus;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Stay\Settlement;
use Modules\Rental\Stay\SettlementCalculator;
use Modules\Rental\Stay\SettlementLine;
use Modules\Rental\Support;

/**
 * Closing a booking (#980, CHANTIER-locations-incidents.md §2.9): when « Clôturer la location » is
 * refused, and « Clôturer avec un solde non recouvré » for the balance the
 * unit gives up on.
 *
 * **The ordinary closure is guarded.** It is refused while a damage is
 * still assessed, while the security deposit is still to give back, or
 * while money is still due — each time with the sentence that says what to
 * do (`check()`). Without Finances, the money cannot be seen: only the
 * assessed damage and the deposit's return recorded by hand count (Q19).
 *
 * **The unrecovered closure writes the balance off, it never forgets it.**
 * Under the asset's lock it reads the amount still due again and refuses
 * when it is not the one the dialog showed; it records a new validated
 * settlement version — the last validated one's lines plus « Montant non
 * recouvré » (Q12), with its date and its reason — brings the receivable
 * down to what actually came in, and closes the booking by compare-and-set.
 * A payment arriving later reads as an overpayment in Finances, like any
 * other money received beyond what a receivable asks.
 *
 * **No e-mail leaves**: nothing here can send one. The reason is
 * encrypted with the version and recorded in the booking's history —
 * which `Core\Audit` encrypts — and never in the journal (SECURITY.md §5).
 */
class RentalClosureService
{
    /** The longest reason an unrecovered closure keeps. */
    public const REASON_MAX = 500;

    public const REFUSED_DAMAGE_ASSESSED = "Un dégât est en cours d'évaluation : tranchez l'incident sur la page "
        . 'État des lieux avant de clôturer la location.';
    public const REFUSED_DEPOSIT_BEFORE_SETTLEMENT = 'La caution est encore détenue : validez le décompte final '
        . 'sur la page Facture, puis rendez au locataire ce qui lui revient, avant de clôturer la location.';
    public const REFUSED_DEPOSIT_TO_REFUND = 'La caution reste à rendre : faites le virement avec la communication '
        . 'de la réservation et rapprochez-le sur la page Finances avant de clôturer la location.';
    public const REFUSED_DEPOSIT_BY_HAND = "La restitution de la caution n'est pas enregistrée : notez-la sur la "
        . 'page Finances avant de clôturer la location.';

    public function __construct(
        private RentalBookingRepository $bookingRepository,
        private RentalStayRepository $stayRepository,
        private RentalStayService $stayService,
        private RentalModificationRepository $modificationRepository,
        private BookingAudit $bookingAudit,
        private JournalService $journal,
        /** Null without the Finance module wiring: only the damage then counts. */
        private ?RentalPaymentService $paymentService = null
    ) {
    }

    /**
     * Whether $booking may be closed now, and the amount an unrecovered
     * closure would write off when it is offered. Only a confirmed booking
     * is closed; anything else answers « closable » here and is left to
     * `BookingTransition`.
     */
    public function check(RentalBooking $booking): ClosureCheck
    {
        if ($booking->status !== BookingStatus::CONFIRMED) {
            return new ClosureCheck();
        }

        if ($this->stayService->hasIncidentUnderAssessment($booking->id)) {
            return new ClosureCheck(self::REFUSED_DAMAGE_ASSESSED);
        }

        $status = $this->paymentStatus($booking);
        if ($status === null) {
            return new ClosureCheck();
        }

        $security = $status['security_deposit'];
        $depositStatus = $security['status'];

        if ($status['tracked']) {
            $remaining = $status['remaining_cents'];
            if ($remaining > 0) {
                $unrecovered = $this->unrecoveredFrom($booking, $status);

                return new ClosureCheck(
                    $unrecovered !== null
                        ? Support::euros($remaining) . ' restent dus sur cette réservation : attendez leur paiement '
                            . 'avant de clôturer la location, ou, si vous renoncez à les recouvrer, utilisez '
                            . '« Clôturer avec un solde non recouvré ».'
                        : Support::euros($remaining) . ' restent dus sur cette réservation : attendez leur paiement '
                            . 'avant de clôturer la location. Pour renoncer à les recouvrer, validez d\'abord le '
                            . 'décompte final sur la page Facture, puis utilisez « Clôturer avec un solde non '
                            . 'recouvré ».',
                    $unrecovered
                );
            }

            if ($depositStatus->isHeld()) {
                return new ClosureCheck(
                    $this->lastValidatedSettlement($booking->id) === null
                        ? self::REFUSED_DEPOSIT_BEFORE_SETTLEMENT
                        : self::REFUSED_DEPOSIT_TO_REFUND
                );
            }

            return new ClosureCheck();
        }

        // Without Finances following the money (Q19): the deposit's return
        // recorded by hand on « Finances » — the very form that page offers
        // until it is recorded.
        $byHand = !($status['available'] && $status['enabled']);
        if ($byHand && ($security['amount_cents'] ?? 0) > 0 && !$depositStatus->isSettled()) {
            return new ClosureCheck(self::REFUSED_DEPOSIT_BY_HAND);
        }

        return new ClosureCheck();
    }

    /**
     * Why « Clôturer la location » is refused right now, or null when it
     * is not.
     */
    public function whyNotClosable(RentalBooking $booking): ?string
    {
        return $this->check($booking)->refusal;
    }

    /**
     * « Clôturer avec un solde non recouvré » (#980, CHANTIER-locations-incidents.md §2.9).
     *
     * $shownCents is the amount the dialog showed: the one read again under
     * the lock must be the same, or nothing is written — a payment arriving
     * in between changes what is being given up, and a manager agreed to a
     * figure, not to whatever the balance became.
     *
     * Everything lands in one transaction under the asset's lock, so a
     * second submit, or a second manager, finds the booking closed and is
     * refused; the status itself moves by compare-and-set.
     *
     * @return Settlement the validated version that writes the balance off
     * @throws RentalException
     */
    public function closeUnrecovered(
        RentalBooking $booking,
        int $shownCents,
        ?string $reason,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): Settlement {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new RentalException(
                'Indiquez pourquoi le solde ne sera pas recouvré : assurance refusée, locataire injoignable…'
            );
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new RentalException('Le motif tient en ' . self::REASON_MAX . ' caractères au plus.');
        }

        return $this->bookingRepository->withAssetLocked($booking->assetId, function () use (
            $booking,
            $shownCents,
            $reason,
            $actorMemberId,
            $now
        ): Settlement {
            $current = $this->bookingRepository->findById($booking->id) ?? $booking;
            if ($current->status !== BookingStatus::CONFIRMED) {
                throw new RentalException(BookingTransition::refusalReason($current->status, BookingStatus::CLOSED));
            }
            if ($this->stayService->hasIncidentUnderAssessment($current->id)) {
                throw new RentalException(self::REFUSED_DAMAGE_ASSESSED);
            }

            $status = $this->paymentStatus($current);
            if ($status === null || !$status['tracked']) {
                throw new RentalException(
                    'Les paiements de cette réservation ne sont pas suivis dans les Finances : il n\'y a pas de '
                    . 'créance à ramener à ce qui a été reçu.'
                );
            }

            $validated = $this->lastValidatedSettlement($current->id);
            if ($validated === null) {
                throw new RentalException(
                    'Validez d\'abord le décompte final sur la page Facture : le montant non recouvré se lit sur lui.'
                );
            }
            if ($validated->isOutdatedFor($this->stayService->incidentBasisHash($current->id))) {
                throw new RentalException(
                    'Le décompte validé ne reflète pas les incidents tels qu\'ils sont tranchés : enregistrez et '
                    . 'validez-en un nouveau avant de clôturer.'
                );
            }

            $received = self::netReceived($status);
            $unrecovered = $status['remaining_cents'] > 0 ? $validated->keptCents() - $received : 0;
            if ($unrecovered <= 0) {
                throw new RentalException(
                    'Rien ne reste dû sur cette réservation : clôturez-la avec « Clôturer la location ».'
                );
            }
            if ($unrecovered !== $shownCents) {
                throw new RentalException(
                    'Le montant restant dû est de ' . Support::euros($unrecovered) . ', et non de '
                    . Support::euros(max(0, $shownCents)) . ' : rechargez la page et vérifiez-le avant de clôturer.'
                );
            }

            $settlement = $this->recordUnrecovered(
                $current,
                $validated,
                $status,
                $unrecovered,
                $reason,
                $actorMemberId,
                $now
            );

            if (!$this->bookingRepository->compareAndSetStatus(
                $current->id,
                BookingStatus::CONFIRMED,
                BookingStatus::CLOSED,
                $now
            )) {
                throw new RentalException("Cette réservation a changé entre-temps. Rechargez la page avant d'agir.");
            }
            $this->cancelModificationInProgress($current, $actorMemberId, $now);

            // Who and when come with the entry; why is its summary, which
            // Core\Audit encrypts like every value it holds.
            $this->bookingAudit->record(
                $current->id,
                BookingAudit::STATUS_CHANGED,
                $current->status->label(),
                BookingStatus::CLOSED->label(),
                'Clôturée avec un solde non recouvré de ' . Support::euros($unrecovered) . ' : ' . $reason,
                $actorMemberId
            );

            // The receivable comes down to what actually came in, in the same
            // transaction: what still arrives is an overpayment. Never throws
            // — a Finance failure is journaled and said in the history.
            $this->paymentService?->settleAmount(
                $current,
                $received,
                $actorMemberId,
                $settlement->securityDepositWithheldCents ?? 0
            );

            // Ids and amounts only: neither the renter nor the reason
            // (SECURITY.md §5).
            $this->journal->log(
                'rental',
                'rental_booking_closed_unrecovered',
                'info',
                $current->reference . ' : clôturée avec un solde non recouvré',
                [
                    'booking_id' => $current->id,
                    'asset_id' => $current->assetId,
                    'settlement_id' => $settlement->id,
                    'unrecovered_cents' => $unrecovered,
                ]
            );

            return $settlement;
        });
    }

    /**
     * The validated version that writes $unrecoveredCents off: the last
     * validated one's lines plus « Montant non recouvré » (Q12), kept at
     * what came in, so its balance is nil.
     *
     * @param array{received_cents: int, refunded_cents: int, security_deposit: array{amount_cents: ?int}} $status
     * @throws RentalException
     */
    private function recordUnrecovered(
        RentalBooking $booking,
        Settlement $validated,
        array $status,
        int $unrecoveredCents,
        string $reason,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): Settlement {
        $received = self::netReceived($status);
        $lines = $validated->lines;
        $lines[] = new SettlementLine(
            SettlementLine::UNRECOVERED_LABEL,
            -$unrecoveredCents,
            SettlementLine::ORIGIN_UNRECOVERED,
            'Le ' . $now->format('d/m/Y')
        );
        $securityDeposit = SettlementCalculator::securityDepositAtSettlement(
            $status['security_deposit']['amount_cents'],
            $status['received_cents'],
            $received
        );

        $version = $this->stayRepository->claimNextSettlementVersion($booking->id);
        $id = $this->stayRepository->createSettlement(
            $booking->id,
            $version,
            $validated->finalPersons,
            $lines,
            $validated->totalCents - $unrecoveredCents,
            $received,
            0,
            $securityDeposit['withheld_cents'],
            $securityDeposit['return_cents'],
            $actorMemberId,
            $received,
            $this->stayService->incidentBasisHash($booking->id)
        );
        $this->stayRepository->markUnrecovered($id, $unrecoveredCents, $reason, $now);

        if (!$this->stayRepository->validateSettlement($id, $actorMemberId, $securityDeposit, $received)) {
            // A damage sent back to its assessment since the check: the
            // write refused it, and the whole closure rolls back.
            throw new RentalException(self::REFUSED_DAMAGE_ASSESSED);
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            'Décompte v' . $version . ' validé',
            SettlementLine::UNRECOVERED_LABEL . ' : ' . Support::euros($unrecoveredCents),
            $actorMemberId
        );

        return $this->stayRepository->findSettlement($id)
            ?? throw new RentalException("Le décompte n'a pas pu être enregistré.");
    }

    /**
     * The amount an unrecovered closure would write off now, or null when
     * it is not offered: money is still due, the final settlement is
     * validated and still matches the incidents.
     *
     * @param array{remaining_cents: int, received_cents: int, refunded_cents: int} $status
     */
    private function unrecoveredFrom(RentalBooking $booking, array $status): ?int
    {
        if ($status['remaining_cents'] <= 0) {
            return null;
        }

        $validated = $this->lastValidatedSettlement($booking->id);
        if ($validated === null || $validated->isOutdatedFor($this->stayService->incidentBasisHash($booking->id))) {
            return null;
        }

        $unrecovered = $validated->keptCents() - self::netReceived($status);

        return $unrecovered > 0 ? $unrecovered : null;
    }

    /** The newest validated settlement version, which is the one in force. */
    private function lastValidatedSettlement(int $bookingId): ?Settlement
    {
        foreach ($this->stayRepository->findSettlements($bookingId) as $settlement) {
            if ($settlement->isValidated) {
                return $settlement;
            }
        }

        return null;
    }

    /**
     * What came in and stayed: received, less what already went back.
     *
     * @param array{received_cents: int, refunded_cents: int} $status
     */
    private static function netReceived(array $status): int
    {
        return max(0, $status['received_cents'] - $status['refunded_cents']);
    }

    /**
     * @return array{
     *   available: bool, enabled: bool, tracked: bool,
     *   remaining_cents: int, received_cents: int, refunded_cents: int,
     *   security_deposit: array{status: SecurityDepositStatus, amount_cents: ?int}
     * }|null
     */
    private function paymentStatus(RentalBooking $booking): ?array
    {
        if ($this->paymentService === null) {
            return null;
        }

        $status = $this->paymentService->statusFor($booking, $this->paymentService->settingsFor($booking->assetId));

        return [
            'available' => $status['available'],
            'enabled' => $status['enabled'],
            'tracked' => $status['tracked'],
            'remaining_cents' => $status['remaining_cents'],
            'received_cents' => $status['received_cents'],
            'refunded_cents' => $status['refunded_cents'],
            'security_deposit' => [
                'status' => $status['security_deposit']['status'],
                'amount_cents' => $status['security_deposit']['amount_cents'],
            ],
        ];
    }

    /**
     * The booking's modification in progress goes with its end, as on any
     * other closure (`RentalOperationsService::changeStatus()`, #927).
     */
    private function cancelModificationInProgress(
        RentalBooking $booking,
        ?int $actorMemberId,
        \DateTimeImmutable $now
    ): void {
        $cancelled = $this->modificationRepository->cancelActiveFor($booking->id, $actorMemberId, $now);
        if ($cancelled === null) {
            return;
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::MODIFICATION,
            null,
            'Annulée',
            'Modification annulée avec la réservation : ' . $cancelled->summary(),
            $actorMemberId
        );
    }
}
