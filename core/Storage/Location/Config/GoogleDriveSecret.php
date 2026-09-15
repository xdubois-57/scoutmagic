<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * The encrypted half of a Google Drive location: the three values that
 * must never be rendered, journalled or exported.
 *
 * **Why three values in one column rather than three columns.** The
 * `storage_locations` row has exactly one `secret_encrypted` BLOB, for the
 * reason {@see LocationConfig} gives about the configuration record: what
 * a location needs varies by type, and widening the table per type is how
 * half of every row becomes NULL. So the encrypted side is serialised the
 * same way the clear side is — as a per-type record — and this class is
 * what keeps it typed in PHP instead of an untyped array passed around.
 *
 * **Where it comes from, and why that matters.** Until IT-05 these three
 * lived in `secrets.enc`, the site's single JSON document of secrets,
 * beside the SMTP password and the column encryption key. D7 moves them
 * into the row of the location they describe. That is not tidying: a
 * second Drive destination was unrepresentable while the keys were named
 * `remote_backup_refresh_token`, and writing `secrets.enc` means
 * REWRITING ALL OF IT — so a site whose master key had gone bad could not
 * reconnect a backup destination without risking every other secret it
 * owned. Here the blast radius of a bad write is one location.
 *
 * `secrets.enc` keeps exactly one of the old keys, and deliberately: the
 * phrase that encrypts the archives ({@see
 * \Core\Maintenance\Remote\RemotePassphrase}) belongs to the ARCHIVES, not
 * to the destination they happen to be sent to, and it has to outlive
 * every destination an operator ever connects.
 */
final class GoogleDriveSecret
{
    public function __construct(
        /** The OAuth client secret of the operator's own Google project. */
        public readonly string $clientSecret = '',
        /**
         * The long-lived grant.
         *
         * **Kept even after Google stops honouring it**, which is a
         * reversal of what `RemoteBackupConnection` did and the reversal
         * is the point. That class dropped the token on a refusal, on the
         * grounds that a dead credential on disk is a credential to leak
         * for nothing — true of `secrets.enc`, which every part of this
         * application reads. Here the value is in the encrypted column of
         * one location row, and keeping it is what lets
         * `Core\Alert\Check\RemoteBackupAgeCheck` go on measuring a site
         * whose grant died silently: that is the exact failure the check
         * exists for, and dropping the token is what used to hide it.
         */
        public readonly string $refreshToken = '',
        /**
         * The Google account the grant belongs to.
         *
         * Not a credential, and encrypted anyway: it is the e-mail address
         * of a real person, and the clear-side record is rendered on a
         * configuration page and exported in the support archive.
         */
        public readonly string $account = ''
    ) {
    }

    /**
     * Reads the column back, tolerating anything that is not this record.
     *
     * **Never throws.** The one caller is a backend being built to answer
     * a page, and a secret that has become unreadable — a master key
     * replaced, a row written by a newer version — must present as « not
     * connected », which every caller already handles, rather than as a
     * fatal on the configuration screen somebody opened to repair it.
     */
    public static function fromStorage(?string $encoded): self
    {
        if ($encoded === null || $encoded === '') {
            return new self();
        }

        $raw = json_decode($encoded, true);
        if (!is_array($raw)) {
            return new self();
        }

        return new self(
            clientSecret: self::text($raw, 'client_secret'),
            refreshToken: self::text($raw, 'refresh_token'),
            account: self::text($raw, 'account')
        );
    }

    /**
     * The string the repository encrypts, or **null when there is nothing
     * to keep**.
     *
     * Null rather than an empty record, because
     * {@see \Core\Storage\Location\StorageLocationRepository::update()}
     * reads a null or empty secret as « leave what is there alone ». A
     * caller clearing the grant therefore does not call this; it writes a
     * record whose fields are empty strings, which is a different thing
     * and encodes to JSON that is present and says so.
     */
    public function toStorage(): ?string
    {
        if ($this->clientSecret === '' && $this->refreshToken === '' && $this->account === '') {
            return null;
        }

        return json_encode([
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'account' => $this->account,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Whether this site still holds a grant it can try to use. */
    public function hasGrant(): bool
    {
        return $this->refreshToken !== '';
    }

    /** Whether the operator has entered their OAuth client at all. */
    public function hasClientSecret(): bool
    {
        return $this->clientSecret !== '';
    }

    /**
     * The same record with a new grant, leaving the client alone.
     *
     * A connection replaces the token and the account; it does not touch
     * the client credentials, which the operator typed once and which
     * outlive any number of reconnections.
     */
    public function withGrant(string $refreshToken, string $account): self
    {
        return new self($this->clientSecret, $refreshToken, $account);
    }

    /** The same record with new client credentials, keeping the grant. */
    public function withClientSecret(string $clientSecret): self
    {
        return new self($clientSecret, $this->refreshToken, $this->account);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function text(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
