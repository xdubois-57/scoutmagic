<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * Where a WebDAV share is and who this site is on it.
 *
 * **Three fields, and that is the point of this type of storage.** An
 * address, a username, a password — no developer console, no OAuth
 * application to register, no consent screen to publish and re-publish
 * every seven days. Nextcloud, kDrive, a Hetzner Storage Box and Koofr
 * all speak it, and a unit that has any of those can point this at it in
 * a minute.
 *
 * The password is not here: it lives in the location row's encrypted
 * column like every other credential (D7), and this object is rendered on
 * a screen and carried into a support package.
 */
final class WebDavLocationConfig implements LocationConfig
{
    public function __construct(
        /**
         * The collection this site writes into, as a full URL —
         * `https://cloud.example.org/remote.php/dav/files/unite/scoutmagic`.
         *
         * Stored with any trailing slash removed so that one join rule
         * works everywhere: every key is appended as `/key`. Two sites
         * configured as `…/scoutmagic` and `…/scoutmagic/` would otherwise
         * write to `…/scoutmagicphoto.jpg` and `…/scoutmagic/photo.jpg`.
         */
        public readonly string $baseUrl = '',
        public readonly string $username = ''
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::normaliseBaseUrl(self::text($raw, 'base_url')),
            trim(self::text($raw, 'username'))
        );
    }

    /**
     * One stored field, and only when it is a string.
     *
     * A `(string)` cast turns a JSON array into the literal `"Array"` with
     * a warning beside it, and this record comes out of a column: a row
     * written by an older version, a hand-edited configuration, a restore
     * from somewhere else. The empty fallback is already the « not
     * configured » state every reader here handles.
     *
     * @param array<string, mixed> $raw
     */
    private static function text(array $raw, string $key): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'username' => $this->username,
        ];
    }

    public function describe(): string
    {
        if ($this->baseUrl === '') {
            return 'Partage WebDAV non configuré';
        }

        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return is_string($host) && $host !== ''
            ? sprintf('Partage WebDAV sur %s', $host)
            : 'Partage WebDAV';
    }

    /**
     * **No.** A WebDAV share answers nobody without the credentials this
     * site holds, so there is no URL to hand a visitor at all: every byte
     * is fetched by this server and served through the consumer's own
     * access-controlled route. That is slower than a signed URL and it is
     * the reason a delegated album may live here safely.
     */
    public function servesPubliclyWithoutExpiry(): bool
    {
        return false;
    }

    /**
     * Trailing slashes removed, so that appending `/key` is the only
     * join rule this type ever needs.
     */
    public static function normaliseBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
