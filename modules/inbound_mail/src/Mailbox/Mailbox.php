<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

use Modules\InboundMail\Api\MailboxPurpose;

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
        public readonly ?\DateTimeImmutable $lastErrorAt = null,
        /**
         * What the box is for. Answered by the operator, not derived from
         * the per-module rows — see `Api\MailboxPurpose`.
         */
        public readonly MailboxPurpose $purpose = MailboxPurpose::SHARED,
        /** The consumer a dedicated box belongs to; null on a shared one. */
        public readonly ?string $dedicatedTo = null
    ) {
    }

    public function isDedicated(): bool
    {
        return $this->purpose === MailboxPurpose::DEDICATED && $this->dedicatedTo !== null;
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

    /**
     * The scopes a dedicated box implies, whatever its rows happen to say.
     *
     * Computed rather than stored twice: « dédiée à camps » and « camps
     * analyse et lit tout, les autres rien » must never be able to drift
     * apart, and the way to guarantee that is for one of them not to be a
     * second source of truth.
     *
     * @param string[] $consumerIds every registered consumer
     * @return array<string, \Modules\InboundMail\Api\MailboxScope>
     */
    public function impliedScopes(array $consumerIds): array
    {
        $scopes = [];
        foreach ($consumerIds as $consumerId) {
            $scopes[$consumerId] = $consumerId === $this->dedicatedTo
                ? new \Modules\InboundMail\Api\MailboxScope(
                    $consumerId,
                    true,
                    \Modules\InboundMail\Api\ReadMode::ALL
                )
                : \Modules\InboundMail\Api\MailboxScope::inert($consumerId);
        }

        return $scopes;
    }
}
