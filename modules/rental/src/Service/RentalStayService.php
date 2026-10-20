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
use Modules\Rental\Stay\InventoryKind;
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
    /** The longest « En attente de… » a decision keeps (#980). */
    public const INCIDENT_AWAITING_MAX = 200;
    /** The longest reference — an insurer's file number. */
    public const INCIDENT_REFERENCE_MAX = 100;
    /** The longest reason for not billing a damage. */
    public const INCIDENT_WAIVE_REASON_MAX = 500;

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
        ?int $actorMemberId,
        bool $arrivalTickedByHand = false
    ): void {
        // A reading belongs to its inventory (#708, IT-17): frozen with it
        // once validated, and the departure one waits for the arrival.
        $this->assertPhaseOpen($booking->id, $phase, $arrivalTickedByHand);

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

        $saved = $this->stayRepository->saveReading(
            $booking->id,
            $meterId,
            $phase,
            $value,
            $readAt,
            $fileId,
            $comment,
            $actorMemberId
        );
        if (!$saved) {
            // Validated by someone else since the check above — the write
            // itself refused it — or a correction that changed nothing.
            $this->assertPhaseOpen($booking->id, $phase, $arrivalTickedByHand);
        }

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
     * Adds an item at the end of the asset's checklist (#708, IT-10).
     *
     * @throws RentalException
     */
    public function addInventoryItem(
        int $assetId,
        string $label,
        InventoryKind $kind = InventoryKind::QUANTITY,
        ?int $expectedCount = null
    ): int {
        $label = trim($label);
        if ($label === '') {
            throw new RentalException("Un élément d'inventaire a besoin d'un libellé.");
        }

        return $this->stayRepository->createInventoryItem(
            $assetId,
            mb_substr($label, 0, 160),
            $kind,
            self::expectedCountFor($kind, $expectedCount)
        );
    }

    /**
     * @return array<int, array{id: int, label: string, kind: InventoryKind, expected_count: ?int, sort_order: int}>
     */
    public function inventoryTemplateFor(int $assetId): array
    {
        return $this->stayRepository->findInventoryItems($assetId);
    }

    /**
     * @return array{id: int, label: string, kind: InventoryKind, expected_count: ?int, sort_order: int}|null
     */
    public function inventoryItem(int $assetId, int $itemId): ?array
    {
        return $this->stayRepository->findInventoryItem($assetId, $itemId);
    }

    /**
     * Changes an item's sort or expected count, in place on the Gabarits
     * page (#708, IT-10). Copies already frozen in a booking do not move.
     *
     * @throws RentalException
     */
    public function updateInventoryItem(int $assetId, int $itemId, InventoryKind $kind, ?int $expectedCount): void
    {
        if ($this->stayRepository->findInventoryItem($assetId, $itemId) === null) {
            throw new RentalException("Cet élément n'existe pas dans le modèle de ce bien.");
        }

        $this->stayRepository->updateInventoryItemKind($itemId, $kind, self::expectedCountFor($kind, $expectedCount));
    }

    /**
     * The order a manager dragged the checklist into — the order copied
     * into each booking at confirmation.
     *
     * @param int[] $itemIds
     */
    public function reorderInventory(int $assetId, array $itemIds): void
    {
        $this->stayRepository->reorderInventoryItems($assetId, $itemIds);
    }

    /**
     * @throws RentalException
     */
    public function removeInventoryItem(int $assetId, int $itemId): void
    {
        // The asset check is the guard that matters: an item id alone must
        // not let a manager of one asset empty another's checklist.
        if ($this->stayRepository->findInventoryItem($assetId, $itemId) === null) {
            throw new RentalException("Cet élément n'existe pas dans le modèle de ce bien.");
        }

        $this->stayRepository->deactivateInventoryItem($itemId);
    }

    /**
     * @throws RentalException
     */
    private static function expectedCountFor(InventoryKind $kind, ?int $expectedCount): ?int
    {
        $count = $kind->normaliseCount($expectedCount);
        if ($count !== null && ($count < 1 || $count > 65535)) {
            throw new RentalException('Le nombre attendu doit être un nombre entier d\'au moins 1.');
        }

        return $count;
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
     *         kind: InventoryKind,
     *         expected_count: ?int,
     *         sort_order: int,
     *         arrival_value: ?string,
     *         departure_value: ?string,
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
     * Whether this asset's inventories are kept on the site (#708, IT-17):
     * it has items to check or meters to read. Without either, its
     * walk-throughs are ticked by hand, like an inventory kept elsewhere.
     */
    public function keepsInventory(int $assetId): bool
    {
        return $this->inventoryTemplateFor($assetId) !== [] || $this->metersFor($assetId) !== [];
    }

    /**
     * The same question for one booking, read from what that booking will
     * actually be walked against: its own checklist once copied at the
     * confirmation (the asset's template may have changed since, either
     * way), the template before then; the asset's meters either way, since
     * meters are read live rather than copied. What the page, the
     * dashboard's steps, the reminders and the validation all agree on.
     */
    public function keepsInventoryFor(RentalBooking $booking): bool
    {
        $lines = $this->stayRepository->isInventorySnapshotted($booking->id)
            ? $this->inventoryFor($booking->id)
            : $this->inventoryTemplateFor($booking->assetId);

        return $lines !== [] || $this->metersFor($booking->assetId) !== [];
    }

    /**
     * The phases validated on this booking (#708, IT-17), keyed by phase.
     *
     * @return array<string, array{validated_at: \DateTimeImmutable, validated_by_member_id: ?int, document_id: ?int}>
     */
    public function inventoryValidations(int $bookingId): array
    {
        return $this->stayRepository->findInventoryValidations($bookingId);
    }

    /**
     * What was found on one line (#708, IT-17): the value, which IS the
     * check, and the note beside it.
     *
     * Refused on a phase already validated — the renter holds its PDF —
     * and on the departure while the arrival is neither validated nor
     * ticked by hand: the departure is read against the arrival.
     *
     * @throws RentalException
     */
    public function setInventoryValue(
        RentalBooking $booking,
        int $inventoryId,
        ReadingPhase $phase,
        string $rawValue,
        ?string $note,
        bool $arrivalTickedByHand = false
    ): void {
        // A checklist row id alone must not let a manager write onto
        // another booking's inventory.
        $line = null;
        foreach ($this->inventoryFor($booking->id) as $row) {
            if ($row['id'] === $inventoryId) {
                $line = $row;
            }
        }
        if ($line === null) {
            throw new RentalException("Cet élément n'appartient pas à cette réservation.");
        }

        $this->assertPhaseOpen($booking->id, $phase, $arrivalTickedByHand);

        try {
            $value = $line['kind']->parseValue($rawValue);
        } catch (\InvalidArgumentException $e) {
            throw new RentalException($line['label'] . ' : ' . $e->getMessage());
        }

        if (!$this->stayRepository->setInventoryValue($inventoryId, $phase, $value, $note)) {
            // Validated by someone else since the check above — the write
            // itself refused it — or a save that changed nothing.
            $this->assertPhaseOpen($booking->id, $phase, $arrivalTickedByHand);
        }
    }

    /**
     * @throws RentalException
     */
    public function assertPhaseOpen(int $bookingId, ReadingPhase $phase, bool $arrivalTickedByHand = false): void
    {
        $validations = $this->inventoryValidations($bookingId);
        if (isset($validations[$phase->value])) {
            throw new RentalException(
                "L'état des lieux " . ($phase === ReadingPhase::ARRIVAL ? "d'entrée" : 'de sortie')
                . ' est validé et envoyé au locataire : il ne se modifie plus.'
            );
        }
        // An arrival ticked by hand was never validated itself, but the
        // departure's PDF was read against it: frozen with that PDF.
        foreach ($phase->frozenBy() as $later) {
            if ($later !== $phase && isset($validations[$later->value])) {
                throw new RentalException(
                    "L'état des lieux de sortie est validé et envoyé au locataire : celui d'entrée, sur lequel "
                    . 'il se lit, ne se modifie plus.'
                );
            }
        }
        if ($phase === ReadingPhase::DEPARTURE
            && !isset($validations[ReadingPhase::ARRIVAL->value])
            && !$arrivalTickedByHand
        ) {
            throw new RentalException("L'état des lieux de sortie commence une fois celui d'entrée validé.");
        }
    }

    /**
     * Freezes a phase (#708, IT-17). False when it already was.
     */
    public function recordInventoryValidation(
        RentalBooking $booking,
        ReadingPhase $phase,
        \DateTimeImmutable $at,
        ?int $actorMemberId
    ): bool {
        return $this->stayRepository->recordInventoryValidation($booking->id, $phase, $at, $actorMemberId);
    }

    public function attachInventoryDocument(RentalBooking $booking, ReadingPhase $phase, int $documentId): void
    {
        $this->stayRepository->setInventoryValidationDocument($booking->id, $phase, $documentId);
    }

    public function forgetInventoryValidation(RentalBooking $booking, ReadingPhase $phase): void
    {
        $this->stayRepository->forgetInventoryValidation($booking->id, $phase);
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

        // Observed during the stay, until the departure inventory closes
        // it (#708, IT-17); deciding one stays possible after, since that
        // is a billing decision, not an observation.
        $closed = new RentalException(
            "L'état des lieux de sortie est validé : un incident ne se constate plus. Ceux déjà constatés se "
            . 'tranchent toujours.'
        );
        if (isset($this->inventoryValidations($booking->id)[ReadingPhase::DEPARTURE->value])) {
            throw $closed;
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
        if ($id === null) {
            // Validated by someone else since the check above: the insert
            // itself refused it.
            throw $closed;
        }

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
     * Records a manager's decision about a damage, in one of its three
     * states (#980) — « En cours d'évaluation » included: that is where
     * what the assessment waits for and its reference are written, and a
     * decided incident may go back to it.
     *
     * The three texts are optional and encrypted (repository). The reason
     * for not billing is kept only with « Ne pas facturer »; what an
     * assessment waits for and its reference stay whatever the state, an
     * insurer's file number being worth keeping once the damage is billed.
     * None of them reaches the history or the journal.
     *
     * @throws RentalException
     */
    public function decideIncident(
        RentalBooking $booking,
        int $incidentId,
        IncidentDecision $decision,
        ?int $decidedAmountCents,
        ?int $actorMemberId,
        ?string $awaiting = null,
        ?string $reference = null,
        ?string $waiveReason = null
    ): void {
        $incident = $this->stayRepository->findIncident($incidentId);
        if ($incident === null || $incident->bookingId !== $booking->id) {
            throw new RentalException("Cet incident n'existe pas.");
        }

        if ($decidedAmountCents !== null && $decidedAmountCents < 0) {
            throw new RentalException('Un montant ne peut pas être négatif.');
        }

        $awaiting = self::boundedText($awaiting, self::INCIDENT_AWAITING_MAX, '« En attente de… »');
        $reference = self::boundedText($reference, self::INCIDENT_REFERENCE_MAX, 'La référence');
        $waiveReason = $decision === IncidentDecision::WAIVE
            ? self::boundedText($waiveReason, self::INCIDENT_WAIVE_REASON_MAX, 'Le motif')
            : null;

        $this->stayRepository->decideIncident(
            $incidentId,
            $decision,
            $decidedAmountCents,
            $actorMemberId,
            $awaiting,
            $reference,
            $waiveReason
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            $incident->decision->label(),
            $decision->label(),
            $decision === IncidentDecision::PENDING ? "Incident en cours d'évaluation" : 'Incident tranché',
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
     * A decision's optional text, trimmed — null when blank, refused when
     * longer than the form allows.
     *
     * @throws RentalException
     */
    private static function boundedText(?string $text, int $max, string $what): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new RentalException($what . ' tient en ' . $max . ' caractères au plus.');
        }

        return $text;
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
     * With the booking's single receivable (issue #927), what was paid
     * includes the security deposit, so the balance is measured against the
     * kept amount: negative, it is everything to pay back, the deposit's
     * return included. The deposit is the one snapshotted on the booking
     * when the receivable was raised, not today's setting.
     *
     * @param SettlementLine[] $manualLines
     * @return array{
     *     lines: SettlementLine[],
     *     total_cents: int,
     *     kept_cents: int,
     *     balance_cents: int,
     *     security_deposit_withheld_cents: int,
     *     security_deposit_return_cents: int,
     *     paid_includes_security_deposit: bool,
     *     security_deposit_held_cents: ?int
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
        $onReceivable = ($paymentStatus['communication'] ?? null) !== null;

        $computed = $this->calculator->compute(
            $booking->effectivePrice(),
            $finalPersons,
            $this->consumptionsFor($booking, $assetId),
            $this->stayRepository->findIncidents($booking->id),
            $manualLines,
            (int) ($paymentStatus['received_cents'] ?? 0),
            $onReceivable
                ? ($paymentStatus['security_deposit']['amount_cents'] ?? null)
                : $paymentSettings->securityDepositAmount(),
            $onReceivable
        );

        // What of the security deposit the unit actually holds — all of it
        // once the agreed total came in, nothing before — so a refund of
        // overpaid rent is never called the deposit on screen.
        $computed['security_deposit_held_cents'] = $onReceivable
            ? (int) ($paymentStatus['security_deposit']['received_cents'] ?? 0)
            : null;

        return $computed;
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
            $actorMemberId,
            $computed['kept_cents']
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
     * Validates a settlement, making it immutable — and brings the
     * booking's receivable to the amount it keeps (issue #927), so what
     * was received beyond it, the security deposit to give back first,
     * reads as an overpayment in Finances. The agreed price is untouched.
     *
     * The validation stands even if Finance fails afterwards: the payment
     * service journals that and says so in the booking's history.
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

        // Refused, cancelled or expired since: its receivable is at 0, and
        // validating would bring it back up for a stay that is over.
        if ($booking->status->isAbandoned()) {
            throw new RentalException(
                'Cette réservation est ' . mb_strtolower($booking->status->label())
                . ' : son décompte ne se valide plus, et rien ne lui est plus demandé.'
            );
        }

        // A newer version already validated is the one that holds: an
        // older one validated afterwards would push its obsolete figure
        // onto the receivable.
        foreach ($this->stayRepository->findSettlements($booking->id) as $other) {
            if ($other->isValidated && $other->version > $settlement->version) {
                throw new RentalException(
                    'Le décompte v' . $other->version . ' est déjà validé : la v' . $settlement->version
                    . ' est dépassée et ne se valide plus.'
                );
            }
        }

        // The security deposit's share, derived from the money as it
        // stands now (#980, chantier §2.3): the refund owed comes first out
        // of the deposit. Only on the booking's single receivable — without
        // one the deposit is held by hand and the version keeps its figures.
        $securityDeposit = $this->securityDepositAtValidation($booking, $settlement);

        if (!$this->stayRepository->validateSettlement($settlementId, $actorMemberId, $securityDeposit)) {
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

        $this->paymentService?->settleAmount(
            $booking,
            $settlement->keptCents(),
            $actorMemberId,
            $securityDeposit['withheld_cents'] ?? $settlement->securityDepositWithheldCents ?? 0
        );
    }

    /**
     * @return array{withheld_cents: int, return_cents: int}|null null without the booking's single receivable
     */
    private function securityDepositAtValidation(RentalBooking $booking, Settlement $settlement): ?array
    {
        if ($this->paymentService === null) {
            return null;
        }

        $status = $this->paymentService->statusFor(
            $booking,
            $this->paymentService->settingsFor($booking->assetId)
        );
        if (($status['communication'] ?? null) === null) {
            return null;
        }

        return SettlementCalculator::securityDepositAtSettlement(
            $status['security_deposit']['amount_cents'] ?? null,
            (int) $status['received_cents'],
            $settlement->keptCents()
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
