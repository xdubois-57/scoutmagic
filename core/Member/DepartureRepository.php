<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;

/**
 * member_years.leaving/leaving_marked_at/leaving_comment_encrypted —
 * Core\Member\DepartureService's storage layer. leaving_comment_encrypted
 * is a free-text reason, often sensitive (conflict, family situation,
 * health) — encrypted/decrypted only here (SECURITY.md §5), never in the
 * Service or in a journal entry.
 */
class DepartureRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function markLeaving(int $memberYearId, ?string $comment): void
    {
        $trimmed = $comment !== null ? trim($comment) : '';
        $commentEncrypted = $trimmed !== '' ? $this->encryption->encrypt($trimmed) : null;

        $stmt = $this->pdo->prepare(
            'UPDATE member_years SET leaving = 1, leaving_marked_at = ?, leaving_comment_encrypted = ? WHERE id = ?'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $commentEncrypted, $memberYearId]);
    }

    public function unmarkLeaving(int $memberYearId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE member_years SET leaving = 0, leaving_marked_at = NULL, leaving_comment_encrypted = NULL WHERE id = ?'
        );
        $stmt->execute([$memberYearId]);
    }

    /**
     * Updates ONLY the comment, leaving `leaving`/`leaving_marked_at`
     * untouched — the registration module's "Départs" page (ARCHITECTURE.md
     * §8.36) saves the checkbox and the comment as two independent
     * auto-save requests specifically so one never silently clobbers the
     * other when two animateurs edit the same section around the same
     * time (module spec's own concurrency requirement). Harmless to call
     * on a not-currently-leaving row; the UI simply never does.
     */
    public function updateComment(int $memberYearId, ?string $comment): void
    {
        $trimmed = $comment !== null ? trim($comment) : '';
        $commentEncrypted = $trimmed !== '' ? $this->encryption->encrypt($trimmed) : null;

        $stmt = $this->pdo->prepare('UPDATE member_years SET leaving_comment_encrypted = ? WHERE id = ?');
        $stmt->execute([$commentEncrypted, $memberYearId]);
    }

    public function getStatus(int $memberYearId): ?DepartureStatus
    {
        $stmt = $this->pdo->prepare(
            'SELECT leaving, leaving_marked_at, leaving_comment_encrypted FROM member_years WHERE id = ?'
        );
        $stmt->execute([$memberYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new DepartureStatus(
            leaving: (bool) $row['leaving'],
            markedAt: $row['leaving_marked_at'] !== null ? new \DateTimeImmutable($row['leaving_marked_at']) : null,
            comment: $row['leaving_comment_encrypted'] !== null ? $this->encryption->decrypt($row['leaving_comment_encrypted']) : null
        );
    }

    /**
     * The persistent member id for a member_year row — DepartureService
     * needs this for its journal entry (member_id only, never
     * member_year_id, per the module spec) without making every caller
     * pass it separately.
     */
    public function findMemberId(int $memberYearId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int) $value : null;
    }
}
