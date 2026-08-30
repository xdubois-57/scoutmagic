<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalSlugGenerator;

/**
 * Builds the unit's hall and the rentals it has had.
 *
 * The wiring here is the module's own composition, rebuilt for a context with
 * no request — the same exercise FinanceSeeder performs for the finance
 * import, and the reason this directory is in `phpstan.neon`'s paths: every
 * one of these constructors is checked at analysis time, so a signature that
 * moves is caught here rather than on somebody's build.
 *
 * Two collaborators are deliberately absent, and both degrade the way the
 * module already degrades without them:
 *
 *  - **Finance.** RentalPaymentService is built without its receivables,
 *    communications, SEPA-QR and account interfaces, so confirming a booking
 *    raises no expected receivable. That is the module's own documented
 *    behaviour on an installation without Finance (§6.19), and the dataset's
 *    receivables story is already told by the cotisations and the campaign.
 *  - **The calendar.** Occupancy is published to the calendar by the
 *    composition root's registry, not by anything a booking does, so nothing
 *    is lost by not building it here.
 *
 * Statuses are reached by walking the real lifecycle — `received` →
 * `reviewing` → `confirmed` → `closed` — through Service\
 * RentalOperationsService, never by writing a status column. Confirmation in
 * particular re-checks availability inside its own lock, which is exactly the
 * behaviour a fixture should be subject to rather than exempt from.
 */
final class RentalSeeder
{
    private readonly RentalAssetRepository $assetRepository;

    private readonly RentalAssetService $assetService;

    private readonly RentalPricingService $pricingService;

    private readonly RentalConstraintsRepository $constraintsRepository;

    private readonly RentalManagerService $managerService;

    private readonly RentalBookingService $bookingService;

    private readonly RentalOperationsService $operationsService;

    private readonly RentalBookingRepository $bookingRepository;

