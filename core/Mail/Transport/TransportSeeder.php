<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Config\SettingService;

/**
 * The chains an installation starts with (ARCHITECTURE.md §8.106).
 *
 * Two things have to be true on the first request after this lands, on
 * an installation that has been sending mail for a year:
 *
 * - **The relay it already had keeps working.** The setup wizard writes
 *   `smtp_host`/`smtp_port`/`smtp_user`/`smtp_password` into
 *   `secrets.enc`, and those four keys become the first provider, under
 *   the prefix `smtp` — the same storage, not a copy of it, so the
 *   wizard and the Fournisseurs page can never drift apart.
 * - **The local send is in all three chains**, because an installation
 *   in `local` mode was sending everything that way and must go on doing
 *   it.
 *
 * **`mail_mode` decides whether there is a relay to import at all, and
 * `smtp_host` does not.** The wizard writes that host back into
 * `secrets.enc` on every save whatever the mode, so an installation that
 * switched SMTP → Local keeps a perfectly readable host it deliberately
 * stopped using. Importing on the host alone would put that abandoned
 * third party first in all three chains — magic links included — on the
 * first boot after this lands, and {@see TransportConfigurator::apply()}
 * would call `isSMTP()` over the local mode {@see \Core\Mail\MailService}
 * had chosen. So the relay is imported only in `smtp` mode, and an
 * absent `mail_mode` reads as `local`, which is what
 * {@see \Core\Mail\MailServiceFactory} already defaults it to.
 *
 * **One flag, since issue #336.** There used to be a second —
 * `mail_transport_relay_imported` — and it existed for one path: an
 * installation seeded while local-only had to pick up the relay somebody
 * configured **through the wizard** a month later, or its mail would go on
 * leaving locally for ever while « Installation & serveur » showed a
 * relay.
 *
 * That page no longer configures a relay after initialisation: the relay
 * belongs to « Courrier sortant › Fournisseurs », which writes a provider
 * row directly rather than a secret this class would have to notice. The
 * path is gone, so the flag that served it is gone with it, and the
 * remaining question is the simple one — have the lanes been laid?
 *
 * What that must NOT become, and what the tests hold: an already-seeded
 * site re-importing on its next boot, and in particular resurrecting a
 * relay the administrator DELETED from the Fournisseurs page — whose
 * secrets {@see ProviderConnections::forget()} deliberately keeps. With one
 * flag the answer is structural rather than careful: a seeded site returns
 * before reading anything else at all.
 *
 * The seeding is idempotent and cheap: one settings read on every boot,
 * and the rest only on a boot that has something to do — an installation
 * with no relay to import reads `mail_mode` out of the secrets the
 * composition root had already decrypted, which costs nothing. It
 * deliberately does NOT carry `mass_mail`'s old
 * `batch_size`/`batch_interval_minutes` over
 * (D14) — the project is in test, no production installation is being
 * preserved, and a carried-over number nobody chose is worse than a
 * default somebody can read the reasoning for.
 */
final class TransportSeeder
{
    public const SETTING_SEEDED = 'mail_transport_seeded';

    /** What a relay starts at when nobody has said otherwise. */
    public const DEFAULT_RELAY_BATCH_SIZE = 50;
    public const DEFAULT_RELAY_BATCH_INTERVAL = 10;

    public function __construct(
        private MailProviderRepository $providers,
        private LaneChainRepository $chains,
        private SettingService $settings
    ) {
    }

    /**
     * @param array<string, mixed> $secrets The composition root's already
     *        decrypted secrets — the only place the pre-existing relay
     *        can be found.
     */
    public function seed(array $secrets): void
    {
        // Nothing left to decide, and nothing else read: the lanes exist,
        // so this installation has been through here. A second condition
        // used to sit beside this one and is what made the common boot able
        // to re-import (issue #336) — see the class docblock for the path
        // it served and why that path is gone.
        if ((string) $this->settings->get(self::SETTING_SEEDED, null, '') === '1') {
            return;
        }

        try {
            $this->layDownChains($secrets);
        } catch (\Throwable) {
            // A database that cannot answer yet — a first install whose
            // schema has not been created, a migration mid-flight — is
            // not a reason to fail a request. Nothing is marked as
            // seeded, so the next boot tries again, and until then the
            // chain reads « aucune chaîne » and hands the message back to
            // the transport MailService had already configured
            // (MailTransportChain's own class docblock).
            return;
        }

        $this->mark(self::SETTING_SEEDED);
    }

    /**
     * The relay this installation is actually sending through, or an
     * empty string when there is none to import.
     *
     * Both halves matter. A host without `smtp` mode is the relay an
     * administrator switched away from, still written back by the wizard
     * on every save; `smtp` mode without a host is a mode nobody has
     * finished configuring. Neither is a provider.
     *
     * @param array<string, mixed> $secrets
     */
    private function relayHost(array $secrets): string
    {
        if ((string) ($secrets['mail_mode'] ?? 'local') !== 'smtp') {
            return '';
        }

        return trim((string) ($secrets[ProviderConnections::LEGACY_PREFIX . '_host'] ?? ''));
    }

