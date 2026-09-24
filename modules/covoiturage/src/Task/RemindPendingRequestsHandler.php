<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Task;

use Core\Scheduler\SchedulerService;
use Core\Service\DateInput;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolNotifier;

/**
 * `covoiturage.request_pending`: reminds a driver of a request that has
 * waited for an answer — never for a trip already gone, and never more
 * than once every few days for the same request (`reminded_at`). The type's
 * e-mail channel is `off`: a driver who has not answered yet does not need
 * a daily mail (module.json).
 *
 * Daily, re-armed through rearm() (RecurringTasksRearmTest).
 */
class RemindPendingRequestsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'remind_pending_requests';
    public const REFERENCE = 'daily';

    /** A request is worth a reminder once it has waited this long… */
    public const WAIT_DAYS = 2;
    /** … and again only after this many days without an answer. */
    public const REPEAT_DAYS = 3;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $now = new \DateTimeImmutable();
        $today = new \DateTimeImmutable('today');

        $carpools = new CarpoolRepository($pdo);
        $offers = new OfferRepository($pdo, $context->encryption);
        $requests = new SeatRequestRepository($pdo, $context->encryption);
        $notifier = new CarpoolNotifier($context->notifications);

        $sent = 0;
        foreach ($requests->findPendingToRemind(
            $now->modify('-' . self::WAIT_DAYS . ' days')->format('Y-m-d H:i:s'),
            $now->modify('-' . self::REPEAT_DAYS . ' days')->format('Y-m-d H:i:s')
        ) as $request) {
            $offer = $offers->findById($request->offerId);
            $carpool = $offer !== null ? $carpools->findById($offer->carpoolId) : null;
            if ($offer === null || $carpool === null) {
                continue;
            }
            $tripDay = DateInput::fromStorage(
                $offer->isOutbound() ? $carpool->outboundDate : ($carpool->returnDate ?? $carpool->outboundDate)
            );
            $asked = DateInput::fromStorage($request->createdAt);
            if ($tripDay === null || $asked === null || $tripDay < $today) {
                continue;
            }

            $notifier->requestPending(
                $carpool,
                $offer,
                $request,
                max(1, (int) $asked->diff($now)->days),
                (int) $today->diff($tripDay)->days
            );
            $requests->markReminded($request->id, $now);
            $sent++;
        }

        if ($sent > 0) {
            $context->journal->log(
                'covoiturage',
                'pending_requests_reminded',
                'info',
                sprintf('%d rappel(s) de demande en attente envoyé(s) aux conducteurs.', $sent),
                ['count' => $sent]
            );
        }

        SchedulerService::forPdo($pdo)->rearm(
            'covoiturage',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 18:00')
        );
    }
}
