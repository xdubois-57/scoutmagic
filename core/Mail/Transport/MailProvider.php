<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * One way out of the site, resolved: the stored row plus the connection
 * values that live in `secrets.enc` (ARCHITECTURE.md §8.106).
 *
 * It carries no password. A provider is handed to templates, to the
 * support collector and to the journal, and the one value none of those
 * may ever see is the credential — so it is not on the object at all
 * rather than on it and carefully omitted three times.
 * `ProviderConnections::password()` is the single place that reads one,
 * and only `TransportConfigurator` calls it.
 */
final class MailProvider
{
    /**
     * The local send's id, in every lane and every counter row.
     *
     * Zero rather than null: MySQL considers two NULLs distinct inside a
     * unique index, so a nullable column would let the same lane hold the
     * local entry twice — the same trap `inbound_message_links.
     * attachment_id` documents (§8.58).
     */
    public const LOCAL_ID = 0;

    public const LOCAL_NAME = 'Envoi local';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        /**
         * How many messages this provider accepts per day, all lanes
         * together — or null when nobody knows, which is the local
         * send's permanent answer (D6). Null is never "unlimited": the
         * chain simply has no quota reason to step past this entry.
         */
        public readonly ?int $dailyQuota,
        public readonly int $batchSize,
        public readonly int $batchIntervalMinutes,
        public readonly string $secretPrefix = ''
    ) {
    }

    /**
     * The permanent entry of all three chains: the server's own `mail()`,
     * with no relay in front of it.
     *
     * Synthesised rather than stored, which is what makes « jamais
     * supprimable » (D5) structural: there is no row for anybody to
     * delete, and no delete path to remember to refuse.
     */
    public static function local(int $batchSize, int $batchIntervalMinutes): self
    {
        return new self(
            id: self::LOCAL_ID,
            name: self::LOCAL_NAME,
            host: '',
            port: 0,
            username: '',
            dailyQuota: null,
            batchSize: $batchSize,
            batchIntervalMinutes: $batchIntervalMinutes
        );
    }

    public function isLocal(): bool
    {
        return $this->id === self::LOCAL_ID;
    }

    /**
     * What the screen prints under the provider's name.
     */
    public function hostSummary(): string
    {
        if ($this->isLocal()) {
            return 'sans relais, directement depuis le serveur';
        }

        return $this->host === '' ? 'hôte non renseigné' : $this->host;
    }

    /**
     * Whether this entry can plausibly deliver anything.
     *
     * The local send always can (that is the point of it being the last
     * resort); a relay needs at least a host.
     */
    public function isUsable(): bool
    {
        return $this->isLocal() || $this->host !== '';
    }
}
