<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Config\ScoutYearService;
use Core\Mail\MailException;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Service\DateInput;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Service\ReenrollmentRecipientService;

/**
 * Sends one of the campaign's four e-mails, in batches.
 *
 * **In batches, and re-scheduling the rest.** A shared host gives a page
 * request a short execution budget, and a unit of two hundred families is
 * two hundred SMTP round trips; the same reasoning as
 * `Core\Notification\Task\SendNotificationEmailsHandler`. The cursor is
 * the smallest member id of the last FAMILY handled — never a raw member
 * id — so a family of three is never split across two batches and never
 * receives two messages.
 *
 * **The recipient list is recomputed on every batch, deliberately.** A
 * family who answers between two batches drops out of the remaining
 * reminders, which is exactly right: chasing somebody who has just
 * answered is the fastest way to teach a unit's families to ignore these
 * e-mails. Recomputing also means the list never lives in
 * `scheduled_actions.payload`, where a set of addresses would be personal
 * data sitting in a queue table.
 *
 * **Nothing about a recipient reaches the journal**: the run logs how many
 * families it wrote to, never to whom.
 */
class SendReenrollmentEmailsHandler implements TaskHandlerInterface
{
    /** Families per run. Small enough to finish inside a page request. */
    private const BATCH_SIZE = 25;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $type = (string) ($payload['type'] ?? '');
        $campaignKey = (string) ($payload['campaign'] ?? '');
        $afterKey = (int) ($payload['after_key'] ?? 0);

        if ($type === '' || $campaignKey === '') {
            return;
        }

        $pdo = $context->connection->getPdo();
        $scoutYearService = new ScoutYearService($pdo);
        $campaign = ReenrollmentCampaignHandler::campaignService($context);

        $publicYear = (new \Core\ScoutYear\ScoutYearResolver(
            $scoutYearService,
            $context->settings,
            new \Core\Import\MemberYearRepository($pdo)
        ))->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel((string) $publicYear['label']);
        $targetYearId = $scoutYearService->ensureYear($targetLabel);

        $recipients = new ReenrollmentRecipientService(
            $pdo,
            $context->encryption,
            new \Modules\Registration\Repository\ReenrollmentRepository($pdo, $context->encryption),
            new \Modules\Registration\Service\PassageService(
                $pdo,
                $context->encryption,
                new \Core\Member\SectionService(
                    $context->connection,
                    $context->encryption,
                    new \Core\Badge\MemberBadgeRepository($pdo)
                ),
                new \Modules\Registration\Repository\SectionTransferRepository($pdo),
                new \Modules\Registration\Repository\RegistrationRequestRepository($pdo, $context->encryption),
                new \Modules\Registration\Repository\AgeBracketRepository($pdo)
            )
        );

        // Only the opening e-mail goes to everybody; the three that follow
        // are owed to whoever still has a child without an answer.
        $silentOnly = $type !== ReenrollmentCampaignService::EMAIL_OPENING;

        $families = $recipients->pendingFamilies(
            (int) $publicYear['id'],
            $targetYearId,
            $silentOnly,
            $afterKey,
            self::BATCH_SIZE
        );

        if ($families === []) {
            $campaign->markDone(ReenrollmentCampaignService::emailMarker($type), $campaignKey);

            return;
        }

        $renderer = self::renderer($context);
        $baseUrl = rtrim((string) ($context->settings->get('base_url') ?: ''), '/');
        $closeDate = DateInput::parse('!Y-m-d', $campaignKey);

        $sent = 0;
        $lastKey = $afterKey;
        foreach ($families as $family) {
            $lastKey = $family['key'];

            try {
                $email = $renderer->render('registration.reenrollment_' . $type, [
                    'site_name' => (string) ($context->settings->get('site_name') ?: 'Notre unité'),
                    'target_year_label' => $targetLabel,
                    'close_date' => $closeDate !== null ? $closeDate->format('d/m/Y') : $campaignKey,
                    'reenrollment_url' => $baseUrl . '/reinscription',
                    'member_names' => $family['member_names'],
                ]);

                $context->mailService->send(
                    $family['email'],
                    $email->subject,
                    $email->bodyHtml,
                    $email->bodyText
                );
                $sent++;
            } catch (MailException) {
                // One bad address must never stop the rest of the unit
                // from being written to, and the address itself never
                // reaches the journal.
            }
        }

        $context->journal->log(
            'registration',
            'reenrollment_emails_sent',
            'info',
            'Envoi de la campagne de réinscription',
            ['type' => $type, 'campaign' => $campaignKey, 'families' => $sent]
        );

        $scheduler = new SchedulerService(new SchedulerRepository($pdo));
        if (count($families) < self::BATCH_SIZE) {
            $campaign->markDone(ReenrollmentCampaignService::emailMarker($type), $campaignKey);

            return;
        }

        // More to do: the reference carries the cursor so a re-run of the
        // same batch cannot be queued twice.
        $scheduler->schedule(
            'registration',
            'send_reenrollment_emails',
            new \DateTimeImmutable(),
            ['type' => $type, 'campaign' => $campaignKey, 'after_key' => $lastKey],
            $type . ':' . $campaignKey . ':' . $lastKey
        );
    }

    /**
     * Core's templates plus this module's own — a handler runs outside the
     * composition root, so nothing has aggregated the manifests for it
     * (ARCHITECTURE.md §8.7bis). A customisation is honoured all the same:
     * that lives in the database, not in the registry.
     */
    private static function renderer(TaskContext $context): \Core\Mail\Template\EmailTemplateRenderer
    {
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json')
        );

        return new \Core\Mail\Template\EmailTemplateRenderer(
            \Core\View\TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['registration' => dirname(__DIR__, 2) . '/views']
            ),
            $registry,
            new \Core\Mail\Template\EmailTemplateOverrideRepository($context->connection->getPdo()),
            $context->journal
        );
    }
}
