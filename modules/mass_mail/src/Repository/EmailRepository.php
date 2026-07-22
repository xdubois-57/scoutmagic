<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_emails — subject/body hold no personal data (module spec:
 * admin-authored content, not imported data) so this repository never
 * touches EncryptionService, unlike Repository\RecipientRepository.
 *
 * An email's scout year(s) live in the mass_mail_email_scout_years
 * junction table (module addendum: an email may target several years at
 * once) — handled here directly, same precedent as Repository\
 * MailingListRepository folding its own function/section junction tables
 * into itself rather than a separate repository class.
 */
class EmailRepository
{
    private const PER_PAGE = 50;

    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Email
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param int[] $scoutYearIds At least one.
     */
    public function create(
        string $subject,
        string $bodyHtml,
        int $sectionId,
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds,
        ?int $createdBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_emails
                (subject, body_html, section_id, list_type, list_id, list_section_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId, Email::STATUS_DRAFT, $createdBy]);
        $id = (int) $this->pdo->lastInsertId();

        $this->replaceScoutYears($id, $scoutYearIds);

        return $id;
    }

    /**
     * @param int[] $scoutYearIds At least one.
     */
    public function update(
        int $id,
        string $subject,
        string $bodyHtml,
        int $sectionId,
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE mass_mail_emails
             SET subject = ?, body_html = ?, section_id = ?, list_type = ?, list_id = ?, list_section_id = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $stmt->execute([$subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId, $id]);

        $this->replaceScoutYears($id, $scoutYearIds);
    }

    public function updateStatus(int $id, string $status, bool $setSentAt = false): void
    {
        if ($setSentAt) {
            $stmt = $this->pdo->prepare(
                'UPDATE mass_mail_emails SET status = ?, sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
        } else {
            $stmt = $this->pdo->prepare('UPDATE mass_mail_emails SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        }
        $stmt->execute([$status, $id]);
    }

    /**
     * @return int[]
     */
    public function getScoutYearIds(int $emailId): array
    {
        $stmt = $this->pdo->prepare('SELECT scout_year_id FROM mass_mail_email_scout_years WHERE email_id = ? ORDER BY scout_year_id ASC');
        $stmt->execute([$emailId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param int[] $scoutYearIds
     */
    private function replaceScoutYears(int $emailId, array $scoutYearIds): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_email_scout_years WHERE email_id = ?')->execute([$emailId]);

        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_email_scout_years (email_id, scout_year_id) VALUES (?, ?)');
        foreach (array_unique($scoutYearIds) as $scoutYearId) {
            $stmt->execute([$emailId, $scoutYearId]);
        }
    }

    /**
     * Server-side paginated, filtered list for the "Envoi de mails" page —
     * module spec: 50/page, most recent first, free-text search across
     * subject/sender-section-name/list-name (both custom-list names and
     * the two fixed default-list labels, matched in PHP since they're not
     * a real column — see the two $matchesActiveMembersLabel/
     * $matchesChiefsLabel params).
     *
     * @return array{emails: Email[], total: int}
     */
    public function findFiltered(
        string $search,
        ?string $status,
        ?int $sectionId,
        bool $matchesActiveMembersLabel,
        bool $matchesChiefsLabel,
        int $page
    ): array {
        $where = [];
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $searchConditions = ['e.subject LIKE ?', 'sender.name LIKE ?', 'list_sec.name LIKE ?', 'ml.name LIKE ?'];
            array_push($params, $like, $like, $like, $like);
            if ($matchesActiveMembersLabel) {
                $searchConditions[] = "e.list_type = 'default_active_members'";
            }
            if ($matchesChiefsLabel) {
                $searchConditions[] = "e.list_type = 'default_chiefs'";
            }
            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
        }
        if ($status !== null) {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        if ($sectionId !== null) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
        }

        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM mass_mail_emails e
             JOIN sections sender ON sender.id = e.section_id
             LEFT JOIN sections list_sec ON list_sec.id = e.list_section_id
             LEFT JOIN mass_mail_lists ml ON ml.id = e.list_id
             {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $offset = (max(1, $page) - 1) * self::PER_PAGE;

        $stmt = $this->pdo->prepare(
            "SELECT e.* FROM mass_mail_emails e
             JOIN sections sender ON sender.id = e.section_id
             LEFT JOIN sections list_sec ON list_sec.id = e.list_section_id
             LEFT JOIN mass_mail_lists ml ON ml.id = e.list_id
             {$whereSql}
             ORDER BY e.created_at DESC, e.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Scout years batch-fetched separately (one IN() query) rather than
        // joined/aggregated in SQL, which differs too much between MySQL
        // (GROUP_CONCAT) and the SQLite test database to keep portable.
        $emailIds = array_map(fn(array $row) => (int) $row['id'], $rows);
        $scoutYearsByEmailId = $this->getScoutYearIdsForEmails($emailIds);

        return [
            'emails' => array_map(fn(array $row) => $this->hydrate($row, $scoutYearsByEmailId[(int) $row['id']] ?? []), $rows),
            'total' => $total,
        ];
    }

    public static function perPage(): int
    {
        return self::PER_PAGE;
    }

    /**
     * @param int[] $emailIds
     * @return array<int, int[]>
     */
    private function getScoutYearIdsForEmails(array $emailIds): array
    {
        if ($emailIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT email_id, scout_year_id FROM mass_mail_email_scout_years WHERE email_id IN ({$placeholders}) ORDER BY scout_year_id ASC"
        );
        $stmt->execute($emailIds);

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['email_id']][] = (int) $row['scout_year_id'];
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param int[]|null $scoutYearIds Pre-fetched (e.g. by findFiltered()'s batch query) — fetched fresh when omitted.
     */
    private function hydrate(array $row, ?array $scoutYearIds = null): Email
    {
        return new Email(
            id: (int) $row['id'],
            subject: (string) $row['subject'],
            bodyHtml: (string) $row['body_html'],
            sectionId: (int) $row['section_id'],
            listType: (string) $row['list_type'],
            listId: $row['list_id'] !== null ? (int) $row['list_id'] : null,
            listSectionId: $row['list_section_id'] !== null ? (int) $row['list_section_id'] : null,
            scoutYearIds: $scoutYearIds ?? $this->getScoutYearIds((int) $row['id']),
            status: (string) $row['status'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            sentAt: $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
