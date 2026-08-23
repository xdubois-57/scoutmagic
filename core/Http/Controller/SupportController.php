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
 * this installation reports usage statistics, send one report on demand to
 * check that it works, and see exactly what such a report contains; and
 * (from §8.48 onward) generate a diagnostic package to attach to a support
 * request.
 *
 * Deliberately not here: where the report goes. See index().
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
        private SchedulerService $schedulerService,
        private StatisticsSender $statisticsSender
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
            // `statistics_destination` is deliberately NOT passed to the
            // view. Where the report goes is a project-level fact, not a
            // unit-level choice: the only legitimate reason to change it is
            // standing up a second receiver, which is a deployment act by
            // whoever runs that receiver — while every unit offered the
            // field could point a live installation at something that never
            // answers, and find out a day later from a failure code. It
            // stays a `settings` row (a restored backup carries it, the
            // sender reads it) that no page renders and no form writes.
            // What the page owes the reader is what leaves the site, and
            // that is the payload preview, not the URL.
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

        if (($guard = $this->guardCsrfJson($request, $token)) !== null) {
            return $guard;
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
     * POST /config/support/statistics — the reporting switch, and nothing
     * else.
     *
     * The destination used to be part of this form. It is not any more, and
     * a value posted under that name is ignored rather than validated: a
     * field no page renders must not stay writable through a hand-made
     * POST, which would be a hidden way to modify exactly the setting that
     * decides where this installation's bearer secret goes. Nothing is lost
     * by dropping the validation with it — Core\Statistics\StatisticsSender
     * checks https and public-host at send time and always had to, since a
     * destination can also arrive from a restored backup, which never
     * passed through this form either.
     *
     * @param array<string, string> $params
     */
    public function saveStatistics(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }

        $wasEnabled = $this->settingService->get('statistics_enabled') === '1';
        $enabled = (string) $request->getBody('statistics_enabled', '') === '1';

        $this->settingService->setInternal('statistics_enabled', $enabled ? '1' : '0');

        // Journaled on an actual change only: a "still enabled" entry every
        // time someone opens the page and presses Enregistrer would be
        // noise, and the switch is the only privacy decision this form
        // still carries.
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
     * POST /config/support/statistics/test — one report, now, on demand.
     *
     * The page can no longer be pointed at another receiver, so the only
     * way left to find out whether reporting actually works is to try it;
     * waiting a day and re-reading "État des envois" is not a test. On the
     * installation that IS the receiver this sends to itself, which is the
     * only way its own report ever reaches its dashboard and the only
     * end-to-end check of the intake endpoint that exists at all
     * (Core\Statistics\StatisticsSender::sendTest()).
     *
     * Synchronous rather than a scheduled task, unlike the support package:
     * the whole value of the button is the answer coming back on the next
     * page, and the request is one bounded POST with a 20 s cap, not a
     * filesystem walk.
     *
     * @param array<string, string> $params
     */
    public function sendTestStatistics(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }

        $result = $this->statisticsSender->sendTest();

        if ($result->isSent()) {
            FlashMessage::set('success', sprintf(
                'Rapport de test transmis (réponse HTTP %d en %d ms).',
                (int) $result->statusCode,
                (int) $result->durationMs
            ));
        } elseif ($result->isSkipped()) {
            FlashMessage::set('warning', self::skipMessage((string) $result->reason));
        } else {
            FlashMessage::set('error', 'Le rapport de test n\'a pas pu être transmis. Motif : ' . (string) $result->reason);
        }

        return $this->redirect('/config/support');
    }

    /**
     * The French reading of a guard that stopped a test send.
     *
     * A reason code is what the journal and "État des envois" record, and
     * that is right for them — it is stable and greppable. It is not right
     * for someone who just pressed a button: `non_public_host` names a
     * setting they have to go and fix, and only the sentence says which.
     */
    private static function skipMessage(string $reason): string
    {
        return match ($reason) {
            'disabled' => 'L\'envoi automatique des statistiques doit être activé pour pouvoir envoyer un rapport de test.',
            'non_public_host' => 'L\'adresse de ce site (paramètre « base_url ») n\'est pas un nom public : aucun rapport n\'est envoyé depuis une installation locale ou de test.',
            'maintenance_in_progress' => 'Une opération de maintenance est en cours : le rapport sera possible une fois terminée.',
            default => 'Aucun rapport n\'a été envoyé. Motif : ' . $reason,
        };
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
