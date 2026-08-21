<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Reminder;

/**
 * One reminder that is due today.
 *
 * **Carries no personal data.** The title and body name the booking by its
 * reference and the asset by its name, never the renter — an internal
 * reminder lands in a notification centre, a push payload and, depending on
 * the channel settings, an email subject line, and a renter's name has no
 * business travelling through any of them (§6.29, SECURITY.md §5). The
 * manager who opens the link sees everything; the notification tells them
 * only that there is something to see.
 */
class DueReminder
{
    public function __construct(
        public readonly ReminderKind $kind,
        /** The booking or register entry this is about. */
        public readonly int $subjectId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        /**
         * Which asset it concerns, so the dispatcher can work out who
         * manages it. Reminders are addressed to the people responsible for
         * *that* hall, never broadcast to every manager in the unit.
         */
        public readonly ?int $assetId = null
    ) {
    }

    public function subjectType(): string
    {
        return $this->kind->subjectType();
    }
}
