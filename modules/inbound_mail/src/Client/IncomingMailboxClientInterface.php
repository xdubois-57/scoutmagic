<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

use Modules\InboundMail\Mailbox\Mailbox;
use Modules\InboundMail\Mailbox\MailboxCredentials;

/**
 * Reading a remote mailbox, abstracted away from IMAP (§7.2).
 *
 * **Every method on this interface is a read.** There is no `markSeen()`,
 * no `move()`, no `delete()` and no `createFolder()`, and that is the
 * point: ScoutMagic is a gateway onto somebody's mailbox, not a mail
 * client, and the way to guarantee it never touches their mail is to give
 * it no vocabulary for doing so (§7.5). An implementation that fetched a
 * body without PEEK would still violate the rule — see `ImapMailboxClient`
 * for how that is pinned.
 *
 * Implementations must never log a message's content or a credential
 * (§8.6), and must never swallow a TLS failure: an invalid certificate is
 * an error, never a silent downgrade.
 */
interface IncomingMailboxClientInterface
{
    /**
     * Open the mailbox. Throws `MailboxConnectionException` on failure —
     * including on a certificate that does not verify.
     */
    public function connect(Mailbox $mailbox, MailboxCredentials $credentials): void;

    public function disconnect(): void;

    /**
     * The folders actually present, so the configuration page can show what
     * is there rather than making the operator type folder names blind —
     * and which of them the server marks as the box's sent mail, which the
     * synchronisation reads for the consumers that ask for it (#720).
     *
     * @return RemoteFolder[]
     */
    public function listFolders(): array;

    public function folderState(string $folder): FolderState;

    /**
     * The messages in $folder with a UID strictly greater than $sinceUid,
     * oldest first, at most $limit of them.
     *
     * Bounded on purpose: a first sync of a five-year-old mailbox must not
     * try to pull five years in one request that the host's
     * max_execution_time will kill halfway. The caller advances the cursor
     * and comes back.
     *
     * @return FetchedMessage[]
     */
    public function fetchSince(string $folder, int $sinceUid, int $limit): array;

    /**
     * Which messages the first read of $folder takes (D11): those the server
     * received on or after $since, at most $limit of them, the most recent
     * by reception date (`INTERNALDATE`) — never by UID.
     *
     * A read like the others: the folder is EXAMINEd, the server searched,
     * and at most the reception dates fetched. No body, no flag.
     */
    public function firstReadSelection(string $folder, \DateTimeImmutable $since, int $limit): FirstReadSelection;

    /**
     * The messages of $folder carrying these UIDs, oldest UID first — the
     * retained messages of a first read, read by name. A UID no longer in
     * the folder, or a message that cannot be parsed, is simply not in the
     * answer: the caller drops every UID it asked for either way, so it
     * plays no part in `lastSkippedUid()`.
     *
     * @param int[] $uids
     * @return FetchedMessage[]
     */
    public function fetchUids(string $folder, array $uids): array;

    /**
     * The highest UID the last `fetchSince()` read and could not hand
     * back — a message it skipped as unreadable — or 0 when it skipped
     * none.
     *
     * The caller moves its cursor past it too. A skipped message is not
     * in what `fetchSince()` returns, so a cursor moved only past those
     * would stop below it, and a run of them at the top of a folder would
     * be fetched again on every pass; a whole batch of them would stop the
     * box for good.
     */
    public function lastSkippedUid(): int;
}
