<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Journal\JournalService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\Settlement;
use Modules\Rental\Stay\SettlementCalculator;
use Modules\Rental\Stay\SettlementLine;
use Modules\Rental\Support;

/**
 * The stay itself (§6.21–§6.23): what was read, what was found, what it all
 * comes to.
 *
 * **The financial decision stays human, everywhere.** An incident becomes a
 * charge only because a manager said so; a settlement becomes binding only
 * because a manager validated it; a meter that reads backwards produces a
 * warning rather than a number. Nothing here decides money on its own.
 *
 * **A settlement never touches the agreed price.** `Stay\
 * SettlementCalculator` takes the agreed quote as an input and returns new
 * lines; this service persists those lines into `rental_settlements` and
 * never calls `setAgreedPrice()`. The separation is structural, not a rule
 * to remember.
 *
 * **A validated settlement is immutable.** Recomputing after validation
 * produces a new version beside it, exactly like a contract.
 *
 * Never reads `$_POST`/`$_SESSION`, and never puts a renter's identity in
 * the journal — incidents are journaled by booking and amount only, which
 * is why their descriptions are encrypted in the first place.
 */
class RentalStayService
{
    public function __construct(
        private RentalStayRepository $stayRepository,
        private BookingAudit $bookingAudit,
        private RentalPricingService $pricingService,
        private SettlementCalculator $calculator,
        private JournalService $journal,
        /**
         * Optional (§6.19): null without the Finance module, in which case
         * "already paid" is zero and the settlement is a statement of what
         * is owed rather than of what is left.
         */
        private ?RentalPaymentService $paymentService = null
    ) {
    }

    // ── Meters (§6.22) ──────────────────────────────────────────────────

    /**
     * @throws RentalException
     */
    public function addMeter(
        int $assetId,
        string $label,
        MeterKind $kind,
        string $unit,
        ?int $feeId,
        int $sortOrder = 0
    ): int {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException('Un compteur a besoin d\'un nom.');
        }