    /** @param array<string, int> $memberIds Tiers => members.id */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        private readonly array $memberIds,
        private readonly ?int $actorId,
    ) {
        $journal = new JournalService(new JournalRepository($pdo));
        $settingService = new SettingService(new SettingRepository($pdo));

        $this->assetRepository = new RentalAssetRepository($pdo, $encryption);
        $managerRepository = new RentalAssetManagerRepository($pdo);
        $this->assetService = new RentalAssetService(
            $this->assetRepository,
            new RentalSlugGenerator($this->assetRepository),
            $journal,
        );
        $this->managerService = new RentalManagerService(
            $managerRepository,
            // The manager service only needs the member service to LIST
            // candidates and to render them; granting takes an id. It is not
            // built here, and the minimum-age setting is read from the real
            // settings table.
            new \Core\Member\MemberService(
                new \Core\Import\MemberYearRepository($pdo),
                $encryption,
                \Core\Database\Connection::withPdo($pdo),
            ),
            $journal,
            $settingService,
        );
        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($pdo),
            new RentalPricingEngine(),
            $journal,
        );
        $this->constraintsRepository = new RentalConstraintsRepository($pdo);

        $this->bookingRepository = new RentalBookingRepository($pdo, $encryption);
        $changeRequestRepository = new RentalChangeRequestRepository($pdo, $encryption);
        $bookingAudit = new BookingAudit(new AuditService(new AuditRepository($pdo, $encryption)));

        $this->bookingService = new RentalBookingService(
            $this->bookingRepository,
            $journal,
            $changeRequestRepository,
            $bookingAudit,
        );

        $availabilityService = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            $this->constraintsRepository,
            [$this->bookingService, new RentalBlockRepository($pdo)],
            $journal,
        );

        $this->operationsService = new RentalOperationsService(
            $this->bookingRepository,
            $bookingAudit,
            new RentalBookingCommentRepository($pdo, $encryption),
            $changeRequestRepository,
            $availabilityService,
            $this->pricingService,
            new QuoteEditor(),
            $journal,
            new RentalPaymentService(new RentalPaymentRepository($pdo, $encryption), $bookingAudit, $journal),
            // No stay service: the inventory checklist it snapshots on
            // confirmation has nothing to copy on an asset with no checklist,
            // and the module treats it as optional for exactly that reason.
            null,
        );
    }

    /**
     * @return array{asset: ?int, manager: bool, bookings: int, refusals: list<string>}
     */
    public function seed(): array
    {
        $assetId = $this->assetService->create(
            RentalBlueprint::ASSET['name'],
            RentalBlueprint::ASSET['type'],
            RentalBlueprint::ASSET['capacity'],
            RentalBlueprint::ASSET['quantity'],
            RentalBlueprint::ASSET['arrivalTime'],
            RentalBlueprint::ASSET['departureTime'],
            RentalBlueprint::ASSET['emergencyPhone'],
            RentalBlueprint::ASSET['isPublic'],
            $this->actorId,
            BillingUnit::from(RentalBlueprint::ASSET['billingUnit']),
        );

        $this->pricingService->saveAssetPricing(
            $assetId,
            RentalBlueprint::PRICING['billingUnit'],
            RentalBlueprint::PRICING['defaultUnitPriceCents'],
            RentalBlueprint::PRICING['minimumAmountCents'],
            RentalBlueprint::PRICING['minimumPersons'],
            $this->actorId,
        );

        $this->constraintsRepository->save($assetId, new BookingConstraints(
            minNights: RentalBlueprint::CONSTRAINTS['minNights'],
            maxNights: RentalBlueprint::CONSTRAINTS['maxNights'],
            minNoticeDays: RentalBlueprint::CONSTRAINTS['minNoticeDays'],
            maxHorizonDays: RentalBlueprint::CONSTRAINTS['maxHorizonDays'],
            allowedArrivalWeekdays: RentalBlueprint::CONSTRAINTS['allowedArrivalWeekdays'],
            maxPersons: RentalBlueprint::CONSTRAINTS['maxPersons'],
            bufferNights: RentalBlueprint::CONSTRAINTS['bufferNights'],
        ));

        $managerId = $this->memberIds[RentalBlueprint::MANAGER_TIERS] ?? null;
        if ($managerId !== null) {
            $this->managerService->grant($assetId, $managerId, true, $this->actorId);
        }

        $refusals = [];
        $bookings = 0;

        foreach (RentalBlueprint::BOOKINGS as $declared) {
            try {
                $this->createAndAdvance($assetId, $declared);
                $bookings++;
            } catch (RentalException $exception) {
                $refusals[] = $declared['name'] . ' (' . $declared['arrival'] . ') : ' . $exception->getMessage();
            }
        }

        return [
            'asset' => $assetId,
            'manager' => $managerId !== null,
            'bookings' => $bookings,
            'refusals' => $refusals,
        ];
    }

    /**
     * Records one request and walks it to the status the table declares.
     *
     * @param array{arrival: string, departure: string, persons: int, status: string, name: string, email: string, phone: ?string, organisation: ?string, purpose: ?string, comment: ?string} $declared
     * @throws RentalException
     */
    private function createAndAdvance(int $assetId, array $declared): void
    {
        $now = new \DateTimeImmutable($declared['arrival'] . ' 00:00:00');
        // The request is made well before the stay, which is what makes the
        // reference's year (LOC-YYYY-NNNN) the year of the REQUEST rather
        // than of the stay — the module's own rule, and one a fixture where
        // every booking was made on the day would never show.
        $now = $now->modify('-60 days');

        $quote = $this->pricingService->quoteForAsset($assetId, new PricingRequest(
            arrivalDate: $declared['arrival'],
            departureDate: $declared['departure'],
            persons: $declared['persons'],
        ));

        $created = $this->bookingService->createFromPublicRequest(
            $assetId,
            $declared['arrival'],
            $declared['departure'],
            1,
            $declared['persons'],
            null,
            [
                'name' => $declared['name'],
                'email' => $declared['email'],
                'phone' => $declared['phone'],
                'organisation' => $declared['organisation'],
                'purpose' => $declared['purpose'],
                'comment' => $declared['comment'],
            ],
            $quote,
            RentalBlueprint::ACCEPTANCES,
            $now,
        );

        $target = BookingStatus::from($declared['status']);
        if ($target === BookingStatus::RECEIVED) {
            return;
        }

        $asset = $this->assetRepository->findById($assetId);
        if ($asset === null) {
            throw new RentalException("Le bien qui vient d'être créé reste introuvable.");
        }

        $booking = $created['booking'];

        // Somebody looked at it. Every path but an outright refusal goes
        // through this first, which is what the lifecycle table means by
        // "confirmation may only follow a state where somebody looked".
        if ($target !== BookingStatus::REFUSED) {
            $this->operationsService->changeStatus($booking, BookingStatus::REVIEWING, null, $now);
            $booking = $this->reload($booking->id);
        }

        if ($target === BookingStatus::REFUSED) {
            $this->operationsService->changeStatus($booking, BookingStatus::REFUSED, null, $now);

            return;
        }

        $this->operationsService->confirm($booking, $asset, null, $now);

        if ($target === BookingStatus::CLOSED) {
            $booking = $this->reload($booking->id);
            // Closed the day after the renter left, which is when a manager
            // actually does it.
            $this->operationsService->changeStatus(
                $booking,
                BookingStatus::CLOSED,
                null,
                new \DateTimeImmutable($declared['departure'] . ' 00:00:00'),
            );
        }
    }

    /**
     * Re-read between two steps: every transition compares the status it was
     * handed against the stored one, so acting on a copy from before the
     * previous step is refused — deliberately, since that refusal is what
     * protects two managers clicking at once.
     *
     * @throws RentalException
     */
    private function reload(int $bookingId): RentalBooking
    {
        $booking = $this->bookingRepository->findById($bookingId);
        if ($booking === null) {
            throw new RentalException('La réservation a disparu entre deux étapes.');
        }

        return $booking;
    }
}
