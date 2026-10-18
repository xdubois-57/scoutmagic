<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * The « E-mail de décision » block of a request's fiche (issue #843, D8 and
 * IT-08): where the e-mail of the decision the request holds now stands,
 * which of « Envoyer maintenant », « Réessayer » and « Renvoyer l'e-mail »
 * it allows, and the history of every attempt — read from
 * `registration_decision_emails`, never rewritten.
 *
 * Built by build() from the request and its rows; the wording lives in views/_decision_email.html.twig.
 * Service\RequestEmailService::sendFromRequestPage() reads the same `actions`
 * before sending, so a button the page does not show cannot be posted
 * into an e-mail either — a second « Envoyer maintenant » after a success
 * is refused, never turned into a resend.
 *
 * The states, in the order they win:
 *
 * - `sent` — an e-mail of this decision went out, whatever came after;
 *   « Renvoyer l'e-mail » unless another send is in play;
 * - `scheduled` — « Prévu le … », « Envoyer maintenant »;
 * - `sending` — a send claimed moments ago, no action until it reports;
 * - `failed` — « Échec le … », « Réessayer »; a send claimed longer ago
 *   than DecisionEmailService::STALE_SENDING_MINUTES is failed too, with
 *   `interrupted` set — « Envoi interrompu »: whether it left is unknown,
 *   and begin() records it as interrupted when the retry starts (the same
 *   bound, so the button the page offers is one begin() accepts);
 * - `cancelled` — « Annulé suite à un changement de décision »: the last
 *   e-mail planned was cancelled by a change of decision and none is
 *   planned for the new one; « Envoyer maintenant »;
 * - `none` — « Non planifié », « Envoyer maintenant ».
 */
final class DecisionEmailPanel
{
    public const STATE_NONE = 'none';
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_SENDING = 'sending';
    public const STATE_SENT = 'sent';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public const ACTION_SEND_NOW = 'send_now';
    public const ACTION_RETRY = 'retry';
    public const ACTION_RESEND = 'resend';

    /**
     * @param array<int, string> $actions the ACTION_* the block offers
     * @param array<int, DecisionEmailEvent> $history newest first
     */
    private function __construct(
        public readonly string $decision,
        public readonly string $state,
        public readonly ?\DateTimeImmutable $at,
        public readonly array $actions,
        public readonly array $history,
        public readonly ?DecisionEmail $lastFailedResend,
        public readonly bool $interrupted = false
    ) {
    }

    /**
     * The block of $request, or null when it holds no decision (pending,
     * withdrawn) — the fiche then shows no block at all.
     *
     * @param array<int, DecisionEmail> $emails every row of the request,
     *        oldest first (DecisionEmailService::history())
     */
    public static function build(
        RegistrationRequest $request,
        array $emails,
        \DateTimeImmutable $now
    ): ?self {
        $decision = DecisionEmail::decisionForStatus($request->status);
        if ($decision === null) {
            return null;
        }

        $current = array_values(array_filter(
            $emails,
            static fn(DecisionEmail $email): bool => $email->isCurrent() && $email->decision === $decision
        ));
        $latest = $current === [] ? null : $current[count($current) - 1];
        $sent = array_values(array_filter(
            $current,
            static fn(DecisionEmail $email): bool => $email->state === DecisionEmail::STATE_SENT
        ));
        $staleBefore = $now->modify('-' . DecisionEmailService::STALE_SENDING_MINUTES . ' minutes');
        $history = self::historyOf($emails, $staleBefore);

        if ($sent !== []) {
            $inPlay = $latest !== null && in_array(
                $latest->state,
                [DecisionEmail::STATE_SCHEDULED, DecisionEmail::STATE_SENDING],
                true
            ) && !self::isStale($latest, $staleBefore);

            return new self(
                $decision,
                self::STATE_SENT,
                $sent[count($sent) - 1]->sentAt,
                !$inPlay ? [self::ACTION_RESEND] : [],
                $history,
                $latest !== null && $latest->state === DecisionEmail::STATE_FAILED ? $latest : null
            );
        }

        $interrupted = $latest !== null && self::isStale($latest, $staleBefore);
        [$state, $at, $actions] = match (true) {
            $latest === null => self::withoutEmailOfThisDecision($emails),
            $latest->state === DecisionEmail::STATE_SCHEDULED
                => [self::STATE_SCHEDULED, $latest->dueAt, [self::ACTION_SEND_NOW]],
            $latest->state === DecisionEmail::STATE_SENDING && !$interrupted
                => [self::STATE_SENDING, $latest->attemptedAt, []],
            $latest->state === DecisionEmail::STATE_SENDING
                => [self::STATE_FAILED, $latest->attemptedAt, [self::ACTION_RETRY]],
            $latest->state === DecisionEmail::STATE_FAILED
                => [self::STATE_FAILED, $latest->failedAt, [self::ACTION_RETRY]],
            // A current row is never cancelled — only a change of decision
            // cancels, and that supersedes the row in the same write.
            default => [self::STATE_CANCELLED, $latest->cancelledAt, [self::ACTION_SEND_NOW]],
        };

        return new self($decision, $state, $at, $actions, $history, null, $interrupted);
    }

