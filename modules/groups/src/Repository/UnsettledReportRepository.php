<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Repository;

/**
 * Reported content still waiting for a moderator's answer, per group — the
 * attention page's question (issue #718 § C point 6), asked in two
 * aggregate queries rather than by loading each group's worklist.
 *
 * "Waiting" is ReportService's own reading: a moderator answers a report by
 * restoring the item (which sets `moderation_cleared`) or by deleting it
 * (which takes its reports with it, ON DELETE CASCADE). Hiding is not an
 * answer — it is what the threshold does on its own, and a hidden item
 * still sits on the group's « Signalements » page until someone restores
 * or deletes it. So an item counts while it has a report and is not
 * cleared, hidden or not.
 */
class UnsettledReportRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<array{group_id: int, group_name: string, items: int}> most items first
     */
    public function countsByGroup(): array
    {
        $stmt = $this->pdo->query(
            'SELECT g.id AS group_id, g.name AS group_name, COUNT(*) AS items
               FROM (
                    SELECT p.group_id
                      FROM discussion_group_posts p
                     WHERE p.moderation_cleared = 0
                       AND EXISTS (SELECT 1 FROM discussion_group_post_reports r WHERE r.post_id = p.id)
                    UNION ALL
                    SELECT p.group_id
                      FROM discussion_group_replies i
                      JOIN discussion_group_posts p ON p.id = i.post_id
                     WHERE i.moderation_cleared = 0
                       AND EXISTS (SELECT 1 FROM discussion_group_reply_reports r WHERE r.reply_id = i.id)
               ) waiting
               JOIN discussion_groups g ON g.id = waiting.group_id
           GROUP BY g.id, g.name
           ORDER BY items DESC, g.id ASC'
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn(array $row): array => [
                'group_id' => (int) $row['group_id'],
                'group_name' => (string) $row['group_name'],
                'items' => (int) $row['items'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }
}
