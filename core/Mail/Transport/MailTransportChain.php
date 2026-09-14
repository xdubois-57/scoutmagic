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
        private ?JournalService $journal = null,
        private ?ProviderHealthRepository $health = null,
        private ?MailReserve $reserve = null
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
            throw new LaneExhaustedException($lane, 'aucun fournisseur disponible');
        }

        $lastReason = '';
        foreach ($candidates as $provider) {
            $this->configurator->apply($mail, $provider);

            try {
                $this->delivery->deliver($mail, $purpose);
            } catch (\Throwable $e) {
                $lastReason = MailErrorRedaction::withoutAddresses($mail->ErrorInfo ?: $e->getMessage());
                $this->journalAttemptFailure($provider, $lane, $lastReason);
                $this->recordFailure($provider, $lane, $lastReason);
                continue;
            }

            $this->recordSuccess($provider);

            // After the transport returned, never before: a counter moved
            // on an attempt would step the lane past a provider that is
            // working perfectly the moment anything else goes wrong.
            //
            // And its failure is not the message's. The recipient has the
            // e-mail; letting a counter write propagate would have
            // `MailService` report a send that did happen as failed, and
            // the caller — `mass_mail` above all — retry it, so a
            // bookkeeping error would put a second copy in somebody's
            // inbox. The count is worth less than that.
            try {
                $this->counters->increment($provider->id, $lane);
            } catch (\Throwable $e) {
                $this->journalCounterFailure($provider, $lane, $e);
            }

            return;
        }

        throw new LaneExhaustedException($lane, $lastReason);
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

            if ($this->hasSpentItsQuota($provider, $usedToday, $lane)) {
                continue;
            }

            $candidates[] = $provider;
        }

        return $this->withoutOpenCircuits($candidates);
    }

    /**
     * Drop the providers the breaker is stepping over — unless that would
     * leave the lane with nothing.
     *
     * **This is the imperative rule of D15**, and it is worth being blunt
     * about why. A breaker still armed after the outage was repaired,
     * applied without this exception, locks every member out of the site
     * — including the super-admin who would have come to fix it. That is
     * the exact failure the whole chain was built to end, so the breaker
     * is not allowed to cause it.
     *
     * The last entry is the one kept, not the first: lane order is
     * preference order, and the last is where the local send sits by
     * construction (TransportSeeder), so « everything is shut » falls
     * back to the server's own `mail()` rather than to the relay that
     * has been refusing all morning.
     *
     * @param array<int, MailProvider> $candidates
     * @return array<int, MailProvider>
     */
    private function withoutOpenCircuits(array $candidates): array
    {
        if ($this->health === null || $candidates === []) {
            return $candidates;
        }

        try {
            $health = $this->health->all();
        } catch (\Throwable) {
            // The breaker is an optimisation over trying and failing; if
            // its table cannot be read, trying and failing is still
            // correct. It must never be the reason a message stops.
            return $candidates;
        }

        $closed = [];
        foreach ($candidates as $provider) {
            if (!($health[$provider->id] ?? null)?->isOpen()) {
                $closed[] = $provider;
            }
        }

        return $closed === [] ? [$candidates[count($candidates) - 1]] : $closed;
    }

    /**
     * Whether this provider has nothing left for THIS lane today.
     *
     * The lane is an argument because the ceiling is not the same for all
     * three. On the mailing lane the reserve comes off the quota first
     * (D8): a publipostage may spend what is left once the day's sign-in
     * links and transactional mail have been set aside. The other two
     * lanes are what the reserve protects, so they see the whole quota —
     * reserving part of a quota against its own beneficiary would be a
     * tax paid to nobody.
     *
     * @param array<int, int> $usedToday
     */
    private function hasSpentItsQuota(MailProvider $provider, array $usedToday, MailLane $lane): bool
    {
        if ($provider->dailyQuota === null) {
            return false;
        }

        $ceiling = $provider->dailyQuota;

        if ($lane === MailLane::Bulk && $this->reserve !== null) {
            try {
                $ceiling -= $this->reserve->forProvider($provider)->messages;
            } catch (\Throwable) {
                // Same posture as the breaker: a reserve that cannot be
                // computed must not become a reason to stop sending.
            }
        }

        return ($usedToday[$provider->id] ?? 0) >= max(0, $ceiling);
    }

    /**
     * Tell the breaker a provider refused — but only when the refusal was
     * its own doing (D15, {@see MailFailure}).
     *
     * A `550` walks straight past this. The relay answered, correctly,
     * about one address; counting that as a strike would shut a provider
     * out because somebody mistyped an e-mail, and the mailing lane —
     * where dead addresses collect — would shut one out nearly every run.
     */
    private function recordFailure(MailProvider $provider, MailLane $lane, string $reason): void
    {
        if ($this->health === null || MailFailure::classify($reason) === MailFailure::Recipient) {
            return;
        }

        try {
            $health = $this->health->recordFailure($provider->id, $reason);
        } catch (\Throwable) {
            return;
        }

        if ($health->isOpen() && $health->openedAt !== null) {
            $this->journalCircuit($provider, $lane, 'mail_provider_circuit_opened', sprintf(
                'Fournisseur d’envoi écarté jusqu’à %s',
                $health->openedUntil ?? '?'
            ), ['until' => $health->openedUntil, 'reason' => $reason]);
        }
    }

    /**
     * A provider answered, so whatever the breaker held against it is out
     * of date. Only the closing of an OPEN circuit is worth a line — the
     * ordinary success of a healthy relay is not news.
     */
    private function recordSuccess(MailProvider $provider): void
    {
        if ($this->health === null) {
            return;
        }

        try {
            $closed = $this->health->recordSuccess($provider->id);
        } catch (\Throwable) {
            return;
        }

        if ($closed) {
            $this->journalCircuit(
                $provider,
                null,
                'mail_provider_circuit_closed',
                'Fournisseur d’envoi de nouveau disponible',
                []
            );
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function journalCircuit(
        MailProvider $provider,
        ?MailLane $lane,
        string $event,
        string $title,
        array $extra
    ): void {
        try {
            $this->journal?->log('core', $event, 'info', $title, array_merge([
                'provider_id' => $provider->id,
                'provider' => $provider->name,
            ], $lane === null ? [] : ['lane' => $lane->value], $extra));
        } catch (\Throwable) {
            // Same posture as every other journal call on this path: the
            // message is what matters, not the note about it.
        }
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

    /**
     * A counter that could not be written, on a message that DID leave.
     *
     * Worth a line precisely because nothing else will show it: the
     * recipient has the e-mail, the caller was told the send succeeded,
     * and the only trace of the miscount is the quota being reached later
     * than the provider thinks. That is the sort of drift somebody
     * eventually has to explain, and this is where they will look.
     *
     * The reason is redacted like every other message this class writes:
     * a PDO exception quotes the statement, and a statement carries a
     * lane and a provider id but must not be trusted to carry nothing
     * else (SECURITY.md §11).
     */
    private function journalCounterFailure(MailProvider $provider, MailLane $lane, \Throwable $error): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_send_counter_failed',
                'warning',
                'Un envoi réussi n’a pas pu être compté',
                [
                    'provider_id' => $provider->id,
                    'provider' => $provider->name,
                    'lane' => $lane->value,
                    'reason' => MailErrorRedaction::withoutAddresses($error->getMessage()),
                ]
            );
        } catch (\Throwable) {
            // Same posture as above: the message left, and nothing that
            // happens here may change that.
        }
    }
}
