<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Controller;

use Core\Geo\GeoPointException;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\View\SearchPickerResult;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolException;
use Modules\Covoiturage\Service\CarpoolService;
use Modules\Covoiturage\Service\CarpoolViewer;
use Modules\Covoiturage\Service\CarpoolViewerResolver;
use Modules\Covoiturage\Service\DuplicateCarpoolException;
use Modules\Covoiturage\Service\LocationMismatchException;
use Twig\Environment;

/**
 * « Organiser les covoiturages » in the Espace animateurs (D2): only a
 * chief creates a carpool (D1). The staff view is additive — the same
 * chief offers seats from the members' page like everybody else.
 */
class CarpoolOrganizerController extends AbstractController
{
    private ?CarpoolViewer $viewer = null;
    private string $viewerKey = '';

    public function __construct(
        Environment $twig,
        private CarpoolRepository $carpools,
        private CarpoolService $service,
        private CarpoolBoard $board,
        private SectionService $sections,
        private CarpoolViewerResolver $viewers
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@covoiturage/organize/list.html.twig', [
            'carpools' => $this->board->organizerList($this->viewer()),
            'delete_refused' => CarpoolService::DELETE_REFUSED,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->renderForm(null, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/covoiturage/organiser/nouveau')) !== null) {
            return $guard;
        }

        try {
            $id = $this->service->create($request->getBodyAll(), $this->viewer());
        } catch (CarpoolException | GeoPointException $e) {
            return $this->renderForm(null, $request->getBodyAll(), [$e->getMessage()], $e);
        }

        FlashMessage::set('success', 'Covoiturage créé : les familles peuvent y proposer leurs places.');

        return $this->redirect('/covoiturage/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }
        if (!$this->viewer()->mayOrganize($carpool)) {
            return $this->forbidden(
                'Ce covoiturage concerne une autre section : vous ne pouvez pas le modifier.',
                $request
            );
        }

        return $this->renderForm($carpool, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/covoiturage/organiser/' . $carpool->id . '/modifier')) !== null) {
            return $guard;
        }

        try {
            $this->service->update($carpool, $request->getBodyAll(), $this->viewer());
        } catch (CarpoolException | GeoPointException $e) {
            return $this->renderForm($carpool, $request->getBodyAll(), [$e->getMessage()], $e);
        }

        FlashMessage::set('success', 'Covoiturage modifié.');

        return $this->redirect('/covoiturage/' . $carpool->id);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $carpool = $this->carpools->findById((int) ($params['id'] ?? 0));
        if ($carpool === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, '/covoiturage/organiser')) !== null) {
            return $guard;
        }

        try {
            $this->service->delete($carpool, $this->viewer());
        } catch (CarpoolException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect('/covoiturage/organiser');
        }

        FlashMessage::set('success', 'Covoiturage supprimé.');

        return $this->redirect('/covoiturage/organiser');
    }

    /**
     * GET /covoiturage/organiser/evenements?q= — the event picker's search
     * (partials/search_picker.html.twig).
     *
     * @param array<string, string> $params
     */
    public function searchEvents(Request $request, array $params): Response
    {
        return $this->json(SearchPickerResult::payload(
            $this->service->searchEvents((string) $request->getQuery('q', ''), $this->viewer())
        ));
    }

    /**
     * GET /covoiturage/organiser/lieux?ids=1,2 — the places the chosen
     * events announce, so the form can fill the address and warn when they
     * disagree.
     *
     * @param array<string, string> $params
     */
    public function eventLocations(Request $request, array $params): Response
    {
        $ids = array_values(array_filter(
            array_map('intval', explode(',', (string) $request->getQuery('ids', ''))),
            static fn(int $id): bool => $id > 0
        ));

        return $this->json([
            'success' => true,
            'locations' => $this->service->eventLocations($ids, $this->viewer()),
        ]);
    }

    /**
     * @param array<string, mixed> $submitted
     * @param list<string> $errors
     */
    private function renderForm(?Carpool $carpool, array $submitted, array $errors, ?\Throwable $error = null): Response
    {
        $values = $submitted !== [] ? $submitted : $this->valuesOf($carpool);
        $selectedEvents = [];
        if ($carpool !== null && $submitted === []) {
            foreach ($carpool->events as $event) {
                $selectedEvents[] = ['id' => $event->eventId, 'label' => $event->title, 'badge' => $event->sectionName];
            }
        } else {
            $known = [];
            foreach ($carpool !== null ? $carpool->events : [] as $event) {
                $known[$event->eventId] = $event->title;
            }
            foreach ((array) ($submitted['event_ids'] ?? []) as $id) {
                $selectedEvents[] = ['id' => (int) $id, 'label' => $known[(int) $id] ?? ('Évènement n° ' . (int) $id)];
            }
        }

        return $this->render('@covoiturage/organize/form.html.twig', [
            'carpool' => $carpool,
            'values' => $values,
            'errors' => $errors,
            'selected_events' => $selectedEvents,
            'has_calendar' => $this->service->hasCalendar(),
            // The list the picker falls back on without JavaScript: the
            // soonest events, which is usually where the answer is.
            'event_options' => array_map(
                static fn(SearchPickerResult $row): array => ['value' => $row->id, 'label' => $row->label],
                $this->service->searchEvents('', $this->viewer())
            ),
            'sections' => $this->sections->getAllWithBranches(),
            'duplicate_of' => $error instanceof DuplicateCarpoolException ? $error->existingCarpoolId : null,
            'location_mismatch' => $error instanceof LocationMismatchException ? $error->locations : [],
            'point' => $carpool?->point,
            'point_is_manual' => $carpool !== null && $carpool->pointIsManual,
            'breadcrumb_current' => $carpool !== null ? 'Modifier le covoiturage' : null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function valuesOf(?Carpool $carpool): array
    {
        if ($carpool === null) {
            return ['address' => '', 'outbound_date' => '', 'return_date' => '', 'section_id' => ''];
        }

        return [
            'address' => $carpool->address,
            'outbound_date' => $carpool->outboundDate,
            'return_date' => $carpool->returnDate ?? '',
            'section_id' => $carpool->sectionId !== null ? (string) $carpool->sectionId : '',
            'latitude' => $carpool->point !== null ? number_format($carpool->point->latitude, 6, '.', '') : '',
            'longitude' => $carpool->point !== null ? number_format($carpool->point->longitude, 6, '.', '') : '',
        ];
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
