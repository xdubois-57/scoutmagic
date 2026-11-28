<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Scheduler\SchedulerService;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Task\SendBatchHandler;

/**
 * A mailing shown « Envoi en cours » that nothing is sending (issue #1443),
 * one point per email.
 *
 * The messages of every mailing are drained by ONE `send_batch` chain.
 * When no row of it is queued or running while messages wait, the
 * mailing has stopped, and until now the only trace was a
 * `scheduler_task_failed` line in the journal while the email list kept
 * saying « Envoi en cours ». The usual cause is the chain stopping on its
 * cap of consecutive failed batches (SendBatchHandler::
 * MAX_CONSECUTIVE_FAILURES); the composition root restarts it after
 * SendBatchHandler::HALT_HOURS, and any other stop on the next request.
 * The point stays while that is not done, which is exactly when somebody
 * should look.
 *
 * Two aggregate queries: whether the chain is alive (one indexed lookup),
 * and only when it is not, the mailings with messages waiting.
 */
class MassMailAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private EmailRepository $emails,
        private SchedulerService $scheduler
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Envoi de mails';
    }

    public function collect(int $scoutYearId): array
    {
        if ($this->scheduler->hasLiveForTask(SendBatchHandler::MODULE_ID, SendBatchHandler::TASK_KEY)) {
            return [];
        }

        $points = [];
        foreach ($this->emails->findSendingWithPendingMessages() as $email) {
            $points[] = new AttentionPoint(
                title: 'L\'envoi « ' . $email['subject'] . ' » est à l\'arrêt : '
                    . self::messages($email['pending']) . ' en attente',
                why: 'Aucun lot n\'est planifié pour ces messages, souvent après plusieurs lots en échec : '
                    . 'ils ne partent pas tant que l\'envoi ne reprend pas. Le site le relance de lui-même dans '
                    . 'l\'heure ; si l\'arrêt revient, le journal des événements dit pourquoi.',
                actionLabel: 'Ouvrir l\'envoi',
                actionUrl: '/mass-mail/' . $email['id'],
                severity: AttentionPoint::SEVERITY_URGENT
            );
        }

        return $points;
    }

    /** « 1 message », « 12 messages ». */
    private static function messages(int $count): string
    {
        return $count . ($count === 1 ? ' message' : ' messages');
    }
}
