<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;

/**
 * Same convention as Core\Security\MagicLinkRepository/
 * PasswordResetRepository: every timestamp comparison is computed in PHP
 * and bound as a parameter, never MySQL's NOW(), so this repository (and
 * its tests) work unmodified against the SQLite test database too.
 */
class MemberEmailRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Creates a new 'manual' address, always 'pending'. Caller
     * (MemberEmailService::addEmail()) has already checked no row exists
     * for this (member, address) pair — the unique index is the last-
     * resort safety net, not the primary dedupe path.
     */
    public function create(
        int $memberId,
        string $email,
        string $source,
        string $status,
        ?string $confirmationTokenHash,
        ?\DateTimeImmutable $confirmationExpiresAt
    ): int {
        $normalized = strtolower(trim($email));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails
                (member_id, email_encrypted, email_blind_index, source, status,
                 confirmation_token_hash, confirmation_expires_at, last_confirmation_sent_at, confirmed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($normalized),
            $this->encryption->blindIndex($normalized),
            $source,
            $status,
            $confirmationTokenHash,
            $confirmationExpiresAt?->format('Y-m-d H:i:s'),
            $confirmationTokenHash !== null ? $now : null,
            $status === MemberEmail::STATUS_VALID ? $now : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?MemberEmail
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_emails WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return MemberEmail[] every address for this member (any status/
     *         source), most recently created first — the member page lists
     *         them all, synthesizing the Desk row separately (see
     *         MemberEmailService::listForMember()).
     */
    public function findByMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM member_emails WHERE member_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$memberId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The dedupe check behind "duplicate email within the same member
     * (any status) must reuse the existing row" — and the lookup behind
     * resolving the Desk address's own status override row.
     */
    public function findByMemberAndEmail(int $memberId, string $email): ?MemberEmail
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)));
        $stmt = $this->pdo->prepare('SELECT * FROM member_emails WHERE member_id = ? AND email_blind_index = ?');
        $stmt->execute([$memberId, $blindIndex]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Every member_emails row (any member, any source) sharing this exact
     * address — module addendum: unsubscribing an address must apply to
     * the address itself, not only the one member profile a particular
     * mass-mail send happened to be tied to. The same address can
     * legitimately be linked to several members (e.g. a parent's address
     * added to more than one child) — MemberEmailService::unsubscribe()
     * marks every one of them inactive and lists them all in the
     * notification emails.
     *
     * @return MemberEmail[]
     */
    public function findAllByEmail(string $email): array
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)));
        $stmt = $this->pdo->prepare('SELECT * FROM member_emails WHERE email_blind_index = ?');
        $stmt->execute([$blindIndex]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every member_id with at least one currently-'valid' row matching
     * this email's blind index — Core\Security\RoleResolver's extension
     * point for login-by-secondary-email. A 'pending'/'inactive' row must
     * never resolve a login.
     *
     * @return int[]
     */
    public function findMemberIdsByValidBlindIndex(string $blindIndex): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT member_id FROM member_emails WHERE email_blind_index = ? AND status = 'valid'"
        );
        $stmt->execute([$blindIndex]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Whether this exact (member, address) pair has been unsubscribed —
     * Core\Security\RoleResolver's extension point for the one Desk-
     * address exception (schema/core.sql): unlike every other Desk-
     * address rule, an unsubscribed 'desk'-sourced override row DOES
     * revoke login, not just mass-mail delivery. Pure blind-index lookup,
     * no decryption needed.
     */
    public function isBlindIndexInactiveForMember(int $memberId, string $blindIndex): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM member_emails WHERE member_id = ? AND email_blind_index = ? AND status = 'inactive'"
        );
        $stmt->execute([$memberId, $blindIndex]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return MemberEmail[] every currently-'valid' row for this member
     *         (source-agnostic) — Modules\MassMail\Service\MassMailService's
     *         recipient expansion reads secondary addresses from here; the
     *         Desk address itself is resolved separately (it's not stored
     *         here unless it has an override row, see
     *         resolveOrCreateDeskOverride()).
     */
    public function findValidByMember(int $memberId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM member_emails WHERE member_id = ? AND status = 'valid' AND source = 'manual'");
        $stmt->execute([$memberId]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Find-or-create the 'desk'-sourced status-override row for this
     * exact Desk address (module addendum, see schema/core.sql's
     * member_emails comment) — lazily materialized only when mass-mail
     * resolution or an unsubscribe action first needs one to exist,
     * defaulting to 'valid' (the Desk address is otherwise always usable).
     * Never created at Desk import time and never synced afterwards — a
     * later change of the member's Desk address simply starts fresh with
     * no row here.
     */
    public function findOrCreateDeskOverride(int $memberId, string $email): MemberEmail
    {
        $existing = $this->findByMemberAndEmail($memberId, $email);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $id = $this->create($memberId, $email, MemberEmail::SOURCE_DESK, MemberEmail::STATUS_VALID, null, null);
        } catch (\PDOException) {
            // Unique index race (findByMemberAndEmail found nothing, but a
            // concurrent request created it in between) — re-select rather
            // than fail.
            $existing = $this->findByMemberAndEmail($memberId, $email);
            \assert($existing !== null);
            return $existing;
        }

        $created = $this->findById($id);
        \assert($created !== null);
        return $created;
    }

    public function markValid(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE member_emails SET status = 'valid', confirmed_at = ?, confirmation_token_hash = NULL, confirmation_expires_at = NULL WHERE id = ?"
        );
        $stmt->execute([$now, $id]);
    }

    /**
     * Reactivate (member's own page action, or a Desk-address override
     * row): straight back to 'valid', no reconfirmation.
     */
    public function reactivate(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE member_emails SET status = 'valid' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Unsubscribe (mass-mail mechanism only — never reachable from the
     * member's own page directly).
     */
    public function markInactive(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE member_emails SET status = 'inactive', deactivated_at = ? WHERE id = ?");
        $stmt->execute([$now, $id]);
    }

    /**
     * Regenerates the confirmation token for a resend — same row, new
     * hash/expiry, cooldown timestamp bumped.
     */
    public function refreshConfirmation(int $id, string $confirmationTokenHash, \DateTimeImmutable $confirmationExpiresAt): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE member_emails SET confirmation_token_hash = ?, confirmation_expires_at = ?, last_confirmation_sent_at = ? WHERE id = ?'
        );
        $stmt->execute([$confirmationTokenHash, $confirmationExpiresAt->format('Y-m-d H:i:s'), $now, $id]);
    }

    /**
     * Hard delete — a pending address cancels outright, a valid/inactive
     * secondary address is permanently removed (module addendum: never
     * for a 'desk'-sourced row, enforced by the caller).
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM member_emails WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MemberEmail
    {
        return new MemberEmail(
            id: (int) $row['id'],
            memberId: (int) $row['member_id'],
            email: $this->encryption->decrypt($row['email_encrypted']),
            source: (string) $row['source'],
            status: (string) $row['status'],
            confirmationTokenHash: $row['confirmation_token_hash'] !== null ? (string) $row['confirmation_token_hash'] : null,
            confirmationExpiresAt: $row['confirmation_expires_at'] !== null ? new \DateTimeImmutable($row['confirmation_expires_at']) : null,
            lastConfirmationSentAt: $row['last_confirmation_sent_at'] !== null ? new \DateTimeImmutable($row['last_confirmation_sent_at']) : null,
            confirmedAt: $row['confirmed_at'] !== null ? new \DateTimeImmutable($row['confirmed_at']) : null,
            deactivatedAt: $row['deactivated_at'] !== null ? new \DateTimeImmutable($row['deactivated_at']) : null,
            createdAt: new \DateTimeImmutable($row['created_at'])
        );
    }
}
