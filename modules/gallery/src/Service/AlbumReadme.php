<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Storage\Location\Backend\StorageBackendInterface;
use Core\View\DateFilterExtension;
use Modules\Gallery\Repository\Album;

/**
 * The `LISEZMOI.txt` in each album's folder (#474).
 *
 * **Why an album folder needs one.** Albums are stored under their NUMBER
 * — `5/`, `12/` — because a number never changes: renaming an album moves
 * nothing. That is right for the site and opaque for a person browsing the
 * storage: a Google Drive or a Nextcloud full of numbered folders says
 * nothing about which one is the camp. This file says it — the album's
 * name, its activity date, the address of the album on the site — and
 * asks, in one sentence, that the folder be neither renamed nor used as a
 * drop box, since the site reads it back by number and ignores whatever
 * it did not put there.
 *
 * **On every kind of storage, not only on Drive.** The problem is the same
 * on a WebDAV share someone opens in a file manager, and on a server disk
 * someone reaches over FTP; a Drive-only branch would be more code to say
 * less. On an S3 location with a public address the file is readable by
 * whoever guesses its key — but so is every photograph beside it, under
 * the same guessable `{albumId}/` prefix, so it publishes nothing that
 * location did not already publish.
 *
 * **Never taken for a medium**, and not by accident: nothing in the
 * gallery lists a location to find its media. Listing, zip download,
 * thumbnails, the migration and the deletion all work from the
 * `gallery_media` rows, and a key with no row is invisible to them. What
 * does list a location — the safety copy, the remote retention — treats
 * keys as opaque files, and a safety copy that carries the readme along is
 * exactly right.
 *
 * **Best effort.** An album whose readme could not be written is an album
 * that works; failing its creation, its renaming or its migration over a
 * courtesy file would be the wrong way round. The failure is journaled.
 */
final class AlbumReadme
{
    public const FILE_NAME = 'LISEZMOI.txt';

    /** The sentence the ticket asked for, word for word. */
    public const DO_NOT_TOUCH = 'Ce dossier est tenu à jour par ScoutMagic. Ne le renommez pas et n\'y déposez rien.';

    public function __construct(
        private readonly SettingService $settings,
        private readonly ?JournalService $journal = null
    ) {
    }

    public static function keyFor(int $albumId): string
    {
        return $albumId . '/' . self::FILE_NAME;
    }

    /**
     * The text of the file. Windows line endings, because the person most
     * likely to open it double-clicks it in a Windows Notepad old enough
     * to show a bare `\n` as nothing at all.
     */
    public function contentFor(Album $album): string
    {
        $lines = [
            'Album : ' . $album->title,
            'Date de l\'activité : ' . DateFilterExtension::frenchDate($album->albumDate),
        ];

        $baseUrl = rtrim((string) ($this->settings->get('base_url') ?: ''), '/');
        $lines[] = $baseUrl !== ''
            ? 'Sur le site : ' . $baseUrl . '/gallery/' . $album->id
            // Said rather than left out: a line that is simply missing
            // reads as a file somebody truncated.
            : 'Sur le site : l\'adresse du site n\'est pas encore renseignée dans ses réglages.';

        $lines[] = '';
        $lines[] = self::DO_NOT_TOUCH;

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Writes (or rewrites) the file in the album's folder on $backend.
     *
     * @return bool whether it was written — for a caller that wants to
     *              know, never a reason to stop
     */
    public function write(Album $album, StorageBackendInterface $backend): bool
    {
        try {
            $backend->put(self::keyFor($album->id), $this->contentFor($album), 'text/plain');

            return true;
        } catch (\Throwable $e) {
            $this->journal?->log(
                'gallery',
                'album_readme_failed',
                'warning',
                "Le fichier LISEZMOI de l'album #{$album->id} n'a pas pu être écrit",
                ['album_id' => $album->id, 'error' => $e->getMessage()]
            );

            return false;
        }
    }
}
