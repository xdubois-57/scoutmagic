<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Journal\JournalService;
use Core\Mail\MailErrorRedaction;
use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * The transport that removes the site's single point of failure
 * (ARCHITECTURE.md §8.106).
 *
 * One relay used to be the whole of the site's outbound mail. When it
 * stopped answering — or when a mailing to four hundred parents spent
 * its free daily quota in one pass — the sign-in links stopped with it,
 * and nobody could log in any more, **including the super-admin who
 * would have come to repair it**. Three ordered chains, one per lane,
 * are what makes that recoverable.
 *
 * What it does, and in this order:
 *
 * 1. The lane comes from `MailPurpose` and from nothing else.
 * 2. The lane's enabled entries are walked in their stored order.
 * 3. An entry whose provider has spent its daily quota is stepped over —
 *    a quota is not a failure, it is the reason the next entry exists.
 * 4. The message is pointed at the provider and handed to the real
 *    delivery transport. On success the counter moves and the call
 *    returns; on failure the next entry is tried.
 * 5. With every entry refused, the exception carries the last relay's
 *    own reason, redacted.
 *
 * **It sits in front of the delivery transport, never instead of it.**
 * `PhpMailerTransport` in production, `Modules\TestTools`'s
 * `CaptureTransport` on an installation whose sandbox is armed — so a
 * captured message is still routed, counted and stepped over exactly as
 * a real one, and the sandbox keeps showing what the site would have
 * sent.
 *
 * **Three states, not two, and the middle one is the easy mistake.**
 *
 * - The chain **cannot be read** — a setup wizard mid-flight, a migration
 *   that has not run, tables that do not exist yet. Reading throws, and
 *   the right answer is to deliver through whatever `MailService` had
 *   already configured rather than to refuse.
 * - The lane **has no row at all**, which is not the same thing and is
 *   handled the same way, deliberately: the only way to reach it is a
 *   database that migrated before `TransportSeeder` could lay the chains
 *   down. Refusing there would mean a site that cannot send mail during
 *   its own installation, so this case delegates too.
 * - The lane **has rows and none of them is usable** — every entry
 *   disabled, or every provider spent or unconfigured. That is a real
 *   configuration error, it is the one an administrator can act on, and
 *   it says so instead of quietly sending through a relay the chain was
 *   built to stop using.
 *
 * `candidates()` returns null for the first two and the empty array for
 * the third, which is why it is nullable rather than merely possibly
 * empty.
 */
final class MailTransportChain implements MailTransportInterface
{
    public function __construct(
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private SendCounterRepository $counters,
        private TransportConfigurator $configurator,
        private MailTransportInterface $delivery,
        private ?JournalService $journal = null
    ) {
    }

    public function deliver(PHPMailer $mail, MailPurpose $purpose): void
    {
        $lane = MailLane::fromPurpose($purpose);
        $candidates = $this->candidates($lane);

        if ($candidates === null) {
            // No chain to read at all — see the class docblock. The
            // message goes out the way it would have before any of this
            // existed.
            $this->delivery->deliver($mail, $purpose);

            return;
        }

        if ($candidates === []) {
            throw new \RuntimeException(sprintf(
                'Aucun fournisseur d’envoi disponible pour la voie « %s ».',
                $lane->label()
            ));
        }

        $lastReason = '';
        foreach ($candidates as $provider) {
            $this->configurator->apply($mail, $provider);

            try {
                $this->delivery->deliver($mail, $purpose);
            } catch (\Throwable $e) {
                $lastReason = MailErrorRedaction::withoutAddresses($mail->ErrorInfo ?: $e->getMessage());
                $this->journalAttemptFailure($provider, $lane, $lastReason);
                continue;
            }

            // After the transport returned, never before: a counter moved
            // on an attempt would step the lane past a provider that is
            // working perfectly the moment anything else goes wrong.
            $this->counters->increment($provider->id, $lane);

            return;
        }

        throw new \RuntimeException(sprintf(
            'Tous les fournisseurs de la voie « %s » ont échoué. Dernière raison : %s',
            $lane->label(),
            $lastReason !== '' ? $lastReason : 'inconnue'
        ));
    }

    /**
     * The providers this lane may be tried on, in order.
     *
     * **Null and the empty array mean different things**: null is « there
     * is no chain here to consult » — unreadable, or not laid down yet —
     * and the caller falls back to the configuration `MailService`
     * already applied. The empty array is « this lane is configured and
     * none of it can carry a message », which is an error. See the class
     * docblock for all three states.
     *
     * @return array<int, MailProvider>|null
     */
    private function candidates(MailLane $lane): ?array
    {
        try {
            $entries = $this->chains->forLane($lane);
            $providers = $this->directory->all();
            $usedToday = $this->counters->totalsForDay();
        } catch (\Throwable) {
            return null;
        }

        if ($entries === []) {
            return null;
        }

        $candidates = [];
        foreach ($entries as $entry) {
            if (!$entry->enabled) {
                continue;
            }

            $provider = $providers[$entry->providerId] ?? null;
            if ($provider === null || !$provider->isUsable()) {
                continue;
            }

            if ($this->hasSpentItsQuota($provider, $usedToday)) {
                continue;
            }

            $candidates[] = $provider;
        }

        return $candidates;
    }

    /**
     * @param array<int, int> $usedToday
     */
    private function hasSpentItsQuota(MailProvider $provider, array $usedToday): bool
    {
        if ($provider->dailyQuota === null) {
            return false;
        }

        return ($usedToday[$provider->id] ?? 0) >= $provider->dailyQuota;
    }

    /**
     * One relay refused; the chain carries on.
     *
     * `warning` rather than `error`: the message has not been lost yet —
     * the next entry is about to be tried, and the whole point of a chain
     * is that one relay refusing is an ordinary event. `MailService`
     * writes the `error` entry if every one of them refuses.
     *
     * Never fails the send, for the reason `MailService::journalFailure()`
     * gives: this runs on a path where something is already going wrong,
     * and a journal insert that throws must not replace a transport
     * failure the caller knows how to read with one it does not.
     */
    private function journalAttemptFailure(MailProvider $provider, MailLane $lane, string $reason): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_provider_attempt_failed',
                'warning',
                'Un fournisseur d’envoi a refusé un message',
                [
                    'provider_id' => $provider->id,
                    'provider' => $provider->name,
                    'lane' => $lane->value,
                    'reason' => $reason,
                ]
            );
        } catch (\Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }
}
