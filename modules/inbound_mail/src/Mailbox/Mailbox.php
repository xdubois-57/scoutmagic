<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * One configured mailbox — **without its password**, which lives in
 * `MailboxCredentials` and is loaded separately (see that class).
 *
 * Several boxes may be configured at once, one box may feed several
 * consumer modules, and one consumer may read from several boxes (§7.4):
 * nothing here ties a box to a module.
 */
class Mailbox
{
    /**
     * @param string[] $folders the folders watched, in the server's own
     *   naming ('INBOX', 'INBOX/Locations'). Empty means INBOX only.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ProviderType $providerType,
        public readonly string $host,
        public readonly int $port,
        public readonly string $encryption,
        public readonly string $username,
        public readonly array $folders,
        public readonly bool $isEnabled,
        public readonly SyncState $syncState,
        public readonly ?\DateTimeImmutable $lastSyncedAt = null,
        /**
         * The last technical failure, kept for the superadmin page. Written
         * by `MailboxErrorFormatter`, which is what guarantees a password
         * never lands in it.
         */
        public readonly ?string $lastError = null,
        public readonly ?\DateTimeImmutable $lastErrorAt = null
    ) {
    }

    /**
     * The folders actually polled. A box configured with none still gets
     * its INBOX read — an empty list means "the obvious one", not "none",
     * which would be a box that silently collects nothing.
     *
     * @return string[]
     */
    public function watchedFolders(): array
    {
        return $this->folders === [] ? ['INBOX'] : $this->folders;
    }

    /**
     * What a non-superadmin may know about this box (§7.4): that it exists
     * and whether it is working. Never the host, the port, the account or
     * anything that would help somebody reach it.
     *
     * @return array{name: string, state: string, is_enabled: bool}
     */
    public function publicSummary(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->syncState->label(),
            'is_enabled' => $this->isEnabled,
        ];
    }
}
