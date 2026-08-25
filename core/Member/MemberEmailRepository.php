<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

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
            $this->encryption->encrypt($normalized, 'member_emails.email'),
            $this->encryption->blindIndex($normalized, 'email'),
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
     * Batch variant of findValidByMember() — every currently-'valid'
     * 'manual' row for a whole list of members in ONE query, grouped by
     * member id. Added for pages that list many members at once (e.g. a
     * whole unit's roster) and would otherwise call findValidByMember()
     * once per member.
     *
     * @param int[] $memberIds
     * @return array<int, MemberEmail[]> keyed by member_id; a member with
     *         no valid secondary address is simply absent from the array
     */
    public function findValidByMemberIds(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_emails
             WHERE member_id IN ($placeholders) AND status = 'valid' AND source = 'manual'
             ORDER BY member_id, created_at ASC, id ASC"
        );
        $stmt->execute($memberIds);

        $grouped = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $memberId = (int) $row['member_id'];
            $grouped[$memberId][] = $this->hydrate($row);
        }

        return $grouped;
    }

    /**
     * The dedupe check behind "duplicate email within the same member
     * (any status) must reuse the existing row" — and the lookup behind
     * resolving the Desk address's own status override row.
     */
    public function findByMemberAndEmail(int $memberId, string $email): ?MemberEmail
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
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
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');
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
     * Every currently-'valid' row matching this blind index, decrypted —
     * the plaintext twin of findMemberIdsByValidBlindIndex(), for the one
     * caller that needs the address itself and not just the members it
     * reaches: Core\Security\AuthService, which gives a secondary address
     * logging in for the first time its own user_accounts row and has
     * nothing but the magic link's blind index to go on.
     *
     * @return MemberEmail[]
     */
    public function findValidByBlindIndex(string $blindIndex): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM member_emails WHERE email_blind_index = ? AND status = 'valid' ORDER BY id"
        );
        $stmt->execute([$blindIndex]);

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
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
            email: $this->encryption->decrypt($row['email_encrypted'], 'member_emails.email'),
            source: (string) $row['source'],
            status: (string) $row['status'],
            confirmationTokenHash: $row['confirmation_token_hash'] !== null ? (string) $row['confirmation_token_hash'] : null,
            confirmationExpiresAt: DateInput::fromStorage($row['confirmation_expires_at'] === null ? null : (string) $row['confirmation_expires_at']),
            lastConfirmationSentAt: DateInput::fromStorage($row['last_confirmation_sent_at'] === null ? null : (string) $row['last_confirmation_sent_at']),
            confirmedAt: DateInput::fromStorage($row['confirmed_at'] === null ? null : (string) $row['confirmed_at']),
            deactivatedAt: DateInput::fromStorage($row['deactivated_at'] === null ? null : (string) $row['deactivated_at']),
            createdAt: DateInput::requireFromStorage((string) $row['created_at'], 'created_at')
        );
    }
}
