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
 * The seeding is idempotent and cheap: one settings read on every boot,
 * and the rest only on the boot that finds nothing. It deliberately does
 * NOT carry `mass_mail`'s old `batch_size`/`batch_interval_minutes` over
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

        $this->markSeeded();
    }

    /**
     * @param array<string, mixed> $secrets
     */
    private function layDownChains(array $secrets): void
    {
        $legacyHost = trim((string) ($secrets[ProviderConnections::LEGACY_PREFIX . '_host'] ?? ''));
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
                'com', 'net', 'org', 'be', 'fr', 'eu', 'io',
            ], true)
        ));

        $candidate = $labels === [] ? $host : $labels[count($labels) - 1];

        return mb_substr(ucfirst($candidate), 0, 100);
    }

    private function markSeeded(): void
    {
        try {
            $this->settings->setInternal(self::SETTING_SEEDED, '1');
        } catch (\Throwable) {
            // A bookkeeping flag that could not be written must never
            // fail a boot — the same posture as the support settings of
            // §8.4. The seeding is idempotent, so the worst case is that
            // it runs its three `exists()` checks again next request.
        }
    }
}
