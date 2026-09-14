<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailPurpose;

/**
 * What happens to a message when a whole lane is spent or down (D9, D16).
 *
 * **The three lanes answer differently, and the difference is the whole
 * decision.** Authentication fails on the spot: a sign-in link delivered
 * tomorrow is not a sign-in link, the token lives fifteen minutes, and a
 * person standing at a login form needs to be told now rather than
 * reassured about later. Transactional and mailing are deferred, because
 * for them « later » is a real answer.
 *
 * **A deferral is not a silence.** The count is on the Courrier sortant
 * page, an alert fires when the queue stops draining, and every message
 * carries its own deadline — past which it is abandoned with a reason
 * rather than sent, because a reminder three days late does more harm
 * than the reminder did good.
 */
final class DeferredMailQueue
{
    /**
     * How long a deferred message may keep trying before it is given up
     * on — hours, and a setting because the right answer depends on what
     * a unit sends (D9 says « de l'ordre de la journée »).
     */
    public const SETTING_LIFETIME_HOURS = 'mail_deferred_lifetime_hours';
    public const DEFAULT_LIFETIME_HOURS = 24;

    /** How long an abandoned message is kept so it can be relaunched (D17). */
    public const SETTING_ABANDONED_RETENTION_DAYS = 'mail_abandoned_retention_days';
    public const DEFAULT_ABANDONED_RETENTION_DAYS = 30;

    /**
     * The retry ladder, in minutes (D16). It grows because a lane that
     * just refused everything will not be fixed a minute later, and it
     * stops growing well inside the default lifetime so that a message
     * gets several tries rather than two.
     */
    private const BACKOFF_MINUTES = [5, 15, 45, 120, 240];

