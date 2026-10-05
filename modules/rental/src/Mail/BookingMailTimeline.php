<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Mail;

use Modules\InboundMail\Api\InboundMessage;

/**
 * The entries of a booking's « Courrier » page (#720), received and sent
 * mixed, most recent first.
 *
 * One shape for every entry, whatever its origin, so that each one opens
 * the same way: a direction (« Reçu » / « Envoyé »), a date, a subject, who
 * wrote or to whom, a one-line excerpt and the whole message in a dialog.
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
     * @param SentEmail[] $sent what the site sent the renter about it
     * @param array<int, string> $documentNames the booking's documents, by id,
     *     for the attachments a sent e-mail names
     * @return list<array{
     *     direction: string, at: \DateTimeImmutable, excerpt: string, has_body: bool,
     *     message: ?InboundMessage, sent: ?SentEmail, attachment_names: list<string>
     * }>
     */
    public static function of(array $received, array $sent = [], array $documentNames = []): array
    {
        $entries = [];
        foreach ($received as $message) {
            $body = self::flatten($message->bodyText);
            $entries[] = [
                'direction' => 'received',
                'at' => $message->sentAt,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
                'message' => $message,
                'sent' => null,
                'attachment_names' => [],
            ];
        }

        foreach ($sent as $email) {
            $body = self::flatten($email->displayText());
            $entries[] = [
                'direction' => 'sent',
                'at' => $email->sentAt,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                'has_body' => $body !== '',
                'message' => null,
                'sent' => $email,
                // A document deleted since keeps a line, so the entry still
                // says something was attached.
                'attachment_names' => array_map(
                    static fn(int $id): string => $documentNames[$id] ?? 'Document supprimé depuis',
                    $email->documentIds
                ),
            ];
        }

        // Most recent first: the page is opened to see what came in last.
        usort(
            $entries,
            static fn(array $a, array $b): int => $b['at'] <=> $a['at']
        );

        return $entries;
    }

    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
