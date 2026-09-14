<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use PDO;

/**
 * Where the round trips are kept — one row per address, replaced on every
 * run (roadmap IT-03).
 *
 * **The address is the key, and that is what makes the reset free.** The
 * roadmap asks that changing an address put its state back to « jamais
 * vérifié »; a reset written as code is a reset somebody forgets to call
 * from the second place that edits an address. Looking the state up by
 * the address itself means a new address simply has no row, with nothing
 * to remember and nothing to maintain.
 *
 * The address is encrypted, and the lookup goes through the blind index —
 * `AGENTS.md` point 3, and the same shape `Core\Member\MemberEmailRepository`
 * uses for a member's.
 */
final class ReturnProbeRepository
{
    private const CONTEXT = 'mail_return_probes.address';

    public function __construct(
        private PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Record a run for one address, replacing whatever was there.
     *
     * @return int the probe's id
     */
    public function issue(
        string $address,
        string $correlationKey,
        \DateTimeImmutable $sentAt,
        \DateTimeImmutable $expiresAt
    ): int {
        $index = $this->indexOf($address);

        $delete = $this->pdo->prepare('DELETE FROM mail_return_probes WHERE address_blind_index = ?');
        $delete->execute([$index]);

        $insert = $this->pdo->prepare(
            'INSERT INTO mail_return_probes
                 (address_blind_index, address_encrypted, correlation_key, sent_at, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $index,
            $this->encryption->encrypt($address, self::CONTEXT),
            $correlationKey,
            $sentAt->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The run in force for one address, or null when there has never been one. */
    public function findByAddress(string $address): ?ReturnProbe
    {
        $statement = $this->pdo->prepare(
            'SELECT id, address_encrypted, correlation_key, sent_at, expires_at, received_at, mailbox_id
             FROM mail_return_probes WHERE address_blind_index = ?'
        );
        $statement->execute([$this->indexOf($address)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The run a key names, still being waited for.
     *
     * An expired key answers null: a message that took three days to come
     * back says nothing useful about the path, and recording it would
     * turn « jamais arrivé » green long after somebody read it.
     */
    public function findPending(string $correlationKey, \DateTimeImmutable $now): ?ReturnProbe
    {
        $statement = $this->pdo->prepare(
            'SELECT id, address_encrypted, correlation_key, sent_at, expires_at, received_at, mailbox_id
             FROM mail_return_probes
             WHERE correlation_key = ? AND received_at IS NULL AND expires_at > ?'
        );
        $statement->execute([$correlationKey, $now->format('Y-m-d H:i:s')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Write down that the message came back, and into which box. */
    public function markReceived(int $id, \DateTimeImmutable $receivedAt, ?int $mailboxId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_return_probes SET received_at = ?, mailbox_id = ? WHERE id = ? AND received_at IS NULL'
        );
        $statement->execute([$receivedAt->format('Y-m-d H:i:s'), $mailboxId, $id]);
    }

    /**
     * Drop one run — the correction for a probe whose message never
     * actually left.
     */
    public function forgetById(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_return_probes WHERE id = ?');
        $statement->execute([$id]);
    }

    /**
     * Forget every run for the addresses NOT in this list — what an
     * address change leaves behind.
     *
     * The row would otherwise sit here for ever holding an address the
     * site no longer uses, which is a copy of an address kept for no
     * reason at all. Called on save rather than on a schedule, because
     * the moment an address stops being in use is a moment somebody is
     * looking at the screen.
     *
     * An empty list clears the table, which is the right answer for a
     * site that has just cleared its addresses.
     *
     * @param list<string> $addresses the addresses still in use
     * @return int how many runs were forgotten
     */
    public function forgetAllExcept(array $addresses): int
    {
        $indexes = array_values(array_unique(array_map(
            fn(string $address) => $this->indexOf($address),
            array_filter($addresses, static fn(string $address) => trim($address) !== '')
        )));

        if ($indexes === []) {
            $statement = $this->pdo->prepare('DELETE FROM mail_return_probes');
            $statement->execute();

            return $statement->rowCount();
        }

        $placeholders = implode(', ', array_fill(0, count($indexes), '?'));
        $statement = $this->pdo->prepare(
            "DELETE FROM mail_return_probes WHERE address_blind_index NOT IN ({$placeholders})"
        );
        $statement->execute($indexes);

        return $statement->rowCount();
    }

    /**
     * Through `EncryptionService::normalizeEmailForIndex()` and never by
     * hand: it is the project's one rule for this (`mb_strtolower` +
     * `trim`), and a byte-wise `strtolower()` leaves an accented address
     * indexing differently from the same address typed in another case —
     * so a case-only edit would leave a stale encrypted copy behind
     * instead of replacing it.
     */
    private function indexOf(string $address): string
    {
        return $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($address),
            self::CONTEXT
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ReturnProbe
    {
        $receivedAt = $row['received_at'] ?? null;

        return new ReturnProbe(
            id: (int) $row['id'],
            address: $this->encryption->decrypt((string) $row['address_encrypted'], self::CONTEXT),
            correlationKey: (string) $row['correlation_key'],
            // NOT NULL in the schema, so an unreadable value is a
            // corrupted row rather than a missing one — and reading it
            // with the bare constructor would answer *now*, quietly
            // turning a broken row into a probe sent this second.
            sentAt: DateInput::requireFromStorage((string) $row['sent_at'], 'mail_return_probes.sent_at'),
            expiresAt: DateInput::requireFromStorage((string) $row['expires_at'], 'mail_return_probes.expires_at'),
            receivedAt: DateInput::fromStorage($receivedAt === null ? null : (string) $receivedAt),
            mailboxId: isset($row['mailbox_id']) ? (int) $row['mailbox_id'] : null
        );
    }
}
