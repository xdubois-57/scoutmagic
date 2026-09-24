<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\Offer;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolException;
use Modules\Covoiturage\Service\CarpoolViewer;
use Modules\Covoiturage\Service\CarpoolViewerResolver;
use Modules\Covoiturage\Service\OfferService;
use Twig\Environment;

/**
 * « Covoiturage » in the Espace membres: the carpools, one carpool, and
 * the offers and requests inside it (D2). Every identified member offers or
 * asks; a chief who drives uses this page like anybody else (D1).
 *
 * Thin by rule: every decision about who may see or do what is in
 * Service\CarpoolViewer and Service\OfferService.
 */
class CarpoolController extends AbstractController
{
    private ?CarpoolViewer $viewer = null;
    private string $viewerKey = '';

    public function __construct(
        Environment $twig,
        private CarpoolRepository $carpools,
        private OfferRepository $offers,
        private SeatRequestRepository $requests,
        private CarpoolBoard $board,
        private OfferService $offerService,
        private CarpoolViewerResolver $viewers
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@covoiturage/list.html.twig', $this->board->memberList($this->viewer()));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }
        $viewer = $this->viewer();
        $direction = (string) $request->getQuery('sens', Offer::OUTBOUND) === Offer::RETURN ? Offer::RETURN : Offer::OUTBOUND;

        return $this->render('@covoiturage/show.html.twig', [
            'carpool' => $this->board->carpoolPage($carpool, $viewer),
            'direction' => $carpool->hasReturn() ? $direction : Offer::OUTBOUND,
            'prefill' => $this->board->prefill($viewer),
            'breadcrumb_current' => $carpool->title(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function offerForm(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }

        return $this->renderOfferForm($carpool, null, $this->defaultOfferValues($request), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function storeOffer(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/covoiturage/' . $carpool->id . '/proposer')) !== null) {
            return $guard;
        }

        try {
            $this->offerService->propose($carpool, $request->getBodyAll(), $this->viewer());
        } catch (CarpoolException $e) {
            return $this->renderOfferForm($carpool, null, $request->getBodyAll(), [$e->getMessage()]);
        }

        FlashMessage::set('success', 'Vos places sont proposées.');

        return $this->redirect('/covoiturage/' . $carpool->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function editOffer(Request $request, array $params): Response
    {
        [$offer, $carpool] = $this->offerAndCarpool($params);
        if ($offer === null || $carpool === null) {
            return $this->notFound();
        }
        if (!$this->viewer()->drives($offer)) {
            return $this->forbidden('Seul le conducteur de cette voiture peut la modifier.', $request);
        }

        return $this->renderOfferForm($carpool, $offer, [
            'departure_time' => $offer->departureTime,
            'endpoint' => $offer->endpoint,
            'seats' => (string) $offer->seats,
            'driver_name' => $offer->driverName,
            'phone' => $offer->phone,
            'note' => $offer->note ?? '',
        ], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateOffer(Request $request, array $params): Response
    {
        [$offer, $carpool] = $this->offerAndCarpool($params);
        if ($offer === null || $carpool === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/covoiturage/' . $carpool->id)) !== null) {
            return $guard;
        }

        try {
            $this->offerService->update($offer, $request->getBodyAll(), $this->viewer(), $carpool);
        } catch (CarpoolException $e) {
            return $this->renderOfferForm($carpool, $offer, $request->getBodyAll(), [$e->getMessage()]);
        }

        FlashMessage::set('success', 'Votre voiture est à jour.');

        return $this->redirect($this->carpoolUrl($carpool, $offer));
    }

    /**
     * @param array<string, string> $params
     */
    public function cancelOffer(Request $request, array $params): Response
    {
        [$offer, $carpool] = $this->offerAndCarpool($params);
        if ($offer === null || $carpool === null) {
            return $this->notFound();
        }

        return $this->act(
            $request,
            $carpool,
            $offer,
            fn() => $this->offerService->cancel($offer, $this->viewer(), $carpool),
            'Votre voiture est retirée.'
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function requestSeats(Request $request, array $params): Response
    {
        [$offer, $carpool] = $this->offerAndCarpool($params);
        if ($offer === null || $carpool === null) {
            return $this->notFound();
        }
        $viewer = $this->viewer();

        return $this->act(
            $request,
            $carpool,
            $offer,
            fn() => $this->offerService->request(
                $carpool,
                $offer,
                $request->getBodyAll(),
                $viewer,
                $this->board->family($viewer),
                $this->board->prefill($viewer)['requester_name']
            ),
            'Demande envoyée : le conducteur vous répondra.'
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function accept(Request $request, array $params): Response
    {
        return $this->decide($request, $params, 'accept', 'Demande acceptée.');
    }

    /**
     * @param array<string, string> $params
     */
    public function refuse(Request $request, array $params): Response
    {
        return $this->decide($request, $params, 'refuse', 'Demande refusée.');
    }

    /**
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        return $this->decide($request, $params, 'revoke', 'La place est retirée ; elle redevient libre.');
    }

    /**
     * @param array<string, string> $params
     */
    public function withdraw(Request $request, array $params): Response
    {
        return $this->decide($request, $params, 'withdraw', 'Votre demande est retirée.');
    }

    /**
     * @param array<string, string> $params
     */
    private function decide(Request $request, array $params, string $action, string $success): Response
    {
        $seatRequest = $this->requests->findById((int) ($params['id'] ?? 0));
        $offer = $seatRequest !== null ? $this->offers->findById($seatRequest->offerId) : null;
        $carpool = $offer !== null ? $this->carpools->findById($offer->carpoolId) : null;
        if ($seatRequest === null || $offer === null || $carpool === null) {
            return $this->notFound();
        }
        $viewer = $this->viewer();

        return $this->act($request, $carpool, $offer, fn() => match ($action) {
            'accept' => $this->offerService->accept($seatRequest, $offer, $viewer, $carpool),
            'refuse' => $this->offerService->refuse($seatRequest, $offer, $viewer, $carpool),
            'revoke' => $this->offerService->revoke($seatRequest, $offer, $viewer, $carpool),
            default => $this->offerService->withdraw($seatRequest, $viewer, $carpool, $offer),
        }, $success);
    }

    /**
     * One POST on a carpool page: CSRF, the service call, a flash, back to
     * the page on the offer's direction.
     */
    private function act(Request $request, Carpool $carpool, Offer $offer, callable $call, string $success): Response
    {
        $back = $this->carpoolUrl($carpool, $offer);
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        try {
            $call();
        } catch (CarpoolException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($back);
        }

        FlashMessage::set('success', $success);

        return $this->redirect($back);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $errors
     */
    private function renderOfferForm(Carpool $carpool, ?Offer $offer, array $values, array $errors): Response
    {
        $taken = $offer !== null ? $this->requests->acceptedSeats($offer->id) : 0;
        $viewer = $this->viewer();

        return $this->render('@covoiturage/offer_form.html.twig', [
            'carpool' => $this->board->carpoolPage($carpool, $viewer),
            'offer' => $offer,
            'values' => $values,
            'errors' => $errors,
            'taken' => $taken,
            'taken_warning' => $taken > 0
                ? ($taken > 1 ? "{$taken} places sont déjà accordées" : '1 place est déjà accordée')
                    . ' : le nombre de places ne peut pas descendre en dessous.'
                : null,
            'max_seats' => OfferService::MAX_SEATS,
            'breadcrumb_trail' => [['label' => $carpool->title(), 'url' => '/covoiturage/' . $carpool->id]],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaultOfferValues(Request $request): array
    {
        $prefill = $this->board->prefill($this->viewer());

        return [
            'direction' => (string) $request->getQuery('sens', Offer::OUTBOUND) === Offer::RETURN ? Offer::RETURN : Offer::OUTBOUND,
            'departure_time' => '',
            'endpoint' => '',
            'seats' => '4',
            'driver_name' => $prefill['driver_name'],
            'phone' => $prefill['phone'],
            'note' => '',
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array{0: ?Offer, 1: ?Carpool}
     */
    private function offerAndCarpool(array $params): array
    {
        $offer = $this->offers->findById((int) ($params['id'] ?? 0));

        return [$offer, $offer !== null ? $this->carpools->findById($offer->carpoolId) : null];
    }

    private function carpoolUrl(Carpool $carpool, Offer $offer): string
    {
        return '/covoiturage/' . $carpool->id . ($offer->isOutbound() ? '' : '?sens=return');
    }

    /**
     * Resolved once per session identity: the staffed sections cost a
     * query, and a page asks several times.
     */
    private function viewer(): CarpoolViewer
    {
        $accountId = (int) AuthSession::getUserAccountId();
        $email = (string) AuthSession::getEmail();
        $role = AuthSession::getRole();
        $key = $accountId . "\0" . $email . "\0" . $role;
        if ($this->viewer === null || $this->viewerKey !== $key) {
            $this->viewer = $this->viewers->resolve($accountId, $email, $role);
            $this->viewerKey = $key;
        }

        return $this->viewer;
    }
}
