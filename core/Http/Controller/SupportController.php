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
use Core\Scheduler\SchedulerService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsSender;
use Core\Statistics\StatisticsStateSettings;
use Core\Support\SupportPackageService;
use Core\Support\SupportPackageState;
use Core\Support\Task\GenerateSupportPackageHandler;
use Twig\Environment;

/**
 * Configuration > Support (`/config/support`, `role_min: superadmin`) —
 * ARCHITECTURE.md §8.47/§8.48.
 *
 * One page for the two things a unit can do about support: decide whether
 * this installation reports usage statistics, and see exactly what such a
 * report contains; and (from §8.48 onward) generate a diagnostic package to
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
    public const LAST_SUCCESS_SETTING = StatisticsStateSettings::LAST_SUCCESS_AT;
    public const LAST_FAILURE_SETTING = StatisticsStateSettings::LAST_FAILURE_AT;
    public const LAST_FAILURE_REASON_SETTING = StatisticsStateSettings::LAST_FAILURE_REASON;

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private JournalService $journalService,
        private StatisticsPayloadBuilder $payloadBuilder,
        private SchedulerService $schedulerService
    ) {
    }

    /**
     * GET /config/support
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $lastSuccessAt = self::nonEmpty($this->settingService->get(self::LAST_SUCCESS_SETTING));
        $lastFailureAt = self::nonEmpty($this->settingService->get(self::LAST_FAILURE_SETTING));

        return $this->render('config/support.html.twig', [
            'statistics_enabled' => $this->settingService->get('statistics_enabled') === '1',
            'statistics_destination' => (string) ($this->settingService->get('statistics_destination') ?? ''),
            'support_email' => (string) ($this->settingService->get('support_email') ?? ''),
            'last_success_at' => $lastSuccessAt,
            'last_failure_at' => $lastFailureAt,
            'last_failure_reason' => self::nonEmpty($this->settingService->get(self::LAST_FAILURE_REASON_SETTING)),
            // Core\Statistics\StatisticsSender never clears the failure
            // settings on a later success — deliberately, since "it failed
            // once and here is why" stays worth reading. But a motif from
            // three weeks ago, sitting under "État des envois" with nothing
            // saying it has since been resolved, reads as the site's current
            // state and sends people chasing a problem that is gone. The
            // page says which of the two came last; the comparison belongs
            // here rather than as a string comparison in Twig.
            'last_failure_superseded' => self::isBefore($lastFailureAt, $lastSuccessAt),
            // Built and shown whether or not reporting is enabled: someone
            // deciding whether to turn it ON has to be able to read what
            // would leave the site first.
            'payload_json' => $this->payloadBuilder->buildJson(),
            'package_file_id' => $this->currentPackageFileId(),
            'package_generated_at' => self::nonEmpty($this->settingService->get(SupportPackageState::GENERATED_AT)),
            'package_retention_days' => SupportPackageService::RETENTION_DAYS,
        ]);
    }

    /**
     * POST /config/support/package (AJAX, JSON) — schedules the background
     * generation, exactly like the Maintenance page's full backup (§8.15).
     *
     * Available whether or not usage reporting is enabled: refusing to help
     * a unit diagnose their own site because they declined telemetry would
     * be indefensible.
     *
     * @param array<string, string> $params
     */
    public function generatePackage(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        $token = is_array($data)
            ? (string) ($data['_csrf_token'] ?? '')
            : (string) $request->getBody('_csrf_token', '');

        if (!CsrfGuard::validateToken($token)) {
            return $this->json(['success' => false, 'error' => 'Jeton de sécurité invalide.'], 400);
        }

        $actionId = $this->schedulerService->scheduleAfter(
            'core',
            GenerateSupportPackageHandler::TASK_KEY,
            0,
            [],
            null,
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'action_id' => $actionId]);
    }

    /**
     * GET /api/support/package-status/{id} — polled by the Support page,
     * same shape as /api/maintenance/backup-status/{id}.
     *
     * @param array<string, string> $params
     */
    public function packageStatus(Request $request, array $params): Response
    {
        $action = $this->schedulerService->findById((int) ($params['id'] ?? 0));
        if ($action === null || (string) $action['task_key'] !== GenerateSupportPackageHandler::TASK_KEY) {
            return $this->json(['error' => 'Génération introuvable.'], 404);
        }

        $status = (string) $action['status'];
        $fileId = $status === 'done' ? $this->currentPackageFileId() : null;

        return $this->json([
            'status' => $status,
            'download_url' => $fileId !== null ? '/files/' . $fileId : null,
        ]);
    }

    private function currentPackageFileId(): ?int
    {
        $fileId = (int) ($this->settingService->get(SupportPackageState::FILE_ID) ?? '0');

        return $fileId > 0 ? $fileId : null;
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

        // Refused here rather than discovered a day later as a failed send.
        // Two separate facts, two separate messages, because they have two
        // separate fixes: a cleartext destination would carry the bearer
        // secret in an `Authorization` header over http, and a destination
        // that is not a public name (localhost, an IP literal, `intranet`,
        // a `.local`/`.test` suffix) points that same credential at
        // something inside the hosting network. Core\Statistics\
        // StatisticsSender enforces both again at send time — a setting can
        // also arrive from a restored backup, which never passes through
        // this form.
        if ($destination !== '' && !str_starts_with(strtolower($destination), 'https://')) {
            FlashMessage::set('error', 'L\'adresse de destination doit être en https : le secret d\'authentification voyage dans un en-tête.');
            return $this->redirect('/config/support');
        }

        if ($destination !== '' && !StatisticsSender::isPublicHost($destination)) {
            FlashMessage::set('error', 'L\'adresse de destination doit désigner un site public. Une adresse locale, une adresse IP ou un nom interne n\'est pas acceptée.');
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

    /**
     * Whether $earlier is strictly older than $later. Either being absent
     * or unparseable answers false — "we cannot tell" must never read as
     * "resolved".
     */
    private static function isBefore(?string $earlier, ?string $later): bool
    {
        if ($earlier === null || $later === null) {
            return false;
        }

        try {
            return (new \DateTimeImmutable($earlier)) < (new \DateTimeImmutable($later));
        } catch (\Throwable) {
            return false;
        }
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