    /**
     * Whether $action is one this block offers.
     */
    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    /**
     * No e-mail of the current decision yet: « Annulé suite à un changement
     * de décision » when the last thing that happened to the request's
     * e-mails is that change cancelling a planned one, « Non planifié »
     * otherwise.
     *
     * @param array<int, DecisionEmail> $emails
     * @return array{0: string, 1: ?\DateTimeImmutable, 2: array<int, string>}
     */
    private static function withoutEmailOfThisDecision(array $emails): array
    {
        $last = $emails === [] ? null : $emails[count($emails) - 1];
        if ($last !== null
            && $last->state === DecisionEmail::STATE_CANCELLED
            && $last->cancelReason === DecisionEmail::CANCEL_DECISION_CHANGED
        ) {
            return [self::STATE_CANCELLED, $last->cancelledAt, [self::ACTION_SEND_NOW]];
        }

        return [self::STATE_NONE, null, [self::ACTION_SEND_NOW]];
    }

    /**
     * A claim older than the stale bound — the comparison of
     * DecisionEmailRepository::failStaleSending() (`attempted_at < ?`), so
     * the page and begin() agree on the very second.
     */
    private static function isStale(DecisionEmail $email, \DateTimeImmutable $staleBefore): bool
    {
        return $email->state === DecisionEmail::STATE_SENDING
            && $email->attemptedAt !== null
            && $email->attemptedAt < $staleBefore;
    }

    /**
     * Every event of every row, newest first: the planning of a scheduled
     * e-mail, then what became of it. Rows of an earlier decision are kept
     * — what was sent for it still happened.
     *
     * A claim older than $staleBefore reads as interrupted, as the state
     * above does.
     *
     * @param array<int, DecisionEmail> $emails
     * @return array<int, DecisionEmailEvent>
     */
    private static function historyOf(array $emails, \DateTimeImmutable $staleBefore): array
    {
        $events = [];
        foreach ($emails as $email) {
            if ($email->dueAt !== null) {
                $events[] = new DecisionEmailEvent(DecisionEmailEvent::PLANNED, $email->createdAt, $email);
            }
            $outcome = match ($email->state) {
                DecisionEmail::STATE_SENT => [DecisionEmailEvent::SENT, $email->sentAt],
                DecisionEmail::STATE_FAILED => [DecisionEmailEvent::FAILED, $email->failedAt],
                DecisionEmail::STATE_CANCELLED => [DecisionEmailEvent::CANCELLED, $email->cancelledAt],
                DecisionEmail::STATE_SENDING => [
                    self::isStale($email, $staleBefore) ? DecisionEmailEvent::INTERRUPTED : DecisionEmailEvent::SENDING,
                    $email->attemptedAt,
                ],
                default => null,
            };
            if ($outcome !== null) {
                $events[] = new DecisionEmailEvent($outcome[0], $outcome[1] ?? $email->createdAt, $email);
            }
        }

        // Newest first; for two events at the same second, the later row,
        // then the outcome after the planning it follows.
        $order = array_keys($events);
        usort($order, static function (int $a, int $b) use ($events): int {
            return [$events[$b]->at, $events[$b]->email->id, $b] <=> [$events[$a]->at, $events[$a]->email->id, $a];
        });

        return array_map(static fn(int $index): DecisionEmailEvent => $events[$index], $order);
    }
}
