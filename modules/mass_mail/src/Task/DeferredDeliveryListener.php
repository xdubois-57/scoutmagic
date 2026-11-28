<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Mail\Transport\DeferredMailAbandonment;
use Core\Module\DeferredMailOutcomeListener;
use Core\Scheduler\TaskContext;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\Target;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;

/**
 * The way back from the core's deferred queue to a mailing's message
 * (issue #1454) — `mass_mail`'s implementation of the core hook
 * `Core\Module\DeferredMailOutcomeListener` (ARCHITECTURE.md §7.4).
 *
 * Task\SendBatchHandler names every message it sends with
 * {@see originFor()}; a message the lane could not take is kept by the
 * core with that name beside it and its row here reads 'deferred'. When the
 * drain (`Core\Mail\Transport\Task\DrainDeferredMailHandler`) finally sends
 * it, the row becomes 'sent' and everything a sent message is owed follows
 * — its `delivery_sent` line, its account's notification
 * (Task\SentMessageFollowUp); when the drain gives up, the row becomes an
 * error with a reason a chief can read, and a `delivery_abandoned` line
 * says so. Either way the mailing then reaches « Envoyé » once nothing of
 * it is left waiting.
 *
 * Registered for the scheduled path in `public/scheduler-bootstrap.php`,
 * under this module's live enabled state; with the module off the core
 * sends or abandons the message all the same and this row simply keeps
 * waiting, which is what a disabled module's data does.
 */
final class DeferredDeliveryListener implements DeferredMailOutcomeListener
{
    private const ORIGIN_PREFIX = 'mass_mail.delivery:';

    /** What a message of a mailing is called in the core's queue — its id, never its address. */
    public static function originFor(int $deliveryId): string
    {
        return self::ORIGIN_PREFIX . $deliveryId;
    }

