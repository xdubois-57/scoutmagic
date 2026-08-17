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
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SecondaryEmailService;
use Modules\Registration\Service\TrackingService;
use Twig\Environment;

/**
 * Two distinct display modes for the same underlying request, matching the
 * module spec's "vue minimale par token" vs "vue complète si identifié et
 * rattaché": showByToken() never reveals more than status + child first
 * name + received date, showLinked() adds contact fields, siblings, and
 * secondary-email management (Service\SecondaryEmailService) — data the
 * token alone was never meant to expose.
 *
 * BOTH modes show Service\RequestStatusService::visibleStatus(), never the
 * raw `status` column: the module spec's own rule is that a parent must not
 * discover an acceptance or a refusal before the corresponding email has
 * actually gone out (a chief deciding on Friday and sending on Monday). The
 * two templates used to render `registration_request.status` directly,
 * which bypassed that rule entirely — the staff fiche was the only surface
 * calling visibleStatus() at all.
 */
class TrackingController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TrackingService $trackingService,
        private SecondaryEmailService $secondaryEmailService,
        private RegistrationRequestRepository $requestRepository,
        private RequestStatusService $statusService
    ) {
    }

    /**
     * GET /inscriptions/suivi/{id}/{token} — public, minimal view.
     *
     * @param array<string, string> $params
     */
    public function showByToken(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $token = (string) ($params['token'] ?? '');
        $registrationRequest = $id > 0 && $token !== '' ? $this->trackingService->findByToken($id, $token) : null;

        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@registration/tracking_minimal.html.twig', [
            'registration_request' => $registrationRequest,
            'visible_status' => $this->statusService->visibleStatus($registrationRequest),
        ]);
    }

    /**
     * GET /inscriptions/suivi/demande/{id} — identified session, full view,
     * only when the session's own email is linked (Service\TrackingService::
     * isLinkedByEmail — primary or a confirmed secondary address).
     *
     * @param array<string, string> $params
     */
    public function showLinked(Request $request, array $params): Response
    {
        $registrationRequest = $this->requireLinkedRequest($params);
        if ($registrationRequest === null) {
            return new Response('Forbidden', 403);
        }

        return $this->render('@registration/tracking_full.html.twig', [
            'registration_request' => $registrationRequest,
            'visible_status' => $this->statusService->visibleStatus($registrationRequest),
            'sibling_member_ids' => $this->requestRepository->findSiblingMemberIds($registrationRequest->id),
            'secondary_emails' => $this->secondaryEmailService->listForRequest($registrationRequest->id),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /inscriptions/suivi/demande/{id}/emails
     *
     * @param array<string, string> $params
     */
    public function addEmail(Request $request, array $params): Response
    {
        $registrationRequest = $this->requireLinkedRequest($params);
        if ($registrationRequest === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/inscriptions/suivi/demande/' . $registrationRequest->id);
        }

        try {
            $this->secondaryEmailService->addEmail($registrationRequest->id, (string) $request->getBody('email', ''));
            FlashMessage::set('success', 'Adresse ajoutée — un email de confirmation vient d\'être envoyé.');
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/inscriptions/suivi/demande/' . $registrationRequest->id);
    }

    /**
     * POST /inscriptions/suivi/demande/{id}/emails/{email_id}/remove
     *
     * @param array<string, string> $params
     */
    public function removeEmail(Request $request, array $params): Response
    {
        $registrationRequest = $this->requireLinkedRequest($params);
        if ($registrationRequest === null) {
            return new Response('Forbidden', 403);
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/inscriptions/suivi/demande/' . $registrationRequest->id);
        }

        try {
            $this->secondaryEmailService->removeEmail($registrationRequest->id, (int) ($params['email_id'] ?? 0));
            FlashMessage::set('success', 'Adresse supprimée.');
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/inscriptions/suivi/demande/' . $registrationRequest->id);
    }

    /**
     * GET /inscriptions/suivi/emails/confirm/{id}?token=... — same
     * fail-quietly contract as Core\Http\Controller\
     * MemberEmailAddressController::confirm(): never a stack trace or an
     * account-enumeration hint, just a confirmed/not-confirmed flag.
     *
     * @param array<string, string> $params
     */
    public function confirmEmail(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $token = (string) $request->getQuery('token', '');

        $confirmed = $id > 0 && $token !== '' && $this->secondaryEmailService->confirmEmail($id, $token);

        return $this->render('@registration/email_confirmed.html.twig', ['confirmed' => $confirmed]);
    }

    /**
     * Re-derives the link from the identified session's own email on every
     * call (never a persisted session-to-request link, per the module
     * spec's own "rattachement... par l'email" rule and TrackingService's
     * docblock) and returns the request only when it's genuinely linked.
     *
     * @param array<string, string> $params
     */
    private function requireLinkedRequest(array $params): ?RegistrationRequest
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $email = AuthSession::getEmail();
        if ($email === null || !$this->trackingService->isLinkedByEmail($id, $email)) {
            return null;
        }

        return $this->requestRepository->findById($id);
    }
}
