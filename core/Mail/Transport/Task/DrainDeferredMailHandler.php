<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\DeferredMessage;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Tries the deferred messages that are due, and purges the ones nobody
 * will send any more (D9, D16, D18).
 *
 * **It replays `MailService::send()`**, which is the whole reason the
 * queue stores arguments rather than an assembled message: a message
 * that drains is signed, routed through its lane, counted against a
 * quota and — on an installation whose sandbox is armed — captured,
 * exactly like one that left the first time. There is one way to send
 * mail on this site, and this task does not add a second.
 *
 * A message that fails again is rescheduled further out; one that has
 * run out of time is abandoned, with its reason, and kept for the
 * Relance screen until the retention purge takes it and its body.
 *
 * Runs often, because the first retry is five minutes out and a queue
 * drained hourly would make that five minutes a lie.
 */
class DrainDeferredMailHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'drain_deferred_mail';
    public const REFERENCE = 'queue';

    private const INTERVAL_SECONDS = 300;

    /**
     * How many messages one pass sends.
     *
     * Bounded because this runs inside a cron tick that has other work to
     * do, and because a queue of four hundred draining in one burst is
     * the thundering herd the cadence exists to avoid — the next pass is
     * five minutes away.
     */
    private const BATCH = 25;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $repository = new DeferredMailRepository($pdo, $context->encryption);
        $queue = new DeferredMailQueue(
            $repository,
            new SettingService(new SettingRepository($pdo)),
            $context->journal
        );

        $this->drain($repository, $queue, $context);
        $this->purge($repository, $queue, $context);

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    private function drain(
        DeferredMailRepository $repository,
        DeferredMailQueue $queue,
        TaskContext $context
    ): void {
        $sent = 0;
        $abandoned = 0;

        foreach ($repository->due(self::BATCH) as $message) {
            if ($message->hasExpired()) {
                $repository->abandon($message->id, $message->attempts, 'délai de vie dépassé');
                $abandoned++;
                continue;
            }

            if ($this->trySend($message, $repository, $queue, $context)) {
                $sent++;
            } else {
                $abandoned += $this->settleFailure($message, $repository, $queue) ? 1 : 0;
            }
        }

        if ($sent > 0 || $abandoned > 0) {
            $context->journal->log(
                'core',
                'mail_deferred_drained',
                'info',
                'File des messages différés traitée',
                ['sent' => $sent, 'abandoned' => $abandoned]
            );
        }
    }

    /**
     * One attempt, replayed through the ordinary send path.
     *
     * A message that goes out is deleted rather than marked: nothing
     * about it is worth keeping once it has arrived, and its body is
     * personal data that has no further reason to exist (D18).
     */
    private function trySend(
        DeferredMessage $message,
        DeferredMailRepository $repository,
        DeferredMailQueue $queue,
        TaskContext $context
    ): bool {
        $payload = $message->payload;
        $attachments = $this->materialise($payload['attachments']);

        try {
            $context->mailService->send(
                $payload['to'],
                $payload['subject'],
                $payload['bodyHtml'],
                $payload['bodyText'],
                $payload['replyTo'],
                $attachments['files'],
                $payload['fromAddressOverride'],
                $payload['fromNameOverride'],
                $payload['extraHeaders'],
                $message->purpose
            );
        } catch (\Throwable $e) {
            $repository->reschedule(
                $message->id,
                $message->attempts + 1,
                $e->getMessage(),
                // Re-read on the next pass; this is only a placeholder in
                // case settleFailure() finds the message still has time.
                $message->nextAttemptAt
            );

            return false;
        } finally {
            foreach ($attachments['temporary'] as $path) {
                @unlink($path);
            }
        }

        $repository->delete($message->id);

        return true;
    }

    /**
     * It failed again: further out, or given up on (D16).
     *
     * @return bool Whether it was abandoned.
     */
    private function settleFailure(
        DeferredMessage $message,
        DeferredMailRepository $repository,
        DeferredMailQueue $queue
    ): bool {
        $next = $queue->nextAttemptFor($message);

        if ($next === null) {
            $repository->abandon($message->id, $message->attempts + 1, 'délai de vie dépassé');

            return true;
        }

        $repository->reschedule($message->id, $message->attempts + 1, 'nouvel échec', $next);

        return false;
    }

    /**
     * Put the carried attachments back on disk, because `send()` takes
     * paths.
     *
     * Written to the system temporary directory and removed in the
     * `finally` above, whatever the send did: these are decrypted copies
     * of personal data, and leaving them lying about would undo the point
     * of encrypting the queue at all (D18).
     *
     * @param array<int, array{name: string, content: string}> $carried
     * @return array{files: array<int, array{path: string, name: string}>, temporary: array<int, string>}
     */
    private function materialise(array $carried): array
    {
        $files = [];
        $temporary = [];

        foreach ($carried as $attachment) {
            $path = tempnam(sys_get_temp_dir(), 'scoutmagic-deferred-');
            if ($path === false) {
                continue;
            }

            if (file_put_contents($path, $attachment['content']) === false) {
                @unlink($path);
                continue;
            }

            @chmod($path, 0600);
            $temporary[] = $path;
            $files[] = ['path' => $path, 'name' => $attachment['name']];
        }

        return ['files' => $files, 'temporary' => $temporary];
    }

    private function purge(
        DeferredMailRepository $repository,
        DeferredMailQueue $queue,
        TaskContext $context
    ): void {
        $cutoff = (new \DateTimeImmutable('-' . $queue->abandonedRetentionDays() . ' days'))->format('Y-m-d H:i:s');
        $dropped = $repository->purgeAbandonedBefore($cutoff);

        if ($dropped > 0) {
            $context->journal->log(
                'core',
                'mail_deferred_purged',
                'info',
                'Purge des messages abandonnés',
                ['dropped' => $dropped, 'retention_days' => $queue->abandonedRetentionDays()]
            );
        }
    }
}
