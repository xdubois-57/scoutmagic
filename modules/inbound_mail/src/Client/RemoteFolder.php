<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * One folder as the server lists it: its path, whether the server marks it
 * as the box's sent mail (#720, step 3), and its other attributes — which
 * say whether the configuration may offer it at all (D9).
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

    /**
     * The attributes of a folder the configuration never offers to read
     * as received mail (D9): junk, trash, drafts, Gmail's « Tous les
     * messages » (which holds every message a second time), the starred
     * and « Important » views, and a name that is only a level of the
     * hierarchy and cannot be opened. RFC 6154 and RFC 3501, matched
     * without regard to case.
     */
    public const NEVER_OFFERED = [
        '\\Junk',
        '\\Trash',
        '\\Drafts',
        '\\All',
        '\\Flagged',
        '\\Important',
        '\\Noselect',
        '\\NonExistent',
    ];

    /**
     * @param list<string> $attributes the other attributes the server
     *   returned, as it spelt them
     */
    public function __construct(
        public readonly string $path,
        public readonly bool $isSent = false,
        public readonly array $attributes = []
    ) {
    }

    /**
     * Whether the form may offer this folder among « Autres dossiers
     * reçus »: not the inbox, which is always read, not the sent mail,
     * which is shown apart, and none of the special folders above.
     */
    public function isOfferedAsReceived(bool $includingJunk = false): bool
    {
        if ($this->isSent || strcasecmp($this->path, 'INBOX') === 0) {
            return false;
        }

        foreach ($this->attributes as $attribute) {
            foreach (self::NEVER_OFFERED as $special) {
                if ($includingJunk && strcasecmp($special, '\\Junk') === 0) {
                    continue;
                }
                if (strcasecmp($attribute, $special) === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether the folder can be opened at all: not a mere level of the
     * hierarchy (`\Noselect`), not a name the server says does not exist.
     */
    public function isSelectable(): bool
    {
        foreach ($this->attributes as $attribute) {
            if (strcasecmp($attribute, '\\Noselect') === 0 || strcasecmp($attribute, '\\NonExistent') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The folders the form may offer as the box's sent mail, for a server
     * that marks none `\Sent` (#1343): every folder that can be opened but
     * the inbox, chosen from a list and never typed — the server's own
     * names, whatever its language or its hierarchy (`INBOX.Sent`).
     *
     * @param RemoteFolder[] $folders
     * @return list<string>
     */
    public static function sentCandidatesAmong(array $folders): array
    {
        return self::paths(array_values(array_filter(
            $folders,
            static fn(RemoteFolder $folder): bool => $folder->isSelectable() && strcasecmp($folder->path, 'INBOX') !== 0
        )));
    }

    /**
     * The paths of the folders the form may offer as received mail.
     *
     * `$includingJunk` is for one kind of box only: a seed box, whose whole
     * purpose is telling whether a copy landed in the inbox or in the junk
     * folder, and which must therefore read that folder too
     * (`Core\Mail\Feedback\Seed\SeedMailboxes`).
     *
     * @param RemoteFolder[] $folders
     * @return list<string>
     */
    public static function offeredAmong(array $folders, bool $includingJunk = false): array
    {
        return self::paths(array_values(array_filter(
            $folders,
            static fn(RemoteFolder $folder): bool => $folder->isOfferedAsReceived($includingJunk)
        )));
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
            $attributes = [];
            foreach ($flags as $flag) {
                if (!is_string($flag)) {
                    continue;
                }
                if (strcasecmp($flag, self::SENT_ATTRIBUTE) === 0) {
                    $isSent = true;
                } else {
                    $attributes[] = $flag;
                }
            }

            $folders[] = new self((string) $path, $isSent, $attributes);
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
