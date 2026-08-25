<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Audit\AuditService;
use Core\Config\ScoutYearService;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Service\DateInput;
use Core\View\MonthGrid\DayState;
use Core\View\MonthGrid\DayStateGridBuilder;
use Modules\Calendar\Service\CalendarService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Availability\MonthWindow;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\MilestoneEvidence;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Booking\RenterDecision;
use Modules\Rental\Calendar\PublishFrom;
use Modules\Rental\Document\DocumentKeywords;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\StandardTemplates;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalCommunicationService;
use Modules\Rental\Service\RentalComplianceService;
use Modules\Rental\Service\RentalDocumentService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStatisticsService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryState;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Support;
use Twig\Environment;

/**
 * The managers' own space (§6.4, §6.5) — where an asset is actually operated.
 *
 * Its routes are `role_min: identified`, as low as a logged-in visitor gets,
 * because a manager is explicitly not required to be a chief (§6.3). The
 * route guard therefore grants almost nothing and
 * **`RentalAuthorizationService` is the actual protection**, re-checked
 * server-side at the top of every action here — never inferred from a hidden
 * button, an absent menu entry or a breadcrumb (ARCHITECTURE.md §12).
 *
 * The asset in the URL is always resolved through `manageableAsset()`, which
 * answers **404** rather than 403 for an asset the visitor may not manage:
 * "this exists but is not yours" is itself a disclosure about the unit's
 * assets (§6.6).
 *
 * **What is administered elsewhere, and what is not.** Which assets exist
 * and who manages each one live in `Espace chefs d'U > Locations` at
 * `admin`. Everything that is a property of *one* asset lives here — its
 * bookings, its documents, its stay, and (since the settings page below) its
 * booking rules, its tariff and its deposit rules — reachable by the people
 * who actually run it. Unit staff reach it the same way, as implicit
 * managers of every asset, rather than through a second admin-only screen.
 */
class RentalManagementController extends AbstractController
{
    /** How far the private calendar pages, in months, either way. */
    private const MONTHS_BACK = 24;
    private const MONTHS_AHEAD = 36;

    /**
     * Bookings per page on the list. A hall that has been let for ten
     * years has hundreds, and the page used to render every one of them.
     */
    private const BOOKINGS_PER_PAGE = 25;

