<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\View\EditableContentService;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Calendar\RenterFeedBuilder;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Support;
use Twig\Environment;

/**
 * The public request form and the renter's tracking page — the two `public`
 * surfaces where a non-member interacts with a booking.
 *
 * **The tracking page is protected by a capability token, not by a session.**
 * A renter has no account and never will (§6.26), so possession of the token
 * *is* the authorisation. That makes three rules absolute here: the token is
 * verified before anything is loaded, a wrong token and an unknown booking
 * are answered identically so the page cannot be used to probe which
 * references exist, and the token is never written to a log or a journal
 * entry (§13 of the conventions).
 *
 * **Never rendered on the tracking page** (§6.26): internal comments, notes
 * on a damage still being assessed, or any downloadable file. What the renter
 * does get is the full, live price breakdown — every line, every change,
 * immediately.
 */
class RentalRequestController extends AbstractController
{
    private const HUMAN_CHECK_FORM_KEY = 'rental_request';

    /** Content keys the public conditions live under, per asset. */
    private const CONDITIONS_SUFFIX = '_conditions';

    public function __construct(
        Environment $twig,
        private RentalAssetRepository $assetRepository,
        private RentalBookingService $bookingService,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private RentalBookingMailService $mailService,
        private RentalManagerService $managerService,
        private MemberService $memberService,
        private ScoutYearService $scoutYearService,
        private EditableContentService $editableContentService,
        private HumanCheckService $humanCheckService,
        private SettingService $settingService,
        private RentalOperationsService $operationsService,
        private RentalChangeRequestRepository $changeRequestRepository,
        /**
         * Optional (§6.32): null without the `calendar` module, in which
         * case the renter's page simply offers no ICS link. Only the ICS
         * generator is borrowed — no calendar row is ever involved.
         */
        private ?IcsBuilder $icsBuilder = null,
        private ?RenterFeedBuilder $renterFeedBuilder = null
    ) {
        parent::__construct($twig);
    }

    /**
     * GET /locations/suivi/{id}/{token}/calendrier.ics — the renter's own
     * feed (§6.32).
     *
     * **Nothing is stored and `FileAccessGuard` is not involved**: this is
     * not a file, it is a document generated from the booking on request.
     * The capability token is the same one that opens their page, verified
     * the same way, and it grants exactly this one booking.
     *
     * A cancelled booking produces a **cancelled event, not an error** — a
     * renter who subscribed needs the entry to disappear from their phone,
     * which only happens if the feed keeps saying it is off.
     *
     * @param array<string, string> $params
     */
    public function renterFeed(Request $request, array $params): Response
    {
        if ($this->icsBuilder === null || $this->renterFeedBuilder === null) {
            return new Response('Not Found', 404);
        }

        $token = (string) ($params['token'] ?? '');
        $booking = $this->bookingService->findByTrackingToken((int) ($params['id'] ?? 0), $token);
        if ($booking === null) {
            // The same answer as a wrong token anywhere else in this
            // controller: never an oracle for which references exist.
            return new Response('Not Found', 404);
        }

        $asset = $this->assetRepository->findById($booking->assetId);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
        $event = $this->renterFeedBuilder->build(
            $booking,
            $asset,
            $token,
            $this->managerService->listRenterContactsForAsset($asset->id, $scoutYearId)
        );

        return (new Response(
            $this->icsBuilder->build($this->renterFeedBuilder->calendarName($booking), [], [$event])
        ))
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="location.ics"');
    }

