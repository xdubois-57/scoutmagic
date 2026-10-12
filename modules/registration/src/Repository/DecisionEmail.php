<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One acceptance or refusal e-mail of a request, as
 * `registration_decision_emails` holds it (issue #843, D8): planned,
 * being sent, sent, failed or cancelled. Read-only — every change goes
 * through Repository\DecisionEmailRepository, which never rewrites a row
 * that has reached a final state.
 */
final class DecisionEmail
{
    public const DECISION_ACCEPTED = 'accepted';
    public const DECISION_REFUSED = 'refused';

    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_SENDING = 'sending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    /** The body has not been written: the module's own guard refused to send. */
    public const FAILURE_BODY_NOT_WRITTEN = 'body_not_written';
    /** The mail transport refused or could not be reached. */
    public const FAILURE_MAIL_TRANSPORT = 'mail_transport';
    /** A send was claimed and never reported back — a process that died mid-send. */
    public const FAILURE_INTERRUPTED = 'interrupted';

    /** « Annulé suite à un changement de décision » (D8). */
    public const CANCEL_DECISION_CHANGED = 'decision_changed';

    /**
     * @var array<int, string>
     */
    public const DECISIONS = [self::DECISION_ACCEPTED, self::DECISION_REFUSED];

    /**
     * @var array<int, string>
     */
    public const FAILURE_CODES = [
        self::FAILURE_BODY_NOT_WRITTEN,
        self::FAILURE_MAIL_TRANSPORT,
        self::FAILURE_INTERRUPTED,
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $requestId,
        public readonly string $decision,
        public readonly string $state,
        public readonly ?\DateTimeImmutable $dueAt,
        public readonly int $attempt,
        public readonly bool $isResend,
        public readonly ?\DateTimeImmutable $attemptedAt,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly ?\DateTimeImmutable $failedAt,
        public readonly ?string $failureCode,
        public readonly ?string $failureMessage,
        public readonly ?\DateTimeImmutable $cancelledAt,
        public readonly ?string $cancelReason,
        public readonly ?\DateTimeImmutable $supersededAt,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /**
     * The decision a request's status asks an e-mail for: an acceptance
     * for a request accepted or already encoded in Desk (encoding does not
     * change the decision the family was told), a refusal for a refused
     * one, and none for a request pending or withdrawn.
     */
    public static function decisionForStatus(string $status): ?string
    {
        return match ($status) {
            RegistrationRequest::STATUS_ACCEPTED, RegistrationRequest::STATUS_ENCODED => self::DECISION_ACCEPTED,
            RegistrationRequest::STATUS_REFUSED => self::DECISION_REFUSED,
            default => null,
        };
    }

    /**
     * The request statuses under which an e-mail of $decision may be
     * created — the reverse of decisionForStatus().
     *
     * @return array<int, string>
     */
    public static function statusesFor(string $decision): array
    {
        return match ($decision) {
            self::DECISION_ACCEPTED => [RegistrationRequest::STATUS_ACCEPTED, RegistrationRequest::STATUS_ENCODED],
            self::DECISION_REFUSED => [RegistrationRequest::STATUS_REFUSED],
            default => [],
        };
    }

    /**
     * Whether this row still describes the request's current decision —
     * false once a later status change superseded it.
     */
    public function isCurrent(): bool
    {
        return $this->supersededAt === null;
    }
}
