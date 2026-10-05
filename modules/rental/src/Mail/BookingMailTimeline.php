<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Modules\InboundMail\Api\InboundMessage;

/**
 * The entries of a booking's « Courrier » page (#720), most recent first.
 *
 * One shape for every entry, whatever its origin, so that each one opens
 * the same way: a direction (« Reçu » today; the site's own sent mail joins
 * the same list), a date, a subject, who wrote, a one-line excerpt and the
 * whole message in a dialog.
 */
final class BookingMailTimeline
{
    /**
     * A one-line excerpt's worth: the whole message opens in a dialog,
     * as on the inbound mail screens.
     */
    public const EXCERPT_LENGTH = 160;

    /**
     * @param InboundMessage[] $received the messages filed under the booking
     * @return list<array{direction: string, message: InboundMessage, excerpt: string, has_body: bool}>
     */
    public static function of(array $received): array
    {
        $entries = [];
        foreach ($received as $message) {
            $body = trim((string) preg_replace('/\s+/u', ' ', $message->bodyText));
            $entries[] = [
                'direction' => 'received',
                'message' => $message,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
            ];
        }

        // Most recent first: the page is opened to see what came in last.
        usort(
            $entries,
            static fn(array $a, array $b): int => $b['message']->sentAt <=> $a['message']->sentAt
        );

        return $entries;
    }
}
