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
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\RegistrationException;
use Twig\Environment;

/**
 * POST /config/inscriptions/decisions-automatiques — the « Décisions
 * automatiques » sub-box of « Capacités par branche », on « Réglages »
 * (chantier #843, D7 / IT-09).
 *
 * A controller of its own, like the copy box's: the sub-box is one
 * self-contained decision with its own « Enregistrer », and its rules —
 * no automatic sending while a decision template is « À rédiger », a delay
 * of whole hours — are Service\AutomaticDecisionEmailService's.
 * RegistrationConfigController renders it.
 */
class RegistrationAutomationSettingsController extends AbstractController
{
    private const PATH_SETTINGS = '/config/inscriptions/reglages';

    public function __construct(
        protected Environment $twig,
        private AutomaticDecisionEmailService $automaticEmails,
        private JournalService $journalService
    ) {
    }

    /**
     * The switch and the delay as one: an invalid delay, or the switch on
     * while a decision template is not written, saves nothing. A delay
     * field that was not submitted keeps the stored delay.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $enabled = (string) $request->getBody('auto_send', '0') === '1';
        $delay = $request->getBody('delay_hours', null);

        try {
            $confirmation = $this->automaticEmails->save($enabled, is_string($delay) ? $delay : null);
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect(self::PATH_SETTINGS);
        }

        $this->journalService->log(
            'registration',
            'registration_decision_email_automation_saved',
            'info',
            $enabled
                ? 'Envoi automatique des e-mails de décision activé'
                : 'Envoi automatique des e-mails de décision désactivé',
            ['enabled' => $enabled, 'delay_hours' => $this->automaticEmails->delayHours()],
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', $confirmation);

        return $this->redirect(self::PATH_SETTINGS);
    }
}
