<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Config\SettingService;

/**
 * Every way out of this site, resolved once (ARCHITECTURE.md §8.106).
 *
 * It joins the stored half of a provider (`mail_providers`) to the half
 * that lives in `secrets.enc` (`ProviderConnections`), and it adds the
 * one entry that is in neither: the local send, synthesised at id 0 so
 * that « permanente, jamais supprimable » (D5) is a property of the code
 * rather than a rule somebody has to remember.
 *
 * Resolution is memoised per request. A mailing asks for the chain once
 * per message, and re-reading three rows five hundred times would be the
 * cost of the feature rather than the feature.
 */
final class MailProviderDirectory
{
    public const SETTING_LOCAL_BATCH_SIZE = 'mail_local_batch_size';
    public const SETTING_LOCAL_BATCH_INTERVAL = 'mail_local_batch_interval_minutes';

    /**
     * A deliberately prudent default (D6).
     *
     * The local send has no relay in front of it to absorb a burst, and a
     * local queue that swells is what gets a hosting account suspended or
     * its outgoing port closed. Ten messages a quarter of an hour is slow
     * on purpose; a unit that knows its host can raise it.
     */
    public const DEFAULT_LOCAL_BATCH_SIZE = 10;
    public const DEFAULT_LOCAL_BATCH_INTERVAL = 15;

    /** @var array<int, MailProvider>|null */
    private ?array $resolved = null;

    public function __construct(
        private MailProviderRepository $repository,
        private ProviderConnections $connections,
        private SettingService $settings
    ) {
    }

    /**
     * Every provider, keyed by id, the local send included.
     *
     * @return array<int, MailProvider>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $providers = [MailProvider::LOCAL_ID => $this->local()];

        foreach ($this->repository->findAll() as $row) {
            $prefix = $row['secret_prefix'];
            $providers[$row['id']] = new MailProvider(
                id: $row['id'],
                name: $row['name'],
                host: $this->connections->host($prefix),
                port: $this->connections->port($prefix),
                username: $this->connections->username($prefix),
                dailyQuota: $row['daily_quota'],
                batchSize: $row['batch_size'],
                batchIntervalMinutes: $row['batch_interval_minutes'],
                secretPrefix: $prefix
            );
        }

        return $this->resolved = $providers;
    }

    public function find(int $id): ?MailProvider
    {
        return $this->all()[$id] ?? null;
    }

    public function local(): MailProvider
    {
        return MailProvider::local($this->localBatchSize(), $this->localBatchInterval());
    }

    /**
     * Only the relays — what the Fournisseurs page lists as deletable.
     *
     * @return array<int, MailProvider>
     */
    public function relays(): array
    {
        return array_filter($this->all(), static fn(MailProvider $p): bool => !$p->isLocal());
    }

    /**
     * Forget what was resolved — for a request that has just written a
     * provider and now has to read it back.
     */
    public function refresh(): void
    {
        $this->resolved = null;
    }

    private function localBatchSize(): int
    {
        $value = (int) $this->settings->get(
            self::SETTING_LOCAL_BATCH_SIZE,
            null,
            (string) self::DEFAULT_LOCAL_BATCH_SIZE
        );

        return $value > 0 ? $value : self::DEFAULT_LOCAL_BATCH_SIZE;
    }

    private function localBatchInterval(): int
    {
        $value = (int) $this->settings->get(
            self::SETTING_LOCAL_BATCH_INTERVAL,
            null,
            (string) self::DEFAULT_LOCAL_BATCH_INTERVAL
        );

        return $value > 0 ? $value : self::DEFAULT_LOCAL_BATCH_INTERVAL;
    }
}
