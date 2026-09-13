<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Journal\JournalService;

/**
 * What the Courrier sortant screen may do to the chains
 * (ARCHITECTURE.md §8.106).
 *
 * Two rules live here rather than in a repository, because both need to
 * be explained to somebody rather than merely enforced:
 *
 * - **A lane is never left with no enabled entry.** An empty
 *   authentication chain locks every account out of the site, the
 *   super-admin who would come to repair it included — which is the
 *   exact failure this whole mechanism exists to remove, arrived at from
 *   the configuration screen instead of from a relay going down.
 * - **The local send is never deleted**, only moved or disabled (D5). It
 *   is not a row, so there is nothing to delete; this is the message
 *   that says so when somebody tries.
 *
 * Every mutation is journaled at `security` level, deliberately: which
 * relay carries the sign-in links is a security decision even when it is
 * taken in good faith.
 */
final class TransportService
{
    public function __construct(
        private MailProviderRepository $providers,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        private ProviderConnections $connections,
        private MailProviderDirectory $directory,
        private JournalService $journal
    ) {
    }

    /**
     * Add a relay, and put it at the end of all three chains, disabled.
     *
     * Disabled everywhere on purpose: a provider that started carrying
     * the site's sign-in links the moment it was saved would be a routing
     * decision nobody took. The page says so under the button.
     */
    public function addProvider(
        string $name,
        string $host,
        int $port,
        string $username,
        string $password,
        ?int $dailyQuota,
        int $batchSize,
        int $batchIntervalMinutes,
        ?int $actorId = null
    ): int {
        $name = $this->requireName($name);
        $host = trim($host);
        if ($host === '') {
            throw new TransportException('Indiquez le serveur SMTP de ce fournisseur.');
        }

        $id = $this->providers->create(
            $name,
            $this->normaliseQuota($dailyQuota),
            $this->normaliseBatchSize($batchSize),
            $this->normaliseInterval($batchIntervalMinutes)
        );

        // The row has to exist first — `prefixFor($id)` needs the id — so
        // this failure cannot be ordered away as `deleteProvider()` orders
        // its own. It is compensated instead: a provider whose credentials
        // were never written is in no lane, has no host, and would sit on
        // the page as a fournisseur that cannot be used, one more on every
        // retry. Taking the row back makes the retry an ordinary retry.
        try {
            $this->connections->store(
                ProviderConnections::prefixFor($id),
                $host,
                $this->normalisePort($port),
                $username,
                $password
            );
            $this->chains->appendToEveryLane($id, false);
        } catch (\Throwable $e) {
            $this->rollBack($id);

            throw new TransportException(
                'Les identifiants de ce fournisseur n’ont pas pu être enregistrés, donc il n’a pas été créé. '
                . 'Vérifiez que le fichier des secrets est accessible en écriture, puis réessayez.',
                0,
                $e
            );
        }

        $this->directory->refresh();

        $this->journal->log(
            'core',
            'mail_provider_added',
            'security',
            'Fournisseur d’envoi ajouté',
            ['provider_id' => $id, 'provider' => $name, 'host' => $host, 'port' => $this->normalisePort($port)],
            $actorId
        );

        return $id;
    }

