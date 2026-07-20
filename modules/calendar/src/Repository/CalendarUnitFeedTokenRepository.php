<?php

declare(strict_types=1);

namespace Modules\Calendar\Repository;

/**
 * Single global row holding the bearer token for the "unité complète"
 * aggregate ICS feed (all configured calendars combined).
 */
class CalendarUnitFeedTokenRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findToken(): ?string
    {
        $stmt = $this->pdo->query('SELECT token FROM calendar_unit_feed_token LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $token = $stmt->fetchColumn();
        return $token !== false ? (string) $token : null;
    }

    public function tokenExists(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM calendar_unit_feed_token WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Insert-or-replace the single global token row.
     */
    public function setToken(string $token): void
    {
        $stmt = $this->pdo->query('SELECT id FROM calendar_unit_feed_token LIMIT 1');
        $existingId = $stmt !== false ? $stmt->fetchColumn() : false;

        if ($existingId === false) {
            $stmt = $this->pdo->prepare('INSERT INTO calendar_unit_feed_token (token) VALUES (?)');
            $stmt->execute([$token]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE calendar_unit_feed_token SET token = ? WHERE id = ?');
        $stmt->execute([$token, $existingId]);
    }
}