    /**
     * GET /locations/{slug}/demande — the request form (§6.13).
     *
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        $asset = $this->publicAsset((string) ($params['slug'] ?? ''));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        return $this->renderForm($asset, $request, []);
    }

    /**
     * POST /locations/{slug}/demande
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        $asset = $this->publicAsset((string) ($params['slug'] ?? ''));
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        if (($guard = $this->guardCsrf($request, '/locations/' . $asset->slug)) !== null) {
            return $guard;
        }

        // HumanCheck before anything else, and never for an identified
        // session (Core\Security\HumanCheck — an identified visitor skips all
        // three barriers unconditionally).
        $humanCheck = $this->humanCheckService->verify(
            self::HUMAN_CHECK_FORM_KEY,
            AuthSession::isAuthenticated(),
            $request->getBodyAll(),
            $request->getServer('REMOTE_ADDR')
        );

        if (!$humanCheck->accepted) {
            // One generic message whatever tripped — never which barrier —
            // and the visitor's input is re-rendered rather than lost.
            return $this->renderForm($asset, $request, [
                'Votre demande n\'a pas pu être envoyée. Merci de réessayer dans un instant.',
            ]);
        }

        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();
        $pricing = $this->pricingService->loadSettings($asset->id);

        $arrival = (string) $request->getBody('arrival', '');
        $departure = (string) $request->getBody('departure', '');
        $persons = max(0, (int) $request->getBody('persons', 0));
        $units = max(1, (int) $request->getBody('units', 1));
        $categoryId = (string) $request->getBody('category', '');

        if (!Support::isDate($arrival) || !Support::isDate($departure)) {
            return $this->renderForm($asset, $request, ['Les dates ne sont pas valides.']);
        }

        // Availability and the booking rules are re-checked SERVER-SIDE, on
        // the real current state — never trusted from the form. The calendar
        // the visitor saw may be minutes old, and another request may have
        // taken the dates since.
        $errors = $this->availabilityService->validateRange(
            $asset,
            $pricing->billingUnit,
            new \DateTimeImmutable($arrival),
            new \DateTimeImmutable($departure),
            $units,
            $today,
            $persons > 0 ? $persons : null
        );

        if (!$this->acceptedBox($request, 'accept_conditions')) {
            $errors[] = 'Vous devez accepter les conditions de location.';
        }

        if (!$this->acceptedBox($request, 'accept_privacy')) {
            $errors[] = 'Vous devez confirmer avoir pris connaissance de la politique de confidentialité.';
        }

        if ($errors !== []) {
            return $this->renderForm($asset, $request, $errors);
        }

        // With no rate configured at all, the quote adds up to 0,00 € — and
        // snapshotting that would freeze "Total 0,00 €" onto the booking,
        // where the tracking page shows it to the renter as if it were the
        // price agreed. The public estimate block already refuses to show
        // it (RentalPublicController's `has_tariff`); the same rule has to
        // hold at submission, or the unit spends the first email walking a
        // number back. Null means "no price yet", which is the truth.
        $quote = $pricing->hasAnyRate()
            ? $this->pricingService->quoteWithSettings($pricing, new PricingRequest(
                arrivalDate: $arrival,
                departureDate: $departure,
                persons: $persons,
                units: $units,
                rooms: 1,
                renterCategoryId: $categoryId !== '' ? (int) $categoryId : null
            ))
            : null;

        try {
            $result = $this->bookingService->createFromPublicRequest(
                $asset->id,
                $arrival,
                $departure,
                $units,
                $persons > 0 ? $persons : null,
                $categoryId !== '' ? (int) $categoryId : null,
                [
                    'name' => (string) $request->getBody('name', ''),
                    'email' => (string) $request->getBody('email', ''),
                    'phone' => Support::optionalString($request->getBody('phone')),
                    'organisation' => Support::optionalString($request->getBody('organisation')),
                    'purpose' => Support::optionalString($request->getBody('purpose')),
                    'comment' => Support::optionalString($request->getBody('comment')),
                ],
                $quote,
                [
                    'conditions_version' => $this->conditionsVersion($asset),
                    'conditions_text' => $this->conditionsText($asset),
                    'privacy_version' => $this->privacyVersion(),
                    'privacy_text' => $this->privacyText(),
                ],
                $now,
                $this->automaticHoldHours()
            );
        } catch (RentalException $e) {
            return $this->renderForm($asset, $request, [$e->getMessage()]);
        }

        $this->sendSubmissionEmails($result['booking'], $asset, $result['tracking_token']);

        // Straight to the tracking page rather than a "thank you" dead end:
        // the renter lands on the thing the email also links to, so the link
        // is already familiar when it arrives.
        return $this->redirect(
            '/locations/suivi/' . $result['booking']->id . '/' . $result['tracking_token']
        );
    }

    /**
     * GET /locations/suivi/{id}/{token} — the renter's tracking page (§6.26).
     *
     * @param array<string, string> $params
     */
    public function tracking(Request $request, array $params): Response
    {
        $booking = $this->bookingService->findByTrackingToken(
            (int) ($params['id'] ?? 0),
            (string) ($params['token'] ?? '')
        );

        // One answer for "no such booking" and "wrong token" alike: anything
        // else turns this page into an oracle for which references exist.
        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        $asset = $this->assetRepository->findById($booking->assetId);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];

