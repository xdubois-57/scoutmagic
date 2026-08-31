<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * The stored result of a cross-ticket analysis run (roadmap IT-28).
 *
 * **Stored, and that is the whole point.** The analysis reads what people
 * wrote about their own installations and sends it to an external
 * provider; recomputing it on every page load would repeat that
 * transmission every time somebody opened the page. A run is asked for,
 * kept, and read back until somebody asks for another.
 *
 * The result is encrypted like the descriptions it summarises: a summary
 * of personal data is personal data (SECURITY.md §5), and it is decrypted
 * here and nowhere else.
 */
class SupportTicketAnalysisRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function store(string $result, int $ticketCount, \DateTimeImmutable $requestedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_ticket_analyses (requested_at, ticket_count, result_encrypted)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $requestedAt->format('Y-m-d H:i:s'),
            max(0, $ticketCount),
            $this->encryption->encrypt($result, 'support_ticket_analyses.result'),
        ]);
    }

    /**
     * The most recent run, or null when none was ever asked for.
     *
     * @return array{requested_at: string, ticket_count: int, result: string}|null
     */
    public function latest(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT requested_at, ticket_count, result_encrypted
             FROM support_ticket_analyses ORDER BY requested_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return null;
        }

        return [
            'requested_at' => (string) $row['requested_at'],
            'ticket_count' => (int) $row['ticket_count'],
            'result' => $this->encryption->decrypt(
                (string) $row['result_encrypted'],
                'support_ticket_analyses.result'
            ),
        ];
    }

    /**
     * Drop the runs older than the tickets they summarise are kept for.
     *
     * An analysis outliving its own sources would be a summary of
     * descriptions that no longer exist anywhere — which is exactly the
     * shape of data nobody remembers they still hold.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM support_ticket_analyses WHERE requested_at < ?');
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    /**
     * When the last run happened, for the « analysé le … » line and for
     * anything that needs to compare it against a clock.
     */
    public function latestRequestedAt(): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->query('SELECT MAX(requested_at) FROM support_ticket_analyses');
        $value = $stmt !== false ? $stmt->fetchColumn() : false;

        return DateInput::fromStorage(is_string($value) ? $value : null);
    }
}
