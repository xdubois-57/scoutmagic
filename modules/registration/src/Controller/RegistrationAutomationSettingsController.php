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
use Modules\Registration\Service\AutomaticAcceptancePolicy;
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
 * a delay of whole hours (Service\AutomaticDecisionEmailService); no automatic
 * acceptance of the « Oui » requests without waitlists, the automatic
 * sending and a date of effect (IT-10, Service\AutomaticAcceptancePolicy,
 * which saves the four settings as one) — are the services'.
 * RegistrationConfigController renders it.
 */
class RegistrationAutomationSettingsController extends AbstractController
{
    private const PATH_SETTINGS = '/config/inscriptions/reglages';

    public function __construct(
        protected Environment $twig,
        private AutomaticDecisionEmailService $automaticEmails,
        private JournalService $journalService,
        private AutomaticAcceptancePolicy $automaticAcceptance
    ) {
    }

    /**
     * The whole sub-box as one: an invalid delay, the acceptance switched on
     * without its prerequisites or its date, or an invalid date, saves
     * nothing. A delay or a date that was not submitted keeps the stored
     * one.
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
        $autoAccept = (string) $request->getBody('auto_accept', '0') === '1';
        $acceptFrom = $request->getBody('auto_accept_from', null);
        $acceptanceBefore = $this->acceptanceState();

        try {
            $confirmation = $this->automaticAcceptance->save(
                $enabled,
                is_string($delay) ? $delay : null,
                $autoAccept,
                is_string($acceptFrom) ? $acceptFrom : null
            );
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
        // Only when it changed — a save of the delay alone says nothing of
        // it. A date of effect is a setting, not a person: it may be
        // journaled.
        $acceptanceAfter = $this->acceptanceState();
        if ($acceptanceAfter !== $acceptanceBefore) {
            $this->journalService->log(
                'registration',
                'registration_auto_accept_saved',
                'info',
                $autoAccept
                    ? 'Acceptation automatique des demandes « Oui » activée'
                    : 'Acceptation automatique des demandes « Oui » désactivée',
                $acceptanceAfter,
                (int) AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', $confirmation);

        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * The automatic acceptance's switch and date, as journaled.
     *
     * @return array{enabled: bool, effective_from: ?string}
     */
    private function acceptanceState(): array
    {
        return [
            'enabled' => $this->automaticAcceptance->isSwitchOn(),
            'effective_from' => $this->automaticAcceptance->effectiveFrom()?->format('Y-m-d'),
        ];
    }
}
