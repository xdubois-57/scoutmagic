<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

/**
 * Is this address failing for now, or for good (roadmap IT-05)?
 *
 * **The one distinction that decides whether anybody is told.** A
 * transient failure is the mail system working as designed — the far end
 * was busy, the mailbox was momentarily full, the queue was backed up —
 * and treating each one as news would have a parent notified after every
 * mailing. A permanent failure is the far end saying « stop », and that is
 * worth interrupting somebody for.
 *
 * **Neither one is ever retried by us** (D16). A bounce means the message
 * was accepted and then refused further on: resending it is writing to an
 * address that has already answered. The transport's own retry loop is a
 * different thing entirely, and it is upstream of this — by the time a
 * `message/delivery-status` exists, the send succeeded.
 */
enum BounceSeverity: string
{
    /** 4.x.x — try this address again; it may well work next time. */
    case Transient = 'transient';

    /** 5.x.x — the far end will not accept this address. */
    case Permanent = 'permanent';

    public function label(): string
    {
        return match ($this) {
            self::Transient => 'Échec temporaire',
            self::Permanent => 'Échec définitif',
        };
    }

    /**
     * Read the severity out of an RFC 3463 enhanced status code.
     *
     * Anything that is not a well-formed 4.x.x or 5.x.x answers `null`
     * rather than a guess — including 2.x.x, which is a *success* code and
     * has no business in a failure report. A report the site cannot read
     * must not be allowed to mark somebody's address: an unreadable bounce
     * is a bounce nobody has diagnosed yet, not a permanent one.
     */
    public static function fromStatusCode(string $status): ?self
    {
        $parts = explode('.', trim($status));
        if (count($parts) !== 3) {
            return null;
        }

        return match ($parts[0]) {
            '4' => self::Transient,
            '5' => self::Permanent,
            default => null,
        };
    }
}
