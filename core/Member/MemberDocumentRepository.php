<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

class MemberDocumentRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return MemberDocument[] Most recent first.
     */
    public function findByMemberAndYear(int $memberId, int $scoutYearId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id, scout_year_id, title, file_id, created_at, created_by
             FROM member_documents
             WHERE member_id = ? AND scout_year_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$memberId, $scoutYearId]);

        return array_map(
            fn(array $row) => self::mapRow($row),
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findById(int $id): ?MemberDocument
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id, scout_year_id, title, file_id, created_at, created_by
             FROM member_documents WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapRow($row);
    }

    /**
     * Storage-only for this iteration — no admin UI calls this yet (member
     * page spec §5: "Build storage + display now; NO generation, NO admin
     * upload UI this iteration"). Exists so the table isn't dead weight and
     * future document-generation features have a ready write path.
     */
    public function create(int $memberId, int $scoutYearId, string $title, int $fileId, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_documents (member_id, scout_year_id, title, file_id, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, $title, $fileId, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): MemberDocument
    {
        return new MemberDocument(
            id: (int) $row['id'],
            memberId: (int) $row['member_id'],
            scoutYearId: (int) $row['scout_year_id'],
            title: (string) $row['title'],
            fileId: (int) $row['file_id'],
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
