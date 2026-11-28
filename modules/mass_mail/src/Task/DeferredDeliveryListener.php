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
use Modules\MassMail\Repository\Target;
use Modules\MassMail\Repository\TargetRepository;
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
        if (preg_match('/^' . preg_quote(self::ORIGIN_PREFIX, '/') . '([1-9]\d{0,9})$/', $origin, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
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

        $massMailService->checkAndMarkSentIfComplete($email->id);
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

        // The reason as the tracking page shows it: a French sentence the
        // core wrote for exactly this, never the relay's transcript, and
        // no address (SECURITY.md §11).
        $deliveries->settleDeferredAsAbandoned($delivery->id, $reason->label());
        $massMailService->journalDeliveryAbandoned($email->id, $delivery->id, $reason->label());

        $massMailService->checkAndMarkSentIfComplete($email->id);
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
