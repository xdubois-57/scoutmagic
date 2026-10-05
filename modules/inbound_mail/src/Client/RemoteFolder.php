<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * One folder as the server lists it: its path, and whether the server marks
 * it as the box's sent mail (#720, step 3).
 *
 * The mark is the RFC 6154 special-use attribute `\Sent`, which a server
 * returns on the same LIST that names the folders — reading it costs no
 * extra command and asks nothing of the server a listing did not already
 * ask. That is why it travels with the listing rather than through a method
 * of its own: the reading contract keeps its five verbs.
 *
 * Names are never guessed. « Envoyés », « Sent Items », « Éléments
 * envoyés », « INBOX.Sent »: a server that does not mark its folder gets
 * the folder the operator names on the box's configuration, or none.
 */
final class RemoteFolder
{
    public const SENT_ATTRIBUTE = '\\Sent';

    public function __construct(
        public readonly string $path,
        public readonly bool $isSent = false
    ) {
    }

    /**
     * A LIST answer, as `[path => ['delimiter' => …, 'flags' => […]]]`.
     *
     * Kept apart from the network call so the reading of the attributes can
     * be tested without a server. Attributes are matched without regard to
     * case: RFC 3501 makes them case-insensitive, and servers disagree.
     *
     * @param array<array-key, mixed> $listing
     * @return list<RemoteFolder>
     */
    public static function fromListing(array $listing): array
    {
        $folders = [];
        foreach ($listing as $path => $item) {
            $flags = is_array($item) && is_array($item['flags'] ?? null) ? $item['flags'] : [];
            $isSent = false;
            foreach ($flags as $flag) {
                if (is_string($flag) && strcasecmp($flag, self::SENT_ATTRIBUTE) === 0) {
                    $isSent = true;
                }
            }

            $folders[] = new self((string) $path, $isSent);
        }

        return $folders;
    }

    /**
     * @param RemoteFolder[] $folders
     * @return list<string>
     */
    public static function paths(array $folders): array
    {
        return array_values(array_map(static fn(RemoteFolder $folder): string => $folder->path, $folders));
    }

    /**
     * The folder the server marks `\Sent`. Null when it marks none — and
     * also when it marks several, which no server should do and which
     * leaves no honest way to choose.
     *
     * @param RemoteFolder[] $folders
     */
    public static function sentAmong(array $folders): ?string
    {
        $sent = array_values(array_filter($folders, static fn(RemoteFolder $folder): bool => $folder->isSent));

        return count($sent) === 1 ? $sent[0]->path : null;
    }
}
