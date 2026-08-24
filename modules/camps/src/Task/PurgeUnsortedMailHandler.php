<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Task;

use Core\File\FileRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;

/**
 * Erases unsorted mail older than the configured retention, then
 * re-schedules itself for tomorrow.
 *
 * A dedicated mailbox claims everything, which is what makes the
 * "Courrier non classé" screen possible — and also what would turn this
 * module into an archive of the unit's mailbox if nothing ever removed
 * what nobody attributed. The retention is that counterweight, and it is
 * stated on the screen itself rather than only in a policy nobody reads.
 *
 * Messages are detached one by one rather than through
 * purgeReference(), which empties a reference wholesale regardless of
 * age: that call is for "this business object is gone", not for a
 * retention window. detach() is also what carries inbound_mail's own
 * semantics — the attachments nothing else claimed go with the message
 * (§7.7).
 */
class PurgeUnsortedMailHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_unsorted_mail';
    public const REFERENCE = 'camps_purge_unsorted_mail';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $months = max(1, (int) ($context->settings->get('camps_unsorted_retention_months', 'camps', '6') ?? 6));
        $pdo = $context->connection->getPdo();

        if (class_exists(InboundMailService::class)) {
            $purged = $this->purge($pdo, $context, $months);
            if ($purged > 0) {
                $context->journal->log(
                    'camps',
                    'unsorted_mail_purged',
                    'info',
                    sprintf('%d message(s) non classé(s) effacé(s) après %d mois.', $purged, $months)
                );
            }
        }

        $this->rescheduleTomorrow($pdo);
    }

    private function purge(\PDO $pdo, TaskContext $context, int $months): int
    {
        $inboundMail = new InboundMailService(
            new InboundMessageRepository($pdo, $context->encryption),
            new InboundMailboxRepository($pdo, $context->encryption),
            new FileRepository($pdo)
        );

        $cutoff = (new \DateTimeImmutable())->modify('-' . $months . ' months');
        $purged = 0;

        foreach ($inboundMail->findForReference(
            CampsMessageConsumer::CONSUMER_ID,
            CampsMessageConsumer::UNSORTED_REFERENCE
        ) as $message) {
            if (!$this->isExpired($message, $cutoff)) {
                continue;
            }
            if ($inboundMail->detach(
                CampsMessageConsumer::CONSUMER_ID,
                CampsMessageConsumer::UNSORTED_REFERENCE,
                $message->id
            )) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * The clock runs from the message's OWN date, because that is the
     * only date inbound_mail exposes — Api\InboundMessage carries sentAt
     * and no attachment timestamp.
     *
     * The consequence is worth stating rather than hiding: a message
     * detached back into "unsorted" after six months on a stay is already
     * expired and goes on the next run. Making it restart would mean
     * adding a timestamp to another module's public value object for one
     * consumer's retention policy, which is a bigger change than this
     * behaviour is worth — so the setting's description promises what
     * actually happens instead.
     */
    private function isExpired(InboundMessage $message, \DateTimeImmutable $cutoff): bool
    {
        return $message->sentAt < $cutoff;
    }

    private function rescheduleTomorrow(\PDO $pdo): void
    {
        SchedulerService::forPdo($pdo)->rearm('camps', self::TASK_KEY, self::REFERENCE, 'tomorrow 04:00');
    }
}
