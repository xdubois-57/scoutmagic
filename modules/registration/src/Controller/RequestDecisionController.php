<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestDecisionService;
use Twig\Environment;

/**
 * POST /config/inscriptions/demandes/{id}/decision — what the decision
 * dialog of the « Demandes » list posts (issue #843, IT-04): Accepter, or
 * « Confirmer le refus » with its reason, and the internal note. The
 * dialog itself is rendered by the list (views/config/_decide_dialogs.html.twig)
 * from Service\RequestDecisionService::dialogContext().
 *
 * A controller of its own rather than one more action on
 * RegistrationRequestController, which is the fiche's: the dialog belongs
 * to the list, and goes back to it — with the year and the filters it was
 * opened from — whatever the outcome. `role_min: admin` in module.json,
 * like every route of the management page.
 */
class RequestDecisionController extends AbstractController
{
    private const PATH_REQUESTS = '/config/inscriptions/demandes';

    public function __construct(
        protected Environment $twig,
        private RegistrationRequestRepository $requestRepository,
        private RequestDecisionService $decisionService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function decide(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $registrationRequest = $id > 0 ? $this->requestRepository->findById($id) : null;
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }

        $back = $this->listAddress($request, $registrationRequest->scoutYearId);
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }

        $decision = (string) $request->getBody('decision', '');
        $reason = trim((string) $request->getBody('refusal_reason', ''));
        // The note the dialog displayed, to tell an untouched note from an
        // edited one (RequestDecisionService::decide()).
        $noteShown = $request->getBody('internal_notes_shown');

        try {
            $this->decisionService->decide(
                $registrationRequest,
                $decision,
                (string) $request->getBody('internal_notes', ''),
                $reason !== '' ? $reason : null,
                is_string($noteShown) ? $noteShown : null
            );
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect($back);
        }

        FlashMessage::set('success', $this->decisionService->confirmation($decision, $reason));

        return $this->redirect($back);
    }

    /**
     * Back to the list the dialog was opened from: the request's own year,
     * and the state filter and search the dialog carried, when they are
     * ones the list understands.
     */
    private function listAddress(Request $request, int $scoutYearId): string
    {
        $query = ['year' => $scoutYearId];
        $status = (string) $request->getBody('list_status', '');
        // The list filters on a state, so only a state is carried back.
        if (isset(RegistrationRequest::STATUS_LABELS[$status])) {
            $query['status'] = $status;
        }
        $search = trim((string) $request->getBody('list_q', ''));
        if ($search !== '') {
            $query['q'] = $search;
        }

        return self::PATH_REQUESTS . '?' . http_build_query($query);
    }
}
