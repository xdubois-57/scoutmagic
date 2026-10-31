<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;

/** Bounded recovery of automatic sends; every failed attempt stays in history. */
final class DecisionEmailRecoveryService
{
    public const NOTIFICATION_TYPE = 'registration.decision_email_failed';

    public function __construct(
        private DecisionEmailRepository $repository,
        private DecisionEmailService $emails,
        private UserAccountRepository $accounts,
        private ?NotificationService $notifications,
        private JournalService $journal
    ) {
    }

    public function recover(\DateTimeImmutable $now, int $limit): bool
    {
        // Attempt/outcome timestamps are wall-clock dates, like the sender's
        // claim, even when a test supplies a clock for due-date planning.
        $observedAt = new \DateTimeImmutable();
        $this->repository->failStaleAutomaticSending(
            $observedAt->modify('-' . DecisionEmailService::STALE_SENDING_MINUTES . ' minutes'),
            $observedAt,
            "L'envoi a été interrompu : vérifiez auprès de la famille avant de réessayer."
        );
        $processed = 0;
        $retryLimit = count(DecisionEmailRetryPolicy::DELAYS_HOURS);
        foreach ($this->repository->findUnprocessedAutomaticFailures($limit, $retryLimit) as $failure) {
            try {
                $processed += $this->repository->processAutomaticFailure(
                    $failure->id,
                    $now,
                    fn(): bool => $this->recoverOne($failure, $now)
                ) ? 1 : 0;
            } catch (\Throwable $error) {
                // A rolled-back marker leaves this failure eligible next pass.
                $this->journal->log(
                    'registration',
                    'decision_email_recovery_failed',
                    'warning',
                    "La prise en charge d'un échec d'e-mail de décision sera réessayée.",
                    ['email_id' => $failure->id, 'error_class' => $error::class]
                );
            }
        }
        return $processed >= $limit;
    }

    private function recoverOne(DecisionEmail $failure, \DateTimeImmutable $now): bool
    {
        // Re-check after taking the marker: a manual retry or a change of
        // decision may have won since the poller's read.
        $latestId = 0;
        foreach ($this->repository->findForRequest($failure->requestId) as $email) {
            if ($email->isCurrent()) {
                $latestId = max($latestId, $email->id);
            }
        }
        $request = $this->repository->recoveryContext($failure->requestId);
        if ($latestId !== $failure->id || $request === null
            || DecisionEmail::decisionForStatus($request['status']) !== $failure->decision
        ) {
            return true;
        }

        $delay = DecisionEmailRetryPolicy::DELAYS_HOURS[$failure->automaticRetryCount] ?? null;
        if ($failure->failureCode === DecisionEmail::FAILURE_MAIL_TEMPORARY && $delay !== null) {
            $retry = $this->emails->scheduleDecision(
                $failure->requestId,
                $failure->decision,
                $now->modify('+' . $delay . ' hours')
            );
            if ($retry !== null) {
                $this->repository->setAutomaticRetryCount($retry->id, $failure->automaticRetryCount + 1);
            }
            return true;
        }

        if ($this->notifications === null) {
            return false;
        }
        $recipients = array_map(
            static fn(int $id): array => ['userAccountId' => $id, 'memberId' => null],
            $this->accounts->findStaffAndSuperAdminIds([$request['scout_year_id']], ['admin'])
        );
        if ($recipients === []) {
            return false;
        }
        $this->notifications->dispatch(self::NOTIFICATION_TYPE, $recipients, [
            'title' => 'E-mail de décision à vérifier',
            'body' => 'Un e-mail de décision nécessite une intervention. '
                . 'Vérifiez sa réception et la fiche avant de réessayer.',
            'url' => '/config/inscriptions/demandes/' . $failure->requestId,
        ]);
        return true;
    }
}