    /**
     * The most attachment bytes a deferred message may carry.
     *
     * Attachments are stored by content, not by path, because the path
     * will be gone (see {@see DeferredMailRepository}). That makes the
     * queue a place where a large file would be encrypted, written and
     * carried for a day, so there is a ceiling — and above it the message
     * is NOT deferred but fails as it would have before. Refusing loudly
     * beats queueing something that will drain without its attachment.
     */
    public const MAX_ATTACHMENT_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private DeferredMailRepository $repository,
        private SettingService $settings,
        private ?JournalService $journal = null
    ) {
    }

    /**
     * Whether this lane defers at all (D9).
     */
    public function defers(MailLane $lane): bool
    {
        return $lane !== MailLane::Authentication;
    }

    /**
     * Take a message the chain could not place.
     *
     * @param array{
     *     to: string, subject: string, bodyHtml: string, bodyText: string,
     *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     attachments: array<int, array{name: string, content: string}>
     * } $payload
     * @return bool Whether it was taken. False means the caller's failure stands.
     */
    public function defer(MailLane $lane, MailPurpose $purpose, array $payload, string $reason): bool
    {
        if (!$this->defers($lane)) {
            return false;
        }

        if ($this->attachmentBytes($payload) > self::MAX_ATTACHMENT_BYTES) {
            // Deliberately not deferred — see MAX_ATTACHMENT_BYTES.
            $this->journalEvent('mail_deferral_refused', 'Message trop lourd pour être différé', [
                'lane' => $lane->value,
                'bytes' => $this->attachmentBytes($payload),
            ]);

            return false;
        }

        $now = time();

        try {
            $this->repository->add(
                $lane,
                $purpose,
                $payload,
                $reason,
                date('Y-m-d H:i:s', $now + self::BACKOFF_MINUTES[0] * 60),
                date('Y-m-d H:i:s', $now + $this->lifetimeHours() * 3600)
            );
        } catch (\Throwable) {
            // A queue that cannot be written to must not swallow the
            // message: the caller still has its exception, and reporting
            // the failure is better than reporting a deferral that never
            // happened.
            return false;
        }

        return true;
    }

    /**
     * The next attempt for a message that has just failed again — or null
     * when it has run out of time and must be abandoned (D9, D16).
     */
    public function nextAttemptFor(DeferredMessage $message, ?string $now = null): ?string
    {
        $now ??= date('Y-m-d H:i:s');
        $index = min($message->attempts, count(self::BACKOFF_MINUTES) - 1);
        $next = strtotime($now) + self::BACKOFF_MINUTES[$index] * 60;

        if ($next >= strtotime($message->expiresAt)) {
            // The next try would land past the deadline, so there is no
            // next try. Abandoning now rather than scheduling an attempt
            // that would be refused on arrival keeps the queue honest
            // about what it still intends to send.
            return null;
        }

        return date('Y-m-d H:i:s', $next);
    }

    /**
     * The windows the Relance dialog offers, in hours (D17).
     *
     * **The default is the shortest one**, and that choice is the whole
     * safety of this feature. The failures a volunteer means to relaunch
     * are the ones from this morning's outage; a dialog that defaults to
     * « tout » would, on the first click, re-send a fortnight of messages
     * whose recipients have long since been told by other means — and
     * would do it to a relay that has only just come back.
     *
     * @var array<string, int>
     */
    public const WINDOWS = [
        'recent' => 6,
        'day' => 24,
        'week' => 24 * 7,
    ];

    public const DEFAULT_WINDOW = 'day';

    /**
     * Put abandoned messages back in the queue (D17).
     *
     * Per lane and per window, both chosen by the person clicking:
     * relaunching yesterday's newsletters and this morning's receipts are
     * different decisions, and a single button that did both would be
     * used for neither.
     *
     * A revived message starts a fresh life — attempts back to zero, a
     * new deadline from the current setting — because it is being sent
     * again on purpose, not resumed. The reason it failed is cleared with
     * it: keeping « délai de vie dépassé » on a message now due in five
     * minutes would describe the last attempt as if it were this one.
     *
     * @param array<int, MailLane> $lanes
     * @return int How many were put back.
     */
    public function relaunch(array $lanes, string $window, ?string $now = null): int
    {
        $hours = self::WINDOWS[$window] ?? self::WINDOWS[self::DEFAULT_WINDOW];
        $moment = strtotime($now ?? date('Y-m-d H:i:s'));
        $since = date('Y-m-d H:i:s', $moment - $hours * 3600);
        $nextAttemptAt = date('Y-m-d H:i:s', $moment);
        $expiresAt = date('Y-m-d H:i:s', $moment + $this->lifetimeHours() * 3600);

        $revived = 0;
        foreach ($lanes as $lane) {
            if (!$this->defers($lane)) {
                // The authentication lane has nothing to relaunch — it
                // never queued anything (D9) — and accepting it here
                // would let a crafted form imply otherwise.
                continue;
            }

            foreach ($this->repository->abandonedIds($lane, $since) as $id) {
                $this->repository->revive($id, $nextAttemptAt, $expiresAt);
                $revived++;
            }
        }

        if ($revived > 0) {
            $this->journalEvent('mail_deferred_relaunched', 'Relance manuelle des messages abandonnés', [
                'count' => $revived,
                'window_hours' => $hours,
                'lanes' => array_map(static fn(MailLane $lane): string => $lane->value, $lanes),
            ]);
        }

        return $revived;
    }

    public function lifetimeHours(): int
    {
        return max(1, (int) $this->settings->get(self::SETTING_LIFETIME_HOURS, null, (string) self::DEFAULT_LIFETIME_HOURS));
    }

    public function abandonedRetentionDays(): int
    {
        return max(
            1,
            (int) $this->settings->get(
                self::SETTING_ABANDONED_RETENTION_DAYS,
                null,
                (string) self::DEFAULT_ABANDONED_RETENTION_DAYS
            )
        );
    }

    /**
     * How the Relance dialog describes what it is offering (D17).
     *
     * Buckets rather than a number, because « 23 échecs » does not tell
     * anybody whether relaunching is a good idea and « 18 de moins de
     * 24 h, 5 de plus d'une semaine » does.
     *
     * @return array{recent: int, day: int, week: int, older: int, total: int}
     */
    public function abandonedByAge(?MailLane $lane = null, ?string $now = null): array
    {
        $buckets = ['recent' => 0, 'day' => 0, 'week' => 0, 'older' => 0, 'total' => 0];

        $moment = strtotime($now ?? date('Y-m-d H:i:s'));

        foreach ($this->repository->abandonedCreatedAt($lane) as $createdAt) {
            // Read from the timestamps alone: counting how old something
            // is never needs its body decrypted (D18).
            $hours = max(0.0, ($moment - strtotime($createdAt)) / 3600);
            $buckets['total']++;

            if ($hours < 24) {
                $buckets[$hours < 6 ? 'recent' : 'day']++;
            } elseif ($hours < 24 * 7) {
                $buckets['week']++;
            } else {
                $buckets['older']++;
            }
        }

        return $buckets;
    }

    /**
     * @param array{attachments: array<int, array{name: string, content: string}>} $payload
     */
    private function attachmentBytes(array $payload): int
    {
        $bytes = 0;
        foreach ($payload['attachments'] as $attachment) {
            $bytes += strlen($attachment['content']);
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function journalEvent(string $event, string $title, array $context): void
    {
        try {
            $this->journal?->log('core', $event, 'info', $title, $context);
        } catch (\Throwable) {
            // Never the reason a message stops.
        }
    }
}