    /** The message an origin names, or null for an origin some other sender wrote. */
    public static function deliveryIdOf(string $origin): ?int
    {
        if (preg_match('/^' . preg_quote(self::ORIGIN_PREFIX, '/') . '([1-9]\d{0,9})\z/', $origin, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * **Asked before the drain replays the message** — the gap #1437 closed
     * for the batch, closed for the deferred queue too. The core's replay
     * checks only its own bounce state; the group-mail unsubscribe is this
     * module's, so somebody who clicked « Se désinscrire » while their
     * message waited would otherwise receive it a day later all the same.
     * Withdrawn too when the message is no longer the queue's to send —
     * « Renvoyer » put it back in the batch, or it no longer exists —
     * because then a replay would be a second copy.
     */
    public function deferredMailWithdrawn(string $origin, TaskContext $context): ?DeferredMailAbandonment
    {
        $deliveryId = self::deliveryIdOf($origin);
        if ($deliveryId === null) {
            return null;
        }

        $pdo = $context->connection->getPdo();
        $delivery = (new DeliveryRepository($pdo, $context->encryption))->findById($deliveryId);
        if (
            $delivery === null
            || !in_array($delivery->status, [Delivery::STATUS_DEFERRED, Delivery::STATUS_ERROR], true)
        ) {
            return DeferredMailAbandonment::Withdrawn;
        }

        return (new SuppressedAddressRepository($pdo))->isSuppressed($delivery->emailAddress)
            ? DeferredMailAbandonment::Unsubscribed
            : null;
    }

    public function deferredMailSent(string $origin, TaskContext $context): void
    {
        $settling = $this->settling($origin, $context);
        if ($settling === null) {
            return;
        }
        [$delivery, $email, $deliveries] = $settling;
        $pdo = $context->connection->getPdo();
        $massMailService = SendBatchHandler::buildMassMailService($context);

        $deliveries->settleDeferredAsSent($delivery->id);

        $followUp = function () use ($context, $massMailService, $pdo, $delivery, $email): void {
            $targets = (new TargetRepository($pdo))->findByDelivery($delivery->id);
            [$subject, $personalised] = $this->sentSubject($context, $email, $delivery);
            (new SentMessageFollowUp())->record(
                $context,
                $massMailService,
                $delivery,
                $email,
                $targets,
                array_values(array_unique(array_map(static fn(Target $t): int => $t->memberId, $targets))),
                $subject,
                $personalised
            );
        };
        $this->thenComplete($massMailService, $email->id, $followUp);
    }

    public function deferredMailAbandoned(
        string $origin,
        DeferredMailAbandonment $reason,
        TaskContext $context
    ): void {
        $settling = $this->settling($origin, $context);
        if ($settling === null) {
            return;
        }
        [$delivery, $email, $deliveries] = $settling;
        $massMailService = SendBatchHandler::buildMassMailService($context);

        if ($reason === DeferredMailAbandonment::Unsubscribed) {
            // The batch's own vocabulary for the same situation (#1437):
            // one sentence on the tracking page, one journal line, whether
            // the unsubscribe was noticed before the send or in the queue.
            $deliveries->settleDeferredAsAbandoned($delivery->id, MassMailService::REASON_UNSUBSCRIBED);
            $this->thenComplete(
                $massMailService,
                $email->id,
                static fn() => $massMailService->journalDeliverySkippedUnsubscribed($email->id, $delivery->id)
            );

            return;
        }

        // The reason as the tracking page shows it: a French sentence the
        // core wrote for exactly this, never the relay's transcript, and
        // no address (SECURITY.md §11).
        $deliveries->settleDeferredAsAbandoned($delivery->id, $reason->label());
        $this->thenComplete(
            $massMailService,
            $email->id,
            static fn() => $massMailService->journalDeliveryAbandoned($email->id, $delivery->id, $reason->label())
        );
    }

    /**
     * Run what follows a settled message, then finish the mailing if
     * nothing of it is left waiting — **whatever the first part did**.
     *
     * The message is already settled when this runs, so the mailing's
     * completion no longer depends on anything else: a follow-up that
     * fails (its journal line, its notification, the lookups before them)
     * used to throw past the completion check and leave the mailing
     * « Envoi en cours » for good, with no message left to settle it.
     *
     * The follow-up's failure is the one that propagates — the drain
     * journals it with the message's origin — and a completion check that
     * fails too must not replace it, so that one is dropped: the next
     * settled message of the mailing asks again.
     */
    private function thenComplete(MassMailService $massMailService, int $emailId, callable $followUp): void
    {
        try {
            $followUp();
        } catch (\Throwable $failure) {
            try {
                $massMailService->checkAndMarkSentIfComplete($emailId);
            } catch (\Throwable) {
                // The follow-up's failure is the one worth reporting.
            }

            throw $failure;
        }

        $massMailService->checkAndMarkSentIfComplete($emailId);
    }

    /**
     * The message an origin names, when it is still one the queue may
     * settle: 'deferred', or 'error' for one a superadmin relaunched after
     * its abandonment (the core's Relance, D17). A message « Renvoyer » put
     * back in the batch queue meanwhile, or one already sent, is left as it
     * is.
     *
     * @return array{Delivery, Email, DeliveryRepository}|null
     */
    private function settling(string $origin, TaskContext $context): ?array
    {
        $deliveryId = self::deliveryIdOf($origin);
        if ($deliveryId === null) {
            return null;
        }

        $pdo = $context->connection->getPdo();
        $deliveries = new DeliveryRepository($pdo, $context->encryption);
        $delivery = $deliveries->findById($deliveryId);
        if (
            $delivery === null
            || !in_array($delivery->status, [Delivery::STATUS_DEFERRED, Delivery::STATUS_ERROR], true)
        ) {
            return null;
        }

        $email = (new EmailRepository($pdo))->findById($delivery->emailId);

        return $email === null ? null : [$delivery, $email, $deliveries];
    }

    /**
     * The subject the notification names, as the batch would have: the
     * mailing's own, or for a merge the one rendered from this message's
     * file row. A row purged since the deferral leaves nothing to render,
     * and the neutral sentence the notification uses for a personalised
     * e-mail stands in — never a subject full of `{{tokens}}`.
     *
     * @return array{string, bool} the subject, and whether it is personalised
     */
    private function sentSubject(TaskContext $context, Email $email, Delivery $delivery): array
    {
        if ($email->listType !== Email::LIST_TYPE_MAIL_MERGE) {
            return [$email->subject, false];
        }

        $renderer = new MergeRenderer();
        if (!$renderer->containsToken($email->subject)) {
            return [$email->subject, false];
        }

        $row = $delivery->audienceRowId !== null
            ? (new AudienceRepository($context->connection->getPdo(), $context->encryption))
                ->findRowById($delivery->audienceRowId)
            : null;

        return $row === null
            ? ['Un email personnalisé vous a été envoyé.', false]
            : [$renderer->renderText($email->subject, $row->data), true];
    }
}
