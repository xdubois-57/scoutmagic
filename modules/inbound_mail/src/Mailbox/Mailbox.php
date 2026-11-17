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
 * Several boxes may be configured at once. A box has one of exactly two
 * purposes (`Api\MailboxPurpose`): the unit's general mail, read by every
 * consumer, or reserved to one module, read by that module alone. Who
 * analyses a box follows from that answer and nothing else
 * (`isAnalysedBy()`): there is no per-module setting to forget.
 *
 * A box has no name of its own: its account address is what every screen
 * shows (`displayName()`).
 */
class Mailbox
{
    /**
     * @param string[] $folders the folders watched, in the server's own
     *   naming ('INBOX', 'INBOX/Locations'). Empty means INBOX only.
     */
    public function __construct(
        public readonly int $id,
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
         * What the box is for — see `Api\MailboxPurpose`.
         */
        public readonly MailboxPurpose $purpose = MailboxPurpose::SHARED,
        /** The consumer a dedicated box belongs to; null on a shared one. */
        public readonly ?string $dedicatedTo = null,
        /**
         * The folder the operator named as the box's sent mail, for a
         * server that marks none `\Sent` (#720). Null: the server's mark.
         */
        public readonly ?string $sentFolder = null
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
     * The folder read as the box's sent mail: the one the operator named,
     * otherwise the one the server marks `\Sent` — never one already
     * watched, whose messages are read as received and must not be read
     * twice in two directions.
     *
     * @param string|null $markedByServer what the folder listing says
     */
    public function sentFolderAmong(?string $markedByServer): ?string
    {
        $folder = $this->sentFolder ?? $markedByServer;

        return $folder !== null && !in_array($folder, $this->watchedFolders(), true) ? $folder : null;
    }

    /**
     * What every screen calls this box: its account address. A box has no
     * other name — a second label the operator had to invent and keep in
     * step with the address was one more thing to get wrong.
     */
    public function displayName(): string
    {
        return $this->username;
    }

    /**
     * Whether this consumer is offered this box's mail.
     *
     * A general box is analysed by every active consumer, a reserved box
     * by its module alone. A box therefore works the moment it is saved:
     * no module waits for a second screen to be told it may look. The one
     * exception: a consumer that reads only boxes reserved to it — the
     * seed boxes — never reads the general mail
     * (`MailboxPurpose::readsReservedBoxesOnly()`).
     */
    public function isAnalysedBy(string $consumerId): bool
    {
        return $this->isDedicated()
            ? $this->dedicatedTo === $consumerId
            : !MailboxPurpose::readsReservedBoxesOnly($consumerId);
    }

    /**
     * The consumers, among those given, that this box's mail is offered to.
     *
     * @template T of \Modules\InboundMail\Api\MessageConsumerInterface
     * @param T[] $consumers
     * @return list<T>
     */
    public function analysingConsumersAmong(array $consumers): array
    {
        return array_values(array_filter(
            $consumers,
            fn($consumer): bool => $this->isAnalysedBy($consumer->consumerId())
        ));
    }

    /**
     * What a non-superadmin may know about this box (§7.4): its address
     * and whether it is working. Never the host, the port or anything that
     * would help somebody log into it.
     *
     * @return array{name: string, state: string, is_enabled: bool}
     */
    public function publicSummary(): array
    {
        return [
            'name' => $this->displayName(),
            'state' => $this->syncState->label(),
            'is_enabled' => $this->isEnabled,
        ];
    }
}
