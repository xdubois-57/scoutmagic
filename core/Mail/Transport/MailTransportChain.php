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
        private ?MailReserve $reserve = null,
        /**
         * Which relay a recipient domain prefers, on the mailing lane
         * (roadmap IT-07, D13). Null everywhere the preference has no
         * meaning — the tests of the chain itself above all, which are
         * about order and failure, not about who is receiving.
         */
        private ?DomainPreferences $preferences = null
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
            $this->journalLaneExhausted($lane, 'aucun fournisseur disponible');

            throw new LaneExhaustedException($lane, 'aucun fournisseur disponible');
        }

        $candidates = $this->preferred($candidates, $lane, $mail);

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

        $this->journalLaneExhausted($lane, $lastReason);

        throw new LaneExhaustedException($lane, $lastReason);
    }

    /**
     * The same candidates, with this recipient's preferred relay first
     * (roadmap IT-07, D13).
     *
     * **A preference is never allowed to cost a message.** It reorders a
     * list the lane has already vetted, so the worst a wrong or stale
     * preference can do is try a working relay in a different order; and
     * anything that goes wrong reading it — an unreadable setting, a
     * database that just went away — leaves the order untouched rather
     * than stopping a mailing that has nothing to do with it.
     *
     * The recipient is read from the message rather than passed in
     * because `MailTransportInterface` is the boundary every transport
     * implements, and widening it for one lane's preference would oblige
     * every implementation to carry a parameter only this one reads.
     *
     * @param array<int, MailProvider> $candidates
     * @return array<int, MailProvider>
     */
    private function preferred(array $candidates, MailLane $lane, PHPMailer $mail): array
    {
        if ($this->preferences === null || $lane !== MailLane::Bulk) {
            return $candidates;
        }

        try {
            $recipients = $mail->getToAddresses();
            // One recipient, or no preference: a mailing is sent one
            // message per member, so a message with several `To:` is not
            // one this reading has an opinion about — and picking the
            // first of them would route the rest by somebody else's
            // domain.
            if (count($recipients) !== 1) {
                return $candidates;
            }

            return $this->preferences->reorder($candidates, $lane, (string) ($recipients[0][0] ?? ''));
        } catch (\Throwable) {
            return $candidates;
        }
    }

    /**
     * A whole lane has run out, which is a different event from one relay
     * refusing.
     *
     * **`security` on the authentication lane, `info` on the other two**,
     * and the gap between those two words is the gap between an
     * inconvenience and an incident. A mailing that cannot go out today
     * goes out tomorrow; a sign-in link that cannot go out means nobody
     * can enter the site, the person who would repair it included, and
     * that belongs in the journal a security review reads rather than in
     * the stream of ordinary operational noise.
     */
    private function journalLaneExhausted(MailLane $lane, string $reason): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_lane_exhausted',
                $lane === MailLane::Authentication ? 'security' : 'info',
                sprintf('Voie « %s » épuisée : aucun fournisseur n\'a pu prendre le message', $lane->label()),
                [
                    'lane' => $lane->value,
                    // Already redacted of addresses where it comes from a
                    // transport (MailErrorRedaction, applied at the catch
                    // above); redacted again here because the empty-chain
                    // branch builds its own string and a future edit to
                    // either one should not have to remember this.
                    'reason' => MailErrorRedaction::withoutAddresses($reason),
                ]
            );
        } catch (\Throwable) {
            // Same posture as every other journal call on this path.
        }
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
            // Read before writing, so « the circuit opened » can be told
            // from « it was already open and this is the one attempt the
            // never-empty-a-lane rule allowed » (D15). Without it every
            // failure on a shut-out provider writes another « écarté
            // jusqu'à » line, and a relay down for an afternoon fills the
            // journal with the same event a hundred times — which is how
            // an operational journal stops being read. One indexed read
            // on a path that has just spent seconds failing to connect.
            $openingsBefore = $this->health->forProvider($provider->id)->openCount;
            $health = $this->health->recordFailure($provider->id, $reason);
        } catch (\Throwable) {
            return;
        }

        if ($health->openCount > $openingsBefore) {
            $this->journalCircuit(
                $provider,
                $lane,
                'mail_provider_circuit_opened',
                sprintf('Fournisseur d’envoi écarté jusqu’à %s', $health->openedUntil ?? '?'),
                ['until' => $health->openedUntil, 'reason' => $reason]
            );
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
            $context = array_merge(
                ['provider_id' => $provider->id, 'provider' => $provider->name],
                $lane === null ? [] : ['lane' => $lane->value],
                $extra
            );
            $this->journal?->log('core', $event, 'info', $title, $context);
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
