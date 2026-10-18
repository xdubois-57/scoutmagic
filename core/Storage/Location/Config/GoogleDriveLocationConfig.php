<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * A folder this application creates in somebody's Google Drive.
 *
 * **Three fields, and what is missing from them is the design.** The
 * client secret, the refresh token and the account address are NOT here:
 * they live in the row's encrypted column ({@see GoogleDriveSecret}),
 * which is D7 of the chantier and the reason the off-site backup's
 * credentials left `secrets.enc` at all. This record is the half that may
 * be rendered, journalled, exported in a support package and read by
 * anybody who can open the configuration page.
 *
 * **The client id stays in the clear, deliberately and not by oversight.**
 * It travels in the authorisation URL the operator's own browser follows,
 * so it is public by construction, and keeping it legible is what lets an
 * operator check they pasted the right one.
 *
 * **The account address is the field that looks like it belongs here and
 * does not.** It is not a credential — but it is the e-mail address of a
 * real person, AGENTS.md's security checklist asks personal data to be
 * encrypted at rest, and a `config` column is rendered in clear on the
 * storage page and travels unredacted in the diagnostic export. So it sits
 * on the other side of the line, beside the grant it belongs to.
 */
final class GoogleDriveLocationConfig implements LocationConfig
{
    /**
     * The folder every Drive location of an account sits under, at the
     * top of « Mon Drive » (#474).
     *
     * **Shared, and only this one.** Each location gets its own folder
     * UNDER it, named after the location's label and remembered by id
     * ({@see $folderId}); this parent is the one folder looked up by name,
     * so that two locations on one account group together in the
     * operator's Drive rather than scattering at its top level. It used to
     * be the location's folder itself — `ScoutMagic — sauvegardes`, found
     * by name — which is how two locations on one account ended up writing
     * into the same folder.
     */
    public const PARENT_FOLDER_NAME = 'ScoutMagic';

    public function __construct(
        public readonly string $clientId = '',
        /**
         * The Drive id of this location's own folder,
         * `ScoutMagic/<label>/`, written by the connection flow.
         *
         * **The only way the site finds its folder.** Never its name: the
         * folder is renamed with the location, an operator may rename or
         * move it in Drive, and a name is what two locations used to share.
         * A location connected before #474 holds the id of the old flat
         * folder here, and is meant to be recreated rather than migrated.
         */
        public readonly string $folderId = '',
        /**
         * When the grant was last obtained, ISO-8601, or '' when never.
         *
         * Kept because « la sauvegarde hors site n'est jamais partie »
         * needs a date to be measured from on a destination that has
         * received nothing yet, and the day it was connected is the only
         * honest one (`Core\Alert\Check\RemoteBackupAgeCheck`).
         */
        public readonly string $connectedAt = '',
        /**
         * Whether the grant's lifetime was looked at when it was obtained
         * (issue #718 § C point 10.2): true for a connection made since the
         * callback reads `refresh_token_expires_in`, false for an older one
         * — whose lifetime nobody knows, and which must not be mistaken for
         * a grant that announced none.
         */
        public readonly bool $grantObserved = false,
        /**
         * When Google said the refresh token stops working, ISO-8601 by the
         * application clock, or '' when it announced no end. Only ever
         * meaningful with {@see $grantObserved}: '' on an unobserved grant
         * is « unknown », never « permanent » — and nothing here infers the
         * Google project's « Test » or « En production » status from it.
         */
        public readonly string $grantExpiresAt = ''
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            clientId: self::text($raw, 'client_id'),
            folderId: self::text($raw, 'folder_id'),
            connectedAt: self::text($raw, 'connected_at'),
            grantObserved: ($raw['grant_observed'] ?? false) === true,
            grantExpiresAt: self::text($raw, 'grant_expires_at')
        );
    }

    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'folder_id' => $this->folderId,
            'connected_at' => $this->connectedAt,
            'grant_observed' => $this->grantObserved,
            'grant_expires_at' => $this->grantExpiresAt,
        ];
    }

    /**
     * **No**, and it is not a judgement call.
     *
     * Everything this application puts in a Drive folder is reached with a
     * `Bearer` token belonging to one account under the `drive.file` scope.
     * There is no URL a visitor could hold, with or without an expiry, so
     * the question a consumer with its own access control is really asking
     * — « would storing here publish it » — has one answer.
     */
    public function servesPubliclyWithoutExpiry(): bool
    {
        return false;
    }

    /**
     * **Names the folder, never the account.** This string is rendered on
     * the storage page and carried in the support package, and the account
     * is the e-mail address of a real person — which is exactly why it is
     * on the encrypted side. The screen that legitimately shows it to an
     * administrator reads it from there.
     *
     * The folder is named after the location's label, which this record
     * does not hold: {@see describeFolderOf()} is what a location that
     * knows its label says, and this is the label-less form.
     */
    public function describe(): string
    {
        return 'Google Drive — Mon Drive › ' . self::PARENT_FOLDER_NAME . ' › dossier au nom de l\'emplacement';
    }

    /** The folder's id — never its name, which two folders can share. */
    public function target(): string
    {
        return $this->folderId;
    }

    /** The real path of this location's folder, for a location labelled $label. */
    public function describeFolderOf(string $label): string
    {
        return 'Google Drive — Mon Drive › ' . self::PARENT_FOLDER_NAME . ' › ' . $label;
    }

    /**
     * Whether an account has been through the consent screen at all.
     *
     * The connection date is the proof: it is written only once Google
     * has answered. Not the folder id any more — that one survives a
     * disconnection on purpose, so that reconnecting the same account
     * finds the same folder rather than starting an empty one.
     */
    public function isConnected(): bool
    {
        return $this->connectedAt !== '';
    }

    /**
     * The same location with its folder changed, the grant left as it is —
     * for the flows that attach or create a folder without going back
     * through Google's consent screen.
     */
    public function withFolder(string $folderId): self
    {
        return new self($this->clientId, $folderId, $this->connectedAt, $this->grantObserved, $this->grantExpiresAt);
    }

    /**
     * The same grant, its announced end dropped: a check succeeded after
     * it, which proves it no longer applies (#718 § C point 10.4). Kept as
     * « observed, nothing to count down », so a later failure for some
     * other reason is reported as that failure — never as the end coming
     * back.
     */
    public function withGrantEndDisproved(): self
    {
        return new self($this->clientId, $this->folderId, $this->connectedAt, true, '');
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
