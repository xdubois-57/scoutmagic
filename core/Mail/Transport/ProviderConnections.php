<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Security\SecretManager;

/**
 * Where a provider's connection lives: `secrets.enc`, and nowhere else
 * (ARCHITECTURE.md §8.106).
 *
 * Not `settings`, and the reason is one line long: Configuration >
 * Réglages renders every settings row as a value beside its key, so a
 * relay password there would be on screen — greyed out changes nothing
 * about what a screenshot carries. Not a table either, because the
 * support archive dumps the structure of every table and the event
 * journal quotes what a failing send said.
 *
 * **The first provider's prefix is `smtp`**, which is to say the historic
 * `smtp_host` / `smtp_port` / `smtp_user` / `smtp_password` keys the
 * setup wizard has written since the first release. That is deliberate
 * and it is the whole answer to a drift this would otherwise have
 * created: the wizard's « Installation & serveur » page and the new
 * Fournisseurs page would each hold their own copy of the same relay,
 * and an administrator changing the password on one of them would break
 * the other with no error anywhere. One storage, two screens.
 *
 * Reads come from the array the composition root already decrypted at
 * boot, so resolving a chain costs no file I/O. Writes go through
 * `SecretManager`, which is why that dependency is nullable: the
 * scheduled path reads and never writes.
 */
final class ProviderConnections
{
    /** The prefix the installation's first, wizard-configured relay uses. */
    public const LEGACY_PREFIX = 'smtp';

    /** The prefix every provider added from the Fournisseurs page uses. */
    public const PREFIX_TEMPLATE = 'mail_provider_%d';

    /**
     * @param array<string, mixed> $secrets Already decrypted by the
     *        composition root. A later write refreshes this copy so a
     *        request that saves a provider and then sends through it does
     *        not read the value it just replaced.
     */
    public function __construct(
        private array $secrets,
        private ?SecretManager $secretManager = null
    ) {
    }

    public static function prefixFor(int $providerId): string
    {
        return sprintf(self::PREFIX_TEMPLATE, $providerId);
    }

    public function host(string $prefix): string
    {
        return trim((string) ($this->secrets[$prefix . '_host'] ?? ''));
    }

    public function port(string $prefix): int
    {
        $port = (int) ($this->secrets[$prefix . '_port'] ?? 0);

        return $port > 0 ? $port : 587;
    }

    public function username(string $prefix): string
    {
        return (string) ($this->secrets[$prefix . '_user'] ?? '');
    }

    /**
     * The one method that returns a credential.
     *
     * Called by `TransportConfigurator` and by nothing else — a provider
     * value object deliberately has no password property, so there is no
     * accidental path from a relay to a template, a journal context or a
     * support archive.
     */
    public function password(string $prefix): string
    {
        return (string) ($this->secrets[$prefix . '_password'] ?? '');
    }

    public function hasPassword(string $prefix): bool
    {
        return $this->password($prefix) !== '';
    }

    /**
     * Write a provider's connection back.
     *
     * A null password means « keep the stored one », exactly as the form
     * label says and exactly as `inbound_mail`'s mailbox page already
     * behaves (§8.58): the page cannot show an operator what they would
     * be retyping, so a blank field has to mean "unchanged" rather than
     * "clear".
     *
     * Reading before writing is not optional: `writeSecrets()` replaces
     * the whole document, so composing one out of four mail keys would
     * take the master encryption keys and the database password with it.
     * Same rule, and the same refusal, as every other writer of
     * `secrets.enc`: never write back a document that could not be read.
     */
    public function store(string $prefix, string $host, int $port, string $username, ?string $password): void
    {
        $this->mutate([
            $prefix . '_host' => $host,
            $prefix . '_port' => (string) $port,
            $prefix . '_user' => $username,
        ] + ($password === null ? [] : [$prefix . '_password' => $password]));
    }

    /**
     * Drop everything a deleted provider had.
     *
     * The legacy prefix is refused: those four keys are the installation's
     * own relay, written by the setup wizard, and removing them because
     * somebody deleted a row would silently empty « Installation &
     * serveur ».
     */
    public function forget(string $prefix): void
    {
        if ($prefix === self::LEGACY_PREFIX) {
            return;
        }

        $this->mutate([
            $prefix . '_host' => '',
            $prefix . '_port' => '',
            $prefix . '_user' => '',
            $prefix . '_password' => '',
        ]);
    }

    /**
     * @param array<string, string> $values An empty value removes the key.
     */
    private function mutate(array $values): void
    {
        if ($this->secretManager === null) {
            throw new \RuntimeException('Aucun gestionnaire de secrets n’a été fourni à ce service.');
        }

        $secrets = $this->secretManager->readSecrets();
        foreach ($values as $key => $value) {
            if ($value === '') {
                unset($secrets[$key]);
                continue;
            }
            $secrets[$key] = $value;
        }

        // Written first, remembered second. Updating the in-memory copy
        // inside the loop above left this object describing a file that
        // a failed write had not changed — and it is the copy the rest of
        // the request reads, so the screen would have shown the new host
        // while `secrets.enc` still held the old one.
        $this->secretManager->writeSecrets($secrets);
        $this->secrets = $secrets;
    }
}
