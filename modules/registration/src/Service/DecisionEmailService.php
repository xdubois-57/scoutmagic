<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * The life of a request's acceptance and refusal e-mails (issue #843, D8):
 * planned, attempted, sent, failed, resent — every step a row of
 * `registration_decision_emails`, none of them ever rewriting an earlier
 * one. Service\RequestEmailService is the one sender, and goes through
 * begin() / recordSuccess() / recordFailure() around the transport.
 *
 * What it does NOT do is cancel: an e-mail becomes obsolete when the
 * request's decision changes, and RegistrationRequestRepository::
 * updateStatus() supersedes it in the same transaction as the status
 * itself, whichever path changed it.
 *
 * Planning with a delay (schedule(), scheduleDecision()) is the automatic
 * sending's (IT-09): Service\AutomaticDecisionEmailService owns the
 * setting and the delay and plans the e-mail when a decision is taken,
 * Task\SendDecisionEmailsHandler consumes the due ones (findDue(), then
 * RequestEmailService::sendScheduled(), which claims each). A send a chief
 * starts by hand is created already claimed, with no due date.
 */
class DecisionEmailService
{
    /**
     * How long a claimed send may stay unreported before begin() takes it
     * for a process that died mid-send. An SMTP round trip takes seconds
     * and a page request is cut off well before this.
     */
    public const STALE_SENDING_MINUTES = 10;

    private const INTERRUPTED_MESSAGE = "L'envoi a été interrompu avant d'aboutir : il n'est pas certain que la "
        . 'famille ait reçu cet e-mail.';

    private const IN_FLIGHT_MESSAGE = "Un envoi de cet e-mail est déjà en cours : rechargez la page dans quelques "
        . 'instants pour voir son résultat.';

    private const NOT_STARTED_MESSAGE = "L'e-mail n'a pas pu être lancé : la décision vient de changer, ou un autre "
        . 'envoi est en cours. Rechargez la page.';

    public function __construct(
        private DecisionEmailRepository $repository,
        private JournalService $journalService
    ) {
    }

    /**
     * Plans the e-mail of the request's current decision, due at $dueAt.
     * Null when the request holds no decision any more, or already has an
     * e-mail in play — never a second one.
     */
    public function schedule(RegistrationRequest $request, \DateTimeImmutable $dueAt): ?DecisionEmail
    {
        $decision = DecisionEmail::decisionForStatus($request->status);
        if ($decision === null) {
            return null;
        }

        return $this->scheduleDecision($request->id, $decision, $dueAt);
    }

    /**
     * Plans the $decision e-mail of request $requestId, due at $dueAt — for
     * a caller that has just taken that decision and holds the request as
     * it was read before (Service\AutomaticDecisionEmailService::plan()).
     * The status is checked by the insert itself, in the same statement:
     * null when the request does not hold $decision (any more), or already
     * has an e-mail in play.
     */
    public function scheduleDecision(int $requestId, string $decision, \DateTimeImmutable $dueAt): ?DecisionEmail
    {
        [$attempt, $isResend] = $this->nextAttempt($requestId, $decision);
        $id = $this->repository->insert(
            $requestId,
            $decision,
            DecisionEmail::STATE_SCHEDULED,
            $dueAt,
            $attempt,
            $isResend,
            new \DateTimeImmutable()
        );
        if ($id === null) {
            return null;
        }

        $this->journalService->log(
            'registration',
            'registration_decision_email_scheduled',
            'info',
            'E-mail de décision planifié',
            ['request_id' => $requestId, 'decision' => $decision, 'due_at' => $dueAt->format('Y-m-d H:i:s')]
        );

        return $this->repository->findById($id);
    }

    /**
     * Moves a still-scheduled e-mail to $dueAt. False when it is no longer
     * scheduled — claimed, cancelled by a decision change — and nothing
     * changed.
     */
    public function reschedule(int $emailId, \DateTimeImmutable $dueAt): bool
    {
        if (!$this->repository->reschedule($emailId, $dueAt, new \DateTimeImmutable())) {
            return false;
        }

        $this->journalService->log(
            'registration',
            'registration_decision_email_rescheduled',
            'info',
            'E-mail de décision reprogrammé',
            ['email_id' => $emailId, 'due_at' => $dueAt->format('Y-m-d H:i:s')]
        );

        return true;
    }

