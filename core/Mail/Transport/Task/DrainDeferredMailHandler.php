<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\MailErrorRedaction;
use Core\Mail\Transport\MailFailure;
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

    /**
     * The outcome of a replay the site declined to make, told apart from
     * every other reason by {@see trySend()} at the catch site rather than
     * by reading its words — `MailFailure::classify()` reads SMTP
     * transcripts, and this is not one.
     *
     * Doubles as the sentence the Relance screen shows, which is why it
     * reads as French rather than as a code.
     */
    private const SUPPRESSED = 'adresse suspendue — le site a cessé de lui écrire';

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

            $reason = $this->trySend($message, $repository, $context);
            if ($reason === null) {
                $sent++;
            } elseif ($reason === self::SUPPRESSED) {
                // **Not a failure, and not something to retry.** The site
                // declined to write to a suspended address, and every
                // later pass would decline identically until somebody
                // lifts the suspension — so walking the ladder would spend
                // a day's attempts learning what is already known.
                // Abandoned rather than deleted, so the Relance screen can
                // say why this one never left.
                $repository->abandon($message->id, $message->attempts + 1, self::SUPPRESSED);
                $abandoned++;
            } else {
                $abandoned += $this->settleFailure($message, $repository, $queue, $reason) ? 1 : 0;
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
     * **Through a queue-less clone** ({@see \Core\Mail\MailService::withoutDeferral()}):
     * this pass IS the queue, and a replay that could defer again would
     * make a message immortal.
     *
     * A message that goes out is deleted rather than marked: nothing
     * about it is worth keeping once it has arrived, and its body is
     * personal data that has no further reason to exist (D18).
     *
     * Rebuilding the attachments is INSIDE the try, deliberately. A row
     * whose payload cannot be read back — corrupted, or written before a
     * key changed — would otherwise throw out of this method, past
     * `drain()` and `handle()`, so the purge and the re-arm at the end of
     * the pass never ran; and since `due()` orders by date, the same row
     * would be first again on every later pass. One unreadable message
     * would stop the whole queue. Here it is a failure like any other and
     * settles like one.
     *
     * @return string|null Null when it left; otherwise the redacted reason
     *         it did not, which is what gets written down.
     */
    private function trySend(
        DeferredMessage $message,
        DeferredMailRepository $repository,
        TaskContext $context
    ): ?string {
        $attachments = ['files' => [], 'temporary' => []];

        try {
            $payload = $message->payload;
            $attachments = $this->materialise($payload['attachments']);

            $context->mailService->withoutDeferral()->send(
                $payload['to'],
                $payload['subject'],
                $payload['bodyHtml'],
                $payload['bodyText'],
                $payload['replyTo'],
                $attachments['files'],
                $payload['fromAddressOverride'],
                $payload['fromNameOverride'],
                $payload['extraHeaders'],
                $message->purpose,
                // **The flag has to survive the queue.** Without it every
                // replay read « false » — « the site did not choose this
                // recipient » — and walked straight past the suppression
                // gate. A notification deferred while the address was
                // still fine, and drained after two permanent bounces had
                // blocked it, went out all the same: to somebody the site
                // had just told it had stopped writing to them. Absent on
                // a row queued before this key existed, and false is the
                // right reading there: an authentication mail wrongly
                // suppressed locks somebody out of the site, which is the
                // worse of the two mistakes (D9).
                $payload['vouchesForRecipient'] ?? false
            );
        } catch (\Core\Mail\SuppressedRecipientException) {
            return self::SUPPRESSED;
        } catch (\Throwable $e) {
            return MailErrorRedaction::withoutAddresses($e->getMessage());
        } finally {
            foreach ($attachments['temporary'] as $path) {
                @unlink($path);
            }
        }

        $repository->delete($message->id);

        return null;
    }

    /**
     * It failed again: further out, or given up on (D16).
     *
     * **The single write of the failure path**, and it carries the reason
     * the transport actually gave rather than a fixed phrase: « SMTP
     * connect() failed » tells whoever opens the Relance screen what to
     * repair, where « nouvel échec » tells them nothing they did not
     * already know from the row existing.
     *
     * @return bool Whether it was abandoned.
     */
    private function settleFailure(
        DeferredMessage $message,
        DeferredMailRepository $repository,
        DeferredMailQueue $queue,
        string $reason
    ): bool {
        // **Somebody said no: there is nothing to wait for.** The same
        // distinction `MailService` draws before queueing a message has to
        // be drawn again here, because the two failures need not be the
        // same one. A message queued during an outage is replayed once the
        // relay comes back; if the address was also wrong, that replay is
        // the FIRST time anybody hears the 550 — and walking the ladder
        // from there would spend eight more attempts over a day learning
        // what the relay already said plainly.
        if (MailFailure::classify($reason) === MailFailure::Recipient) {
            $repository->abandon($message->id, $message->attempts + 1, $reason);

            return true;
        }

        // `attempts + 1` on BOTH sides of this: the attempt that has just
        // failed is not yet counted on the object, and the backoff rung is
        // chosen from how many have failed in total. Reading the stale
        // count here would hand out five minutes twice and make the real
        // ladder 5, 5, 15, 45 rather than 5, 15, 45.
        $next = $queue->nextAttemptFor($message, $message->attempts + 1);

        if ($next === null) {
            $repository->abandon($message->id, $message->attempts + 1, $reason);

            return true;
        }

        $repository->reschedule($message->id, $message->attempts + 1, $reason, $next);

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
     * **A file that cannot be written stops the replay**, it is not
     * skipped. `MailService::payloadFor()` refuses to queue a message it
     * cannot capture whole, precisely so that `send()` never reports
     * success for a message arriving without something the caller asked
     * it to carry; dropping an attachment here would reintroduce that on
     * the way out, and worse — the row is deleted on success, so the only
     * remaining copy of the receipt would go with it, unrecorded. A full
     * temporary directory is a reason to try again later, which is what
     * throwing gets: `trySend()` catches it, and the message is
     * rescheduled or abandoned like any other failure.
     *
     * @param array<int, array{name: string, content: string}> $carried
     * @return array{files: array<int, array{path: string, name: string}>, temporary: array<int, string>}
     */
    private function materialise(array $carried): array
    {
        $files = [];
        $temporary = [];

        try {
            foreach ($carried as $attachment) {
                $path = tempnam(sys_get_temp_dir(), 'scoutmagic-deferred-');
                if ($path === false) {
                    throw new \RuntimeException('Impossible de créer un fichier temporaire pour une pièce jointe.');
                }

                $temporary[] = $path;

                if (file_put_contents($path, $attachment['content']) === false) {
                    throw new \RuntimeException('Impossible d’écrire une pièce jointe sur le disque.');
                }

                @chmod($path, 0600);
                $files[] = ['path' => $path, 'name' => $attachment['name']];
            }
        } catch (\Throwable $e) {
            // The ones already written go now: `trySend()`'s `finally`
            // cleans up what this method RETURNED, and it is about to
            // receive an exception instead.
            foreach ($temporary as $path) {
                @unlink($path);
            }

            throw $e;
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
