<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\InboundMail\Mailbox\FolderCursor;
use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Mailbox\SyncState;

/**
 * The mailboxes and their cursors.
 *
 * Every timestamp written here is computed in PHP and bound as a parameter,
 * never MySQL's `NOW()` — same convention as the rest of the codebase, so
 * this repository and its tests run unmodified against the SQLite test
 * database too.
 *
 * **`findAll()` never carries a password.** The credentials come back only
 * from `findCredentials()`, which is called from exactly one place — the
 * moment a client is about to connect. That is not politeness: a `Mailbox`
 * that carried its password would put it into every listing, every
 * template variable and every stack trace rendered while a connection was
 * failing (§7.4).
 */
class InboundMailboxRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return Mailbox[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM inbound_mailboxes ORDER BY name ASC');

        return array_map(
            fn(array $row) => $this->hydrate($row),
            $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * The boxes a synchronisation run should poll: enabled ones only. A
     * disabled box keeps its cursor and its configuration — disabling is
     * how an operator stops a misbehaving box without losing where it got
     * to (§7.4).
     *
     * @return Mailbox[]
     */
    public function findEnabled(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM inbound_mailboxes WHERE is_enabled = 1 ORDER BY id ASC');

        return array_map(
            fn(array $row) => $this->hydrate($row),
            $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findById(int $id): ?Mailbox
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inbound_mailboxes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function countEnabled(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM inbound_mailboxes WHERE is_enabled = 1');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * The account name and password, decrypted — the only method that ever
     * returns them.
     */
    public function findCredentials(int $mailboxId): ?MailboxCredentials
    {
        $stmt = $this->pdo->prepare('SELECT username_encrypted, password_encrypted FROM inbound_mailboxes WHERE id = ?');
        $stmt->execute([$mailboxId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return new MailboxCredentials(
            $this->encryption->decrypt((string) $row['username_encrypted'], 'inbound_mailboxes.username'),
            $this->encryption->decrypt((string) $row['password_encrypted'], 'inbound_mailboxes.password')
        );
    }

    /**
     * @param string[] $folders
     */
    public function create(
        string $name,
        ProviderType $providerType,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_mailboxes
                (name, provider_type, host, port, encryption, username_encrypted, password_encrypted,
                 folders, is_enabled, sync_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $providerType->value,
            $host,
            $port,
            $encryption,
            $this->encryption->encrypt($username, 'inbound_mailboxes.username'),
            $this->encryption->encrypt($password, 'inbound_mailboxes.password'),
            implode("\n", $folders),
            $isEnabled ? 1 : 0,
            SyncState::NEVER->value,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update everything but the password.
     *
     * **A blank password field means "leave it alone", never "clear it".**
     * The page cannot show the current one (§7.4), so an operator editing
     * the port has no way to retype it — and a save that silently emptied
     * the credential would break the box on the next run for a reason
     * nothing on screen would explain. `setPassword()` is the deliberate
     * act of changing it.
     *
     * @param string[] $folders
     */
    public function update(
        int $id,
        string $name,
        string $host,
        int $port,
        string $encryption,
        string $username,
        array $folders,
        bool $isEnabled
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET name = ?, host = ?, port = ?, encryption = ?, username_encrypted = ?,
                    folders = ?, is_enabled = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $host,
            $port,
            $encryption,
            $this->encryption->encrypt($username, 'inbound_mailboxes.username'),
            implode("\n", $folders),
            $isEnabled ? 1 : 0,
            self::now(),
            $id,
        ]);
    }

    public function setPassword(int $id, string $password): void
    {
        $stmt = $this->pdo->prepare('UPDATE inbound_mailboxes SET password_encrypted = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$this->encryption->encrypt($password, 'inbound_mailboxes.password'), self::now(), $id]);
    }

    public function setEnabled(int $id, bool $isEnabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE inbound_mailboxes SET is_enabled = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$isEnabled ? 1 : 0, self::now(), $id]);
    }

    public function recordSuccess(int $id, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET sync_state = ?, last_synced_at = ?, last_error = NULL, last_error_at = NULL, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([SyncState::OK->value, $at->format('Y-m-d H:i:s'), self::now(), $id]);
    }

    /**
     * $reason must already be the operator-facing sentence — see
     * Service\MailboxErrorFormatter. This method does no formatting of its
     * own precisely so there is one place where that rule lives.
     */
    public function recordFailure(int $id, string $reason, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET sync_state = ?, last_error = ?, last_error_at = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            SyncState::ERROR->value,
            mb_substr($reason, 0, 500),
            $at->format('Y-m-d H:i:s'),
            self::now(),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM inbound_mailboxes WHERE id = ?');
        $stmt->execute([$id]);
    }

    // ── Cursors ─────────────────────────────────────────────────────────

    public function findCursor(int $mailboxId, string $folder): FolderCursor
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inbound_mailbox_cursors WHERE mailbox_id = ? AND folder = ?');
        $stmt->execute([$mailboxId, $folder]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            // A folder never read yet: UIDVALIDITY 0 matches no real
            // folder, so the first sync always looks like a renumbering and
            // reads from the start — which is exactly right.
            return new FolderCursor($mailboxId, $folder, 0, 0);
        }

        return new FolderCursor(
            $mailboxId,
            $folder,
            (int) $row['uid_validity'],
            (int) $row['last_uid']
        );
    }

    /**
     * Portable insert-or-update — a SELECT then a branch, never MySQL's
     * `INSERT ... ON DUPLICATE KEY UPDATE`, which SQLite does not
     * understand and which would make this the one repository its own tests
     * could not exercise. Same convention as
     * `Modules\Gallery\Repository\S3SecretRepository`.
     */
    public function saveCursor(FolderCursor $cursor): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_mailbox_cursors WHERE mailbox_id = ? AND folder = ?'
        );
        $stmt->execute([$cursor->mailboxId, $cursor->folder]);
        $existingId = $stmt->fetchColumn();

        if ($existingId !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE inbound_mailbox_cursors SET uid_validity = ?, last_uid = ?, updated_at = ? WHERE id = ?'
            );
            $stmt->execute([$cursor->uidValidity, $cursor->lastUid, self::now(), (int) $existingId]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_mailbox_cursors (mailbox_id, folder, uid_validity, last_uid, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cursor->mailboxId,
            $cursor->folder,
            $cursor->uidValidity,
            $cursor->lastUid,
            self::now(),
        ]);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Mailbox
    {
        $folders = array_values(array_filter(
            array_map('trim', explode("\n", (string) ($row['folders'] ?? ''))),
            static fn(string $folder) => $folder !== ''
        ));

        return new Mailbox(
            id: (int) $row['id'],
            name: (string) $row['name'],
            providerType: ProviderType::from((string) $row['provider_type']),
            host: (string) $row['host'],
            port: (int) $row['port'],
            encryption: (string) $row['encryption'],
            username: $this->encryption->decrypt((string) $row['username_encrypted'], 'inbound_mailboxes.username'),
            folders: $folders,
            isEnabled: (bool) $row['is_enabled'],
            syncState: SyncState::from((string) $row['sync_state']),
            lastSyncedAt: DateInput::fromStorage($row['last_synced_at'] === null ? null : (string) $row['last_synced_at']),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            lastErrorAt: DateInput::fromStorage($row['last_error_at'] === null ? null : (string) $row['last_error_at'])
        );
    }
}
