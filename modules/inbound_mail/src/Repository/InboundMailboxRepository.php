<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Repository;

use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\InboundMail\Api\MailboxPurpose;
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
    /**
     * Switching a box back on starts its failures over: the time it spent
     * switched off is a pause, not an outage (#718 § C point 8). Leads the
     * SET list on purpose — MySQL evaluates assignments left to right, so
     * `is_enabled` must still be the old value when this reads it.
     */
    private const FAILING_SINCE_ON_ENABLE =
        'failing_since = CASE WHEN is_enabled = 0 THEN NULL ELSE failing_since END, ';

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
        $stmt = $this->pdo->query('SELECT * FROM inbound_mailboxes ORDER BY id ASC');

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

    /**
     * The sync state of every enabled box, with its address and nothing
     * else: no password, no host (#718 § C point 8). For the attention
     * page, which renders on demand for an admin. The address is the box's
     * only name (D7), so it is decrypted — and an address that cannot be
     * read falls back to the box's number rather than failing the page.
     *
     * @return list<array{
     *     name: string,
     *     state: SyncState,
     *     lastSyncedAt: ?\DateTimeImmutable,
     *     failingSince: ?\DateTimeImmutable
     * }>
     */
    public function enabledSyncStates(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username_encrypted, sync_state, last_synced_at, failing_since FROM inbound_mailboxes
              WHERE is_enabled = 1 ORDER BY id ASC'
        );

        return array_map(
            fn(array $row): array => [
                'name' => $this->readableAddress($row),
                'state' => SyncState::from((string) $row['sync_state']),
                'lastSyncedAt' => DateInput::fromStorage(
                    $row['last_synced_at'] === null ? null : (string) $row['last_synced_at']
                ),
                'failingSince' => DateInput::fromStorage(
                    $row['failing_since'] === null ? null : (string) $row['failing_since']
                ),
            ],
            $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readableAddress(array $row): string
    {
        try {
            return $this->encryption->decrypt((string) $row['username_encrypted'], 'inbound_mailboxes.username');
        } catch (\RuntimeException) {
            return 'n° ' . (int) $row['id'];
        }
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
        $stmt = $this->pdo->prepare('SELECT username_encrypted, password_encrypted FROM inbound_mailboxes WHERE id = '
            . '?');
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
        ProviderType $providerType,
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        array $folders,
        bool $isEnabled,
        MailboxPurpose $purpose = MailboxPurpose::SHARED,
        ?string $dedicatedTo = null
    ): int {
        // The purpose is written by the INSERT itself: a box reserved to a
        // module that already has one is refused by the unique index before
        // any row exists, and no sync ever sees the box as general mail for
        // the instant between two writes.
        [$dedicatedTo, $key] = self::dedication($purpose, $dedicatedTo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_mailboxes
                (provider_type, host, port, encryption, username_encrypted, password_encrypted,
                 folders, is_enabled, sync_state, purpose, dedicated_to, dedication_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $providerType->value,
            $host,
            $port,
            $encryption,
            $this->encryption->encrypt($username, 'inbound_mailboxes.username'),
            $this->encryption->encrypt($password, 'inbound_mailboxes.password'),
            implode("\n", $folders),
            $isEnabled ? 1 : 0,
            SyncState::NEVER->value,
            $purpose->value,
            $dedicatedTo,
            $key,
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
        string $host,
        int $port,
        string $encryption,
        string $username,
        array $folders,
        bool $isEnabled
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET ' . ($isEnabled ? self::FAILING_SINCE_ON_ENABLE : '') . 'host = ?, port = ?,
                    encryption = ?, username_encrypted = ?, folders = ?, is_enabled = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
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

    /**
     * The connection settings of a box, and nothing about whether it is
     * read: `is_enabled` and `failing_since` are left as they stand. An edit
     * verifies the connection before it writes (#1333, D10), and a box
     * disabled from the list in the meantime must stay disabled — so the
     * configuration form never writes the enabled state it read earlier.
     *
     * @param string[] $folders
     */
    public function updateConnection(
        int $id,
        string $host,
        int $port,
        string $encryption,
        string $username,
        array $folders
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET host = ?, port = ?, encryption = ?, username_encrypted = ?, folders = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $host,
            $port,
            $encryption,
            $this->encryption->encrypt($username, 'inbound_mailboxes.username'),
            implode("\n", $folders),
            self::now(),
            $id,
        ]);
    }

    /**
     * The folder to read as the box's sent mail when the server marks none
     * `\Sent` (#720). Blank or null clears it: the server's mark applies.
     */
    public function setSentFolder(int $id, ?string $folder): void
    {
        // Stored as the server spells it, like the watched folders: only a
        // blank value clears it.
        $blank = $folder === null || trim($folder) === '';
        $stmt = $this->pdo->prepare('UPDATE inbound_mailboxes SET sent_folder = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$blank ? null : $folder, self::now(), $id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $stmt = $this->pdo->prepare('UPDATE inbound_mailboxes SET password_encrypted = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$this->encryption->encrypt($password, 'inbound_mailboxes.password'), self::now(), $id]);
    }

    public function setEnabled(int $id, bool $isEnabled): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes SET ' . ($isEnabled ? self::FAILING_SINCE_ON_ENABLE : '')
                . 'is_enabled = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$isEnabled ? 1 : 0, self::now(), $id]);
    }

    public function recordSuccess(int $id, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET sync_state = ?, last_synced_at = ?, last_error = NULL, last_error_at = NULL,
                    failing_since = NULL, last_failure_signature = NULL, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([SyncState::OK->value, $at->format('Y-m-d H:i:s'), self::now(), $id]);
    }

    /**
     * Forget a failure the operator has just fixed: an edit whose new
     * settings connected (#1333, D10). The box leaves the error state —
     * back to « ok » if it was ever relevée, to « never » otherwise — and
     * the run of failures ends (`failing_since`), so a failure of the next
     * relève dates a new run. `last_synced_at` is left alone, since nothing
     * was read, and so is `is_enabled`.
     */
    public function clearFailure(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes
                SET sync_state = CASE WHEN last_synced_at IS NULL THEN ? ELSE ? END,
                    last_error = NULL, last_error_at = NULL, failing_since = NULL,
                    last_failure_signature = NULL, updated_at = ?
              WHERE id = ? AND sync_state = ?'
        );
        $stmt->execute([SyncState::NEVER->value, SyncState::OK->value, self::now(), $id, SyncState::ERROR->value]);
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
                SET sync_state = ?, last_error = ?, last_error_at = ?,
                    failing_since = COALESCE(failing_since, ?), updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([
            SyncState::ERROR->value,
            mb_substr($reason, 0, 500),
            $at->format('Y-m-d H:i:s'),
            // The first failure of a run dates it; the next ones keep it.
            $at->format('Y-m-d H:i:s'),
            self::now(),
            $id,
        ]);
    }

    /** A failed journal write must leave the diagnostic eligible for retry. */
    public function acknowledgeFailure(int $id, string $signature): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes SET last_failure_signature = ? WHERE id = ? AND sync_state = ?'
        );
        $stmt->execute([$signature, $id, SyncState::ERROR->value]);
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
            (int) $row['last_uid'],
            self::decodeUids($row['first_read_pending_uids'] ?? null)
        );
    }

    /**
     * @return list<int>
     */
    private static function decodeUids(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        return array_map('intval', explode(',', $stored));
    }

    private static function encodeUids(FolderCursor $cursor): ?string
    {
        return $cursor->hasPending() ? implode(',', $cursor->pendingUids) : null;
    }

    /**
     * Portable insert-or-update — a SELECT then a branch, never MySQL's
     * `INSERT ... ON DUPLICATE KEY UPDATE`, which SQLite does not
     * understand and which would make this the one repository its own tests
     * could not exercise. The same convention as
     * `Core\Http\LinkPreviewCacheRepository::store()`.
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
                'UPDATE inbound_mailbox_cursors
                 SET uid_validity = ?, last_uid = ?, first_read_pending_uids = ?, updated_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $cursor->uidValidity,
                $cursor->lastUid,
                self::encodeUids($cursor),
                self::now(),
                (int) $existingId,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_mailbox_cursors
                (mailbox_id, folder, uid_validity, last_uid, first_read_pending_uids, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $cursor->mailboxId,
            $cursor->folder,
            $cursor->uidValidity,
            $cursor->lastUid,
            self::encodeUids($cursor),
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
        // Read back exactly as saved: a folder path is the server's own
        // spelling, a leading or trailing space included, and
        // `requireFolder()` matches it exactly. Only a blank line and the
        // `\r` of a CRLF separator go.
        $folders = array_values(array_filter(
            array_map(static fn(string $line): string => rtrim($line, "\r"), explode("\n", (string) ($row['folders'] ?? ''))),
            static fn(string $folder) => trim($folder) !== ''
        ));

        return new Mailbox(
            id: (int) $row['id'],
            providerType: ProviderType::from((string) $row['provider_type']),
            host: (string) $row['host'],
            port: (int) $row['port'],
            encryption: (string) $row['encryption'],
            username: $this->encryption->decrypt((string) $row['username_encrypted'], 'inbound_mailboxes.username'),
            folders: $folders,
            isEnabled: (bool) $row['is_enabled'],
            syncState: SyncState::from((string) $row['sync_state']),
            lastSyncedAt: DateInput::fromStorage(
                $row['last_synced_at'] === null ? null : (string) $row['last_synced_at']
            ),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            lastErrorAt: DateInput::fromStorage($row['last_error_at'] === null ? null : (string) $row['last_error_at']),
            purpose: MailboxPurpose::fromString(
                array_key_exists('purpose', $row) ? (string) $row['purpose'] : null
            ),
            dedicatedTo: ($row['dedicated_to'] ?? null) !== null && (string) $row['dedicated_to'] !== ''
                ? (string) $row['dedicated_to']
                : null,
            sentFolder: ($row['sent_folder'] ?? null) !== null && (string) $row['sent_folder'] !== ''
                ? (string) $row['sent_folder']
                : null,
            failingSince: DateInput::fromStorage(
                $row['failing_since'] === null ? null : (string) $row['failing_since']
            ),
            lastFailureSignature: isset($row['last_failure_signature']) ? (string) $row['last_failure_signature'] : null
        );
    }

    // ── What each box is for (§8.58) ────────────────────────────────────

    /**
     * Declare what a box is for. A general box has no module; the caller
     * that offers the choice checks first that the module has no other
     * reserved box (`Service\MailboxAdminService::setPurpose()`), and the
     * unique index on `dedication_key` is what holds if two saves race.
     *
     * `dedication_key` is written here and nowhere else: the module, except
     * for a consumer allowed several boxes (the seed boxes), for which it
     * stays NULL and the index lets them be.
     */
    public function setPurpose(int $id, MailboxPurpose $purpose, ?string $dedicatedTo): void
    {
        [$dedicatedTo, $key] = self::dedication($purpose, $dedicatedTo);

        $stmt = $this->pdo->prepare(
            'UPDATE inbound_mailboxes SET purpose = ?, dedicated_to = ?, dedication_key = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$purpose->value, $dedicatedTo, $key, self::now(), $id]);
    }

    /**
     * The module a box is reserved to, and its `dedication_key`: the
     * module, or NULL for a consumer allowed several boxes (the seed boxes)
     * and on a general box — the one place that rule is written.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function dedication(MailboxPurpose $purpose, ?string $dedicatedTo): array
    {
        $dedicatedTo = $purpose === MailboxPurpose::DEDICATED ? $dedicatedTo : null;
        $key = $dedicatedTo !== null && !MailboxPurpose::readsReservedBoxesOnly($dedicatedTo)
            ? $dedicatedTo
            : null;

        return [$dedicatedTo, $key];
    }

    /**
     * The box reserved to this consumer other than `$exceptId`, enabled or
     * not — a module has at most one (D5), and a disabled box still holds
     * its place.
     */
    public function findOtherDedicatedTo(string $consumerId, int $exceptId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_mailboxes WHERE dedicated_to = ? AND id <> ? ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute([$consumerId, $exceptId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The boxes one consumer reads in full: the one reserved to it, if
     * any. A general box is never among them — there, a module's users see
     * only what is attached to something they manage (D3).
     *
     * @return int[]
     */
    public function mailboxIdsReadableInFull(string $consumerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_mailboxes WHERE purpose = ? AND dedicated_to = ? ORDER BY id ASC'
        );
        $stmt->execute([MailboxPurpose::DEDICATED->value, $consumerId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
