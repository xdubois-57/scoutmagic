<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Repository;

use Core\Config\AppClock;

/**
 * The short code an evening's sheet is reached by — one per event, kept.
 *
 * **The code is created once and reused for ever after.** An agenda
 * refreshes every few hours, so minting one per read would fill
 * `short_urls` with thousands of rows and, worse, change the link in
 * somebody's calendar every time it syncs.
 */
class PresenceEventLinkRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findCode(int $eventId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT short_code FROM presences_event_links WHERE calendar_event_id = ?');
        $stmt->execute([$eventId]);
        $code = $stmt->fetchColumn();

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Remember a code for an event, unless one is already remembered.
     *
     * Two agendas refreshing at the same second both find no code and
     * both mint one; the UNIQUE index is what settles it, and the loser
     * of that race takes the winner's code rather than raising — its own
     * freshly-minted `short_urls` row is simply never pointed at, which
     * is a wasted row and not a broken link.
     *
     * @return string the code now in force for this event
     */
    public function rememberCode(int $eventId, string $code): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO presences_event_links (calendar_event_id, short_code, created_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$eventId, $code, AppClock::now()->format('Y-m-d H:i:s')]);

            return $code;
        } catch (\PDOException $e) {
            return $this->findCode($eventId) ?? throw $e;
        }
    }

    /**
     * Forget the code an event was reached by, for when the evening
     * itself is deleted — see
     * `Modules\Presences\Service\PresenceEventCleanupService`.
     */
    public function forgetEvent(int $eventId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM presences_event_links WHERE calendar_event_id = ?');
        $stmt->execute([$eventId]);
    }
}
