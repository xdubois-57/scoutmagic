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
use Core\Notification\NotificationService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Service\DateInput;
use Core\View\EditableContentService;
use Modules\Calendar\Api\IcsFeedBuilderInterface;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Calendar\RenterFeedBuilder;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\ManagerRecipientResolver;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalConditionsService;
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

    /** The notification a new request raises (#708, IT-05). */
    public const NEW_REQUEST_NOTIFICATION = 'rental.new_request';

    public function __construct(
        Environment $twig,
        private RentalAssetRepository $assetRepository,
        private RentalBookingService $bookingService,
        private RentalAvailabilityService $availabilityService,
        private RentalPricingService $pricingService,
        private RentalBookingMailService $mailService,
        private RentalManagerService $managerService,
        private ScoutYearService $scoutYearService,
        private EditableContentService $editableContentService,
        private HumanCheckService $humanCheckService,
        private SettingService $settingService,
        private RentalOperationsService $operationsService,
        private RentalChangeRequestRepository $changeRequestRepository,
        /**
         * The conditions as versions (issue #494): the form shows the one
         * in force and carries its version, and a submission is accepted
         * only against that same version.
         */
        private RentalConditionsService $conditionsService,
        /**
         * Optional (§6.32): null without the `calendar` module, in which
         * case the renter's page simply offers no ICS link. Only the ICS
         * generator is borrowed — no calendar row is ever involved.
         */
        /**
         * The calendar's PUBLISHED feed-formatting Api (ARCHITECTURE.md
         * §7.5) — null with the module disabled, and the tracking page
         * simply offers no ICS link.
         */
        private ?IcsFeedBuilderInterface $icsBuilder = null,
        private ?RenterFeedBuilder $renterFeedBuilder = null,
        /**
         * « Nouvelle demande de location » (#708, IT-05): a notification,
         * not a direct email, to the people the shared rule names. Null
         * leaves the managers to find the request on their pages.
         */
        private ?NotificationService $notificationService = null,
        private ?ManagerRecipientResolver $recipientResolver = null,
        /**
         * The contract's two signatures (#708, IT-16): the renter sends
         * their signed copy from this page and downloads the contract
         * signed by both parties — the one file this page ever serves.
         * Null offers neither.
         */
        private ?\Modules\Rental\Service\RentalSignedContractService $signedContractService = null,
        private ?\Modules\Rental\Service\RentalDocumentService $documentService = null,
        private ?\Core\File\UploadHandler $uploadHandler = null
    ) {
        parent::__construct($twig);
    }

    /**
     * POST /locations/suivi/{id}/{token}/contrat — the renter sends their
     * signed copy of the contract: a PDF, a scan or a photo (#708, IT-16).
     *
     * The token is verified exactly as on the GET. The file goes through
     * `UploadHandler` like any manager's upload — real MIME check, a name
     * nobody chose, EXIF stripped from a photo, stored outside `public/`
     * and reachable only by the asset's managers.
     *
     * @param array<string, string> $params
     */
    public function uploadSignedCopy(Request $request, array $params): Response
    {
        $trackingUrl = '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? '');
        if (($guard = $this->guardCsrf($request, $trackingUrl)) !== null) {
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
        if ($asset === null || $this->signedContractService === null || $this->uploadHandler === null) {
            return new Response('Not Found', 404);
        }

        try {
            // Said before the file is even stored: a copy nobody expects
            // must not land on disk first.
            $refusal = $this->signedContractService->whyNoCopy($booking);
            if ($refusal !== null) {
                throw new \Modules\Rental\Service\RentalException($refusal);
            }

            $uploaded = $request->getFile('signed_copy');
            if ($uploaded === null || (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \Modules\Rental\Service\RentalException('Choisissez le fichier de votre copie signée.');
            }

            try {
                $fileId = $this->uploadHandler->handle(
                    $uploaded,
                    \Modules\Rental\Service\RentalDocumentService::STORAGE_SUBDIRECTORY,
                    \Modules\Rental\Service\RentalSignedContractService::COPY_MIMES,
                    \Modules\Rental\Service\RentalSignedContractService::MAX_COPY_BYTES,
                    \Modules\Rental\Service\RentalDocumentService::FILE_ROLE_MIN,
                    'rental',
                    null,
                    \Modules\Rental\Service\RentalDocumentService::OWNER_TYPE,
                    $booking->id
                );
            } catch (\Core\File\UploadException $e) {
                // French and meant for the visitor (« Le fichier dépasse la
                // taille maximale autorisée »): the one thing that says
                // what to do.
                throw new \Modules\Rental\Service\RentalException($e->getMessage(), 0, $e);
            }

            $this->signedContractService->receiveCopy($booking, $asset, $fileId);
            FlashMessage::set(
                'success',
                'Votre copie signée est bien reçue. Nous la vérifions et vous renvoyons le contrat signé '
                    . 'par les deux parties.'
            );
        } catch (\Modules\Rental\Service\RentalException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($trackingUrl . '#contrat');
    }

    /**
     * GET /locations/suivi/{id}/{token}/contrat-signe.pdf — the contract
     * signed by both parties, and nothing else (#708, IT-16).
     *
     * **The one file a tracking token ever opens.** It is a capability for
     * this booking's page, not a file credential: this route serves the
     * countersigned contract of THIS booking, after the same check as the
     * page, and there is no id of a document in its address to change. A
     * wrong token, an unknown booking and a contract not countersigned yet
     * get the same answer.
     *
     * @param array<string, string> $params
     */
    public function downloadSignedContract(Request $request, array $params): Response
    {
        $booking = $this->bookingService->findByTrackingToken(
            (int) ($params['id'] ?? 0),
            (string) ($params['token'] ?? '')
        );
        $final = $booking !== null ? $this->signedContractService?->finalContract($booking->id) : null;
        $path = $final !== null ? $this->documentService?->absolutePath($final) : null;
        if ($booking === null || $path === null) {
            return new Response('Not Found', 404);
        }

        return (new Response((string) file_get_contents($path)))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="contrat-signe-' . $booking->reference . '.pdf"'
            )
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
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
            $this->icsBuilder->buildVirtualCalendar($this->renterFeedBuilder->calendarName($booking), [$event])
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
            DateInput::requireFromStorage($arrival, 'the submitted arrival date'),
            DateInput::requireFromStorage($departure, 'the submitted departure date'),
            $units,
            $today,
            $persons > 0 ? $persons : null
        );

        if (!$this->acceptedBox($request, 'accept_conditions')) {
            $errors[] = 'Vous devez accepter les conditions de location.';
        }

        // The version the visitor was SHOWN, not the one in force now: a
        // manager may have saved new conditions while the form was open, and
        // ticking the box then accepted a text nobody displayed. The box
        // proves acceptance of one version only, so a different one is a
        // refusal — with the input kept, and the form now showing the new
        // text to read (issue #494).
        $conditions = $this->conditionsService->current($asset->id);
        if ((string) $request->getBody('conditions_version', '') !== $conditions->version) {
            $errors[] = 'Les conditions de location ont été modifiées pendant que vous remplissiez ce formulaire. '
                . 'Relisez-les avant d\'envoyer votre demande.';
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
            ? $this->pricingService->quoteWithSettings(
                $pricing,
                new PricingRequest(
                    arrivalDate: $arrival,
                    departureDate: $departure,
                    persons: $persons,
                    units: $units,
                    rooms: 1,
                    renterCategoryId: $categoryId !== '' ? (int) $categoryId : null
                )
            )
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
                    // Phone and purpose are REQUIRED as of §22.5, so they
                    // are handed over raw and the service refuses an empty
                    // one — the same door that already refuses a missing
                    // name or a malformed address. The organisation stays
                    // optional: a family letting the hall for a communion
                    // has none, and demanding one makes them invent an
                    // answer.
                    'phone' => (string) $request->getBody('phone', ''),
                    'organisation' => Support::optionalString($request->getBody('organisation')),
                    'purpose' => (string) $request->getBody('purpose', ''),
                    'comment' => Support::optionalString($request->getBody('comment')),
                ],
                $quote,
                [
                    // The archived version itself: its text is what the hash
                    // is taken from, and it stays readable at its address.
                    'conditions_version' => $conditions->version,
                    'conditions_text' => $conditions->html,
                    'privacy_version' => $this->privacyVersion(),
                    'privacy_text' => $this->privacyText(),
                ],
                $now,
                $this->automaticHoldDays()
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
            // The renter's own billing coordinates (§22.6), decrypted for
            // the one person entitled to them — a token for THIS booking
            // reaches this page and nothing else. The block presents itself
            // as a task while every field is empty.
            'billing' => $this->operationsService->billingIdentity($booking->id),
            // Echoed back so this page's own forms post to a URL that still
            // carries the capability. It is already in the address bar; it
            // is never journaled and never leaves this page.
            'tracking_token' => (string) ($params['token'] ?? ''),
            // Offered only when the calendar module is there to render it
            // (§6.32); its absence simply hides the link.
            'ics_available' => $this->icsBuilder !== null && $this->renterFeedBuilder !== null,
            'csrf_token' => CsrfGuard::generateToken(),
            'breadcrumb_current' => $booking->reference,
            // The contract to sign and send back, and the one signed by
            // both parties to download (#708, IT-16).
            'contract' => $this->signedContractService !== null ? [
                'sent' => $this->signedContractService->sentContract($booking->id),
                'accepts_copy' => $this->signedContractService->acceptsCopy($booking),
                'pending' => $this->signedContractService->pendingCopy($booking->id),
                'refused' => $this->signedContractService->lastRefusedCopy($booking->id),
                'final' => $this->signedContractService->finalContract($booking->id),
            ] : null,
        // **Not kept by anything between here and the renter.** This page
        // carries their name, their dates and now their billing
        // coordinates, behind a capability in the URL rather than a
        // session — so a shared computer, a browser's back button or an
        // intermediate cache is exactly where a copy would outlive the
        // link. `no-store` and not `no-cache`, and the same header
        // `SectionRosterController` and `MemberContactController` already
        // set for the same reason.
        ])->setHeader('Cache-Control', 'private, no-store');
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
        if (($guard = $this->guardCsrf(
            $request,
            '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? '')
        )) !== null) {
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

        // Cancelling is its own button, and it says so in the body rather
        // than by being one option of a menu: « Annuler ma demande » sat
        // between « Changer les dates » and « Changer le nombre de
        // participants » in one `<select>`, which is the surest way to
        // cancel a stay by mistake. The word that travels with it comes
        // from the confirmation dialog (`data-confirm-note`), and it is
        // optional — a renter who has decided must not be held up by a
        // text field.
        if ($request->getBody('action') === 'cancel') {
            return $this->recordChange(
                $booking,
                $asset,
                ChangeRequestKind::CANCELLATION,
                null,
                null,
                null,
                Support::optionalString($request->getBody('message')),
                $params
            );
        }

        $arrival = Support::optionalString($request->getBody('arrival'));
        $departure = Support::optionalString($request->getBody('departure'));
        $persons = $request->getBody('persons') !== null && (int) $request->getBody('persons') > 0
            ? (int) $request->getBody('persons')
            : null;

        // **The type is derived, never chosen.** The form is the renter's
        // own booking, pre-filled; what they changed is what they are
        // asking for. `rental_change_requests` has always carried the dates
        // and the head count on one row — only `kind` forbade combining
        // them, which turned "other dates AND a smaller group" into two
        // requests a manager had to answer separately, each of them valid
        // only if the other was accepted too.
        $kind = ChangeRequestKind::forChange(
            ($arrival !== null && $arrival !== $booking->arrivalDate)
                || ($departure !== null && $departure !== $booking->departureDate),
            $persons !== null && $persons !== $booking->estimatedPersons
        );

        if ($kind === null) {
            FlashMessage::set(
                'error',
                "Votre demande ne change rien à votre réservation. Modifiez les dates ou le nombre "
                . "de participants avant de l'envoyer."
            );

            return $this->backToTracking($params);
        }

        // Mandatory on this form, and only on this one. A manager reading
        // « du 12/07 au 14/07 » with nothing beside it cannot tell a firm
        // request from a question, and answers the wrong one.
        $message = Support::optionalString($request->getBody('message'));
        if ($message === null) {
            FlashMessage::set('error', 'Expliquez votre demande en quelques mots avant de l\'envoyer.');

            return $this->backToTracking($params);
        }

        return $this->recordChange(
            $booking,
            $asset,
            $kind,
            // The dates always travel together once either moved: a
            // request carrying only the new arrival would be accepted
            // against the old departure.
            $kind->affectsAvailability() ? ($arrival ?? $booking->arrivalDate) : null,
            $kind->affectsAvailability() ? ($departure ?? $booking->departureDate) : null,
            // **Gated the same way, and for a sharper reason.** The form
            // pre-fills the head count with the booking's own, so a
            // dates-only request carries it silently — and `acceptChange()`
            // writes `proposedPersons ?? current` back through `setStay()`.
            // A request made before a head-count change was accepted would
            // therefore revert it, weeks later, with nothing on the manager's
            // screen to warn them: `summary()` renders a DATES request as
            // dates alone. It also put every dates-only request through the
            // capacity check, so a booking already over the asset's capacity
            // could no longer move its dates at all.
            $kind->changesPersons() ? $persons : null,
            $message,
            $params
        );
    }

    /**
     * POST /locations/suivi/{id}/{token}/facturation — the renter's own
     * billing coordinates (§22.6).
     *
     * **Asked here rather than on the public request form**, and the
     * chantier is explicit about why: a visitor enquiring about a weekend
     * has no reason to type a VAT number, and most never need one at all.
     * The block only becomes a task once there is a booking to invoice.
     *
     * Written to the same encrypted columns a manager fills in by hand, by
     * the same repository method — one write path, and a manager keeps the
     * right to correct what a renter typed.
     *
     * **No email chases this.** The confirmation already carries the
     * tracking link and a call to action (`Booking\RenterDecision`); a
     * dedicated message, and any reminder about it, is written out of the
     * chantier.
     *
     * @param array<string, string> $params
     */
    public function saveBillingIdentity(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf(
            $request,
            '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? '')
        )) !== null) {
            return $guard;
        }

        $booking = $this->bookingService->findByTrackingToken(
            (int) ($params['id'] ?? 0),
            (string) ($params['token'] ?? '')
        );

        if ($booking === null) {
            return new Response('Not Found', 404);
        }

        // **Nothing is invoiced for a letting that never happened.** The
        // page hides this block on a refused, cancelled or expired booking,
        // and a hidden form is not a rule: the token reaches this route
        // whatever the page rendered.
        //
        // `isAbandoned()` and not `isFinal()`, deliberately — a CLOSED
        // booking is final and is exactly the one being invoiced, which is
        // why the template shows the block there too. And the guard sits
        // here rather than in the service because it is about who is
        // writing: a manager keeps the right to correct the coordinates on
        // any file, including one that went nowhere.
        if ($booking->status->isAbandoned()) {
            FlashMessage::set(
                'error',
                'Cette réservation n\'a pas eu lieu : ses coordonnées de facturation ne se modifient plus.'
            );

            return $this->backToTracking($params);
        }

        try {
            $this->operationsService->saveBillingIdentity($booking->id, [
                'name' => Support::optionalString($request->getBody('billing_name')),
                'address' => Support::optionalString($request->getBody('billing_address')),
                'country' => Support::optionalString($request->getBody('billing_country')),
                'vat_number' => Support::optionalString($request->getBody('billing_vat_number')),
                'enterprise_number' => Support::optionalString($request->getBody('billing_enterprise_number')),
                'email' => Support::optionalString($request->getBody('billing_email')),
                'reference' => Support::optionalString($request->getBody('billing_reference')),
            ]);
        } catch (RentalException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->backToTracking($params);
        }

        FlashMessage::set('success', 'Vos coordonnées de facturation ont été enregistrées.');

        return $this->backToTracking($params);
    }

    /**
     * The one door both buttons of « Modifier votre demande » go through.
     *
     * @param array<string, string> $params
     */
    private function recordChange(
        RentalBooking $booking,
        RentalAsset $asset,
        ChangeRequestKind $kind,
        ?string $arrival,
        ?string $departure,
        ?int $persons,
        ?string $message,
        array $params
    ): Response {
        try {
            $this->operationsService->requestChange(
                $booking,
                $asset,
                ChangeRequestOrigin::RENTER,
                $kind,
                $arrival,
                $departure,
                null,
                $persons,
                null,
                $message
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
        if (($guard = $this->guardCsrf(
            $request,
            '/locations/suivi/' . (int) ($params['id'] ?? 0) . '/' . (string) ($params['token'] ?? '')
        )) !== null) {
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
            // « Locations » is declared statically on the route (module.
            // json's breadcrumb.ancestors); only the asset itself is
            // dynamic, so only the asset is resolved here.
            'breadcrumb_trail' => [
                ['label' => $asset->name, 'url' => '/locations/' . $asset->slug],
            ],
            'categories' => $pricing->categories,
            'errors' => $errors,
            // Read, and so archived, before the form shows it: the version it
            // carries is one a renter can always come back to (issue #494).
            'conditions' => $this->conditionsService->current($asset->id),
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

        $this->notifyManagers($booking, $asset);
    }

    /**
     * Tells the people who run this asset that a request arrived (#708,
     * IT-05) — through the notification system, which brings what the old
     * direct email lacked: the account's own address, failures in the
     * journal, one reservation per delivery against duplicates, and the
     * account's discretion setting.
     *
     * **No renter identity**, like the email it replaces: the asset and the
     * dates say what it is, and the link opens the booking behind a real
     * permission check.
     */
    private function notifyManagers(RentalBooking $booking, RentalAsset $asset): void
    {
        if ($this->notificationService === null || $this->recipientResolver === null) {
            return;
        }

        try {
            $recipients = $this->recipientResolver->recipientsFor($asset->id, 'new_request');
            if ($recipients === []) {
                return;
            }

            $this->notificationService->dispatch(self::NEW_REQUEST_NOTIFICATION, $recipients, [
                'title' => 'Nouvelle demande de location — ' . $asset->name,
                'body' => 'Du ' . self::frenchDate($booking->arrivalDate) . ' au '
                    . self::frenchDate($booking->departureDate) . ' (' . $booking->reference . ').',
                'url' => '/mes-locations/' . rawurlencode($asset->slug) . '/reservations/' . $booking->id,
            ]);
        } catch (\Throwable) {
            // The managers' page still shows the request; a failed
            // notification must not surface to the renter as their problem.
        }
    }

    private static function frenchDate(string $isoDate): string
    {
        return DateInput::iso($isoDate)?->format('d/m/Y') ?? $isoDate;
    }

    /**
     * How long the automatic hold lasts, in days (#708, IT-01), configurable
     * per installation. 0 turns it off; a negative value is read as 0.
     */
    private function automaticHoldDays(): int
    {
        $configured = $this->settingService->get('automatic_hold_days', 'rental');

        if ($configured === null || $configured === '') {
            return RentalBookingService::DEFAULT_AUTOMATIC_HOLD_DAYS;
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

    private function privacyText(): string
    {
        return (string) ($this->editableContentService->get('rgpd_content', '') ?? '');
    }

    private function privacyVersion(): string
    {
        return substr(RentalBookingService::hashAcceptedText($this->privacyText()), 0, 12);
    }
}
