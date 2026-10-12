<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

use Core\Service\DateInput;

/**
 * `registration_decision_emails` — every acceptance or refusal e-mail a
 * request was planned or tried with (issue #843, D8). See schema.sql for
 * the model: one row per e-mail, a row belongs to one decision
 * (`superseded_at`), and `active_request_id` is the claim.
 *
 * **No row in a final state is ever rewritten.** Every write below is a
 * conditional UPDATE naming the state it moves from — 'scheduled' to
 * 'sending' (the claim), 'sending' to 'sent' or 'failed' — so a row that
 * has been sent, has failed or was cancelled stays exactly what it was,
 * and a retry or a resend is a row of its own. The one write that touches
 * a final row is supersedeForRequest(), and it only dates the moment that
 * row stopped describing the request's decision.
 *
 * Nothing here is personal data: an id, a decision, dates, and a French
 * sentence written for a chief. The address the e-mail went to is the
 * request's, decrypted only by RegistrationRequestRepository.
 */
class DecisionEmailRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?DecisionEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_decision_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * The whole history of one request's decision e-mails, oldest first —
     * superseded rows included, since what was sent for an earlier
     * decision is still something that happened.
     *
     * @return array<int, DecisionEmail>
     */
    public function findForRequest(int $requestId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM registration_decision_emails WHERE registration_request_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$requestId]);

        return array_map(static fn(array $row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The one e-mail of this request still in play — scheduled or being
     * sent — or null. There is never more than one: that is what the
     * UNIQUE index on `active_request_id` holds.
     */
    public function findActiveForRequest(int $requestId): ?DecisionEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registration_decision_emails WHERE active_request_id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? self::hydrate($row) : null;
    }

    /**
     * The scheduled e-mails due at $now, oldest due first — what the
     * automatic sending (IT-09) consumes, over the (state, due_at) index.
     * A row with no due date is a manual send and is never due.
     *
     * @return array<int, DecisionEmail>
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registration_decision_emails
             WHERE state = 'scheduled' AND due_at IS NOT NULL AND due_at <= ?
             ORDER BY due_at ASC, id ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, self::format($now));
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(array $row) => self::hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The earliest due date among the scheduled e-mails, or null when none
     * is scheduled — when the automatic sending (Task\
     * SendDecisionEmailsHandler) next has something to do.
     */
    public function findEarliestDueAt(): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            "SELECT MIN(due_at) FROM registration_decision_emails WHERE state = 'scheduled' AND due_at IS NOT NULL"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return DateInput::fromStorage(is_string($value) ? $value : null);
    }

    /**
     * How many e-mails are scheduled and not yet claimed — what the
     * « Décisions automatiques » box warns about when the automatic
     * sending is switched off: those still leave at their due date.
     */
    public function countScheduled(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM registration_decision_emails WHERE state = 'scheduled'");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Creates one e-mail of $decision for a request, in state 'scheduled'
     * (a planned send) or 'sending' (a send claimed by its own creator).
     *
     * **One statement decides both conditions it depends on.** The row is
     * selected FROM the request itself, filtered on the statuses that call
     * for this decision, so a request whose decision changed between the
     * caller's read and this write gets no e-mail at all; and the UNIQUE
     * `active_request_id` refuses it when another e-mail of the request is
     * already in play. Either way the answer is null and nothing is
     * written. A read-then-insert would let two clicks each create — and
     * send — their own.
     *
     * @return int|null the new row's id, or null when nothing was created
     */
    public function insert(
        int $requestId,
        string $decision,
        string $state,
        ?\DateTimeImmutable $dueAt,
        int $attempt,
        bool $isResend,
        \DateTimeImmutable $now
    ): ?int {
        $statuses = DecisionEmail::statusesFor($decision);
        if ($statuses === []
            || !in_array($state, [DecisionEmail::STATE_SCHEDULED, DecisionEmail::STATE_SENDING], true)
        ) {
            throw new \InvalidArgumentException(
                'A decision e-mail is created scheduled or sending, for a known decision.'
            );
        }

        $stamp = self::format($now);
        $stmt = $this->pdo->prepare(
            'INSERT INTO registration_decision_emails
                (registration_request_id, decision, state, due_at, attempt, is_resend, attempted_at,
                 active_request_id, created_at, updated_at)
             SELECT id, ?, ?, ?, ?, ?, ?, id, ?, ?
             FROM registration_requests
             WHERE id = ? AND status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')'
        );

        try {
            $stmt->execute(array_merge([
                $decision,
                $state,
                $dueAt !== null ? self::format($dueAt) : null,
                $attempt,
                $isResend ? 1 : 0,
                $state === DecisionEmail::STATE_SENDING ? $stamp : null,
                $stamp,
                $stamp,
                $requestId,
            ], $statuses));
        } catch (\PDOException $exception) {
            // 23000 is a UNIQUE violation on both engines — another e-mail
            // of this request is in play — and the only reason to answer
            // "not created": anything else surfaces as itself (same rule
            // as Core\Mail\SentEmailClaimRepository::claim()).
            if (($exception->errorInfo[0] ?? '') !== '23000') {
                throw $exception;
            }

            return null;
        }

        return $stmt->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * Takes a scheduled e-mail for sending — 'scheduled' to 'sending',
     * only if it still is scheduled and still describes the request's
     * decision. True for exactly one of any number of concurrent callers.
     */
    public function claim(int $id, \DateTimeImmutable $now): bool
    {
        $stamp = self::format($now);
        $stmt = $this->pdo->prepare(
            "UPDATE registration_decision_emails SET state = 'sending', attempted_at = ?, updated_at = ?
             WHERE id = ? AND state = 'scheduled' AND superseded_at IS NULL"
        );
        $stmt->execute([$stamp, $stamp, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The send went out. Frees the request for its next e-mail.
     *
     * Also from 'failed' with the code 'interrupted', and only that one:
     * an interruption is the record of an outcome nobody knew yet
     * (failStaleSending()), and a send that was merely slow and does
     * report back resolves it into what happened. Any other final row is
     * left as it is.
     */
    public function markSent(int $id, \DateTimeImmutable $now): bool
    {
        $stamp = self::format($now);
        $stmt = $this->pdo->prepare(
            "UPDATE registration_decision_emails
             SET state = 'sent', sent_at = ?, failed_at = NULL, failure_code = NULL, failure_message = NULL,
                 active_request_id = NULL, updated_at = ?
             WHERE id = ? AND (state = 'sending' OR (state = 'failed' AND failure_code = 'interrupted'))"
        );
        $stmt->execute([$stamp, $stamp, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The send did not go out. $message is the French sentence a chief may
     * read — already gated by the caller (Core\Exception\UserFacingMessage).
     */
    public function markFailed(int $id, string $failureCode, string $message, \DateTimeImmutable $now): bool
    {
        self::assertFailureCode($failureCode);
        $stamp = self::format($now);
        $stmt = $this->pdo->prepare(
            "UPDATE registration_decision_emails
             SET state = 'failed', failed_at = ?, failure_code = ?, failure_message = ?,
                 active_request_id = NULL, updated_at = ?
             WHERE id = ? AND state = 'sending'"
        );
        $stmt->execute([$stamp, $failureCode, mb_substr($message, 0, 500), $stamp, $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * A send of this request claimed before $claimedBefore and never
     * reported back — the process died between the claim and the outcome —
     * is recorded as failed, 'interrupted', so the request is not blocked
     * for ever. Whether the message left is unknown, which $message says.
     *
     * @return int the number of rows recorded as interrupted
     */
    public function failStaleSending(
        int $requestId,
        \DateTimeImmutable $claimedBefore,
        string $message,
        \DateTimeImmutable $now
    ): int {
        $stamp = self::format($now);
        $stmt = $this->pdo->prepare(
            "UPDATE registration_decision_emails
             SET state = 'failed', failed_at = ?, failure_code = ?, failure_message = ?,
                 active_request_id = NULL, updated_at = ?
             WHERE registration_request_id = ? AND state = 'sending' AND attempted_at < ?"
        );
        $stmt->execute([
            $stamp,
            DecisionEmail::FAILURE_INTERRUPTED,
            mb_substr($message, 0, 500),
            $stamp,
            $requestId,
            self::format($claimedBefore),
        ]);

        return $stmt->rowCount();
    }

    /**
     * Moves a still-scheduled e-mail's due date. Nothing else of the row
     * changes: it has not happened yet, so there is no history to keep.
     */
    public function reschedule(int $id, \DateTimeImmutable $dueAt, \DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE registration_decision_emails SET due_at = ?, updated_at = ?
             WHERE id = ? AND state = 'scheduled' AND superseded_at IS NULL"
        );
        $stmt->execute([self::format($dueAt), self::format($now), $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The request's decision changed: every row still describing the old
     * one stops describing it. A row still scheduled never happened and
     * is cancelled (« Annulé suite à un changement de décision »), which
     * also frees the request for the new decision's e-mail; a row sent,
     * failed or in flight keeps its state and is only dated. A row in
     * flight also gives up `active_request_id`: it no longer describes the
     * request's decision, so it must not hold the new decision's e-mail
     * back until stale-send recovery — its own outcome is still recorded
     * by markSent() / markFailed(), which read its state, not that column.
     *
     * $keptDecision spares the rows of one decision — the acceptance, when
     * a request becomes `encoded`: the family was told the same decision.
     *
     * Called by RegistrationRequestRepository::updateStatus() and
     * linkToMemberAndEncode(), inside the same transaction as the status.
     *
     * @return int the number of scheduled e-mails cancelled
     */
    public function supersedeForRequest(int $requestId, \DateTimeImmutable $now, ?string $keptDecision = null): int
    {
        $stamp = self::format($now);
        $spare = $keptDecision !== null ? ' AND decision <> ?' : '';
        $spared = $keptDecision !== null ? [$keptDecision] : [];

        $cancel = $this->pdo->prepare(
            "UPDATE registration_decision_emails
             SET state = 'cancelled', cancelled_at = ?, cancel_reason = ?, superseded_at = ?,
                 active_request_id = NULL, updated_at = ?
             WHERE registration_request_id = ? AND state = 'scheduled' AND superseded_at IS NULL" . $spare
        );
        $cancel->execute(array_merge(
            [$stamp, DecisionEmail::CANCEL_DECISION_CHANGED, $stamp, $stamp, $requestId],
            $spared
        ));
        $cancelled = $cancel->rowCount();

        $supersede = $this->pdo->prepare(
            'UPDATE registration_decision_emails SET superseded_at = ?, active_request_id = NULL, updated_at = ?
             WHERE registration_request_id = ? AND superseded_at IS NULL' . $spare
        );
        $supersede->execute(array_merge([$stamp, $stamp, $requestId], $spared));

        return $cancelled;
    }

    /**
     * For each request, the date its CURRENT decision's e-mail first went
     * out, per decision — the first send, never a resend's: what the
     * tracking page waits for (R3). Superseded rows are not read.
     *
     * @param array<int> $requestIds
     * @return array<int, array<string, \DateTimeImmutable>>
     */
    public function firstSentAtByRequest(array $requestIds): array
    {
        $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
        if ($requestIds === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT registration_request_id, decision, MIN(sent_at) AS first_sent_at
             FROM registration_decision_emails
             WHERE state = 'sent' AND superseded_at IS NULL
               AND registration_request_id IN (" . implode(', ', array_fill(0, count($requestIds), '?')) . ')
             GROUP BY registration_request_id, decision'
        );
        $stmt->execute($requestIds);

        $dates = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sentAt = DateInput::fromStorage($row['first_sent_at'] === null ? null : (string) $row['first_sent_at']);
            if ($sentAt !== null) {
                $dates[(int) $row['registration_request_id']][(string) $row['decision']] = $sentAt;
            }
        }

        return $dates;
    }

    /**
     * Every e-mail of one request — RegistrationRequestRepository::
     * deleteById() calls this before deleting the request itself, so the
     * purge removes them whether or not the engine enforces the cascade.
     */
    public function deleteForRequest(int $requestId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM registration_decision_emails WHERE registration_request_id = ?');
        $stmt->execute([$requestId]);
    }

    private static function assertFailureCode(string $failureCode): void
    {
        if (!in_array($failureCode, DecisionEmail::FAILURE_CODES, true)) {
            throw new \InvalidArgumentException('Unknown decision e-mail failure code.');
        }
    }

    private static function format(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): DecisionEmail
    {
        $date = static fn(string $column): ?\DateTimeImmutable => DateInput::fromStorage(
            $row[$column] === null ? null : (string) $row[$column]
        );

        return new DecisionEmail(
            id: (int) $row['id'],
            requestId: (int) $row['registration_request_id'],
            decision: (string) $row['decision'],
            state: (string) $row['state'],
            dueAt: $date('due_at'),
            attempt: (int) $row['attempt'],
            isResend: (bool) (int) $row['is_resend'],
            attemptedAt: $date('attempted_at'),
            sentAt: $date('sent_at'),
            failedAt: $date('failed_at'),
            failureCode: $row['failure_code'] !== null ? (string) $row['failure_code'] : null,
            failureMessage: $row['failure_message'] !== null ? (string) $row['failure_message'] : null,
            cancelledAt: $date('cancelled_at'),
            cancelReason: $row['cancel_reason'] !== null ? (string) $row['cancel_reason'] : null,
            supersededAt: $date('superseded_at'),
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at')
        );
    }
}
