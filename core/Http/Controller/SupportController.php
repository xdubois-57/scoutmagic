<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Statistics\StatisticsPayloadBuilder;
use Twig\Environment;

/**
 * Configuration > Support (`/config/support`, `role_min: superadmin`) —
 * ARCHITECTURE.md §8.41/§8.42.
 *
 * One page for the two things a unit can do about support: decide whether
 * this installation reports usage statistics, and see exactly what such a
 * report contains; and (from §8.42 onward) generate a diagnostic package to
 * attach to a support request.
 *
 * The controller orchestrates only — the payload is built by
 * Core\Statistics\StatisticsPayloadBuilder, the settings are read and
 * written through SettingService.
 *
 * Deliberately NOT here, and not anywhere: a bug-report form. Collecting a
 * name, a contact email and an incident description would create a whole
 * personal-data flow for something an email to the support address already
 * does better.
 */
class SupportController extends AbstractController
{
    public const LAST_SUCCESS_SETTING = 'statistics_last_success_at';
    public const LAST_FAILURE_SETTING = 'statistics_last_failure_at';
    public const LAST_FAILURE_REASON_SETTING = 'statistics_last_failure_reason';

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private JournalService $journalService,
        private StatisticsPayloadBuilder $payloadBuilder
    ) {
    }

    /**
     * GET /config/support
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('config/support.html.twig', [
            'statistics_enabled' => $this->settingService->get('statistics_enabled') === '1',
            'statistics_destination' => (string) ($this->settingService->get('statistics_destination') ?? ''),
            'support_email' => (string) ($this->settingService->get('support_email') ?? ''),
            'last_success_at' => self::nonEmpty($this->settingService->get(self::LAST_SUCCESS_SETTING)),
            'last_failure_at' => self::nonEmpty($this->settingService->get(self::LAST_FAILURE_SETTING)),
            'last_failure_reason' => self::nonEmpty($this->settingService->get(self::LAST_FAILURE_REASON_SETTING)),
            // Built and shown whether or not reporting is enabled: someone
            // deciding whether to turn it ON has to be able to read what
            // would leave the site first.
            'payload_json' => $this->payloadBuilder->buildJson(),
        ]);
    }

    /**
     * POST /config/support/statistics — the reporting switch and its
     * destination.
     *
     * @param array<string, string> $params
     */
    public function saveStatistics(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton de sécurité invalide. Merci de réessayer.');
            return $this->redirect('/config/support');
        }

        $wasEnabled = $this->settingService->get('statistics_enabled') === '1';
        $enabled = (string) $request->getBody('statistics_enabled', '') === '1';

        $destination = trim((string) $request->getBody('statistics_destination', ''));
        if ($destination !== '' && filter_var($destination, FILTER_VALIDATE_URL) === false) {
            FlashMessage::set('error', 'L\'adresse de destination n\'est pas une URL valide.');
            return $this->redirect('/config/support');
        }

        $this->settingService->setInternal('statistics_enabled', $enabled ? '1' : '0');
        if ($destination !== '') {
            $this->settingService->setInternal('statistics_destination', $destination);
        }

        // Journaled on an actual change only. A daily "still enabled" entry
        // would be noise, and re-saving the destination is not a privacy
        // decision worth recording as one.
        if ($enabled !== $wasEnabled) {
            $this->journalService->log(
                'core',
                $enabled ? 'statistics_reporting_enabled' : 'statistics_reporting_disabled',
                'info',
                $enabled
                    ? 'Envoi automatique des statistiques d\'utilisation activé'
                    : 'Envoi automatique des statistiques d\'utilisation désactivé',
                [],
                AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', 'Préférences de support enregistrées.');

        return $this->redirect('/config/support');
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