        return $this->stayRepository->createMeter(
            $assetId,
            $label,
            $kind,
            trim($unit) !== '' ? trim($unit) : $kind->defaultUnit(),
            $feeId,
            $sortOrder
        );
    }

    /**
     * @return \Modules\Rental\Stay\RentalMeter[]
     */
    public function metersFor(int $assetId, bool $activeOnly = true): array
    {
        return $this->stayRepository->findMeters($assetId, $activeOnly);
    }

    /**
     * Retires a meter rather than deleting it: readings already taken
     * against it are evidence for a settlement.
     */
    public function retireMeter(int $assetId, int $meterId): void
    {
        $meter = $this->stayRepository->findMeter($meterId);
        if ($meter === null || $meter->assetId !== $assetId) {
            return;
        }

        $this->stayRepository->deactivateMeter($meterId);
    }

    /**
     * @throws RentalException
     */
    public function recordReading(
        RentalBooking $booking,
        int $assetId,
        int $meterId,
        ReadingPhase $phase,
        ?string $rawValue,
        \DateTimeImmutable $readAt,
        ?int $fileId,
        ?string $comment,
        ?int $actorMemberId
    ): void {
        $meter = $this->stayRepository->findMeter($meterId);
        // The asset check is the guard that matters: a meter id alone must
        // not let a manager of one asset write onto another's booking.
        if ($meter === null || $meter->assetId !== $assetId) {
            throw new RentalException("Ce compteur n'existe pas pour ce bien.");
        }

        $value = MeterReading::parse($rawValue);
        if ($value === null) {
            throw new RentalException('Le relevé doit être un nombre, par exemple 1234,567.');
        }

        if ($value < 0) {
            throw new RentalException('Un index de compteur ne peut pas être négatif.');
        }

        $this->stayRepository->saveReading(
            $booking->id,
            $meterId,
            $phase,
            $value,
            $readAt,
            $fileId,
            $comment,
            $actorMemberId
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $meter->label . ' : ' . MeterReading::format($value),
            'Relevé ' . mb_strtolower($phase->label()),
            $actorMemberId
        );
    }

    /**
     * Every meter of the asset paired with its two readings and its cost.
     *
     * @return MeterConsumption[]
     */
    public function consumptionsFor(RentalBooking $booking, int $assetId): array
    {
        $fees = $this->pricingService->loadSettings($assetId)->fees;
        $readings = $this->stayRepository->findReadings($booking->id);

        $consumptions = [];
        foreach ($this->stayRepository->findMeters($assetId) as $meter) {
            $consumptions[] = MeterConsumption::of(
                $meter,
                $readings[$meter->id . '.' . ReadingPhase::ARRIVAL->value] ?? null,
                $readings[$meter->id . '.' . ReadingPhase::DEPARTURE->value] ?? null,
                SettlementCalculator::feeFor($meter->feeId, $fees)
            );
        }

        return $consumptions;
    }

    // ── Inventory (§6.23) ───────────────────────────────────────────────

    /**
     * @throws RentalException
     */
    public function addInventoryItem(int $assetId, string $label, int $sortOrder = 0): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException("Un élément d'inventaire a besoin d'un libellé.");
        }

        return $this->stayRepository->createInventoryItem($assetId, $label, $sortOrder);
    }

    /**
     * @return array<int, array{id: int, label: string, sort_order: int}>
     */
    public function inventoryTemplateFor(int $assetId): array
    {
        return $this->stayRepository->findInventoryItems($assetId);
    }

    public function removeInventoryItem(int $itemId): void
    {
        $this->stayRepository->deactivateInventoryItem($itemId);
    }

    /**
     * Copies the asset's checklist into the booking — called at
     * confirmation (§6.23).
     *
     * Idempotent, and deliberately so: confirmation can be reached more than
     * once in a booking's life, and re-snapshotting would overwrite a
     * completed inventory with blanks.
     */
    public function snapshotInventory(RentalBooking $booking, int $assetId): bool
    {
        return $this->stayRepository->snapshotInventory($booking->id, $assetId);
    }

    /**
     * @return array<
     *     int,
     *     array{
     *         id: int,
     *         label: string,
     *         sort_order: int,
     *         arrival_state: InventoryState,
     *         departure_state: InventoryState,
     *         arrival_note: ?string,
     *         departure_note: ?string
     *     }
     * >
     */
    public function inventoryFor(int $bookingId): array
    {
        return $this->stayRepository->findBookingInventory($bookingId);
    }

    /**
     * @throws RentalException
     */
    public function setInventoryState(
        RentalBooking $booking,
        int $inventoryId,
        ReadingPhase $phase,
        InventoryState $state,
        ?string $note
    ): void {
        // A checklist row id alone must not let a manager write onto
        // another booking's inventory.
        if ($this->stayRepository->findInventoryBookingId($inventoryId) !== $booking->id) {
            throw new RentalException("Cet élément n'appartient pas à cette réservation.");
        }

        $this->stayRepository->setInventoryState($inventoryId, $phase, $state, $note);
    }

    // ── Incidents (§6.23) ───────────────────────────────────────────────

    /**
     * @throws RentalException
     */
    public function reportIncident(
        RentalBooking $booking,
        string $description,
        ?int $proposedAmountCents,
        ?int $fileId,
        ?int $actorMemberId
    ): int {
        $description = trim($description);
        if ($description === '') {
            throw new RentalException('Décrivez ce qui a été constaté.');
        }

        if ($proposedAmountCents !== null && $proposedAmountCents < 0) {
            throw new RentalException('Un montant ne peut pas être négatif.');
        }

        $id = $this->stayRepository->createIncident(
            $booking->id,
            $description,
            $proposedAmountCents,
            $fileId,
            $actorMemberId
        );

        // Amount and ids only. The description is encrypted precisely
        // because it is about a renter's group (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_incident_reported',
            'info',
            'Incident constaté sur ' . $booking->reference,
            ['booking_id' => $booking->id, 'incident_id' => $id, 'proposed_cents' => $proposedAmountCents]
        );

        return $id;
    }

    /**
     * Records a manager's decision about a damage.
     *
     * @throws RentalException
     */
    public function decideIncident(
        RentalBooking $booking,
        int $incidentId,
        IncidentDecision $decision,
        ?int $decidedAmountCents,
        ?int $actorMemberId
    ): void {
        $incident = $this->stayRepository->findIncident($incidentId);
        if ($incident === null || $incident->bookingId !== $booking->id) {
            throw new RentalException("Cet incident n'existe pas.");
        }

        if ($decision === IncidentDecision::PENDING) {
            throw new RentalException('Choisissez ce qu\'il advient de cet incident.');
        }

        if ($decidedAmountCents !== null && $decidedAmountCents < 0) {
            throw new RentalException('Un montant ne peut pas être négatif.');
        }

        $this->stayRepository->decideIncident($incidentId, $decision, $decidedAmountCents, $actorMemberId);

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            $incident->decision->label(),
            $decision->label(),
            'Incident tranché',
            $actorMemberId
        );

        $this->journal->log(
            'rental',
            'rental_incident_decided',
            'info',
            'Incident tranché sur ' . $booking->reference . ' : ' . $decision->value,
            [
                'booking_id' => $booking->id,
                'incident_id' => $incidentId,
                'amount_cents' => $decidedAmountCents ?? $incident->proposedAmountCents,
            ]
        );
    }

    /**
     * @return Incident[]
     */
    public function incidentsFor(int $bookingId): array
    {
        return $this->stayRepository->findIncidents($bookingId);
    }

    /**
     * Incidents a renter may see (§6.26) — everything except an assessment
     * still in progress, which they would read as a demand.
     *
     * @return Incident[]
     */
    public function incidentsVisibleToRenter(int $bookingId): array
    {
        return array_values(array_filter(
            $this->stayRepository->findIncidents($bookingId),
            static fn(Incident $incident) => $incident->decision->isVisibleToRenter()
        ));
    }

    // ── The final settlement (§6.21) ────────────────────────────────────

    /**
     * Computes what the stay comes to, **without storing anything**.
     *
     * The preview a manager reads before deciding to record it. Separate
     * from `recordSettlement()` on purpose: looking must not create a
     * version, or a manager who opens the page twice ends up with two.
     *
     * @param SettlementLine[] $manualLines
     * @return array{
     *     lines: SettlementLine[],
     *     total_cents: int,
     *     balance_cents: int,
     *     security_deposit_withheld_cents: int,
     *     security_deposit_return_cents: int
     * }
     */
    public function previewSettlement(
        RentalBooking $booking,
        int $assetId,
        ?int $finalPersons,
        array $manualLines = [],
        ?PaymentSettings $paymentSettings = null
    ): array {
        $paymentSettings ??= $this->paymentService?->settingsFor($assetId) ?? new PaymentSettings();
        $paymentStatus = $this->paymentService?->statusFor($booking, $paymentSettings);

        return $this->calculator->compute(
            $booking->effectivePrice(),
            $finalPersons,
            $this->consumptionsFor($booking, $assetId),
            $this->stayRepository->findIncidents($booking->id),
            $manualLines,
            (int) ($paymentStatus['received_cents'] ?? 0),
            $paymentSettings->securityDepositAmount()
        );
    }

    /**
     * Records a new settlement version.
     *
     * Always a new version, never an edit: the previous one may already have
     * been sent, and §6.21 requires a change after validation to be
     * historised rather than applied in place.
     *
     * @param SettlementLine[] $manualLines
     * @throws RentalException
     */
    public function recordSettlement(
        RentalBooking $booking,
        int $assetId,
        ?int $finalPersons,
        array $manualLines,
        ?int $actorMemberId,
        ?PaymentSettings $paymentSettings = null
    ): Settlement {
        if ($finalPersons !== null && $finalPersons < 0) {
            throw new RentalException('Le nombre de participants ne peut pas être négatif.');
        }

        $paymentSettings ??= $this->paymentService?->settingsFor($assetId) ?? new PaymentSettings();
        $computed = $this->previewSettlement($booking, $assetId, $finalPersons, $manualLines, $paymentSettings);

        $version = $this->stayRepository->claimNextSettlementVersion($booking->id);
        $paymentStatus = $this->paymentService?->statusFor($booking, $paymentSettings);

        $id = $this->stayRepository->createSettlement(
            $booking->id,
            $version,
            $finalPersons,
            $computed['lines'],
            $computed['total_cents'],
            (int) ($paymentStatus['received_cents'] ?? 0),
            $computed['balance_cents'],
            $computed['security_deposit_withheld_cents'],
            $computed['security_deposit_return_cents'],
            $actorMemberId
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::PRICE_CHANGED,
            null,
            Support::euros($computed['total_cents']),
            'Décompte final v' . $version,
            $actorMemberId
        );

        $this->journal->log(
            'rental',
            'rental_settlement_recorded',
            'info',
            'Décompte final v' . $version . ' pour ' . $booking->reference,
            [
                'booking_id' => $booking->id,
                'settlement_id' => $id,
                'total_cents' => $computed['total_cents'],
                'balance_cents' => $computed['balance_cents'],
            ]
        );

        $settlement = $this->stayRepository->findSettlement($id);
        if ($settlement === null) {
            throw new RentalException("Le décompte n'a pas pu être enregistré.");
        }

        return $settlement;
    }

    /**
     * Validates a settlement, making it immutable.
     *
     * @throws RentalException
     */
    public function validateSettlement(
        RentalBooking $booking,
        int $settlementId,
        ?int $actorMemberId
    ): void {
        $settlement = $this->stayRepository->findSettlement($settlementId);
        if ($settlement === null || $settlement->bookingId !== $booking->id) {
            throw new RentalException("Ce décompte n'existe pas.");
        }

        if (!$this->stayRepository->validateSettlement($settlementId, $actorMemberId)) {
            // Already validated: saying so beats silently re-stamping a
            // document somebody may already have acted on.
            throw new RentalException('Ce décompte est déjà validé. Enregistrez-en un nouveau pour le corriger.');
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            'Décompte v' . $settlement->version . ' validé',
            'Décompte final',
            $actorMemberId
        );
    }

    /**
     * @return Settlement[]
     */
    public function settlementsFor(int $bookingId): array
    {
        return $this->stayRepository->findSettlements($bookingId);
    }

    public function latestSettlement(int $bookingId): ?Settlement
    {
        return $this->stayRepository->findLatestSettlement($bookingId);
    }
}