    /**
     * @param array<string, mixed> $secrets
     * @return bool Whether this installation's own relay is now a
     *         provider row — false when it has none to import.
     */
    private function layDownChains(array $secrets): bool
    {
        $legacyHost = $this->relayHost($secrets);
        $relayId = null;

        if ($legacyHost !== '') {
            // **Resumed by prefix, never by a count of providers**, and
            // the difference is a permanent gap rather than a retry.
            // Laying the chains down is several writes: the row, then one
            // entry per lane. A transient failure between them leaves the
            // row committed and the seed unmarked, so the next boot tries
            // again — and a `countAll() === 0` guard would then conclude
            // there is nothing to create, leave `$relayId` null, and skip
            // the lanes the first attempt never reached. The relay would
            // be missing from them for the life of the installation, with
            // no screen able to put it back and mail still flowing, so
            // nothing would ever say so.
            $existing = $this->providers->findBySecretPrefix(ProviderConnections::LEGACY_PREFIX);
            $relayId = $existing['id'] ?? $this->providers->create(
                $this->nameFor($legacyHost),
                null,
                self::DEFAULT_RELAY_BATCH_SIZE,
                self::DEFAULT_RELAY_BATCH_INTERVAL,
                ProviderConnections::LEGACY_PREFIX
            );
        }

        foreach (MailLane::ordered() as $lane) {
            if ($relayId !== null && !$this->chains->exists($lane, $relayId)) {
                $this->chains->append($lane, $relayId, true);
                $this->putFirst($lane, $relayId);
            }

            if (!$this->chains->exists($lane, MailProvider::LOCAL_ID)) {
                // Enabled, including behind a relay: an installation that
                // was sending locally must keep sending, and one that was
                // sending through a relay gains the fallback this whole
                // change exists to give it. A unit that would rather its
                // mailings never leave from the server itself disables the
                // entry on the Masse lane, which the screen suggests.
                $this->chains->append($lane, MailProvider::LOCAL_ID, true);
            }
        }

        return $relayId !== null;
    }

    /**
     * The relay leads the lane it has just joined.
     *
     * `append()` puts it last, which is right on the boot that lays a
     * lane down — the relay goes in before the local send — and wrong on
     * every later one, where the local send already holds position 0. A
     * relay configured through the wizard a month after the first boot
     * would then sit BEHIND the local send: tried second, reached only
     * when sending from the server itself had failed. Mail would keep
     * leaving locally, which is the very outcome importing it late is
     * meant to end.
     *
     * Only ever called for an entry this pass has just created, so it
     * cannot overrule an order an administrator chose.
     */
    private function putFirst(MailLane $lane, int $providerId): void
    {
        $others = array_values(array_filter(
            array_map(static fn(LaneEntry $entry): int => $entry->providerId, $this->chains->forLane($lane)),
            static fn(int $id): bool => $id !== $providerId
        ));

        $this->chains->reorder($lane, [$providerId, ...$others]);
    }

    /**
     * A name a volunteer recognises, derived from the host: « Brevo » out
     * of `smtp-relay.brevo.com`, « Ovh » out of `ssl0.ovh.net`. It is
     * only a first label — the Fournisseurs page renames it in one click
     * — and a host that yields nothing readable falls back to the host
     * itself.
     */
    private function nameFor(string $host): string
    {
        $labels = array_values(array_filter(
            explode('.', strtolower($host)),
            static fn(string $label): bool => $label !== '' && !in_array($label, [
                'www', 'mail', 'smtp', 'smtp-relay', 'relay', 'mx', 'out', 'send', 'in',
                // Suffixes, named rather than measured. A « drop any
                // trailing label of three letters or fewer » rule reads
                // as the same idea and is not: `ssl0.ovh.net` would
                // become « Ssl0 », because `ovh` IS the provider. The
                // country codes below are the ones a unit here plausibly
                // buys a relay in; an unknown suffix names the provider
                // after its country, which is wrong but visible, and the
                // Fournisseurs page renames it in one click.
                'com', 'net', 'org', 'eu', 'io', 'be', 'fr', 'ch', 'de', 'nl',
                'lu', 'uk', 'es', 'it', 'pt', 'at', 'dk', 'se', 'no', 'fi',
                'pl', 'cz', 'ie', 'ca',
            ], true)
        ));

        $candidate = $labels === [] ? $host : $labels[count($labels) - 1];

        return mb_substr(ucfirst($candidate), 0, 100);
    }

    private function mark(string $setting): void
    {
        try {
            $this->settings->setInternal($setting, '1');
        } catch (\Throwable) {
            // A bookkeeping flag that could not be written must never
            // fail a boot — the same posture as the support settings of
            // §8.4. The seeding is idempotent, so the worst case is that
            // it runs its three `exists()` checks again next request.
        }
    }
}
