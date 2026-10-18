<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Task;

use Core\Config\ScoutYearService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RequestEmailService;

/**
 * The automatic sending of the decision e-mails (issue #843, D7.1 and
 * IT-09): every e-mail planned when a decision was taken
 * (Service\AutomaticDecisionEmailService::plan()) leaves here once its due
 * date has come — in batches, like Task\SendReenrollmentEmailsHandler.
 *
 * **Each due e-mail is claimed before it is sent, by its own row**
 * (RequestEmailService::sendScheduled(): `scheduled` → `sending`, a
 * conditional UPDATE that one caller only can win). So:
 *
 * - two passes that overlap, or a pass racing a chief's « Envoyer
 *   maintenant », send it once;
 * - **a pass interrupted mid-batch resumes without sending twice**: the
 *   scheduler replays the whole run (it marks a task done only after
 *   handle() returns), and the replay reads the due e-mails again — the
 *   ones already sent are `sent`, the one whose transport was cut off is
 *   `sending`, and neither is due any more. That last one is never resent
 *   on its own: whether it left is unknown, and the fiche shows it
 *   « Envoi interrompu », with « Réessayer », once it is stale;
 * - an e-mail cancelled by a change of decision is never due (the change
 *   supersedes it in the same transaction as the status), and one whose
 *   claim races that change loses it.
 *
 * **A body that lost its tracking link is a failure, never a success**: the
 * attempt is recorded failed (`body_not_written`) and the family still
 * sees « en attente ». The e-mail carries the request's own tracking link,
 * read back and never renewed (issue #932), and the unit is in copy as for
 * a manual send (D9) — the same path, RequestEmailService::deliverAttempt().
 *
 * **It runs whatever the switch says**: switching the automatic sending
 * off cancels nothing (D7), so e-mails planned before still leave at their
 * due date. Only the planning reads the switch.
 *
 * **A recurring poller, re-armed for the next due date.** It re-arms
 * itself (rearm(), in a `finally`, so a failed run does not end the chain)
 * for the earliest due date still scheduled, and never later than an
 * hour: the delay is at least an hour, so an e-mail planned after this
 * run is never due before the next one. A full batch re-arms at once.
 *
 * **Nothing about a family reaches the journal**: the run counts what it
 * sent, what failed and what was not its to send; each send journals
 * itself with the request id only.
 */
class SendDecisionEmailsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_decision_emails';
    public const REFERENCE = 'poll';

    /** E-mails per run. Small enough to finish inside a page request. */
    public const BATCH_SIZE = 20;

    /** The longest wait between two runs — the shortest delay a unit can set. */
    public const MAX_INTERVAL_SECONDS = 3600;

    /**
     * @param \DateTimeImmutable|null $now The moment a test runs at; the
     *        real one otherwise (the scheduler builds handlers with no
     *        argument).
     */
    public function __construct(private ?\DateTimeImmutable $now = null)
    {
    }

    /**
     * The first run of the chain, from an entry point (public/
     * scheduler-bootstrap.php). Idempotent: seed() adds nothing while the
     * chain is alive.
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->seed('registration', self::TASK_KEY, self::REFERENCE, new \DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $decisionEmailRepository = new DecisionEmailRepository($pdo);
        $decisionEmails = new DecisionEmailService($decisionEmailRepository, $context->journal);
        $full = false;

        try {
            $due = $decisionEmails->findDue(self::BATCH_SIZE, $this->now ?? new \DateTimeImmutable());
            $full = count($due) >= self::BATCH_SIZE;
            if ($due === []) {
                return;
            }

            $requests = new RegistrationRequestRepository(
                $pdo,
                $context->encryption,
                null,
                $decisionEmailRepository
            );
            $emailService = new RequestEmailService(
                $requests,
                $context->mailService,
                new EditableContentService(new EditableContentRepository($pdo)),
                $context->journal,
                (string) ($context->settings->get('base_url') ?: ''),
                (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
                $decisionEmails,
                new FamilyEmailCopyService($context->settings, $context->journal)
            );
            $scoutYears = new ScoutYearService($pdo);

            $counts = [
                RequestEmailService::SCHEDULED_SENT => 0,
                RequestEmailService::SCHEDULED_FAILED => 0,
                RequestEmailService::SCHEDULED_SKIPPED => 0,
            ];
            // The label each e-mail names, read once per scout year.
            $labels = [];
            $yearLabel = static function (int $scoutYearId) use ($scoutYears, &$labels): string {
                return $labels[$scoutYearId] ??= (string) ($scoutYears->findById($scoutYearId)['label'] ?? '');
            };
            foreach ($due as $email) {
                $counts[$emailService->sendScheduled($email, $yearLabel)]++;
            }

            $context->journal->log(
                'registration',
                'registration_decision_emails_auto_sent',
                'info',
                'Envoi automatique des e-mails de décision',
                [
                    'sent' => $counts[RequestEmailService::SCHEDULED_SENT],
                    'failed' => $counts[RequestEmailService::SCHEDULED_FAILED],
                    'skipped' => $counts[RequestEmailService::SCHEDULED_SKIPPED],
                ]
            );
        } finally {
            SchedulerService::forPdo($pdo)->rearm(
                'registration',
                self::TASK_KEY,
                self::REFERENCE,
                $full ? new \DateTimeImmutable() : self::nextRun($decisionEmails->nextDueAt(), new \DateTimeImmutable())
            );
        }
    }

    /**
     * When the next run should happen: the earliest due date still
     * scheduled, never before now and never later than an hour from now.
     */
    public static function nextRun(?\DateTimeImmutable $earliestDue, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $latest = $now->modify('+' . self::MAX_INTERVAL_SECONDS . ' seconds');
        if ($earliestDue === null || $earliestDue > $latest) {
            return $latest;
        }

        return $earliestDue < $now ? $now : $earliestDue;
    }
}
