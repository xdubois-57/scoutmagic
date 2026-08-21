<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\MonthGrid\DayState;
use Core\View\MonthGrid\DayStateGridBuilder;
use Modules\Rental\Availability\MonthWindow;
use Modules\Rental\Booking\BookingMilestones;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\BookingTransition;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingEventRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
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
 * **Structural configuration is deliberately not here.** What an asset is,
 * who manages it, its rules and its tariff live in `Espace chefs d'U >
 * Locations` at `admin`. What happens to a booking lives here, reachable by
 * the people who actually do it.
 */
class RentalManagementController extends AbstractController
{
    /** How far the private calendar pages, in months, either way. */
    private const MONTHS_BACK = 24;
    private const MONTHS_AHEAD = 36;

    public function __construct(
        Environment $twig,
        private RentalAuthorizationService $authorizationService,
        private ScoutYearService $scoutYearService,
        private RentalAssetRepository $assetRepository,
        private RentalBookingRepository $bookingRepository,
        private RentalBookingEventRepository $eventRepository,
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
        private ?RentalPaymentService $paymentService = null
    ) {
        parent::__construct($twig);
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

        // Someone who manages nothing gets a plain 403 rather than an empty
        // page: the menu entry is not shown to them at all, so reaching this
        // URL means the id came from somewhere other than the interface.
        if ($assets === []) {
            return new Response('Forbidden', 403);
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
            'breadcrumb_current' => $asset->name,
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
        $status = BookingStatus::tryFrom($filter);
        $bookings = $this->bookingRepository->findAllForAssets([$asset->id], $status);

        // "À traiter" is not a status but the union of three, so it is a
        // filter of its own rather than something the enum has to pretend
        // to model.
        if ($filter === 'a_traiter') {
            $bookings = array_values(array_filter(
                $this->bookingRepository->findAllForAssets([$asset->id]),
                static fn(RentalBooking $b) => $b->status->needsAttention()
            ));
        }

        return $this->render('@rental/management/bookings.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Réservations',
            'bookings' => $bookings,
            'filter' => $filter,
            'statuses' => BookingStatus::cases(),
            'nav_page' => 'bookings',
        ]);
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

        return $this->render('@rental/management/booking.html.twig', [
            'asset' => $asset,
            'booking' => $booking,
            'breadcrumb_current' => $booking->reference,
            'milestones' => BookingMilestones::for($booking, $now),
            'allowed_transitions' => BookingTransition::allowedFrom($booking->status),
            'can_confirm' => BookingTransition::isAllowed($booking->status, BookingStatus::CONFIRMED),
            'quote' => $this->operationsService->workingQuote($booking, $asset),
            'price_is_agreed' => $booking->priceHasBeenAgreed(),
            'comments' => $this->decorateWithAuthors($this->commentRepository->findForBooking($booking->id)),
            'history' => $this->decorateWithAuthors(
                $this->eventRepository->findForBooking($booking->id),
                'actor_member_id'
            ),
            'change_requests' => $this->changeRequestRepository->findForBooking($booking->id),
            'is_in_progress' => $booking->isInProgress($now),
            'payment' => $this->paymentStatus($booking, $asset),
            'csrf_token' => CsrfGuard::generateToken(),
            'nav_page' => 'bookings',
        ]);
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

            $returnedAt = \DateTimeImmutable::createFromFormat(
                'Y-m-d',
                (string) $request->getBody('returned_at', '')
            ) ?: new \DateTimeImmutable('today');

            $this->paymentService->recordSecurityDepositReturn(
                $booking,
                RentalPricingService::parseAmountToCents((string) $request->getBody('returned', '')) ?? 0,
                self::optionalString($request->getBody('note')),
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
            $today
        );

        $from = $window->firstDay()->modify('-7 days');
        $to = $window->lastDay()->modify('+7 days');

        return $this->render('@rental/management/calendar.html.twig', [
            'asset' => $asset,
            'breadcrumb_current' => 'Calendrier',
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
            if ($target === BookingStatus::CONFIRMED) {
                $this->operationsService->confirm($booking, $asset, $this->actorMemberId(), $now);
                FlashMessage::set('success', 'Réservation confirmée.');

                return;
            }

            $this->operationsService->changeStatus($booking, $target, $this->actorMemberId(), $now);
            FlashMessage::set('success', 'Réservation « ' . $target->label() . ' ».');
        });
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

            $deadline = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $until);

            if ($deadline === false) {
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
                    self::optionalString($request->getBody('label')),
                    $request->getBody('quantity') !== null ? max(1, (int) $request->getBody('quantity')) : null,
                    self::optionalString($request->getBody('amount')) !== null
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
        return $this->bookingAction($request, function (RentalBooking $booking) use ($request): void {
            $kind = ChangeRequestKind::tryFrom((string) $request->getBody('kind', ''));
            if ($kind === null) {
                throw new RentalException("Ce type de proposition n'existe pas.");
            }

            $this->operationsService->requestChange(
                $booking,
                ChangeRequestOrigin::MANAGER,
                $kind,
                self::optionalString($request->getBody('arrival')),
                self::optionalString($request->getBody('departure')),
                $request->getBody('units') !== null ? max(1, (int) $request->getBody('units')) : null,
                $request->getBody('persons') !== null ? max(1, (int) $request->getBody('persons')) : null,
                null,
                self::optionalString($request->getBody('message')),
                $this->actorMemberId()
            );

            FlashMessage::set(
                'success',
                'Proposition envoyée. Elle ne change rien tant que le locataire ne l\'a pas acceptée.'
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

            if ((string) $request->getBody('decision', '') === 'accept') {
                $this->operationsService->acceptChange(
                    $changeRequest,
                    $booking,
                    $asset,
                    ChangeRequestOrigin::MANAGER,
                    $this->actorMemberId(),
                    new \DateTimeImmutable()
                );
                FlashMessage::set('success', 'Demande acceptée et appliquée.');

                return;
            }

            $this->operationsService->refuseChange($changeRequest, ChangeRequestOrigin::MANAGER, $this->actorMemberId());
            FlashMessage::set('success', 'Demande refusée. La réservation est inchangée.');
        });
    }

    /**
     * POST /mes-locations/blocage — a manual block (§6.18).
     *
     * @param array<string, string> $params
     */
    public function createBlock(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
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
                self::optionalString($request->getBody('reason')),
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
            FlashMessage::set('danger', $e->getMessage());
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
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
        }

        $asset = $this->manageableAssetById((int) $request->getBody('asset_id', 0));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        try {
            $this->blockService->delete($asset->id, (int) $request->getBody('block_id', 0));
            FlashMessage::set('success', 'Blocage supprimé.');
        } catch (RentalException $e) {
            FlashMessage::set('danger', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/calendrier');
    }

    // ── Internals ───────────────────────────────────────────────────────

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
        if (!CsrfGuard::validateRequest()) {
            return new Response('Forbidden', 403);
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
            FlashMessage::set('danger', $e->getMessage());
        }

        return $this->redirect('/mes-locations/' . $asset->slug . '/reservations/' . $booking->id);
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

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
