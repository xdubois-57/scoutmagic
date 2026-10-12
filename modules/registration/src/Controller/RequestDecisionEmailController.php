<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\DecisionEmailPanel;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use Twig\Environment;

/**
 * The three buttons of a fiche's « E-mail de décision » block (issue #843,
 * IT-08) — « Envoyer maintenant », « Réessayer », « Renvoyer l'e-mail » —
 * one POST route each under /config/inscriptions/demandes/{id}/e-mail/,
 * `role_min: admin` in module.json like every route of the fiche.
 *
 * Nothing is sent from here: Service\RequestEmailService::sendFromRequestPage()
 * checks that the block offers the action at this moment, claims the
 * attempt, sends through MailService with the unit in copy (D9), records
 * the outcome and journals. Every outcome goes back to the fiche.
 */
class RequestDecisionEmailController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private RegistrationRequestRepository $requestRepository,
        private RequestEmailService $emailService,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * POST /config/inscriptions/demandes/{id}/e-mail/envoyer
     *
     * @param array<string, string> $params
     */
    public function sendNow(Request $request, array $params): Response
    {
        return $this->perform($request, $params, DecisionEmailPanel::ACTION_SEND_NOW);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/e-mail/reessayer
     *
     * @param array<string, string> $params
     */
    public function retry(Request $request, array $params): Response
    {
        return $this->perform($request, $params, DecisionEmailPanel::ACTION_RETRY);
    }

    /**
     * POST /config/inscriptions/demandes/{id}/e-mail/renvoyer
     *
     * @param array<string, string> $params
     */
    public function resend(Request $request, array $params): Response
    {
        return $this->perform($request, $params, DecisionEmailPanel::ACTION_RESEND);
    }

    /**
     * @param array<string, string> $params
     */
    private function perform(Request $request, array $params, string $action): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $registrationRequest = $id > 0 ? $this->requestRepository->findById($id) : null;
        if ($registrationRequest === null) {
            return new Response('Not Found', 404);
        }

        $requestPage = '/config/inscriptions/demandes/' . $registrationRequest->id;
        if (($guard = $this->guardCsrf($request, $requestPage)) !== null) {
            return $guard;
        }

        $scoutYear = $this->scoutYearService->findById($registrationRequest->scoutYearId);

        try {
            $decision = $this->emailService->sendFromRequestPage(
                $registrationRequest,
                $action,
                $scoutYear['label'] ?? ''
            );
            FlashMessage::set('success', self::sentMessage($decision, $action));
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($requestPage);
    }

    private static function sentMessage(string $decision, string $action): string
    {
        $email = $decision === DecisionEmail::DECISION_REFUSED ? "L'e-mail de refus" : "L'e-mail d'acceptation";

        return $action === DecisionEmailPanel::ACTION_RESEND
            ? $email . ' a été renvoyé à la famille.'
            : $email . ' est parti : la famille voit maintenant la décision.';
    }
}
