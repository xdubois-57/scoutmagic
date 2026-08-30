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

    /**
     * Every private document this person holds, newest first, across all
     * scout years — what the Staff d'Unité sees on the admin member sheet.
     *
     * Deliberately not year-scoped, unlike the member's own page: a tax
     * certificate belongs to the season it covers, and the family asking
     * « nous n'avons rien reçu » is asking about last year's. A page that
     * showed only the effective year would answer « aucun document » to the
     * one question it exists to answer.
     *
     * @return MemberDocument[] Most recent first.
     */
    public function findByMember(int $memberId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, member_id, scout_year_id, title, file_id, created_at, created_by
             FROM member_documents
             WHERE member_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$memberId]);

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
     * Written by whatever produces a private document. The attestations
     * module is the first and, so far, only producer: it creates one row
     * per certificate when a batch is published (ARCHITECTURE.md §8.86).
     * There is still no manual upload UI — a document arrives because
     * something generated or split it, never because somebody attached it
     * by hand.
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
