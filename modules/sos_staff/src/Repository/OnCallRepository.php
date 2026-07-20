<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

class OnCallRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return OnCallAssignment[]
     */
    public function findForRange(string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sos_oncall_assignments WHERE assignment_date BETWEEN ? AND ? ORDER BY assignment_date ASC, id ASC'
        );
        $stmt->execute([$fromDate, $toDate]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @return OnCallAssignment[]
     */
    public function findForDate(string $date): array
    {
        return $this->findForRange($date, $date);
    }

    /**
     * Every assignment currently stored — bounded in practice by the >1
     * year purge (module spec §6), not by this query. Used by
     * Service\CalendarSyncService::resync(), which rebuilds every synced
     * calendar event from scratch on each call.
     *
     * @return OnCallAssignment[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM sos_oncall_assignments ORDER BY assignment_date ASC, id ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Replace every assignment within [fromDate, toDate] with $assignments
     * in one transaction — matches the admin page's "save the complete
     * month state" behavior (module spec §2.6): sparse storage, so a
     * member with no row for a day is simply "available".
     *
     * @param OnCallAssignment[] $assignments
     */
    public function replaceRange(string $fromDate, string $toDate, array $assignments): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sos_oncall_assignments WHERE assignment_date BETWEEN ? AND ?');
            $stmt->execute([$fromDate, $toDate]);

            $insert = $this->pdo->prepare(
                'INSERT INTO sos_oncall_assignments (member_id, assignment_date, state) VALUES (?, ?, ?)'
            );
            foreach ($assignments as $assignment) {
                $insert->execute([$assignment->memberId, $assignment->date, $assignment->state]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Purge assignments older than one year (module spec §6). Returns the
     * number of rows deleted.
     */
    public function deleteOlderThan(string $cutoffDate): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sos_oncall_assignments WHERE assignment_date < ?');
        $stmt->execute([$cutoffDate]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OnCallAssignment
    {
        return new OnCallAssignment(
            memberId: (int) $row['member_id'],
            date: (string) $row['assignment_date'],
            state: (string) $row['state']
        );
    }
}