    /**
     * Opens one attempt at sending the $decision e-mail of $request, and
     * hands it back CLAIMED: whoever holds it is the only caller that will
     * send it. The planned e-mail is taken when one is scheduled; otherwise
     * a new one is created already claimed — a retry after a failure, or a
     * resend after a success, each a row of its own.
     *
     * @throws RegistrationException when the request does not hold that
     *         decision, or another send of it is already in flight
     */
    public function begin(RegistrationRequest $request, string $decision): DecisionEmail
    {
        if (DecisionEmail::decisionForStatus($request->status) !== $decision) {
            throw new RegistrationException(
                $decision === DecisionEmail::DECISION_REFUSED
                    ? "Cette demande n'est pas refusée : l'e-mail de refus ne peut pas partir."
                    : "Cette demande n'est pas acceptée : l'e-mail d'acceptation ne peut pas partir."
            );
        }

        $now = new \DateTimeImmutable();
        $this->recoverInterruptedSend($request->id, $now);

        $active = $this->repository->findActiveForRequest($request->id);
        if ($active !== null) {
            if ($active->state === DecisionEmail::STATE_SCHEDULED
                && $active->decision === $decision
                && $this->repository->claim($active->id, $now)
            ) {
                return $this->reload($active->id);
            }

            throw new RegistrationException(self::IN_FLIGHT_MESSAGE);
        }

        [$attempt, $isResend] = $this->nextAttempt($request->id, $decision);
        $id = $this->repository->insert(
            $request->id,
            $decision,
            DecisionEmail::STATE_SENDING,
            null,
            $attempt,
            $isResend,
            $now
        );
        if ($id === null) {
            throw new RegistrationException(self::NOT_STARTED_MESSAGE);
        }

        return $this->reload($id);
    }

    /**
     * Takes a scheduled e-mail for sending, by id — what the automatic
     * sending does for each e-mail findDue() returns. True for exactly one
     * of any number of concurrent callers.
     */
    public function claim(int $emailId): bool
    {
        return $this->repository->claim($emailId, new \DateTimeImmutable());
    }

    /**
     * The scheduled e-mails due at $now (by default, now), oldest first.
     *
     * @return array<int, DecisionEmail>
     */
    public function findDue(int $limit, ?\DateTimeImmutable $now = null): array
    {
        return $this->repository->findDue($now ?? new \DateTimeImmutable(), $limit);
    }

    /**
     * When the next scheduled e-mail is due, or null when none is.
     */
    public function nextDueAt(): ?\DateTimeImmutable
    {
        return $this->repository->findEarliestDueAt();
    }

    /**
     * How many e-mails are scheduled and still waiting for their due date.
     */
    public function countScheduled(): int
    {
        return $this->repository->countScheduled();
    }

    /**
     * The claimed attempt went out. False when nothing could be recorded —
     * the row was settled by somebody else meanwhile — which the caller
     * journals, since the e-mail left all the same.
     */
    public function recordSuccess(DecisionEmail $email): bool
    {
        return $this->repository->markSent($email->id, new \DateTimeImmutable());
    }

    /**
     * The claimed attempt did not go out. $message is a French sentence a
     * chief may read: pass it through Core\Exception\UserFacingMessage
     * when it comes from an exception — the row keeps it, and a template
     * will show it later.
     */
    public function recordFailure(DecisionEmail $email, string $failureCode, string $message): void
    {
        $this->repository->markFailed($email->id, $failureCode, $message, new \DateTimeImmutable());
    }

    /**
     * Every decision e-mail of a request, oldest first — the history the
     * fiche shows (IT-08).
     *
     * @return array<int, DecisionEmail>
     */
    public function history(int $requestId): array
    {
        return $this->repository->findForRequest($requestId);
    }

    /**
     * A send claimed long ago and never reported back is recorded as
     * interrupted, so it stops blocking the request. Never resent from
     * here: whether it left is unknown, and the chief who starts the next
     * send is told so by the history.
     */
    private function recoverInterruptedSend(int $requestId, \DateTimeImmutable $now): void
    {
        $recovered = $this->repository->failStaleSending(
            $requestId,
            $now->modify('-' . self::STALE_SENDING_MINUTES . ' minutes'),
            self::INTERRUPTED_MESSAGE,
            $now
        );

        if ($recovered > 0) {
            $this->journalService->log(
                'registration',
                'registration_decision_email_interrupted',
                'warning',
                "Envoi d'un e-mail de décision interrompu avant d'aboutir",
                ['request_id' => $requestId]
            );
        }
    }

    /**
     * The attempt number and the resend flag of the next e-mail of this
     * decision: one more than its earlier tries, and a resend once one of
     * them went out. Only the current decision's rows count — a superseded
     * one belongs to another decision, and that includes every cancelled
     * row, since only a change of decision cancels.
     *
     * @return array{0: int, 1: bool}
     */
    private function nextAttempt(int $requestId, string $decision): array
    {
        $tries = array_filter(
            $this->repository->findForRequest($requestId),
            static fn(DecisionEmail $email): bool => $email->isCurrent() && $email->decision === $decision
        );
        $sent = array_filter(
            $tries,
            static fn(DecisionEmail $email): bool => $email->state === DecisionEmail::STATE_SENT
        );

        return [count($tries) + 1, $sent !== []];
    }

    private function reload(int $emailId): DecisionEmail
    {
        $email = $this->repository->findById($emailId);
        if ($email === null) {
            throw new \LogicException('A decision e-mail vanished right after it was claimed.');
        }

        return $email;
    }
}
