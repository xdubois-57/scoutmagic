<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\Service\DateInput;

/**
 * The only place a member note is encrypted or decrypted (SECURITY.md
 * §5). Nothing above this class ever sees the ciphertext, and nothing
 * inside it ever writes to the journal or builds a message out of a
 * note's text — see MemberNoteService for why that matters here more
 * than almost anywhere else on the site.
 *
 * Notes are keyed on `members.id`, the persistent identity: a note about
 * a person outlives the scout year that saw it written.
 */
class MemberNoteRepository
{
    private const ENCRYPTION_CONTEXT = 'member_notes.body';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private UserAccountRepository $userAccountRepository
    ) {
    }

    /**
     * Every note about one member, most recent first.
     *
     * The author's name is resolved in the same pass rather than one
     * query per row, and a note whose author account is gone keeps its
     * place with a null name.
     *
     * @return MemberNote[]
     */
    public function findForMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM member_notes WHERE member_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$memberId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // One batched name lookup rather than one per row — the account
        // names are encrypted, so they cannot be joined on in SQL.
        $names = $this->userAccountRepository->findNamesByIds(
            array_values(array_filter(array_map(
                static fn(array $r): ?int => $r['created_by'] !== null ? (int) $r['created_by'] : null,
                $rows
            )))
        );

        return array_map(fn(array $row): MemberNote => $this->hydrate($row, $names), $rows);
    }

    public function findById(int $id): ?MemberNote
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_notes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $names = $row['created_by'] !== null
            ? $this->userAccountRepository->findNamesByIds([(int) $row['created_by']])
            : [];

        return $this->hydrate($row, $names);
    }

    public function create(int $memberId, string $body, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_notes (member_id, body, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->encryption->encrypt($body, self::ENCRYPTION_CONTEXT), $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The author and the creation date are deliberately NOT touched: they
     * are what gives the history its meaning, and an edit is a correction
     * to an entry rather than a new one by whoever happened to fix it.
     */
    public function update(int $id, string $body): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_notes SET body = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $this->encryption->encrypt($body, self::ENCRYPTION_CONTEXT),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM member_notes WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{first_name: ?string, last_name: ?string}> $names
     */
    private function hydrate(array $row, array $names): MemberNote
    {
        $createdBy = $row['created_by'] !== null ? (int) $row['created_by'] : null;
        $name = $createdBy !== null ? ($names[$createdBy] ?? null) : null;
        $authorName = $name !== null
            ? trim(trim((string) $name['first_name']) . ' ' . trim((string) $name['last_name']))
            : '';

        return new MemberNote(
            id: (int) $row['id'],
            memberId: (int) $row['member_id'],
            body: $this->encryption->decrypt($row['body'], self::ENCRYPTION_CONTEXT),
            createdBy: $createdBy,
            authorName: $authorName !== '' ? $authorName : null,
            // The column is NOT NULL, so a row without a readable one is
            // a corrupt row and deserves the 500 requireFromStorage()
            // raises — never today's date, which is what the raw
            // constructor answers for an empty string.
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'member_notes.created_at'),
            updatedAt: DateInput::fromStorage($row['updated_at'] !== null ? (string) $row['updated_at'] : null),
        );
    }
}