    /**
     * What a manager may attach to a booking (§6.24).
     *
     * A closed list of document and image types, checked by
     * `UploadHandler` against the file's **real** MIME rather than its
     * extension or the browser's claim. No archives and nothing executable:
     * a rental document is a scan, a photo or a PDF.
     */
    private const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
    ];

    /** 15 MB — a phone photo of a signed contract, with room to spare. */
    private const MAX_DOCUMENT_BYTES = 15 * 1024 * 1024;

    /**
     * Meter and damage photos: images only, no PDF.
     *
     * Narrower than the document list on purpose — a photo of a dial is a
     * photo, and every one of these types has its EXIF stripped by
     * `UploadHandler`, which matters more here than anywhere else in the
     * module: a phone photograph taken inside somebody's rented hall
     * carries GPS coordinates.
     */
    private const ALLOWED_PHOTO_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
    ];

    public function __construct(
        Environment $twig,
        private RentalAuthorizationService $authorizationService,
        private ScoutYearService $scoutYearService,
        private RentalAssetRepository $assetRepository,
        private RentalBookingRepository $bookingRepository,
        private AuditService $audit,
        private RentalBookingCommentRepository $commentRepository,
        private RentalChangeRequestRepository $changeRequestRepository,
        private RentalOperationsService $operationsService,
        private RentalBlockService $blockService,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private MemberService $memberService,
        private DayStateGridBuilder $gridBuilder,
        /**
         * Optional (§6.19): null on an installation without the Finance
         * module, where the payment panel says so rather than rendering a
         * dead section.
         */
        private ?RentalPaymentService $paymentService = null,
        private ?RentalDocumentService $documentService = null,
        private ?RentalBookingMailService $mailService = null,
        private ?UploadHandler $uploadHandler = null,
        private ?RentalStayService $stayService = null,
        /**
         * Optional (§6.31): null without the `calendar` module, in which
         * case the publication section explains that instead of offering a
         * picker with nothing in it. `rental` consuming `calendar` is one
         * half of the circular dependency — see
         * Modules\Calendar\Service\VirtualEventRegistry for how the other
         * half is wired.
         */
        private ?CalendarService $calendarService = null,
        /**
         * Optional (§7.7): null without the `inbound_mail` module, in which
         * case the Communications tab is simply not offered. `rental`
         * consuming `inbound_mail` is an ordinary §7.5 dependency — the
         * booking page works identically without it, minus a tab.
         */
        private ?RentalCommunicationService $communicationService = null,
        /**
         * The asset paperwork register (§6.33). Nullable only so the
         * controller stays constructible in tests that do not care about
         * it — the module always wires one.
         */
        private ?RentalComplianceService $complianceService = null,
        private ?RentalStatisticsService $statisticsService = null,
        /**
         * Only « Régénérer le lien de suivi » needs it — the service half
         * of `RentalBookingRepository::regenerateTrackingToken()`, which is
         * where the journal entry is written. Nullable so the controller
         * stays constructible in the tests that do not reach that action.
         */
        private ?RentalBookingService $bookingService = null
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /mes-locations/{slug}/reglages — the asset's own settings: what a
     * visitor may ask for, what it costs, and what is expected up front.
     *
     * These three blocks were reachable only at `role_min: admin` until now,
     * which made a chief d'unité the only person able to price a hall
     * somebody else runs day to day. There is one authority in this module
     * and unit staff hold it over every asset (Service\RentalAuthorizationService),
     * so they reach this page as implicit managers rather than through a
     * second, admin-only screen.
     *
     * **The simulator is computed server-side, through the very same engine
     * as the public page and the contract** (Controller\RentalPricingController::simulate()).
     * That is the only thing that makes it a real guard-rail against a wrong
     * tariff rather than a second opinion — a client-side re-implementation
     * would be a guard-rail against nothing.
     *
     * @param array<string, string> $params
     */
    public function settings(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $pricingSettings = $this->pricingService->loadSettings($asset->id);
        $paymentSettings = $this->paymentService !== null
            ? $this->paymentService->settingsFor($asset->id)
            : new PaymentSettings();

        return $this->render('@rental/management/settings.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Réglages',
            'breadcrumb_trail' => $this->assetSubPageTrail($asset),
            'nav_page' => 'settings',
            'constraints' => $this->availabilityService->constraintsFor($asset->id),
            'pricing' => $pricingSettings,
            'billing_units' => \Modules\Rental\Pricing\BillingUnit::all(),
            'fee_natures' => \Modules\Rental\Pricing\RentalFee::natures(),
            'simulation' => RentalPricingController::simulate($this->pricingService, $pricingSettings, [
                'sim_arrival' => $request->getQuery('sim_arrival', ''),
                'sim_departure' => $request->getQuery('sim_departure', ''),
                'sim_persons' => $request->getQuery('sim_persons', 0),
                'sim_units' => $request->getQuery('sim_units', 1),
                'sim_rooms' => $request->getQuery('sim_rooms', 1),
                'sim_category' => $request->getQuery('sim_category', ''),
            ]),
            'finance_available' => $this->paymentService?->isAvailable() ?? false,
            'payment_settings' => $paymentSettings,
            // The account's NAME, resolved server-side — never the picker,
            // and never the IBAN the picker carries. Which account is in
            // force is legitimate context for whoever runs the asset; the
            // unit's account numbers are not (SECURITY.md §3).
            'payment_account_name' => $this->financeAccountName($paymentSettings->financeAccountId),
            // Whether to offer the link to the page that pins it, rather
            // than a dead end for somebody who cannot reach it.
            'can_pin_account' => $this->authorizationService->isUnitStaff(
                AuthSession::getEmail(),
                $this->scoutYearId()
            ),
            'deposit_modes' => DepositMode::all(),
            'csrf_token' => CsrfGuard::generateToken(),
            'current_path' => '/mes-locations/' . $asset->slug . '/reglages',
        ]);
    }

    /**
     * The display name of the Finance account an asset's money is expected
     * on, or null. Deliberately the name alone: `availableAccounts()` also
     * carries every account's IBAN, which has no business on a page a
     * manager who is not a chief can open.
     */
    private function financeAccountName(?int $accountId): ?string
    {
        if ($accountId === null || $this->paymentService === null) {
            return null;
        }

        foreach ($this->paymentService->availableAccounts() as $account) {
            if ((int) $account['id'] === $accountId) {
                return (string) $account['name'];
            }
        }

        return null;
    }

    /**
     * GET /mes-locations/{slug}/conformite — the asset's paperwork
     * register (§6.33).
     *
     * **A reminder list, not a compliance check.** The page says so in as
     * many words, and there is deliberately nothing here that computes a
     * status: what paperwork a hall needs differs by commune, by federation
     * and by year, and a green tick derived from a date would be a legal
     * opinion this software has no business giving.
     *
     * @param array<string, string> $params
     */
    public function compliance(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null || $this->complianceService === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@rental/management/compliance.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Conformité',
            'breadcrumb_trail' => $this->assetSubPageTrail($asset),
            'items' => $this->complianceService->forAsset($asset->id),
            'label_suggestions' => $this->complianceService->labelSuggestions(),
            'today' => new \DateTimeImmutable('today'),
            'warning_days' => RentalComplianceService::EXPIRY_WARNING_DAYS,
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'compliance',
        ]);
    }

    /**
     * POST /mes-locations/conformite-ajouter
     *
     * @param array<string, string> $params
     */
    public function addComplianceItem(Request $request, array $params): Response
    {
        return $this->complianceAction($request, function (RentalAsset $asset) use ($request): void {
            $fileId = $this->uploadComplianceFile($request, $asset);

            $this->complianceService?->add(
                $asset->id,
                (string) $request->getBody('label', ''),
                Support::optionalString($request->getBody('expires_on')),
                Support::optionalString($request->getBody('remark')),
                $fileId,
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Entrée ajoutée au registre.');
        });
    }

    /**
     * POST /mes-locations/conformite-modifier
     *
     * @param array<string, string> $params
     */
    public function updateComplianceItem(Request $request, array $params): Response
    {
        return $this->complianceAction($request, function (RentalAsset $asset) use ($request): void {
            $itemId = (int) $request->getBody('item_id', 0);

            $this->complianceService?->update(
                $asset->id,
                $itemId,
                (string) $request->getBody('label', ''),
                Support::optionalString($request->getBody('expires_on')),
                Support::optionalString($request->getBody('remark')),
                $this->actorMemberId()
            );

            $fileId = $this->uploadComplianceFile($request, $asset);
            if ($fileId !== null) {
                $this->complianceService?->attachFile($asset->id, $itemId, $fileId, $this->actorMemberId());
            }

            FlashMessage::set('success', 'Entrée mise à jour.');
        });
    }

    /**
     * POST /mes-locations/conformite-supprimer
     *
     * @param array<string, string> $params
     */
    public function deleteComplianceItem(Request $request, array $params): Response
    {
        return $this->complianceAction($request, function (RentalAsset $asset) use ($request): void {
            $this->complianceService?->delete(
                $asset->id,
                (int) $request->getBody('item_id', 0),
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Entrée supprimée.');
        });
    }

    /**
     * The register's own action shape: same guards as `bookingAction()`,
     * but back to the compliance page and without a booking.
     *
     * @param callable(RentalAsset): void $work
     */
    private function complianceAction(Request $request, callable $work): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null || $this->complianceService === null) {
            return new Response('Not Found', 404);
        }

        try {
            $work($asset);
        } catch (RentalException | UploadException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/conformite');
    }

    /**
     * The uploaded proof, when there is one.
     *
     * Through `UploadHandler` like every other file in this module: real
     * MIME check, regenerated name, EXIF stripped, stored outside
     * `public/`, and served only through `FileAccessGuard`.
     *
     * @throws UploadException
     */
    private function uploadComplianceFile(Request $request, RentalAsset $asset): ?int
    {
        $file = $request->getFile('document');
        if ($this->uploadHandler === null || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $this->uploadHandler->handle(
            $file,
            RentalComplianceService::STORAGE_SUBDIRECTORY,
            self::ALLOWED_DOCUMENT_MIMES,
            self::MAX_DOCUMENT_BYTES,
            RentalComplianceService::FILE_ROLE_MIN,
            'rental',
            AuthSession::getUserAccountId(),
            RentalComplianceService::FILE_OWNER_TYPE,
            $asset->id
        );
    }

    /**
     * GET /mes-locations — every asset the visitor manages (§6.5).
     *
     * @param array<string, string> $params
     */
    public function myRentals(Request $request, array $params): Response
    {
        $email = AuthSession::getEmail();
        $scoutYearId = $this->scoutYearId();
        $assets = $this->authorizationService->listManageableAssets($email, $scoutYearId);

        // Someone who manages nothing is still refused (403 — the menu never
        // offered them this page), but with a page saying what this space is
        // and how one becomes able to open it, rather than a bare
        // "Forbidden". The page names no asset and nobody: it is reached
        // precisely by people the module must tell nothing to.
        if ($assets === []) {
            return new Response(
                $this->renderToString('@rental/management/no_access.html.twig', []),
                403
            );
        }

        $bookings = $this->bookingRepository->findAllForAssets(array_map(
            static fn(RentalAsset $asset) => $asset->id,
            $assets
        ));

        $pending = array_values(array_filter(
            $bookings,
            static fn(RentalBooking $booking) => $booking->status->needsAttention()
        ));

        $countsByAsset = [];
        foreach ($pending as $booking) {
            $countsByAsset[$booking->assetId] = ($countsByAsset[$booking->assetId] ?? 0) + 1;
        }

        return $this->render('@rental/management/my_rentals.html.twig', [
            'assets' => $assets,
            'is_unit_staff' => $this->authorizationService->isUnitStaff($email, $scoutYearId),
            'pending_bookings' => $pending,
            'pending_counts' => $countsByAsset,
            'assets_by_id' => $this->indexById($assets),
        ]);
    }

    /**
     * GET /mes-locations/{slug} — one asset's overview.
     *
     * @param array<string, string> $params
     */
    public function overview(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $now = new \DateTimeImmutable();
        $bookings = $this->bookingRepository->findAllForAssets([$asset->id]);

        return $this->render('@rental/management/overview.html.twig', [
            'asset' => $asset,
            // A public asset with no rate at all answers every visitor
            // "Tarif sur demande" — true, but rarely what the unit meant.
            // Nobody used to be told; the public page just looked broken to
            // whoever tried it first.
            'tariff_missing' => $asset->isPublic
                && !$asset->isArchived
                && !$this->pricingService->loadSettings($asset->id)->hasAnyRate(),
            'breadcrumb_current' => $asset->name,
            'breadcrumb_trail' => $this->assetTrail(),
            'bookings' => $bookings,
            'needs_attention' => array_values(array_filter(
                $bookings,
                static fn(RentalBooking $b) => $b->status->needsAttention()
            )),
            'in_progress' => array_values(array_filter(
                $bookings,
                static fn(RentalBooking $b) => $b->isInProgress($now)
            )),
            'upcoming_blocks' => $this->blockService->upcomingFor($asset->id, $now),
            // The three figures of §6.34, read from the live bookings AND
            // the anonymous aggregates a purge left behind — otherwise the
            // year's revenue drops to zero the morning the purge runs.
            'statistics' => $this->statisticsService?->forAsset($asset->id, $now),
            'nav_page' => 'overview',
        ]);
    }

    /**
     * GET /mes-locations/{slug}/reservations — the list, with filters.
     *
     * @param array<string, string> $params
     */
    public function bookings(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $filter = (string) $request->getQuery('statut', '');
        $year = (string) $request->getQuery('annee', '');
        $search = trim((string) $request->getQuery('q', ''));

        // ONE query, then every filter applied to its result. The status
        // filter used to be pushed into the query and then thrown away
        // whenever "à traiter" was chosen — a second full read of the same
        // rows, in the same request. And the renter's name is encrypted,
        // so searching it cannot happen in SQL at all: filtering here is
        // what makes the reference and the name searchable by one box.
        $all = $this->bookingRepository->findAllForAssets([$asset->id]);

        // "À traiter" is not a status but the union of three, so it is a
        // filter of its own rather than something the enum has to pretend
        // to model.
        $status = BookingStatus::tryFrom($filter);
        $matching = array_values(array_filter($all, static function (RentalBooking $b) use (
            $filter,
            $status,
            $year,
            $search
        ): bool {
            if ($filter === 'a_traiter' && !$b->status->needsAttention()) {
                return false;
            }
            if ($status !== null && $b->status !== $status) {
                return false;
            }
            if ($year !== '' && !str_starts_with($b->arrivalDate, $year . '-')) {
                return false;
            }
            if ($search === '') {
                return true;
            }

            $haystack = $b->reference . ' ' . $b->renterName . ' ' . ($b->renterOrganisation ?? '');

            return mb_stripos($haystack, $search) !== false;
        }));

        $perPage = self::BOOKINGS_PER_PAGE;
        $totalPages = max(1, (int) ceil(count($matching) / $perPage));
        $page = min($totalPages, max(1, (int) $request->getQuery('page', 1)));

        return $this->render('@rental/management/bookings.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Réservations',
            'breadcrumb_trail' => $this->assetSubPageTrail($asset),
            'bookings' => array_slice($matching, ($page - 1) * $perPage, $perPage),
            'filter' => $filter,
            'year' => $year,
            'search' => $search,
            // Only the years this asset actually has bookings in: an
            // arbitrary range would offer a dozen empty answers.
            'years' => self::bookingYears($all),
            'statuses' => BookingStatus::cases(),
            'page' => $page,
            'total_pages' => $totalPages,
            'total_matching' => count($matching),
            'pagination_base_url' => '/mes-locations/' . rawurlencode($asset->slug) . '/reservations?'
                . http_build_query(['statut' => $filter, 'annee' => $year, 'q' => $search]) . '&page=',
            'nav_page' => 'bookings',
        ]);
    }

    /**
     * The arrival years this asset has bookings in, newest first.
     *
     * @param RentalBooking[] $bookings
     * @return string[]
     */
    private static function bookingYears(array $bookings): array
    {
        $years = [];
        foreach ($bookings as $booking) {
            $years[substr($booking->arrivalDate, 0, 4)] = true;
        }
        $years = array_keys($years);
        rsort($years);

        return $years;
    }

    /**
     * GET /mes-locations/{slug}/reservations/{id} — one booking's file.
     *
     * @param array<string, string> $params
     */
    public function booking(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $booking = $this->bookingOfAsset($asset, (int) ($params['id'] ?? 0));
        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        $now = new \DateTimeImmutable();

        $documents = $this->documentService?->forBooking($booking->id);
        $payment = $this->paymentStatus($booking, $asset);
        // Null, not [], when the stay module is unavailable: the checklist
        // reads the difference between "no inventory on this asset" and
        // "inventories do not exist here" (Booking\MilestoneEvidence).
        $inventory = $this->stayService?->inventoryFor($booking->id);
        $consumptions = $this->stayService?->consumptionsFor($booking, $asset->id);
        $evidence = MilestoneEvidence::collect(
            $booking,
            $documents,
            $payment,
            $inventory,
            $consumptions,
            $this->stayService?->latestSettlement($booking->id)
        );

        return $this->render('@rental/management/booking.html.twig', [
            'asset' => $asset,
            'booking' => $booking,
            'breadcrumb_current' => $booking->reference,
            'breadcrumb_trail' => $this->bookingTrail($asset),
            // The checklist is derived from what the booking's own records
            // say — the contract that was sent, the deposit that arrived,
            // the inventory that was finished — never from a stored flag,
            // so pressing a button on this page moves the box it belongs to
            // (§6.15).
            'milestones' => BookingMilestones::for($booking, $now, $evidence->done, $evidence->details),
            'allowed_transitions' => BookingTransition::allowedFrom($booking->status),
            // Keyed by status value so the template can ask "does this
            // button write to the renter?" without knowing which statuses
            // do — that answer belongs to Booking\RenterDecision alone.
            'renter_decisions' => self::renterDecisionPrompts(BookingTransition::allowedFrom($booking->status)),
            'can_confirm' => BookingTransition::isAllowed($booking->status, BookingStatus::CONFIRMED),
            'quote' => $this->operationsService->workingQuote($booking, $asset),
            'price_is_agreed' => $booking->priceHasBeenAgreed(),
            'comments' => $this->decorateWithAuthors($this->commentRepository->findForBooking($booking->id)),
            // The booking's own change history (§6.15), through Core\Audit
            // (§8.66) like every other timeline on the site. The partial
            // renders the first page server-side; later pages come from
            // /api/audit/{type}/{id}, which is why the entity type has to be
            // registered in AuditAccessResolver.
            'audit_page' => $this->audit->page(
                BookingAudit::ENTITY_TYPE, $booking->id, 1, AuditService::DEFAULT_PER_PAGE
            ),
            'audit_labels' => BookingAudit::FIELD_LABELS,
            'change_requests' => $this->changeRequestRepository->findForBooking($booking->id),
            'is_in_progress' => $booking->isInProgress($now),
            'payment' => $payment,
            'documents' => $documents ?? [],
            // Communications (§7.7). Absent rather than empty when
            // `inbound_mail` is disabled or no mailbox is enabled: a tab
            // that can only ever be empty is noise on a busy page.
            'communications_available' => $this->communicationService?->isAvailable() ?? false,
            'messages' => $this->communicationService?->timeline($booking) ?? [],
            'message_documents' => $this->communicationService?->documentsByFileId($booking) ?? [],
            'move_targets' => $this->communicationService?->moveTargets(
                $booking,
                AuthSession::getEmail(),
                (int) $this->scoutYearService->getCurrentYear()['id']
            ) ?? [],
            'uploadable_types' => DocumentType::uploadable(),
            'billing' => $this->bookingRepository->findBillingIdentity($booking->id),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'bookings',
        ]);
    }

    /**
     * POST /mes-locations/message/detacher — take a message off this
     * booking (§7.7).
     *
     * There is no queue for it to fall into, so it is deleted, along with
     * the attachments nobody re-classified. Said on the button rather than
     * hidden: a manager who detaches by mistake gets the message back only
     * if the next synchronisation still finds it on the server and still
     * matches it.
     *
     * @param array<string, string> $params
     */
    public function detachMessage(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->communicationService === null) {
                throw new RentalException("Le courrier entrant n'est pas disponible.");
            }

            $detached = $this->communicationService->detach(
                $booking,
                (int) $request->getBody('message_id', 0),
                $this->actorMemberId()
            );

            if (!$detached) {
                throw new RentalException("Ce message n'appartient pas à cette réservation.");
            }

            FlashMessage::set('success', 'Message détaché.');
        });
    }

    /**
     * POST /mes-locations/lien-suivi — mints a fresh tracking link and
     * mails it to the renter (§8.52).
     *
     * Revocability is what makes a capability token acceptable at all, and
     * until now nothing on any screen could exercise it: a renter who
     * forwarded their link to the wrong person had no way back. The token
     * itself never reaches the manager — regenerating it and sending it are
     * one action for that reason, not two.
     *
     * @param array<string, string> $params
     */
    public function regenerateTrackingLink(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset): void {
            if ($this->bookingService === null || $this->mailService === null) {
                throw new RentalException("Le lien de suivi ne peut pas être régénéré ici.");
            }

            $token = $this->bookingService->regenerateTrackingToken($booking->id);
            $sent = $this->mailService->sendTrackingLink($booking, $asset, $token);

            // The old link is dead either way — a failed email must not be
            // reported as though nothing had happened.
            FlashMessage::set(
                $sent ? 'success' : 'warning',
                $sent
                    ? 'Nouveau lien de suivi envoyé au locataire. L\'ancien ne fonctionne plus.'
                    : "L'ancien lien ne fonctionne plus, mais l'email portant le nouveau n'a pas pu partir : "
                        . 'régénérez-le à nouveau pour le renvoyer.'
            );
        });
    }

    /**
     * POST /mes-locations/message/deplacer — move a message to another
     * booking of an asset this manager manages (§7.7).
     *
     * @param array<string, string> $params
     */
    public function moveMessage(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->communicationService === null) {
                throw new RentalException("Le courrier entrant n'est pas disponible.");
            }

            $moved = $this->communicationService->move(
                $booking,
                (int) $request->getBody('message_id', 0),
                (int) $request->getBody('target_booking_id', 0),
                AuthSession::getEmail(),
                $this->scoutYearId(),
                $this->actorMemberId()
            );

            if (!$moved) {
                throw new RentalException("Ce message n'appartient pas à cette réservation.");
            }

            FlashMessage::set('success', 'Message déplacé.');
        });
    }

    /**
     * POST /mes-locations/document-reclasser — say what a document actually
     * is (§6.24, §7.8).
     *
     * The counterpart of an email attachment arriving as `Non classé`: it
     * is never presumed to be the signed contract, and this is how a
     * manager says that it is.
     *
     * @param array<string, string> $params
     */
    public function reclassifyDocument(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->documentService === null) {
                throw new RentalException('Les documents ne sont pas disponibles.');
            }

            $type = DocumentType::tryFrom((string) $request->getBody('document_type', ''));
            if ($type === null) {
                throw new RentalException('Type de document inconnu.');
            }

            $this->documentService->reclassify(
                $booking,
                (int) $request->getBody('document_id', 0),
                $type,
                $request->getBody('is_for_renter') !== null,
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Document reclassé.');
        });
    }

    /**
     * GET /mes-locations/{slug}/reservations/{id}/document/{type} — the
     * booking's own copy of a contract or invoice template (§6.25, level 2).
     *
     * @param array<string, string> $params
     */
    public function documentEditor(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null || $this->documentService === null) {
            return new Response('Not Found', 404);
        }

        $booking = $this->bookingOfAsset($asset, (int) ($params['id'] ?? 0));
        $type = DocumentType::tryFrom((string) ($params['type'] ?? ''));
        if ($booking === null || $type === null || !$type->isGenerated()) {
            return new Response('Not Found', 404);
        }

        $body = $this->documentService->bookingText($booking, $asset, $type);

        return $this->render('@rental/management/document_editor.html.twig', [
            'asset' => $asset,
            'booking' => $booking,
            'document_type' => $type,
            'breadcrumb_current' => $type->label(),
            'breadcrumb_trail' => $this->bookingTrail($asset, $booking),
            'body_html' => $body,
            // Level 1 for reference, so a manager can see what they are
            // diverging from without leaving the page.
            'asset_template' => $this->documentService->template($asset, $type),
            'keywords' => DocumentKeywords::catalogue(),
            // Reported while there is still time to fix them: a keyword
            // nobody recognises must never survive into a signed contract
            // as literal braces (§6.25).
            'unknown_keywords' => DocumentKeywords::unknownIn($body),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'bookings',
        ]);
    }

    /**
     * POST /mes-locations/document-texte
     *
     * @param array<string, string> $params
     */
    public function saveDocumentText(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->documentService === null) {
                throw new RentalException('Les documents ne sont pas disponibles.');
            }

            $type = DocumentType::tryFrom((string) $request->getBody('document_type', ''));
            if ($type === null || !$type->isGenerated()) {
                throw new RentalException("Ce type de document ne se rédige pas.");
            }

            $unknown = $this->documentService->saveBookingText(
                $booking,
                $type,
                (string) $request->getBody('body', ''),
                $this->actorMemberId()
            );

            FlashMessage::set(
                $unknown === [] ? 'success' : 'warning',
                $unknown === []
                    ? 'Texte enregistré.'
                    : 'Texte enregistré, mais ces mots-clés ne sont pas reconnus et resteront tels quels '
                        . 'dans le document : ' . implode(', ', $unknown) . '.'
            );
        });
    }

    /**
     * POST /mes-locations/document-generer — a new version, never an
     * overwrite (§6.25).
     *
     * @param array<string, string> $params
     */
    public function generateDocument(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            if ($this->documentService === null) {
                throw new RentalException('Les documents ne sont pas disponibles.');
            }

            $type = DocumentType::tryFrom((string) $request->getBody('document_type', ''));
            if ($type === null || !$type->isGenerated()) {
                throw new RentalException("Ce type de document ne se génère pas.");
            }

            $settings = $this->paymentService?->settingsFor($asset->id) ?? new PaymentSettings();
            $communication = $this->paymentService?->statusFor($booking, $settings)['communication'] ?? null;

            $document = $this->documentService->generate(
                $booking,
                $asset,
                $type,
                $settings,
                $communication,
                $this->actorMemberId()
            );

            FlashMessage::set(
                'success',
                $type->label() . ' v' . $document->version . ' généré. '
                . 'Les versions précédentes sont conservées.'
            );
        });
    }

    /**
     * POST /mes-locations/document-envoyer — email it to the renter
     * (§6.24, §6.26).
     *
     * @param array<string, string> $params
     */
    public function sendDocument(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            if ($this->documentService === null || $this->mailService === null) {
                throw new RentalException("L'envoi de documents n'est pas disponible.");
            }

            $document = $this->documentService->find((int) $request->getBody('document_id', 0));
            // The booking check is the guard that matters: a document id
            // alone must not let a manager mail another booking's contract.
            if ($document === null || $document->bookingId !== $booking->id) {
                throw new RentalException("Ce document n'existe pas.");
            }

            $path = $this->documentService->absolutePath($document);
            if ($path === null) {
                throw new RentalException("Le fichier de ce document est introuvable. Régénérez-le.");
            }

            $this->mailService->sendDocument(
                $booking,
                $asset,
                $document->label(),
                $path,
                $document->originalName ?? 'document.pdf',
                $document->hasBeenSent()
            );
            $this->documentService->markSent($document->id, new \DateTimeImmutable());

            FlashMessage::set('success', $document->label() . ' envoyé au locataire par email.');
        });
    }

    /**
     * POST /mes-locations/document-ajouter — a manager uploads a file
     * (§6.24).
     *
     * @param array<string, string> $params
     */
    public function uploadDocument(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->documentService === null || $this->uploadHandler === null) {
                throw new RentalException("L'ajout de documents n'est pas disponible.");
            }

            $type = DocumentType::tryFrom((string) $request->getBody('document_type', ''))
                ?? DocumentType::UNSORTED;
            if ($type->isGenerated()) {
                throw new RentalException('Un contrat ou une facture se génèrent, ils ne se téléversent pas.');
            }

            $uploaded = $request->getFile('document');
            if ($uploaded === null) {
                throw new RentalException('Aucun fichier reçu.');
            }

            try {
                // UploadHandler does the real work: true MIME check,
                // regenerated file name, EXIF stripped, size cap, stored
                // outside public/.
                $fileId = $this->uploadHandler->handle(
                    $uploaded,
                    RentalDocumentService::STORAGE_SUBDIRECTORY,
                    self::ALLOWED_DOCUMENT_MIMES,
                    self::MAX_DOCUMENT_BYTES,
                    RentalDocumentService::FILE_ROLE_MIN,
                    'rental',
                    AuthSession::getUserAccountId(),
                    RentalDocumentService::OWNER_TYPE,
                    $booking->id
                );
            } catch (UploadException $e) {
                // Narrow on purpose. UploadException's messages are French
                // and user-facing (« Le fichier dépasse la taille maximale
                // autorisée (10 Mo). »), so forwarding one is the point —
                // it is the only thing that tells the manager what to do.
                // A \Throwable from anywhere else has nothing to say to
                // them and must not be dressed up as if it did; it now
                // reaches the error handler instead. This method's callers
                // catch RentalException, hence the re-throw.
                throw new RentalException($e->getMessage(), 0, $e);
            }

            $this->documentService->attachUploaded(
                $booking,
                $fileId,
                $type,
                $request->getBody('is_for_renter') !== null,
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Document ajouté.');
        });
    }

    /**
     * POST /mes-locations/document-supprimer
     *
     * @param array<string, string> $params
     */
    public function deleteDocument(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->documentService === null) {
                throw new RentalException('Les documents ne sont pas disponibles.');
            }

            $document = $this->documentService->find((int) $request->getBody('document_id', 0));
            if ($document === null || $document->bookingId !== $booking->id) {
                throw new RentalException("Ce document n'existe pas.");
            }

            $this->documentService->delete($document, $this->actorMemberId());
            FlashMessage::set('success', 'Document supprimé.');
        });
    }

    /**
     * POST /mes-locations/facturation — the renter's billing identity
     * (§6.27).
     *
     * @param array<string, string> $params
     */
    public function saveBillingIdentity(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            $this->bookingRepository->saveBillingIdentity($booking->id, [
                'name' => Support::optionalString($request->getBody('billing_name')),
                'address' => Support::optionalString($request->getBody('billing_address')),
                'country' => Support::optionalString($request->getBody('billing_country')),
                'vat_number' => Support::optionalString($request->getBody('billing_vat_number')),
                'enterprise_number' => Support::optionalString($request->getBody('billing_enterprise_number')),
                'email' => Support::optionalString($request->getBody('billing_email')),
                'reference' => Support::optionalString($request->getBody('billing_reference')),
            ]);

            FlashMessage::set('success', 'Coordonnées de facturation enregistrées.');
        });
    }

    /**
     * Where this booking's money is (§6.19, §6.20), or a flat "not
     * available" when Finance is off — the template then renders one honest
     * sentence rather than a dead panel.
     *
     * @return array<string, mixed>
     */
    private function paymentStatus(RentalBooking $booking, RentalAsset $asset): array
    {
        if ($this->paymentService === null) {
            return [
                'available' => false,
                'enabled' => false,
                'security_deposit' => ['amount_cents' => null],
            ];
        }

        return $this->paymentService->statusFor($booking, $this->paymentService->settingsFor($asset->id));
    }

    /**
     * POST /mes-locations/caution — records what was actually given back
     * (§6.20).
     *
     * By hand, deliberately: Finance reconciles incoming transfers, not
     * outgoing ones, so no bank statement can confirm this.
     *
     * @param array<string, string> $params
     */
    public function recordSecurityDeposit(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            if ($this->paymentService === null) {
                throw new RentalException("Le module « Finances » n'est pas actif.");
            }

            $returnedAt = DateInput::iso((string) $request->getBody('returned_at', ''))
                ?? new \DateTimeImmutable('today');

            $this->paymentService->recordSecurityDepositReturn(
                $booking,
                RentalPricingService::parseAmountToCents((string) $request->getBody('returned', '')) ?? 0,
                Support::optionalString($request->getBody('note')),
                $returnedAt,
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Restitution de la caution enregistrée.');
        });
    }

    /**
     * GET /mes-locations/{slug}/calendrier — the private calendar (§6.18).
     *
     * Unlike the public one it **distinguishes** bookings, options and
     * manual blocks, and it pages into the past: half a manager's work is
     * about stays that already happened.
     *
     * @param array<string, string> $params
     */
    public function calendar(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $today = new \DateTimeImmutable('today');
        $window = MonthWindow::resolve(
            (string) $request->getQuery('month', ''),
            $today,
            self::MONTHS_BACK,
            self::MONTHS_AHEAD
        );

        $pricing = $this->pricingService->loadSettings($asset->id);
        $states = $this->availabilityService->monthDayStates(
            $asset,
            $pricing->billingUnit,
            $window->year,
            $window->month,
            $today,
            // A manager's grid, not a visitor's form. Occupancy outranks the
            // bookable window here: this page can page 24 months back, and
            // the public precedence greyed every one of those days out as
            // "Date passée" — hiding the very bookings the calendar exists
            // to show. Same for a day inside the notice period, which is not
            // in the past at all.
            discloseOccupancy: true
        );

        $from = $window->firstDay()->modify('-7 days');
        $to = $window->lastDay()->modify('+7 days');

        return $this->render('@rental/management/calendar.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Calendrier',
            'breadcrumb_trail' => $this->assetSubPageTrail($asset),
            'calendar' => $this->gridBuilder->build(
                $window->year,
                $window->month,
                $states,
                // Every unmapped day still needs an accessible label; the
                // calculator maps the whole rendered window, so this is a
                // belt-and-braces default rather than a normal path.
                new DayState(DayState::STATE_UNSELECTABLE, 'Indisponible'),
                $today
            ),
            'calendar_label' => $window->label(),
            'previous_month' => $window->previous(),
            'next_month' => $window->next(),
            // The three are listed separately rather than merged into one
            // "occupied" list: to the public they are indistinguishable by
            // design, but a manager needs to know which is which before
            // deciding anything (§6.18).
            'bookings' => $this->bookingRepository->findOccupyingBetween(
                $asset->id,
                $from->format('Y-m-d'),
                $to->format('Y-m-d')
            ),
            'blocks' => $this->blockService->upcomingFor($asset->id, $from),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'calendar',
        ]);
    }

    /**
     * GET /mes-locations/{slug}/gabarits — the asset's contract and invoice
     * templates (§6.25, level 1).
     *
     * **In the managed space, not in the configuration one.** The wording a
     * unit lets its hall under is the managers' business, and they are not
     * necessarily chiefs (§6.4). Unit staff reach it too, being implicit
     * managers of every asset.
     *
     * @param array<string, string> $params
     */
    public function templates(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null || $this->documentService === null) {
            return new Response('Not Found', 404);
        }

        $templates = [];
        foreach ([DocumentType::CONTRACT, DocumentType::INVOICE] as $type) {
            // The editor shows the text generation would actually use: the
            // asset's own wording, or the shipped standard while nobody has
            // written any — never an empty surface asking a volunteer to
            // write a rental contract from nothing.
            $body = $this->documentService->templateOrStandard($asset, $type);
            $templates[] = [
                'type' => $type,
                'body' => $body,
                // Drives the note vs. the "réinitialiser" button. Compared
                // ignoring whitespace: the sanitizer may reflow what a reset
                // stored, and a no-op reset button on a standard template is
                // the harmless direction to fail in.
                'is_standard' => self::isStandardBody($body, StandardTemplates::forType($type)),
                'unknown_keywords' => DocumentKeywords::unknownIn($body),
            ];
        }

        return $this->render('@rental/management/templates.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Gabarits',
            'breadcrumb_trail' => $this->assetSubPageTrail($asset),
            'templates' => $templates,
            // The ready-to-use Belgian bodies, offered on the page rather
            // than applied behind anybody's back: an empty editor asks a
            // volunteer to write a rental contract from nothing, which in
            // practice means either no contract or one copied from whatever
            // the previous chief had on a USB stick.
            'standard_templates' => [
                DocumentType::CONTRACT->value => StandardTemplates::forType(DocumentType::CONTRACT),
                DocumentType::INVOICE->value => StandardTemplates::forType(DocumentType::INVOICE),
            ],
            'keywords' => DocumentKeywords::catalogue(),
            'vat_note' => $asset->vatExemptionNote,
            // Meters and the inventory checklist share this page because
            // all three are "what this asset needs before a stay can be
            // settled" (§6.22, §6.23).
            'meters' => $this->stayService?->metersFor($asset->id) ?? [],
            'meter_kinds' => \Modules\Rental\Stay\MeterKind::all(),
            'meter_fees' => array_values(array_filter(
                $this->pricingService->loadSettings($asset->id)->fees,
                static fn($fee) => $fee->nature === \Modules\Rental\Pricing\RentalFee::NATURE_METER
            )),
            'inventory_template' => $this->stayService?->inventoryTemplateFor($asset->id) ?? [],
            // Calendar publication (§6.30). With the module off there is
            // nothing to publish onto, and the section says so.
            'calendar_available' => $this->calendarService !== null,
            // Labels resolved by the calendar module itself
            // (CalendarService::listSelectableCalendars): a section calendar
            // has no name of its own, so rendering `calendar.name` gave a
            // list of blank options with only "Animateurs" readable.
            'calendars' => $this->calendarService?->listSelectableCalendars() ?? [],
            'publish_from_options' => PublishFrom::all(),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'templates',
        ]);
    }

    /**
     * Whether $body still is the shipped standard template, whitespace
     * aside. The sanitizer may reflow markup on the way into storage, so an
     * exact string comparison would misread a freshly reset template as a
     * customised one; comparing without whitespace errs the other way — at
     * worst the reset button shows on a standard template and resetting is
     * a no-op.
     */
    private static function isStandardBody(string $body, ?string $standard): bool
    {
        if ($standard === null) {
            return false;
        }

        $strip = static fn(string $html): string => (string) preg_replace('/\s+/u', '', $html);

        return $strip($body) === $strip($standard);
    }

    /**
     * POST /mes-locations/compteur — add or retire a meter (§6.22).
     *
     * In the managed space, like the contract templates: what is metered on
     * a hall is its managers' business, and they are not necessarily chiefs
     * (§6.4).
     *
     * @param array<string, string> $params
     */
    public function saveMeter(Request $request, array $params): Response
    {
        return $this->assetSetupAction($request, function (RentalAsset $asset) use ($request): void {
            if ((string) $request->getBody('meter_action', '') === 'retire') {
                $this->stayService?->retireMeter($asset->id, (int) $request->getBody('meter_id', 0));
                FlashMessage::set(
                    'success',
                    'Compteur retiré. Les relevés déjà pris restent, ils servent de preuve.'
                );

                return;
            }

            $feeId = (int) $request->getBody('fee_id', 0);
            $this->stayService?->addMeter(
                $asset->id,
                (string) $request->getBody('label', ''),
                \Modules\Rental\Stay\MeterKind::tryFrom((string) $request->getBody('kind', ''))
                    ?? \Modules\Rental\Stay\MeterKind::OTHER,
                (string) $request->getBody('unit', ''),
                $feeId > 0 ? $feeId : null,
                (int) $request->getBody('sort_order', 0)
            );

            FlashMessage::set('success', 'Compteur ajouté.');
        });
    }

    /**
     * POST /mes-locations/inventaire-modele — the asset's checklist (§6.23).
     *
     * @param array<string, string> $params
     */
    public function saveInventoryTemplate(Request $request, array $params): Response
    {
        return $this->assetSetupAction($request, function (RentalAsset $asset) use ($request): void {
            if ((string) $request->getBody('item_action', '') === 'remove') {
                $this->stayService?->removeInventoryItem((int) $request->getBody('item_id', 0));
                FlashMessage::set(
                    'success',
                    'Élément retiré du modèle. Les états des lieux déjà figés ne changent pas.'
                );

                return;
            }

            $this->stayService?->addInventoryItem(
                $asset->id,
                (string) $request->getBody('label', ''),
                (int) $request->getBody('sort_order', 0)
            );

            FlashMessage::set('success', 'Élément ajouté au modèle.');
        });
    }

    /**
     * The shared shape of an asset-level setup write: CSRF, authorisation,
     * the work, back to the templates page.
     *
     * @param callable(RentalAsset): void $work
     */
    private function assetSetupAction(Request $request, callable $work): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null || $this->stayService === null) {
            return new Response('Not Found', 404);
        }

        try {
            $work($asset);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/gabarits');
    }

    /**
     * POST /mes-locations/calendrier-publication — where this asset's
     * occupancy is published (§6.30).
     *
     * @param array<string, string> $params
     */
    public function saveCalendarPublication(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null || $this->calendarService === null) {
            return new Response('Not Found', 404);
        }

        // Several, not one: a hall is very often both the unit's business
        // and one section's, and the old single choice meant the other
        // group simply never saw it. Cross-checked against the calendars
        // that actually exist, so a stale id in a posted form cannot create
        // a row pointing at nothing.
        $existingIds = array_map(
            static fn(array $calendar) => $calendar['id'],
            $this->calendarService->listSelectableCalendars()
        );
        $calendarIds = array_values(array_intersect(
            array_map('intval', (array) ($request->getBody('calendar_ids') ?? [])),
            $existingIds
        ));
        $enabled = $request->getBody('publication_enabled') !== null;

        if ($enabled && $calendarIds === []) {
            // Half-configured is worse than off: an asset that says
            // "publish" but has nowhere to publish to looks like it works.
            FlashMessage::set('error', 'Choisissez au moins un calendrier sur lequel publier cette occupation.');

            return $this->redirect('/mes-locations/' . $asset->slug . '/gabarits');
        }

        $this->assetRepository->saveCalendarPublication(
            $asset->id,
            $enabled,
            $calendarIds,
            PublishFrom::tryFrom((string) $request->getBody('publish_from', '')) ?? PublishFrom::CONFIRMATION
        );

        FlashMessage::set(
            'success',
            $enabled
                ? "Publication activée. L'occupation apparaît comme événement calculé : rien n'est écrit dans le calendrier."
                : 'Publication désactivée.'
        );

        return $this->redirect('/mes-locations/' . $asset->slug . '/gabarits');
    }

    /**
     * POST /mes-locations/gabarit
     *
     * @param array<string, string> $params
     */
    public function saveTemplate(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null || $this->documentService === null) {
            return new Response('Not Found', 404);
        }

        $type = DocumentType::tryFrom((string) $request->getBody('document_type', ''));
        if ($type === null || !$type->isGenerated()) {
            return new Response('Not Found', 404);
        }

        $unknown = $this->documentService->saveTemplate(
            $asset,
            $type,
            (string) $request->getBody('body', ''),
            AuthSession::getUserAccountId(),
            $this->actorMemberId()
        );

        // The VAT-exemption sentence lives with the invoice template
        // because that is the only place it is ever read (§6.27).
        if ($type === DocumentType::INVOICE) {
            $this->assetRepository->saveVatExemptionNote(
                $asset->id,
                Support::optionalString($request->getBody('vat_exemption_note'))
            );
        }

        FlashMessage::set(
            $unknown === [] ? 'success' : 'warning',
            $unknown === []
                ? 'Gabarit enregistré.'
                : 'Gabarit enregistré, mais ces mots-clés ne sont pas reconnus et resteront tels quels '
                    . 'dans les documents : ' . implode(', ', $unknown) . '.'
        );

        return $this->redirect('/mes-locations/' . $asset->slug . '/gabarits');
    }

    /**
     * GET /mes-locations/{slug}/reservations/{id}/sejour — meters,
     * inventory, incidents and the settlement (§6.21–§6.23).
     *
     * Its own page rather than another card on the booking's file: this is
     * what a manager opens on the day, and burying it under a contract
     * editor and a payment panel would make the one screen used with muddy
     * boots on the hardest to reach.
     *
     * **Deliberately absent from `Core\Offline\OfflineWhitelist`** (§6.23).
     * The module declares no `offline` section at all, so nothing here is
     * ever cached — and it must stay that way: these are WRITE pages, and
     * the offline layer caches reads. A cached inventory form would let a
     * manager fill it in on a phone with no signal and lose everything on
     * the way home. The documented workaround is the honest one: photograph
     * on site, type it up on return.
     *
     * @param array<string, string> $params
     */
    public function stay(Request $request, array $params): Response
    {
        $asset = $this->manageableAsset($params);
        if ($asset === null || $this->stayService === null) {
            return new Response('Not Found', 404);
        }

        $booking = $this->bookingOfAsset($asset, (int) ($params['id'] ?? 0));
        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        // The count from the last settlement if one exists, else what was
        // announced — a manager correcting a figure should not have to
        // retype the one they already recorded.
        $latestSettlement = $this->stayService->latestSettlement($booking->id);
        $finalPersons = $latestSettlement !== null && $latestSettlement->finalPersons !== null
            ? $latestSettlement->finalPersons
            : $booking->estimatedPersons;

        return $this->render('@rental/management/stay.html.twig', [
            'asset' => $asset,
            'booking' => $booking,
            'breadcrumb_current' => 'Séjour',
            'breadcrumb_trail' => $this->bookingTrail($asset, $booking),
            'consumptions' => $this->stayService->consumptionsFor($booking, $asset->id),
            'inventory' => $this->stayService->inventoryFor($booking->id),
            'inventory_states' => InventoryState::all(),
            'incidents' => $this->stayService->incidentsFor($booking->id),
            'incident_decisions' => IncidentDecision::decidable(),
            'settlements' => $this->stayService->settlementsFor($booking->id),
            'final_persons' => $finalPersons,
            // Recomputed live so a manager sees the effect of the reading
            // they just typed — looking never creates a version.
            'preview' => $this->stayService->previewSettlement($booking, $asset->id, $finalPersons),
            'phases' => ReadingPhase::cases(),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'bookings',
        ]);
    }

    /**
     * POST /mes-locations/releve — one meter reading (§6.22).
     *
     * @param array<string, string> $params
     */
    public function recordReading(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            $phase = ReadingPhase::tryFrom((string) $request->getBody('phase', ''));
            if ($phase === null) {
                throw new RentalException("Cette phase n'existe pas.");
            }

            $readAt = DateInput::parse(DateInput::ISO_DATETIME_LOCAL, (string) $request->getBody('read_at', ''))
                ?? new \DateTimeImmutable();

            $fileId = null;
            $uploaded = $request->getFile('photo');
            if ($uploaded !== null && $this->uploadHandler !== null) {
                try {
                    $fileId = $this->uploadHandler->handle(
                        $uploaded,
                        RentalDocumentService::STORAGE_SUBDIRECTORY,
                        self::ALLOWED_PHOTO_MIMES,
                        self::MAX_DOCUMENT_BYTES,
                        RentalDocumentService::FILE_ROLE_MIN,
                        'rental',
                        AuthSession::getUserAccountId(),
                        RentalDocumentService::OWNER_TYPE,
                        $booking->id
                    );
                } catch (UploadException $e) {
                    // Narrow on purpose — see the identical catch in
                    // uploadDocument() above.
                    throw new RentalException($e->getMessage(), 0, $e);
                }
            }

            $this->stayService?->recordReading(
                $booking,
                $asset->id,
                (int) $request->getBody('meter_id', 0),
                $phase,
                Support::optionalString($request->getBody('value')),
                $readAt,
                $fileId,
                Support::optionalString($request->getBody('comment')),
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Relevé enregistré.');
        });
    }

    /**
     * POST /mes-locations/inventaire — one checklist line (§6.23).
     *
     * @param array<string, string> $params
     */
    public function recordInventory(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking) use ($request): void {
            $phase = ReadingPhase::tryFrom((string) $request->getBody('phase', ''));
            $state = InventoryState::tryFrom((string) $request->getBody('state', ''));
            if ($phase === null || $state === null) {
                throw new RentalException("Cet état n'existe pas.");
            }

            $this->stayService?->setInventoryState(
                $booking,
                (int) $request->getBody('inventory_id', 0),
                $phase,
                $state,
                Support::optionalString($request->getBody('note'))
            );

            FlashMessage::set('success', "État des lieux mis à jour.");
        });
    }

    /**
     * POST /mes-locations/incident — report a damage (§6.23).
     *
     * @param array<string, string> $params
     */
    public function reportIncident(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking) use ($request): void {
            $fileId = null;
            $uploaded = $request->getFile('photo');
            if ($uploaded !== null && $this->uploadHandler !== null) {
                try {
                    $fileId = $this->uploadHandler->handle(
                        $uploaded,
                        RentalDocumentService::STORAGE_SUBDIRECTORY,
                        self::ALLOWED_PHOTO_MIMES,
                        self::MAX_DOCUMENT_BYTES,
                        RentalDocumentService::FILE_ROLE_MIN,
                        'rental',
                        AuthSession::getUserAccountId(),
                        RentalDocumentService::OWNER_TYPE,
                        $booking->id
                    );
                } catch (UploadException $e) {
                    // Narrow on purpose — see the identical catch in
                    // uploadDocument() above.
                    throw new RentalException($e->getMessage(), 0, $e);
                }
            }

            $this->stayService?->reportIncident(
                $booking,
                (string) $request->getBody('description', ''),
                RentalPricingService::parseAmountToCents((string) $request->getBody('amount', '')),
                $fileId,
                $this->actorMemberId()
            );

            FlashMessage::set(
                'success',
                'Incident enregistré. Rien n\'est facturé tant que vous n\'avez pas tranché.'
            );
        });
    }

    /**
     * POST /mes-locations/incident-decision — the human decision (§6.23).
     *
     * @param array<string, string> $params
     */
    public function decideIncident(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking) use ($request): void {
            $decision = IncidentDecision::tryFrom((string) $request->getBody('decision', ''));
            if ($decision === null) {
                throw new RentalException("Cette décision n'existe pas.");
            }

            $this->stayService?->decideIncident(
                $booking,
                (int) $request->getBody('incident_id', 0),
                $decision,
                RentalPricingService::parseAmountToCents((string) $request->getBody('amount', '')),
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Décision enregistrée : ' . $decision->label() . '.');
        });
    }

    /**
     * POST /mes-locations/decompte — record a settlement version (§6.21).
     *
     * @param array<string, string> $params
     */
    public function recordSettlement(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            if ($this->stayService === null) {
                throw new RentalException('Le décompte final n\'est pas disponible.');
            }

            $settlement = $this->stayService->recordSettlement(
                $booking,
                $asset->id,
                self::optionalInt($request->getBody('final_persons')),
                [],
                $this->actorMemberId()
            );

            FlashMessage::set(
                'success',
                'Décompte v' . $settlement->version . ' enregistré. '
                . 'Le prix convenu n\'est pas modifié.'
            );
        });
    }

    /**
     * POST /mes-locations/decompte-valider (§6.21).
     *
     * @param array<string, string> $params
     */
    public function validateSettlement(Request $request, array $params): Response
    {
        return $this->stayAction($request, function (RentalBooking $booking) use ($request): void {
            $this->stayService?->validateSettlement(
                $booking,
                (int) $request->getBody('settlement_id', 0),
                $this->actorMemberId()
            );

            FlashMessage::set('success', 'Décompte validé. Il ne peut plus être modifié.');
        });
    }

    // ── Write actions ───────────────────────────────────────────────────

    /**
     * POST /mes-locations/statut
     *
     * @param array<string, string> $params
     */
    public function changeStatus(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            $target = BookingStatus::tryFrom((string) $request->getBody('status', ''));
            if ($target === null) {
                throw new RentalException("Cet état n'existe pas.");
            }

            $now = new \DateTimeImmutable();
            $word = Support::optionalString($request->getBody('message'));

            if ($target === BookingStatus::CONFIRMED) {
                $this->operationsService->confirm($booking, $asset, $this->actorMemberId(), $now);
                FlashMessage::set(
                    'success',
                    'Réservation confirmée.'
                    . $this->tellRenter($booking, $asset, RenterDecision::CONFIRMED, $word)
                );

                return;
            }

            $this->operationsService->changeStatus($booking, $target, $this->actorMemberId(), $now);
            FlashMessage::set(
                'success',
                'Réservation « ' . $target->label() . ' ».'
                . $this->tellRenter($booking, $asset, RenterDecision::forStatus($target), $word)
                . $this->outstandingMoneyWarning($booking, $asset, $target)
            );
        });
    }

    /** `467,50 €` — for a flash message a human reads, never for arithmetic. */

    /**
     * The sentence a manager needs when they close a booking that is still
     * owed money.
     *
     * Nothing here decides anything — §6.17 is explicit that there is no
     * automatic cancellation scale and nothing computes a refund. But
     * cancelling a booking leaves its receivable exactly where it was, and
     * "Réservation « Annulée »." alone reads as "everything is settled".
     * A manager who is not told has to remember to look.
     */
    private function outstandingMoneyWarning(
        RentalBooking $booking,
        RentalAsset $asset,
        BookingStatus $target
    ): string {
        if (!$target->isFinal() || $this->paymentService === null) {
            return '';
        }

        $status = $this->paymentStatus($booking, $asset);
        $due = (int) ($status['total_cents'] ?? 0);
        $received = (int) ($status['received_cents'] ?? 0);
        if ($due <= 0 || $received >= $due) {
            return '';
        }

        return $received > 0
            ? ' Attention : ' . Support::euros($due - $received) . ' restent attendus sur cette réservation'
                . ' — décidez ce qu\'il advient de la créance dans les Finances.'
            : ' Attention : la créance de ' . Support::euros($due)
                . ' est toujours ouverte dans les Finances.';
    }

    /**
     * POST /mes-locations/option — a manager's hold with a deadline (§6.14).
     *
     * @param array<string, string> $params
     */
    public function placeOption(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            $until = (string) $request->getBody('until', '');

            if ($until === '') {
                $this->operationsService->clearHold($booking, $this->actorMemberId());
                FlashMessage::set('success', 'Blocage levé.');

                return;
            }

            $deadline = DateInput::parse(DateInput::ISO_DATETIME_LOCAL, $until)
                ?? DateInput::iso($until);

            if ($deadline === null) {
                throw new RentalException("L'échéance n'est pas une date valide.");
            }

            $this->operationsService->placeOption(
                $booking,
                $deadline,
                $this->actorMemberId(),
                new \DateTimeImmutable()
            );
            FlashMessage::set('success', "Option posée jusqu'au " . $deadline->format('d/m/Y à H\hi') . '.');
        });
    }

    /**
     * POST /mes-locations/commentaire — an internal comment (§6.6).
     *
     * @param array<string, string> $params
     */
    public function addComment(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            $this->operationsService->addComment(
                $booking,
                $this->actorMemberId(),
                (string) $request->getBody('body', '')
            );
            FlashMessage::set('success', 'Commentaire enregistré. Il reste invisible du locataire.');
        });
    }

    /**
     * POST /mes-locations/ligne — edit, add or remove a price line (§6.12).
     *
     * @param array<string, string> $params
     */
    public function priceLine(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            $action = (string) $request->getBody('line_action', '');
            $index = (int) $request->getBody('index', -1);
            $actor = $this->actorMemberId();

            match ($action) {
                'add' => $this->operationsService->addPriceLine(
                    $booking,
                    $asset,
                    (string) $request->getBody('label', ''),
                    max(1, (int) $request->getBody('quantity', 1)),
                    RentalPricingService::parseAmountToCents((string) $request->getBody('amount', '')) ?? 0,
                    $actor
                ),
                'remove' => $this->operationsService->removePriceLine($booking, $asset, $index, $actor),
                'edit' => $this->operationsService->editPriceLine(
                    $booking,
                    $asset,
                    $index,
                    Support::optionalString($request->getBody('label')),
                    $request->getBody('quantity') !== null ? max(1, (int) $request->getBody('quantity')) : null,
                    Support::optionalString($request->getBody('amount')) !== null
                        ? RentalPricingService::parseAmountToCents((string) $request->getBody('amount'))
                        : null,
                    $actor
                ),
                'recalculate' => $this->operationsService->recalculate($booking, $asset, $actor),
                default => throw new RentalException("Cette action sur une ligne n'existe pas."),
            };

            FlashMessage::set(
                'success',
                $action === 'recalculate'
                    ? 'Lignes automatiques recalculées. Les lignes modifiées à la main sont conservées.'
                    : 'Prix mis à jour. Le locataire le voit immédiatement sur sa page de suivi.'
            );
        });
    }

    /**
     * POST /mes-locations/proposition — a manager's proposal (§6.16).
     *
     * @param array<string, string> $params
     */
    public function propose(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            $kind = ChangeRequestKind::tryFrom((string) $request->getBody('kind', ''));
            if ($kind === null) {
                throw new RentalException("Ce type de proposition n'existe pas.");
            }

            $this->operationsService->requestChange(
                $booking,
                ChangeRequestOrigin::MANAGER,
                $kind,
                Support::optionalString($request->getBody('arrival')),
                Support::optionalString($request->getBody('departure')),
                $request->getBody('units') !== null ? max(1, (int) $request->getBody('units')) : null,
                $request->getBody('persons') !== null ? max(1, (int) $request->getBody('persons')) : null,
                null,
                Support::optionalString($request->getBody('message')),
                $this->actorMemberId()
            );

            // The flash used to say « Proposition envoyée » while nothing
            // had been sent to anyone. It now says what actually left.
            FlashMessage::set(
                'success',
                'Proposition enregistrée. Elle ne change rien tant que le locataire ne l\'a pas acceptée.'
                . $this->tellRenter(
                    $booking,
                    $asset,
                    RenterDecision::PROPOSED,
                    Support::optionalString($request->getBody('message'))
                )
            );
        });
    }

    /**
     * POST /mes-locations/demande — decide a renter's change request (§6.16).
     *
     * @param array<string, string> $params
     */
    public function decideChange(Request $request, array $params): Response
    {
        return $this->bookingAction($request, function (RentalBooking $booking, RentalAsset $asset) use ($request): void {
            $changeRequest = $this->changeRequestRepository->findById((int) $request->getBody('request_id', 0));
            if ($changeRequest === null || $changeRequest->bookingId !== $booking->id) {
                throw new RentalException("Cette demande n'existe pas.");
            }

            $word = Support::optionalString($request->getBody('message'));

            if ((string) $request->getBody('decision', '') === 'accept') {
                $this->operationsService->acceptChange(
                    $changeRequest,
                    $booking,
                    $asset,
                    ChangeRequestOrigin::MANAGER,
                    $this->actorMemberId(),
                    new \DateTimeImmutable()
                );
                // Re-read: accepting a change rewrites the dates, and the
                // copy in hand is from before that — the email has to
                // quote the booking the renter now has, not the one they
                // asked to leave behind.
                $updated = $this->bookingRepository->findById($booking->id) ?? $booking;
                FlashMessage::set(
                    'success',
                    'Demande acceptée et appliquée.'
                    . $this->tellRenter($updated, $asset, RenterDecision::CHANGE_ACCEPTED, $word)
                );

                return;
            }

            $this->operationsService->refuseChange($changeRequest, ChangeRequestOrigin::MANAGER, $this->actorMemberId());
            FlashMessage::set(
                'success',
                'Demande refusée. La réservation est inchangée.'
                . $this->tellRenter($booking, $asset, RenterDecision::CHANGE_REFUSED, $word)
            );
        });
    }

    /**
     * POST /mes-locations/blocage — a manual block (§6.18).
     *
     * @param array<string, string> $params
     */
    public function createBlock(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        try {
            $this->blockService->create(
                $asset->id,
                (string) $request->getBody('start', ''),
                (string) $request->getBody('end', ''),
                max(1, (int) $request->getBody('units', 1)),
                Support::optionalString($request->getBody('reason')),
                $this->actorMemberId()
            );

            // Accepted, never refused, even over a booked period (§6.18) —
            // but said out loud, so an accidental overlap is visible rather
            // than silent.
            $overlapping = $this->bookingRepository->findOccupyingBetween(
                $asset->id,
                (string) $request->getBody('start', ''),
                (string) $request->getBody('end', '')
            );

            FlashMessage::set(
                $overlapping === [] ? 'success' : 'warning',
                $overlapping === []
                    ? 'Blocage enregistré.'
                    : 'Blocage enregistré. Attention : ' . count($overlapping)
                        . ' réservation(s) occupent déjà tout ou partie de cette période. '
                        . 'Les deux coexistent — traitez chaque réservation individuellement.'
            );
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/calendrier');
    }

    /**
     * POST /mes-locations/blocage-supprimer
     *
     * @param array<string, string> $params
     */
    public function deleteBlock(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        try {
            $this->blockService->delete($asset->id, (int) $request->getBody('block_id', 0));
            FlashMessage::set('success', 'Blocage supprimé.');
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/calendrier');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * For each transition offered, the question and the note label of the
     * email it would send — or nothing at all for the ones that write to
     * nobody.
     *
     * @param list<BookingStatus> $targets
     * @return array<string, array{question: string, note_label: string}>
     */
    private static function renterDecisionPrompts(array $targets): array
    {
        $prompts = [];
        foreach ($targets as $target) {
            $decision = RenterDecision::forStatus($target);
            if ($decision !== null) {
                $prompts[$target->value] = [
                    'question' => $decision->question(),
                    'note_label' => $decision->noteLabel(),
                ];
            }
        }

        return $prompts;
    }

    /**
     * Tells the renter what was just decided, and says so in the flash.
     *
     * Returns the sentence to append to the manager's own confirmation —
     * « … Le locataire a été prévenu par email. » — because the manager
     * needs to know whether the message left. Silence would leave them
     * guessing, and guessing here ends with a second phone call.
     *
     * A null decision means nothing was decided that the renter should
     * hear about (a booking moved to « en cours d'examen », a hold that
     * lapsed) — see RenterDecision::forStatus(). Nothing is sent and
     * nothing is added to the flash.
     *
     * Never throws. A decision is already recorded by the time this runs,
     * and an SMTP timeout must not turn a confirmed booking into a red
     * error page suggesting it failed.
     */
    private function tellRenter(
        RentalBooking $booking,
        RentalAsset $asset,
        ?RenterDecision $decision,
        ?string $managerWord
    ): string {
        if ($decision === null || $this->mailService === null) {
            return '';
        }

        $sent = $this->mailService->sendDecision(
            $booking,
            $asset,
            $decision,
            $decision->carriesTheTrackingLink()
                ? $this->bookingRepository->trackingTokenOf($booking->id)
                : null,
            $managerWord
        );

        return $sent
            ? ' Le locataire a été prévenu par email.'
            : " L'email au locataire n'a pas pu partir : prévenez-le autrement.";
    }

    /**
     * The shared shape of every booking write: CSRF, authorisation, the
     * booking, the work, the redirect.
     *
     * Written once because every one of those steps is a place a missing
     * check would be invisible — an action that forgot the authorisation
     * would work perfectly for the person who wrote it.
     *
     * @param callable(RentalBooking, RentalAsset): void $work
     */
    private function bookingAction(Request $request, callable $work): Response
    {
        // The JSON twin for the asynchronous path: a 302 to /mes-locations
        // would reach the page's fetch as an HTML body it cannot read, and
        // the manager would be told "erreur réseau" for what is really an
        // expired session.
        $guard = self::wantsJson($request)
            ? $this->guardCsrfJson($request)
            : $this->guardCsrf($request, '/mes-locations');
        if ($guard !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $booking = $this->bookingOfAsset($asset, (int) $request->getBody('booking_id', 0));
        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        try {
            $work($booking, $asset);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        if (self::wantsJson($request)) {
            return $this->json(self::flashAsJson());
        }

        return $this->redirect($this->bookingUrl($asset, $booking));
    }

    /**
     * Whether this POST came from the booking page's own fetch rather than
     * from a browser submitting the form (public/assets/js/rental-booking.js
     * sets the header). Same signal as Modules\Groups\Controller\
     * ReplyController::wantsJson().
     *
     * Every button on that page posts through bookingAction(), which is why
     * this lives here and not in sixteen handlers: one branch turns the whole
     * page asynchronous, and each handler goes on doing exactly what it did —
     * work, then a flash — with no idea who is listening.
     */
    private static function wantsJson(Request $request): bool
    {
        return $request->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    /**
     * The flash the handler just set, as the JSON body the page reads.
     *
     * Consumed here rather than left in the session: nothing is going to
     * render it — the caller stays on the page it is already on — and a
     * flash left behind would surface on whatever page the manager opened
     * next, minutes later and out of context.
     *
     * A handler that set no flash at all answers success with no message,
     * and the page says nothing rather than inventing a confirmation.
     *
     * @return array{success: bool, type: string, message: ?string}
     */
    private static function flashAsJson(): array
    {
        $flash = FlashMessage::get();
        $type = (string) ($flash['type'] ?? 'success');

        return [
            'success' => $type !== 'error',
            'type' => $type,
            'message' => isset($flash['message']) ? (string) $flash['message'] : null,
        ];
    }

    /**
     * Same shape as `bookingAction()`, but back to the stay page.
     *
     * A manager recording eight meter readings should land where they were,
     * not on the booking's file eight times.
     *
     * @param callable(RentalBooking, RentalAsset): void $work
     */
    private function stayAction(Request $request, callable $work): Response
    {
        if (($guard = $this->guardCsrf($request, '/mes-locations')) !== null) {
            return $guard;
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null || $this->stayService === null) {
            return new Response('Not Found', 404);
        }

        $booking = $this->bookingOfAsset($asset, (int) $request->getBody('booking_id', 0));
        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        try {
            $work($booking, $asset);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect(
            $this->bookingUrl($asset, $booking) . '/sejour'
        );
    }

    /**
     * An integer a form actually carried, or null for a field left empty.
     *
     * `(int) ''` is `0`, and zero participants is a real answer — a group
     * that cancelled on the day — so an empty field has to stay
     * distinguishable from a typed zero.
     */
    private static function optionalInt(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return (int) trim($value);
    }

    /**
     * The asset named in the URL, or null — **404, never 403**, for one the
     * visitor may not manage (§6.6).
     *
     * @param array<string, string> $params
     */
    private function manageableAsset(array $params): ?RentalAsset
    {
        $asset = $this->assetRepository->findBySlug((string) ($params['slug'] ?? ''));

        return $asset !== null && $this->mayManage($asset) ? $asset : null;
    }

    private function manageableAssetById(int $assetId): ?RentalAsset
    {
        $asset = $assetId > 0 ? $this->assetRepository->findById($assetId) : null;

        return $asset !== null && $this->mayManage($asset) ? $asset : null;
    }

    private function mayManage(RentalAsset $asset): bool
    {
        return $this->authorizationService->canManageAsset(
            AuthSession::getEmail(),
            $this->scoutYearId(),
            $asset
        );
    }

    /**
     * A booking, but only if it belongs to $asset.
     *
     * The asset check is the one that matters: bookings are numbered across
     * the whole installation, so without it a manager of one asset could
     * read every booking of every other by walking the ids.
     */
    private function bookingOfAsset(RentalAsset $asset, int $bookingId): ?RentalBooking
    {
        $booking = $bookingId > 0 ? $this->bookingRepository->findById($bookingId) : null;

        return $booking !== null && $booking->assetId === $asset->id ? $booking : null;
    }

    /**
     * The acting member's id, for the history's "who did this".
     *
     * Null for a unit chief with no member row of their own in this year —
     * the action still happens, it is simply recorded without an author,
     * which is honest rather than inventing one.
     */
    /**
     * The management URL of one booking. Assembled here rather than at each
     * redirect, so the four or five places that end on this page cannot
     * drift apart.
     */
    private function bookingUrl(RentalAsset $asset, RentalBooking $booking): string
    {
        return '/mes-locations/' . $asset->slug . '/reservations/' . $booking->id;
    }

    /**
     * The fil d'Ariane for a page inside one asset's managed space.
     *
     * The managed space is several levels deep — an asset, its bookings,
     * one booking, that booking's stay or one of its documents — and the
     * menu has ONE entry for the whole of it, so none of those ancestors
     * is reachable from the nav. That is exactly what
     * partials/breadcrumb_bar.html.twig's `breadcrumb_trail` is for: real
     * links to real ancestor PAGES, resolved by the controller that knows
     * the asset's slug and the booking's id.
     *
     * These three replace a hand-rolled "← Réservations" link that used to
     * sit above the title of every page in the module — a pattern nothing
     * else on the site used, which is what made the module feel foreign.
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function assetTrail(): array
    {
        return [['label' => 'Mes locations', 'url' => '/mes-locations']];
    }

    /**
     * One level deeper: a page belonging to an asset (its calendar, its
     * bookings, its templates, its compliance register).
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function assetSubPageTrail(RentalAsset $asset): array
    {
        return array_merge($this->assetTrail(), [
            ['label' => $asset->name, 'url' => '/mes-locations/' . $asset->slug],
        ]);
    }

    /**
     * Deeper still: one booking, and — with $booking given — a page
     * belonging to that booking (its stay, one of its documents).
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function bookingTrail(RentalAsset $asset, ?RentalBooking $booking = null): array
    {
        $trail = array_merge($this->assetSubPageTrail($asset), [
            [
                'label' => 'Réservations',
                'url' => '/mes-locations/' . $asset->slug . '/reservations',
            ],
        ]);

        if ($booking !== null) {
            $trail[] = [
                'label' => $booking->reference,
                'url' => '/mes-locations/' . $asset->slug . '/reservations/' . $booking->id,
            ];
        }

        return $trail;
    }

    private function actorMemberId(): ?int
    {
        $email = AuthSession::getEmail();
        if ($email === null || $email === '') {
            return null;
        }

        $members = $this->memberService->getLinkedMembers($email, $this->scoutYearId());

        return $members === [] ? null : $members[0]->memberId;
    }

    private function scoutYearId(): int
    {
        return (int) $this->scoutYearService->getCurrentYear()['id'];
    }

    /**
     * Attaches a display name to rows carrying a member id.
     *
     * Resolved here rather than in a template, and in one batch rather than
     * per row: a booking with forty history entries must not mean forty
     * member lookups.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorateWithAuthors(array $rows, string $key = 'author_member_id'): array
    {
        $memberIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row) => isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null,
            $rows
        ))));

        $names = $memberIds === []
            ? []
            : $this->memberService->findDisplayNamesByMemberIds($memberIds, $this->scoutYearId());

        foreach ($rows as $index => $row) {
            $memberId = isset($row[$key]) && is_int($row[$key]) ? $row[$key] : null;
            $rows[$index]['author_name'] = $memberId !== null ? ($names[$memberId] ?? null) : null;
        }

        return $rows;
    }

    /**
     * @param RentalAsset[] $assets
     * @return array<int, RentalAsset>
     */
    private function indexById(array $assets): array
    {
        $indexed = [];
        foreach ($assets as $asset) {
            $indexed[$asset->id] = $asset;
        }

        return $indexed;
    }
}
