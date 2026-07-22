<?php

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Mail\MailException;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Service\MassMailService;

/**
 * The one and only task type mass_mail ever schedules (module spec —
 * explicitly never one job per recipient). Each run pulls the oldest
 * `batch_size` 'pending' recipients across every email combined (FIFO,
 * Repository\RecipientRepository::findOldestPending()), sends each via
 * Core\Mail\MailService, and — as long as any 'pending' row remains
 * anywhere — reschedules itself `batch_interval_minutes` later. Both
 * settings are GLOBAL (module spec), never per-email.
 */
class SendBatchHandler implements TaskHandlerInterface
{
    private const SETTING_BATCH_SIZE = 'batch_size';
    private const SETTING_BATCH_INTERVAL_MINUTES = 'batch_interval_minutes';
    private const DEFAULT_BATCH_SIZE = 20;
    private const DEFAULT_BATCH_INTERVAL_MINUTES = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $recipientRepository = new RecipientRepository($pdo, $context->encryption);
        $emailRepository = new EmailRepository($pdo);
        $attachmentRepository = new EmailAttachmentRepository($pdo);
        $fileRepository = new \Core\File\FileRepository($pdo);
        $massMailService = $this->buildMassMailService($context);

        $batchSize = (int) $context->settings->get(self::SETTING_BATCH_SIZE, 'mass_mail', (string) self::DEFAULT_BATCH_SIZE);
        if ($batchSize <= 0) {
            $batchSize = self::DEFAULT_BATCH_SIZE;
        }

        $batch = $recipientRepository->findOldestPending($batchSize);

        $sentCount = 0;
        $errorCount = 0;
        $touchedEmailIds = [];
        $senderIdentityBySection = [];

        foreach ($batch as $recipient) {
            $touchedEmailIds[$recipient->emailId] = true;

            $email = $emailRepository->findById($recipient->emailId);
            if ($email === null || $recipient->emailAddress === null) {
                // A frozen recipient row is only ever created with a null
                // address alongside status 'error' (never 'pending') — this
                // branch is defensive, not an expected path.
                $recipientRepository->recordSendFailure($recipient->id, 'Adresse invalide');
                $errorCount++;
                continue;
            }

            $attachments = [];
            foreach ($attachmentRepository->findByEmailId($email->id) as $attachment) {
                $file = $fileRepository->findById($attachment->fileId);
                if ($file !== null) {
                    $attachments[] = ['path' => $context->storagePath . '/' . $file->relativePath, 'name' => $file->originalName];
                }
            }

            // Cached per batch — several recipients typically share the
            // same email/sender section.
            if (!isset($senderIdentityBySection[$email->sectionId])) {
                $senderIdentityBySection[$email->sectionId] = $massMailService->resolveSenderIdentity($email->sectionId);
            }
            $sender = $senderIdentityBySection[$email->sectionId];

            try {
                $context->mailService->send(
                    $recipient->emailAddress,
                    $email->subject,
                    $email->bodyHtml,
                    strip_tags($email->bodyHtml),
                    null,
                    $attachments,
                    $sender['address'],
                    $sender['name']
                );
                $recipientRepository->recordSendSuccess($recipient->id);
                $sentCount++;
            } catch (MailException $e) {
                // $e->getMessage() is a transport-level error (SMTP
                // response, connection failure) — never contains the
                // recipient's own address, so it's safe to persist as-is.
                $recipientRepository->recordSendFailure($recipient->id, $e->getMessage());
                $errorCount++;
            }
        }

        foreach (array_keys($touchedEmailIds) as $emailId) {
            $massMailService->checkAndMarkSentIfComplete($emailId);
        }

        if ($batch !== []) {
            $context->journal->log(
                'mass_mail', 'batch_sent', 'info', 'Lot d\'emails de masse envoyé',
                ['sent' => $sentCount, 'errors' => $errorCount, 'batch_size' => $batchSize], null
            );
        }

        $this->rescheduleIfPendingRemain($context, $recipientRepository);
    }

    private function rescheduleIfPendingRemain(TaskContext $context, RecipientRepository $recipientRepository): void
    {
        if ($recipientRepository->findOldestPending(1) === []) {
            return;
        }

        $intervalMinutes = (int) $context->settings->get(
            self::SETTING_BATCH_INTERVAL_MINUTES, 'mass_mail', (string) self::DEFAULT_BATCH_INTERVAL_MINUTES
        );
        if ($intervalMinutes <= 0) {
            $intervalMinutes = self::DEFAULT_BATCH_INTERVAL_MINUTES;
        }

        $schedulerService = new SchedulerService(new \Core\Scheduler\SchedulerRepository($context->connection->getPdo()));
        $schedulerService->scheduleAfter('mass_mail', 'send_batch', $intervalMinutes * 60);
    }

    private function buildMassMailService(TaskContext $context): MassMailService
    {
        $pdo = $context->connection->getPdo();
        $sectionService = new \Core\Member\SectionService(
            $context->connection,
            $context->encryption,
            new \Core\Badge\MemberBadgeRepository($pdo)
        );

        return new MassMailService(
            new EmailRepository($pdo),
            new RecipientRepository($pdo, $context->encryption),
            new EmailAttachmentRepository($pdo),
            new \Core\File\FileRepository($pdo),
            new \Modules\MassMail\Service\MailingListService(
                new \Modules\MassMail\Repository\MailingListRepository($pdo),
                new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $context->encryption),
                $sectionService,
                new \Core\Import\FunctionRepository($pdo)
            ),
            new \Core\Member\MemberService(new \Core\Import\MemberYearRepository($pdo), $context->encryption, $context->connection),
            $sectionService,
            $context->mailService,
            new SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo)),
            $context->journal,
            new \Core\Security\HtmlSanitizer(),
            new \Core\Config\ScoutYearService($pdo),
            new \Core\Import\ImportJournalRepository($pdo),
            $context->storagePath
        );
    }
}