    /**
     * Save a relay.
     *
     * A null password means « keep the stored one » — the form cannot
     * show an operator what they would be retyping, so a blank field has
     * to mean unchanged rather than cleared. Same rule, same reason, as
     * `inbound_mail`'s mailbox form (§8.58).
     */
    public function updateProvider(
        int $id,
        string $name,
        string $host,
        int $port,
        string $username,
        ?string $password,
        ?int $dailyQuota,
        int $batchSize,
        int $batchIntervalMinutes,
        ?int $actorId = null
    ): void {
        $row = $this->providers->findById($id);
        if ($row === null) {
            throw new TransportException('Ce fournisseur n’existe plus — rechargez la page.');
        }

        $name = $this->requireName($name);
        $host = trim($host);
        if ($host === '') {
            throw new TransportException('Indiquez le serveur SMTP de ce fournisseur.');
        }

        // Credentials first, metadata second — the same reasoning as
        // `deleteProvider()`. Written the other way round, a failed secret
        // write left the new name, quota and cadence saved beside the OLD
        // host and password, splitting one fournisseur's state across two
        // stores with nothing saying so. This way a failure changes
        // nothing at all. The remaining window is the harmless one: new
        // credentials under the previous name, which the retry finishes
        // and which sends correctly meanwhile.
        try {
            $this->connections->store(
                $row['secret_prefix'],
                $host,
                $this->normalisePort($port),
                $username,
                $password
            );
            $this->providers->update(
                $id,
                $name,
                $this->normaliseQuota($dailyQuota),
                $this->normaliseBatchSize($batchSize),
                $this->normaliseInterval($batchIntervalMinutes)
            );
        } catch (\Throwable $e) {
            // `TransportException` is final and `ProviderConnections`
            // throws a plain `RuntimeException`, so without this the
            // controller's `catch (TransportException)` misses it and a
            // superadmin gets a 500 instead of a sentence. The message is
            // written here rather than taken from $e, which names a path
            // on the server (SECURITY.md §11).
            throw new TransportException(
                'Ce fournisseur n’a pas pu être enregistré. Vérifiez que le fichier des secrets est '
                . 'accessible en écriture, puis réessayez.',
                0,
                $e
            );
        }

        $this->directory->refresh();

        $this->journal->log(
            'core',
            'mail_provider_updated',
            'security',
            'Fournisseur d’envoi modifié',
            ['provider_id' => $id, 'provider' => $name, 'host' => $host, 'port' => $this->normalisePort($port)],
            $actorId
        );
    }

    /**
     * Undo a provider whose creation could not be finished.
     *
     * Best effort, and deliberately silent: it runs inside a `catch` whose
     * exception is the one worth reporting, so a failure here must not
     * replace it with a second one. `forget()` first, for the reason
     * {@see deleteProvider()} spells out — a secret outliving its row is
     * worse than a row outliving its secret.
     */
    private function rollBack(int $id): void
    {
        try {
            $this->connections->forget(ProviderConnections::prefixFor($id));
        } catch (\Throwable) {
            // Nothing to add: the caller is already throwing.
        }

        try {
            $this->chains->removeProvider($id);
            $this->providers->delete($id);
        } catch (\Throwable) {
            // Same.
        }
    }

    /**
     * Remove a relay from the installation.
     *
     * Refused while it is the last enabled entry of any lane: deleting it
     * would empty that chain, and the message names the lane rather than
     * saying « impossible ».
     */
    public function deleteProvider(int $id, ?int $actorId = null): void
    {
        if ($id === MailProvider::LOCAL_ID) {
            throw new TransportException(
                'L’envoi local ne peut pas être supprimé — il est le dernier recours des trois voies. '
                . 'Vous pouvez le désactiver dans une voie, ou le placer en dernier.'
            );
        }

        $row = $this->providers->findById($id);
        if ($row === null) {
            return;
        }

        foreach (MailLane::ordered() as $lane) {
            if ($this->isLastEnabledOf($lane, $id)) {
                throw new TransportException(sprintf(
                    'Ce fournisseur est le seul actif de la voie « %s ». Activez-en un autre avant de le supprimer.',
                    $lane->label()
                ));
            }
        }

        // **The credentials go first, and the row second.** `forget()` is
        // file I/O on `secrets.enc` and can fail — an unreadable file, a
        // disk that is full. Erasing after the row is gone would strand
        // that relay's host, user and password there for good: the retry
        // finds no row, returns at the `findById()` guard above having
        // done nothing, and no code path anywhere else knows the prefix
        // to erase. A third party's password would outlive the
        // fournisseur that justified keeping it.
        //
        // Doing it first fails cleanly instead — nothing else has moved,
        // so a retry is an ordinary retry — and the window it opens is
        // the harmless one: a provider row whose secrets are gone has no
        // host, so `MailProvider::isUsable()` is false and the chain
        // steps over it exactly as it steps over a spent one.
        try {
            $this->connections->forget($row['secret_prefix']);
        } catch (\Throwable $e) {
            // `ProviderConnections` throws a plain `RuntimeException`, and
            // `TransportException` is final, so this would otherwise leave
            // the controller's `catch (TransportException)` untouched and
            // reach the visitor as a 500. The message is written here
            // rather than taken from $e: that one names a path on the
            // server (SECURITY.md §11).
            throw new TransportException(
                'Les identifiants de ce fournisseur n’ont pas pu être effacés, donc il n’a pas été supprimé. '
                . 'Vérifiez que le fichier des secrets est accessible en écriture, puis réessayez.',
                0,
                $e
            );
        }

        $this->chains->removeProvider($id);
        $this->counters->forgetProvider($id);
        $this->providers->delete($id);
        $this->directory->refresh();

        $this->journal->log(
            'core',
            'mail_provider_deleted',
            'security',
            'Fournisseur d’envoi supprimé',
            ['provider_id' => $id, 'provider' => $row['name']],
            $actorId
        );
    }