        return $this->render('@rental/public/tracking.html.twig', [
            'booking' => $booking,
            'asset' => $asset,
            // The price actually in force: the agreed one once it exists,
            // the estimate until then. `effectivePrice()` is the single
            // source both sides read (Booking\RentalBooking) — reading
            // `estimatedPrice` here left this page showing the frozen
            // estimate for ever while the manager's panel showed the
            // negotiated total, and the manager was being told "le locataire
            // le voit immédiatement sur sa page de suivi".
            'quote' => $booking->effectivePrice(),
            // Only the managers explicitly flagged as renter contacts, and
            // the filter is in SQL rather than in the template: a template
            // that forgot the condition would hand every manager's details
            // to an external renter (§6.3, §6.6).
            'renter_contacts' => $this->managerService->listRenterContactsForAsset($asset->id, $scoutYearId),
            // Shown only here, never publicly (§6.6).
            'emergency_phone' => $asset->emergencyPhone,
            // Change requests and the manager's proposals (§6.16). Internal
            // comments are deliberately absent — the tracking page does not
            // even load them, which is a stronger guarantee than a template
            // remembering to hide them.
            'change_requests' => $this->changeRequestRepository->findForBooking($booking->id),
            // Echoed back so this page's own forms post to a URL that still
            // carries the capability. It is already in the address bar; it
            // is never journaled and never leaves this page.
            'tracking_token' => (string) ($params['token'] ?? ''),
            // Offered only when the calendar module is there to render it
            // (§6.32); its absence simply hides the link.
            'ics_available' => $this->icsBuilder !== null && $this->renterFeedBuilder !== null,
            'csrf_token' => CsrfGuard::generateToken(),
            'breadcrumb_current' => $booking->reference,
        ]);
    }

    /**
     * POST /locations/suivi/{id}/{token}/demande — the renter asks for a
     * change or an outright cancellation (§6.16, §6.17).
     *
     * Records the request and **changes nothing**: that is the rule the spec
     * is emphatic about. A manager decides, from the booking's own page.
     *
     * The token is verified exactly as on the GET, because a POST is not
     * less of an entry point than a GET.
     *
     * @param array<string, string> $params
     */
    public function requestChange(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? ''))) !== null) {
            return $guard;
        }

        $booking = $this->bookingService->findByTrackingToken(
            (int) ($params['id'] ?? 0),
            (string) ($params['token'] ?? '')
        );

        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        $kind = ChangeRequestKind::tryFrom((string) $request->getBody('kind', ''));
        if ($kind === null) {
            FlashMessage::set('error', "Ce type de demande n'existe pas.");

            return $this->backToTracking($params);
        }

        try {
            $this->operationsService->requestChange(
                $booking,
                ChangeRequestOrigin::RENTER,
                $kind,
                Support::optionalString($request->getBody('arrival')),
                Support::optionalString($request->getBody('departure')),
                null,
                $request->getBody('persons') !== null && (int) $request->getBody('persons') > 0
                    ? (int) $request->getBody('persons')
                    : null,
                null,
                Support::optionalString($request->getBody('message'))
            );

            FlashMessage::set(
                'success',
                'Votre demande a bien été transmise. Elle ne change rien à votre réservation '
                . "tant qu'un gestionnaire ne l'a pas acceptée."
            );
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->backToTracking($params);
    }

    /**
     * POST /locations/suivi/{id}/{token}/reponse — the renter accepts or
     * refuses a manager's proposal (§6.16).
     *
     * @param array<string, string> $params
     */
    public function decideProposal(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? ''))) !== null) {
            return $guard;
        }

        $booking = $this->bookingService->findByTrackingToken(
            (int) ($params['id'] ?? 0),
            (string) ($params['token'] ?? '')
        );

        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        $asset = $this->assetRepository->findById($booking->assetId);
        if ($asset === null) {
            return new Response('Not Found', 404);
        }

        $changeRequest = $this->changeRequestRepository->findById((int) $request->getBody('request_id', 0));
        // The booking check is the guard that matters: a change-request id
        // alone must not let one renter decide another's proposal.
        if ($changeRequest === null || $changeRequest->bookingId !== $booking->id) {
            return new Response('Not Found', 404);
        }

        try {
            if ((string) $request->getBody('decision', '') === 'accept') {
                $this->operationsService->acceptChange(
                    $changeRequest,
                    $booking,
                    $asset,
                    ChangeRequestOrigin::RENTER,
                    null,
                    new \DateTimeImmutable()
                );
                FlashMessage::set('success', 'Proposition acceptée. Votre réservation a été mise à jour.');
            } else {
                $this->operationsService->refuseChange($changeRequest, ChangeRequestOrigin::RENTER, null);
                FlashMessage::set('success', 'Proposition refusée. Votre réservation est inchangée.');
            }
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->backToTracking($params);
    }

    /**
     * Back to the renter's own page, token included — it is the only way
     * back in, so a redirect that dropped it would lock them out of their
     * own booking.
     *
     * @param array<string, string> $params
     */
    private function backToTracking(array $params): Response
    {
        return $this->redirect(
            '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? '')
        );
    }

    /**
     * @param string[] $errors
     */
    private function renderForm(RentalAsset $asset, Request $request, array $errors): Response
    {
        $pricing = $this->pricingService->loadSettings($asset->id);

        return $this->render('@rental/public/request.html.twig', [
            'asset' => $asset,
            // The generic fil d'Ariane, the way every other module does it:
            // the index, then the asset the visitor came from, then this
            // form. No hand-rolled back link above the title.
            'breadcrumb_trail' => [
                ['label' => 'Locations', 'url' => '/locations'],
                ['label' => $asset->name, 'url' => '/locations/' . $asset->slug],
            ],
            'categories' => $pricing->categories,
            'errors' => $errors,
            'conditions_html' => $this->conditionsText($asset),
            // Null for an identified session — the partial then renders
            // nothing, which is the documented contract.
            'human_check' => AuthSession::isAuthenticated()
                ? null
                : $this->humanCheckService->generateChallenge(self::HUMAN_CHECK_FORM_KEY),
            'csrf_token' => CsrfGuard::generateToken(),
            // Everything the visitor typed, so a rejection never costs them
            // the form.
            'submitted' => [
                'arrival' => (string) ($request->getBody('arrival') ?? $request->getQuery('arrival', '')),
                'departure' => (string) ($request->getBody('departure') ?? $request->getQuery('departure', '')),
                'persons' => (string) ($request->getBody('persons') ?? $request->getQuery('persons', '')),
                'units' => (string) ($request->getBody('units') ?? $request->getQuery('units', '')),
                'category' => (string) ($request->getBody('category') ?? $request->getQuery('category', '')),
                'name' => (string) ($request->getBody('name') ?? ''),
                'email' => (string) ($request->getBody('email') ?? ''),
                'phone' => (string) ($request->getBody('phone') ?? ''),
                'organisation' => (string) ($request->getBody('organisation') ?? ''),
                'purpose' => (string) ($request->getBody('purpose') ?? ''),
                'comment' => (string) ($request->getBody('comment') ?? ''),
            ],
        ]);
    }

    /**
     * The two emails a submission always sends (§6.28).
     *
     * A delivery failure must not lose the booking that is already
     * committed, so each send is guarded independently: the renter's
     * acknowledgement failing is serious (it carries their only way back)
     * but is still not a reason to throw away a stored request.
     */
    private function sendSubmissionEmails(RentalBooking $booking, RentalAsset $asset, string $trackingToken): void
    {
        try {
            $this->mailService->sendAcknowledgement($booking, $asset, $trackingToken);
        } catch (\Throwable) {
            FlashMessage::set(
                'warning',
                "Votre demande est bien enregistrée, mais l'email de confirmation n'a pas pu partir. "
                . 'Conservez le lien de cette page.'
            );
        }

        try {
            $scoutYearId = (int) $this->scoutYearService->getCurrentYear()['id'];
            $recipients = $this->managerEmails($asset->id, $scoutYearId);
            if ($recipients !== []) {
                $this->mailService->sendManagerNotification($booking, $asset, $recipients);
            }
        } catch (\Throwable) {
            // The managers' page still shows the request; a failed
            // notification must not surface to the renter as their problem.
        }
    }

    /**
     * Every manager's address for an asset — the unit staff included, since
     * they are implicit managers of every asset (§6.3).
     *
     * @return string[]
     */
    private function managerEmails(int $assetId, int $scoutYearId): array
    {
        $memberIds = array_map(
            static fn(array $row): int => $row['manager']->memberId,
            $this->managerService->listManagersForAsset($assetId, $scoutYearId)
        );

        // One query for everybody, not one profile per manager: a profile
        // lookup costs several queries each — for addresses, functions and
        // badges nothing here reads — and this runs on the public request
        // form, where a unit staff of fifteen turned one visitor's
        // submission into dozens of queries.
        return array_values(array_unique($this->memberService->findEmailsByMemberIds($memberIds, $scoutYearId)));
    }

    /**
     * How long the automatic hold lasts (§6.14), configurable per
     * installation. Clamped to something sane: a zero or negative value
     * would mean "no hold at all", which is a legitimate choice but must be
     * made by clearing the setting, not by typing a nonsense number.
     */
    private function automaticHoldHours(): int
    {
        $configured = $this->settingService->get('automatic_hold_hours', 'rental');

        if ($configured === null || $configured === '') {
            return RentalBookingService::DEFAULT_AUTOMATIC_HOLD_HOURS;
        }

        return max(0, (int) $configured);
    }

    private function publicAsset(string $slug): ?RentalAsset
    {
        $asset = $this->assetRepository->findBySlug($slug);

        return $asset !== null && $asset->isPubliclyVisible() ? $asset : null;
    }

    private function acceptedBox(Request $request, string $field): bool
    {
        return $request->getBody($field) !== null;
    }

    /**
     * The conditions text a renter is accepting, per asset — the same
     * `editable()` content the public page shows, so what they tick is
     * literally what they read (§6.9, §6.13).
     */
    private function conditionsText(RentalAsset $asset): string
    {
        return (string) ($this->editableContentService->get(
            'rental_asset_' . $asset->id . self::CONDITIONS_SUFFIX,
            ''
        ) ?? '');
    }

    /**
     * A version derived from the text itself.
     *
     * Deliberately not a number somebody has to remember to bump: a version
     * that only moves when a human remembers is a version that is wrong
     * exactly when it matters. The stored hash proves what was accepted; this
     * gives it a short, readable label.
     */
    private function conditionsVersion(RentalAsset $asset): string
    {
        return substr(RentalBookingService::hashAcceptedText($this->conditionsText($asset)), 0, 12);
    }

    private function privacyText(): string
    {
        return (string) ($this->editableContentService->get('rgpd_content', '') ?? '');
    }

    private function privacyVersion(): string
    {
        return substr(RentalBookingService::hashAcceptedText($this->privacyText()), 0, 12);
    }
}
