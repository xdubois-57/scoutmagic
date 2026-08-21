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
 * (§7.9), and must never swallow a TLS failure: an invalid certificate is
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
     * is there rather than making the operator type folder names blind.
     *
     * @return string[]
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
}