    /**
     * Rewrite one lane's order.
     *
     * @param array<int, int> $providerIds
     */
    public function reorderLane(MailLane $lane, array $providerIds, ?int $actorId = null): void
    {
        $this->chains->reorder($lane, $providerIds);

        $this->journal->log(
            'core',
            'mail_lane_reordered',
            'security',
            'Chaîne d’envoi réordonnée',
            ['lane' => $lane->value, 'order' => $providerIds],
            $actorId
        );
    }

    /**
     * Turn one entry of one lane on or off.
     */
    public function setEntryEnabled(MailLane $lane, int $providerId, bool $enabled, ?int $actorId = null): void
    {
        if (!$this->chains->exists($lane, $providerId)) {
            throw new TransportException('Cette entrée n’existe plus — rechargez la page.');
        }

        if (!$enabled && $this->isLastEnabledOf($lane, $providerId)) {
            throw new TransportException(sprintf(
                'La voie « %s » doit garder au moins un fournisseur actif. %s',
                $lane->label(),
                $lane === MailLane::Authentication
                    ? 'Sans elle, plus personne ne peut se connecter au site.'
                    : 'Activez-en un autre d’abord.'
            ));
        }

        $this->chains->setEnabled($lane, $providerId, $enabled);

        $this->journal->log(
            'core',
            $enabled ? 'mail_lane_entry_enabled' : 'mail_lane_entry_disabled',
            'security',
            $enabled ? 'Fournisseur activé dans une voie' : 'Fournisseur désactivé dans une voie',
            ['lane' => $lane->value, 'provider_id' => $providerId],
            $actorId
        );
    }

    /**
     * Is this entry the only enabled one left on that lane?
     */
    public function isLastEnabledOf(MailLane $lane, int $providerId): bool
    {
        if ($this->chains->countEnabled($lane) > 1) {
            return false;
        }

        foreach ($this->chains->forLane($lane) as $entry) {
            if ($entry->enabled) {
                return $entry->providerId === $providerId;
            }
        }

        return false;
    }

    private function requireName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new TransportException('Donnez un nom à ce fournisseur — c’est ce que les écrans afficheront.');
        }

        return mb_substr($name, 0, 100);
    }

    private function normalisePort(int $port): int
    {
        return $port >= 1 && $port <= 65535 ? $port : 587;
    }

    /**
     * Zero and « nothing typed » both mean « no known daily ceiling »,
     * which is what null stores (D6). It never means unlimited: the chain
     * simply has no quota reason to step past this provider.
     */
    private function normaliseQuota(?int $quota): ?int
    {
        return $quota !== null && $quota > 0 ? $quota : null;
    }

    private function normaliseBatchSize(int $batchSize): int
    {
        return $batchSize > 0 ? min($batchSize, 10000) : 50;
    }

    private function normaliseInterval(int $minutes): int
    {
        return $minutes > 0 ? min($minutes, 1440) : 10;
    }
}
