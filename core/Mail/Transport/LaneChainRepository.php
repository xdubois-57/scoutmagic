<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use PDO;

/**
 * `mail_lane_entries` — the three ordered chains
 * (ARCHITECTURE.md §8.106).
 *
 * Order IS the chain: the first enabled entry is tried, then the next
 * when it fails or has spent its quota. Nothing here refuses to empty a
 * lane — that rule lives in `TransportService`, which is where a refusal
 * can be explained to somebody.
 */
final class LaneChainRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Every entry of one lane, in chain order.
     *
     * @return array<int, LaneEntry>
     */
    public function forLane(MailLane $lane): array
    {
        $statement = $this->pdo->prepare(
            'SELECT lane, provider_id, position, is_enabled
             FROM mail_lane_entries WHERE lane = ? ORDER BY position, id'
        );
        $statement->execute([$lane->value]);

        return array_map([$this, 'hydrate'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Every entry of every lane, keyed by lane value.
     *
     * One query rather than three: the configuration screen and the
     * support collector both want the whole picture.
     *
     * @return array<string, array<int, LaneEntry>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT lane, provider_id, position, is_enabled
             FROM mail_lane_entries ORDER BY lane, position, id'
        );

        $chains = [];
        foreach (MailLane::ordered() as $lane) {
            $chains[$lane->value] = [];
        }

        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entry = $this->hydrate($row);
            $chains[$entry->lane->value][] = $entry;
        }

        return $chains;
    }

    /**
     * Put a provider at the end of all three chains.
     *
     * Disabled everywhere, deliberately: a relay nobody has placed in a
     * lane must send nothing, and an addition that silently started
     * routing the site's sign-in links would be the opposite of what this
     * screen is for. The page says so in as many words.
     */
    public function appendToEveryLane(int $providerId, bool $enabled = false): void
    {
        foreach (MailLane::ordered() as $lane) {
            $this->append($lane, $providerId, $enabled);
        }
    }

    public function append(MailLane $lane, int $providerId, bool $enabled): void
    {
        $next = $this->pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM mail_lane_entries WHERE lane = ?');
        $next->execute([$lane->value]);
        $position = (int) $next->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO mail_lane_entries (lane, provider_id, position, is_enabled) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$lane->value, $providerId, $position, $enabled ? 1 : 0]);
    }

    public function exists(MailLane $lane, int $providerId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mail_lane_entries WHERE lane = ? AND provider_id = ?'
        );
        $statement->execute([$lane->value, $providerId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Rewrite one lane's order from a list of provider ids.
     *
     * Ids the lane does not hold are ignored rather than inserted: this
     * is a reordering, and a browser sending an id from another page must
     * not create an entry.
     *
     * @param array<int, int> $providerIds
     */
    public function reorder(MailLane $lane, array $providerIds): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_lane_entries SET position = ? WHERE lane = ? AND provider_id = ?'
        );

        $position = 0;
        foreach ($providerIds as $providerId) {
            $statement->execute([$position, $lane->value, $providerId]);
            $position++;
        }
    }

    public function setEnabled(MailLane $lane, int $providerId, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_lane_entries SET is_enabled = ? WHERE lane = ? AND provider_id = ?'
        );
        $statement->execute([$enabled ? 1 : 0, $lane->value, $providerId]);
    }

    public function removeProvider(int $providerId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_lane_entries WHERE provider_id = ?');
        $statement->execute([$providerId]);
    }

    /**
     * How many entries of this lane are currently enabled.
     *
     * The one figure the « never empty a lane » rule is decided on.
     */
    public function countEnabled(MailLane $lane): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM mail_lane_entries WHERE lane = ? AND is_enabled = 1'
        );
        $statement->execute([$lane->value]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LaneEntry
    {
        return new LaneEntry(
            MailLane::from((string) $row['lane']),
            (int) $row['provider_id'],
            (int) $row['position'],
            (bool) $row['is_enabled']
        );
    }
}
