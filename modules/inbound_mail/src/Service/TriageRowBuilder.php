<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;

/**
 * The rows of a triage screen — `InboundMailInterface::triageRows()`,
 * built from that interface's own scoped reads and nothing else.
 *
 * Kept apart from `InboundMailService` so that it is written once over
 * the interface: every implementation of it, the real one and the test
 * doubles alike, answers `triageRows()` with the same rows from whatever
 * its `findForTriage()` returns.
 */
final class TriageRowBuilder
{
    /**
     * A one-line excerpt's worth: the whole message opens in a dialog.
     */
    public const EXCERPT_LENGTH = 220;

    /**
     * @param string[] $ownReferences
     * @return list<array{message: InboundMessage, excerpt: string, truncated: bool, has_body: bool,
     *     attachment_count: int, links: \Modules\InboundMail\Api\MessageLink[],
     *     candidates: \Modules\InboundMail\Api\MessageCandidate[]}>
     */
    public static function build(
        InboundMailInterface $mail,
        string $consumerId,
        array $ownReferences,
        int $limit,
        bool $dismissed
    ): array {
        $messages = $mail->findForTriage($consumerId, $ownReferences, $limit, $dismissed);
        $candidates = $mail->findCandidatesFor(
            $consumerId,
            array_map(static fn(InboundMessage $message): int => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $body = trim($message->bodyText);
            $rows[] = [
                'message' => $message,
                'excerpt' => mb_substr($body, 0, self::EXCERPT_LENGTH),
                // Whether the excerpt actually cut something off, so the
                // screen promises "there is more" only when there is.
                'truncated' => mb_strlen($body) > self::EXCERPT_LENGTH,
                'has_body' => $body !== '' || trim($message->bodyHtml) !== '',
                'attachment_count' => count($message->attachments),
                'links' => $message->linksFor($consumerId),
                'candidates' => $candidates[$message->id] ?? [],
            ];
        }

        return $rows;
    }
}
