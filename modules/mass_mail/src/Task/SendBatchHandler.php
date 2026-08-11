<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Import\MemberYearRepository;
use Core\Mail\MailException;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\Recipient;
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

            // One-click unsubscribe (module addendum, RFC 8058) — a fresh
            // token generated right before this actual send (never at
            // freeze time in MassMailService::startSending(), which only
            // resolves member_email_id; there'd be nowhere safe to hold a
            // raw token between freezing and sending), same generation/
            // hashing convention as Core\Security\AuthService's magic
            // links. Every send gets the footer link + both headers,
            // regardless of whether this recipient's address maps to the
            // Desk-imported email or a secondary one — Modules\MassMail\
            // Controller\UnsubscribeController resolves the right
            // Core\Member\MemberEmail row from recipient->memberEmailId.
            $rawUnsubscribeToken = bin2hex(random_bytes(32));
            $recipientRepository->setUnsubscribeTokenHash($recipient->id, password_hash($rawUnsubscribeToken, PASSWORD_DEFAULT));
            $unsubscribeUrl = rtrim((string) $context->settings->get('base_url'), '/')
                . '/mass-mail/unsubscribe/' . $recipient->id . '?token=' . $rawUnsubscribeToken;

            $bodyHtml = $email->bodyHtml
                . '<hr><p style="font-size:12px;color:#999;">Vous recevez cet email en tant que membre de l\'unité. '
                . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Se désinscrire des emails groupés</a>.</p>';
            $bodyText = strip_tags($email->bodyHtml)
                . "\n\n---\nVous recevez cet email en tant que membre de l'unité.\nSe désinscrire des emails groupés : " . $unsubscribeUrl;

            try {
                $context->mailService->send(
                    $recipient->emailAddress,
                    $email->subject,
                    $bodyHtml,
                    $bodyText,
                    null,
                    $attachments,
                    $sender['address'],
                    $sender['name'],
                    [
                        'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ]
                );
                $recipientRepository->recordSendSuccess($recipient->id);
                $sentCount++;
                $this->dispatchEmailReceivedNotification($context, $recipient, $email);
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

    /**
     * Notification centre + push (module.json's "mass_mail.email_received"
     * — push default-on, email channel forced off since a redundant
     * "you got an email" email would defeat the point). Only fires when
     * the recipient's plaintext address matches an existing login
     * account — most recipients are scouts with no account of their own,
     * so this is a targeted single-recipient dispatch, not a broadcast
     * (unlike the calendar/gallery modules' "every identified member"
     * notifications, which have no such natural single-recipient
     * resolution). The deep link resolves the member_year row for this
     * recipient's own snapshot scout year, matching MemberEmailController's
     * `/members/{member_year_id}/emails/{recipient_id}` route.
     */
    private function dispatchEmailReceivedNotification(TaskContext $context, Recipient $recipient, Email $email): void
    {
        if ($context->notifications === null || $recipient->emailAddress === null) {
            return;
        }

        $account = $context->userAccounts->findByEmail($recipient->emailAddress);
        if ($account === null) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $memberYear = (new MemberYearRepository($pdo))->findByMemberAndYear($recipient->memberId, $recipient->scoutYearId);
        $url = $memberYear !== null ? '/members/' . $memberYear['id'] . '/emails/' . $recipient->id : null;

        $context->notifications->dispatch('mass_mail.email_received', [
            ['userAccountId' => $account->id, 'memberId' => $recipient->memberId],
        ], [
            'title' => 'Nouvel email',
            'body' => $email->subject,
            'url' => $url,
        ], $email->createdBy);
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

        $memberService = new \Core\Member\MemberService(new \Core\Import\MemberYearRepository($pdo), $context->encryption, $context->connection);
        $scoutYearService = new \Core\Config\ScoutYearService($pdo);

        // No module namespace needed — Core\Member\MemberEmailService only
        // ever renders core's own email/member_email_confirmation.html.twig
        // from this reconstruction path (via resolveValidAddressesForMassMail()
        // → findOrCreateDeskOverride(), which never actually sends mail —
        // $sectionService/$memberService/$scoutYearService are only here
        // to satisfy the constructor, unsubscribe() is never reached from
        // this particular call path). Same TwigFactory::create()
        // task-context pattern as Modules\News\Task\SendResponseDigestHandler.
        $twig = \Core\View\TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        $memberEmailService = new \Core\Member\MemberEmailService(
            new \Core\Member\MemberEmailRepository($pdo, $context->encryption),
            $context->mailService,
            $twig,
            $context->journal,
            $sectionService,
            $memberService,
            $scoutYearService,
            (string) $context->settings->get('base_url'),
            (string) ($context->settings->get('site_name') ?: 'Unité scoute')
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
            $memberService,
            $memberEmailService,
            $sectionService,
            $context->mailService,
            new SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo)),
            $context->journal,
            new \Core\Security\HtmlSanitizer(),
            $scoutYearService,
            new \Core\Import\ImportJournalRepository($pdo),
            $context->storagePath
        );
    }
}
