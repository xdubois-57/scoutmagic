<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\AppClock;
use Core\Debug\MeasurementWindow;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Scheduler\SchedulerService;
use Core\Service\DateInput;
use Core\Statistics\DestinationMatcher;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsSender;
use Core\File\FileRepository;
use Core\Support\Ticket\ArchiveContents;
use Core\Support\Ticket\MailProbeSender;
use Core\Support\Ticket\SupportArchiveSender;
use Core\Support\Ticket\SupportTicketSender;
use Core\Support\Ticket\TicketIdentityService;
use Core\Statistics\StatisticsStateSettings;
use Core\Support\SupportPackageService;
use Core\Support\SupportPackageState;
use Core\Support\Task\GenerateSupportPackageHandler;
use Core\Support\Task\SendTicketArchiveHandler;
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
        private StatisticsSender $statisticsSender,
        /**
         * Null when nothing wired one — the ticket section then simply
         * does not render, the way every other optional capability of this
         * codebase degrades.
         */
        private ?SupportTicketSender $ticketSender = null,
        private ?TicketIdentityService $ticketIdentity = null,
        private ?SupportArchiveSender $archiveSender = null,
        /**
         * The collectors' technical names, in the order the archive is
         * built — turned into French on the way to the view. Empty when
         * nothing wired them, which simply hides the contents list.
         *
         * @var list<string>
         */
        private array $collectorNames = [],
        /** Only ever asked for the archive's size, on the consent screen. */
        private ?FileRepository $fileRepository = null,
        /**
         * The diagnostic mail probes (roadmap IT-27). Null when nothing
         * wired one — the section then does not render, like the rest.
         */
        private ?MailProbeSender $probeSender = null,
        /**
         * The measurement window (Core\Debug\MeasurementWindow). Null when
         * nothing wired one — the card then does not render.
         */
        private ?MeasurementWindow $measurementWindow = null
    ) {
    }

    /**
     * GET /config/support
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->renderIndex();
    }

    /**
     * The page, with an optional half-filled ticket form.
     *
     * `$ticketForm` is what an administrator had typed when a send
     * failed: the page is re-rendered rather than redirected to, because
     * a redirect would lose the description they just wrote and « votre
     * saisie a disparu, réessayez » is not an error message anybody
     * accepts (roadmap IT-25).
     *
     * @param array<string, string> $ticketForm
     */
    private function renderIndex(array $ticketForm = []): Response
    {
        $lastSuccessAt = self::nonEmpty($this->settingService->get(self::LAST_SUCCESS_SETTING));
        $lastFailureAt = self::nonEmpty($this->settingService->get(self::LAST_FAILURE_SETTING));

        return $this->render('config/support.html.twig', [
            // The ticket section, or nothing at all when no sender is
            // wired.
            'ticket_available' => $this->ticketSender !== null,
            'ticket_categories' => $this->ticketSender?->categories() ?? [],
            'ticket_last_sent' => $this->ticketSender?->lastSent(),
            'ticket_form' => $ticketForm,
            'ticket_contact_default' => (string) (AuthSession::getEmail() ?? ''),
            'ticket_guard' => $this->ticketIdentity?->firstFailingGuard(),
            'ticket_telemetry_enabled' => $this->ticketIdentity?->telemetryEnabled() ?? false,
            // The archive half of the ticket (roadmap IT-26): what the
            // archive holds, in French, and how big it is — both shown
            // BEFORE the box that agrees to transmit it.
            'archive_available' => $this->archiveSender !== null,
            'archive_contents' => ArchiveContents::describe($this->collectorNames),
            'archive_size_bytes' => $this->currentPackageSizeBytes(),
            'archive_transmitted' => $this->archiveTransmitted(),
            'archive_transmitted_at' => $this->archiveSender?->transmittedAt() ?? '',
            'archive_destination' => (string) ($this->settingService->get('statistics_destination') ?? ''),
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
            // The receiver installation is the one place that never reports
            // on a schedule — it would be reporting to itself. That used to
            // show up as a dated failure under "État des envois" and stay
            // there for ever, reading as a fault to chase. It is a fact
            // about this installation, so it is said as one. Derived, not
            // stored: it is exactly the guard Core\Statistics\
            // StatisticsSender applies, asked at display time.
            'is_statistics_receiver' => DestinationMatcher::isReceiver(
                self::nonEmpty($this->settingService->get('base_url')),
                self::nonEmpty($this->settingService->get('statistics_destination'))
            ),
            // Built and shown whether or not reporting is enabled: someone
            // deciding whether to turn it ON has to be able to read what
            // would leave the site first.
            'payload_json' => $this->payloadBuilder->buildJson(),
            'package_file_id' => $this->currentPackageFileId(),
            'package_generated_at' => self::onTheAppClock(
                self::nonEmpty($this->settingService->get(SupportPackageState::GENERATED_AT))
            ),
            'package_retention_days' => SupportPackageService::RETENTION_DAYS,
            // The mail probes (roadmap IT-27). The page has to say when
            // the button will work again rather than simply refusing:
            // a disabled control with no reason is the same silence the
            // probe exists to remove.
            // Only the last run's key, so the confirmation can name what
            // the support will be looking for. There is no probe button
            // any more: a probe goes with a ticket and only with one.
            'probe_last_run' => $this->probeSender?->lastRun(),
            // The measurement window: whether one can be opened, whether one
            // is open and until when (on the application clock, as a time
            // of day — it closes within the hour), and how many requests
            // the current or last one recorded, so the page can say what
            // the next archive will carry.
            'measurement_available' => $this->measurementWindow !== null,
            'measurement_open' => $this->measurementWindow?->isOpen() ?? false,
            'measurement_expires_at' => $this->measurementWindow?->expiresAt()
                ?->setTimezone(AppClock::zone())->format('H:i') ?? '',
            'measurement_requests' => $this->measurementWindow?->recordedRequests() ?? 0,
            'measurement_minutes' => MeasurementWindow::DEFAULT_MINUTES,
            'measurement_cap' => MeasurementWindow::MAX_REQUESTS,
        ]);
    }

    /**
     * POST /config/support/measure — opens the measurement window for
     * MeasurementWindow::DEFAULT_MINUTES (docs/chantiers/CHANTIER-performance.md
     * §6). A plain form with data-confirm: the page has to say, before
     * anything is recorded, that every request of every account will be.
     *
     * @param array<string, string> $params
     */
    public function startMeasurement(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }
        if ($this->measurementWindow === null) {
            FlashMessage::set('error', "La mesure n'est pas disponible sur cette installation.");

            return $this->redirect('/config/support');
        }

        $expiresAt = $this->measurementWindow->open(MeasurementWindow::DEFAULT_MINUTES);
        $this->journalService->log(
            'core',
            'measurement_window_opened',
            'info',
            'Fenêtre de mesure des performances ouverte pour ' . MeasurementWindow::DEFAULT_MINUTES . ' minutes',
            ['expires_at' => $expiresAt->format(\DateTimeInterface::ATOM)],
            AuthSession::getUserAccountId()
        );
        FlashMessage::set(
            'success',
            'Mesure en cours jusqu\'à ' . $expiresAt->setTimezone(AppClock::zone())->format('H:i')
                . ' : parcourez les pages qui vous paraissent lentes, puis revenez ici générer un paquet de support'
                . ' et envoyer un ticket.'
        );

        return $this->redirect('/config/support');
    }

    /**
     * POST /config/support/measure/stop — closes it early. What was
     * recorded stays in the journal and goes into the next archive.
     *
     * @param array<string, string> $params
     */
    public function stopMeasurement(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }
        if ($this->measurementWindow !== null && $this->measurementWindow->isOpen()) {
            $this->measurementWindow->close();
            $this->journalService->log(
                'core',
                'measurement_window_closed',
                'info',
                'Fenêtre de mesure des performances fermée, ' . $this->measurementWindow->recordedRequests()
                    . ' requête(s) enregistrée(s)',
                ['requests' => $this->measurementWindow->recordedRequests()],
                AuthSession::getUserAccountId()
            );
        }
        FlashMessage::set('success', 'Mesure arrêtée.');

        return $this->redirect('/config/support');
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
     * The size of the archive currently on disk, or null when there is
     * none. What a consent screen owes a reader alongside the contents:
     * agreeing to transmit « quelque chose » is not agreeing to transmit
     * forty megabytes of it.
     */
    private function currentPackageSizeBytes(): ?int
    {
        $fileId = $this->currentPackageFileId();
        if ($fileId === null || $this->archiveSender === null) {
            return null;
        }

        $record = $this->fileRepository?->findById($fileId);

        return $record !== null ? $record->sizeBytes : null;
    }

    /** Whether the archive of the ticket currently displayed has left. */
    private function archiveTransmitted(): bool
    {
        $last = $this->ticketSender?->lastSent();

        return $last !== null
            && ($this->archiveSender?->wasTransmittedFor($last['reference']) ?? false);
    }

    /**
     * POST /config/support/ticket/archive — the second, separate call
     * (roadmap IT-26).
     *
     * The acknowledgement is verified **here**, on the server: a checkbox
     * enforced only in the browser is a decoration, and this one is the
     * whole consent.
     *
     * @param array<string, string> $params
     */
    public function sendArchive(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }

        $last = $this->ticketSender?->lastSent();
        if ($this->archiveSender === null || $last === null) {
            FlashMessage::set('error', "Aucun ticket récent auquel joindre une archive.");

            return $this->redirect('/config/support');
        }

        $result = $this->archiveSender->send(
            $last['reference'],
            (string) $request->getBody('archive_acknowledged', '') === '1'
        );

        if ($result->sent) {
            FlashMessage::set('success', sprintf(
                'Archive transmise et rattachée au ticket %s.',
                $last['reference']
            ));
        } else {
            FlashMessage::set('error', self::archiveFailureMessage((string) $result->failureReason));
        }

        return $this->redirect('/config/support');
    }

    /**
     * The French reading of an archive that did not leave.
     *
     * « Le ticket est intact » is in every one of them on purpose: the
     * separate call exists so a failed upload costs nothing, and an
     * administrator reading an error has to know that before deciding
     * whether to start over.
     */
    private static function archiveFailureMessage(string $reason): string
    {
        return match ($reason) {
            SupportArchiveSender::FAILURE_NOT_ACKNOWLEDGED =>
                "Cochez la case de transmission pour envoyer l'archive. Le ticket est intact.",
            SupportArchiveSender::FAILURE_NO_ARCHIVE =>
                "Aucune archive de diagnostic n'est disponible : générez-en une d'abord. Le ticket est intact.",
            SupportArchiveSender::FAILURE_UNREADABLE_ARCHIVE =>
                "L'archive conservée est illisible : générez-en une nouvelle. Le ticket est intact.",
            SupportArchiveSender::FAILURE_REFUSED =>
                "Le serveur de support a refusé l'archive. Le ticket est intact, l'archive n'a pas été transmise.",
            default =>
                "L'archive n'a pas pu être transmise (serveur injoignable ou envoi trop long). Le ticket est intact : "
                    . "vous pouvez réessayer.",
        };
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
            FlashMessage::set('error',
                'Le rapport de test n\'a pas pu être transmis. Motif : ' . (string) $result->reason);
        }

        return $this->redirect('/config/support');
    }

    /**
     * POST /config/support/ticket — one ticket, sent now.
     *
     * Synchronous like the test report beside it, and for the same
     * reason: the whole value is the answer coming back on the next page,
     * and the call is one bounded POST with the transport's own 10 s / 20 s
     * caps.
     *
     * **A failure re-renders rather than redirects.** What the
     * administrator wrote is the one thing this page must not lose, and a
     * redirect carries a flash message but not a form.
     *
     * @param array<string, string> $params
     */
    public function sendTicket(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/support')) !== null) {
            return $guard;
        }

        if ($this->ticketSender === null) {
            FlashMessage::set('error', "L'envoi de tickets n'est pas disponible sur cette installation.");

            return $this->redirect('/config/support');
        }

        $category = trim((string) $request->getBody('ticket_category', ''));
        $description = trim((string) $request->getBody('ticket_description', ''));
        $contactEmail = trim((string) $request->getBody('ticket_contact_email', ''));

        $submitted = [
            'category' => $category,
            'description' => $description,
            'contact_email' => $contactEmail,
        ];

        if ($description === '' || $contactEmail === '' || $category === '') {
            FlashMessage::set('error', 'Complétez la catégorie, la description et l\'adresse de contact.');

            return $this->renderIndex($submitted);
        }

        // **Both boxes, verified here and not only in the browser.** They
        // are the whole consent for the two things that leave beside the
        // message — the diagnostic archive and a test e-mail — and a
        // `required` attribute is a decoration a hand-made POST walks
        // past.
        $archiveAccepted = (string) $request->getBody('archive_acknowledged', '') === '1';
        $probeAccepted = (string) $request->getBody('probe_acknowledged', '') === '1';

        if (!$archiveAccepted || !$probeAccepted) {
            FlashMessage::set(
                'error',
                'Cochez les deux cases : elles couvrent tout ce qui part avec votre message.'
            );

            return $this->renderIndex($submitted);
        }

        $result = $this->ticketSender->send($category, $description, $contactEmail);

        if (!$result->sent) {
            // The reason is a category; the message is French. Neither
            // carries a word of what was typed.
            FlashMessage::set('error', self::ticketFailureMessage((string) $result->failureReason));

            return $this->renderIndex($submitted);
        }

        $reference = (string) $result->reference;

        // Everything below is **after** an accepted ticket, and nothing
        // below may undo it. A failed archive upload or an unreachable
        // relay is worth saying, never worth losing the report over —
        // which is exactly why they are separate calls (§8.48).
        $followUp = array_filter([
            $this->attachArchiveTo($reference),
            $this->probeAfterTicket(),
        ]);

        FlashMessage::set('success', trim(sprintf(
            'Ticket envoyé. Référence : %s. Le mainteneur répondra par e-mail à %s. %s',
            $reference,
            $contactEmail,
            implode(' ', $followUp)
        )));

        return $this->redirect('/config/support');
    }

    /**
     * Joins the diagnostic archive to a ticket that has just been created.
     *
     * One of two paths, and the second is the reason this is not a line in
     * the caller: an installation that has never generated a package would
     * otherwise send a ticket with nothing attached. It gets the
     * generation queued and `Task\SendTicketArchiveHandler` joins the
     * archive when it exists.
     *
     * @return string the sentence to add to the confirmation, or '' when
     *         there is nothing worth saying
     */
    private function attachArchiveTo(string $reference): string
    {
        if ($this->archiveSender === null) {
            return '';
        }

        if ($this->currentPackageFileId() !== null) {
            $sent = $this->archiveSender->send($reference, true);

            return $sent->sent
                ? "L'archive de diagnostic est partie avec lui."
                : "L'archive de diagnostic n'a pas pu être transmise : vous pouvez la renvoyer ci-dessous.";
        }

        $this->schedulerService->scheduleAfter(
            'core',
            GenerateSupportPackageHandler::TASK_KEY,
            0,
            [],
            null,
            AuthSession::getUserAccountId()
        );
        $this->schedulerService->scheduleAfter(
            'core',
            SendTicketArchiveHandler::TASK_KEY,
            SendTicketArchiveHandler::RETRY_SECONDS,
            ['reference' => $reference, 'attempt' => 1]
        );

        return "Aucune archive n'existait : elle est en cours de génération et sera jointe automatiquement.";
    }

    /**
     * Sends the diagnostic mail probe that goes with a ticket.
     *
     * **Always, now.** There used to be an hourly limit on both sides, and
     * a ticket reported within an hour of a previous probe simply carried
     * none — which is the case the probe exists for: somebody writes
     * « mes e-mails ne partent pas » precisely because they have been
     * pressing send. A report whose evidence was dropped to save one
     * message is a report the maintainer cannot answer.
     *
     * Silent on the two ordinary non-events — no probe sender wired, and a
     * receiver that synchronises no mailbox — because neither is something
     * the person who just reported a bug has to act on.
     */
    private function probeAfterTicket(): string
    {
        if ($this->probeSender === null) {
            return '';
        }

        $probe = $this->probeSender->send(new \DateTimeImmutable());
        if ($probe->sent) {
            return "Un e-mail de test est parti vers le support ({$probe->correlationKey}).";
        }

        return match ($probe->failureReason) {
            MailProbeSender::FAILURE_NO_MAILBOX => '',
            // Only a receiver still running a version from before the
            // limit was lifted can answer this now.
            MailProbeSender::FAILURE_RATE_LIMITED =>
                "Aucun e-mail de test : le serveur de support en a déjà reçu un très récemment.",
            MailProbeSender::FAILURE_MAIL_REFUSED =>
                "L'e-mail de test n'est pas parti : votre serveur de messagerie l'a refusé — c'est déjà un diagnostic.",
            default => "L'e-mail de test n'a pas pu partir.",
        };
    }

    /**
     * The French reading of a ticket that did not leave, or that the
     * receiver refused.
     *
     * Every branch says what to do about it. « Échec de l'envoi » alone
     * tells a superadmin nothing they can act on, and this is a page whose
     * whole audience is somebody already stuck.
     */
    private static function ticketFailureMessage(string $reason): string
    {
        return match ($reason) {
            TicketIdentityService::GUARD_NO_DESTINATION =>
                "Aucune destination de support n'est configurée sur cette installation.",
            TicketIdentityService::GUARD_INSECURE_DESTINATION =>
                "La destination du support n'est pas en HTTPS : rien n'est envoyé, le secret d'installation "
                    . "voyagerait en clair.",
            TicketIdentityService::GUARD_NON_PUBLIC_DESTINATION =>
                "La destination du support n'est pas un nom public : rien n'est envoyé.",
            SupportTicketSender::FAILURE_NO_IDENTITY =>
                "L'identité de cette installation n'a pas pu être créée (fichier de secrets indisponible). Votre "
                    . "saisie est conservée ci-dessous.",
            SupportTicketSender::FAILURE_REFUSED =>
                "Le serveur de support a refusé le ticket. Vérifiez la catégorie, puis réessayez. Votre saisie est "
                    . "conservée ci-dessous.",
            SupportTicketSender::FAILURE_MALFORMED_ANSWER =>
                "Le serveur de support a répondu quelque chose d'inattendu. Réessayez plus tard ; votre saisie est "
                    . "conservée ci-dessous.",
            default =>
                "Le serveur de support est injoignable pour le moment. Aucun ticket n'a été créé ; votre saisie est "
                    . "conservée ci-dessous, vous pouvez réessayer.",
        };
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
            'disabled' => 'L\'envoi automatique des statistiques doit être activé pour pouvoir envoyer un rapport de '
                . 'test.',
            'non_public_host' => 'L\'adresse de ce site (paramètre « base_url ») n\'est pas un nom public : aucun '
                . 'rapport n\'est envoyé depuis une installation locale ou de test.',
            'maintenance_in_progress' => 'Une opération de maintenance est en cours : le rapport sera possible une '
                . 'fois terminée.',
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
        $before = DateInput::fromStorage($earlier);
        $after = DateInput::fromStorage($later);

        return $before !== null && $after !== null && $before < $after;
    }

    /**
     * The package's generation stamp, moved onto the clock the rest of
     * the site is rendered on.
     *
     * It is the one timestamp of this page stored as ISO 8601 **UTC**
     * (`Core\Support\SupportPackageService::nowIso()`) rather than as a
     * naive `Europe/Brussels` value like every other column here, so
     * printing it as-is showed an hour that is one or two off the one on
     * the administrator's own clock — and « générée à 06:14 » for an
     * archive built at 08:14 reads as a stale archive. Converted here
     * rather than in Twig: `datetime_fr` formats, it does not travel.
     */
    private static function onTheAppClock(?string $storedUtc): ?string
    {
        $moment = DateInput::fromStorage($storedUtc);

        return $moment?->setTimezone(AppClock::zone())->format('Y-m-d H:i:s');
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
