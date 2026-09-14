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
     * The folder this application creates in the operator's Drive.
     *
     * Named here rather than in the backend because the backend creates it
     * on first use and the screen names it to the operator before that
     * ever happens — two spellings would be two folders.
     */
    public const FOLDER_NAME = 'ScoutMagic — sauvegardes';

    public function __construct(
        public readonly string $clientId = '',
        public readonly string $folderId = '',
        /**
         * When the grant was last obtained, ISO-8601, or '' when never.
         *
         * Kept because « la sauvegarde hors site n'est jamais partie »
         * needs a date to be measured from on a destination that has
         * received nothing yet, and the day it was connected is the only
         * honest one (`Core\Alert\Check\RemoteBackupAgeCheck`).
         */
        public readonly string $connectedAt = ''
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
            connectedAt: self::text($raw, 'connected_at')
        );
    }

    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'folder_id' => $this->folderId,
            'connected_at' => $this->connectedAt,
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
     */
    public function describe(): string
    {
        return 'Google Drive — ' . self::FOLDER_NAME;
    }

    /**
     * Whether an account has been through the consent screen at all.
     *
     * The folder id is the proof: it is written only once Google has
     * answered, and it is what every later call addresses. A client id on
     * its own is an operator half way through the setup.
     */
    public function isConnected(): bool
    {
        return $this->folderId !== '';
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
