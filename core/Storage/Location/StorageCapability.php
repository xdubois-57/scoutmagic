<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * What a storage backend can do BEYOND the common floor every one of them
 * implements ({@see Backend\StorageBackendInterface}: put, get, size,
 * delete, list, testConnection).
 *
 * Two rules govern this enum, and both matter more than the cases:
 *
 * 1. **A capability is declared, never guessed.** Each backend answers
 *    {@see Backend\StorageBackendInterface::capabilities()} from a static
 *    list of its own, so a consumer can ask before it calls and a screen
 *    can compare types without holding credentials for any of them.
 * 2. **A capability is never shown to an administrator as it is written
 *    here.** Nobody picks a storage on « lecture par plage d'octets ».
 *    The Stockage screen shows CONSEQUENCES — photos, vidéos, sauvegardes,
 *    place restante — derived from these; the vocabulary below stays in
 *    the code.
 *
 * Asking for a capability a backend does not have is refused by
 * {@see StorageCapabilities::require()} with a French sentence, never by a
 * « call to undefined method » on a missing interface.
 */
enum StorageCapability: string
{
    /**
     * Reads an arbitrary byte range of an object. What a browser needs to
     * seek inside a video: without it a `Range:` request cannot be served,
     * and a player can start a film but never move in it.
     */
    case RangeRead = 'range_read';

    /**
     * Hands a visitor a URL the storage itself serves, so the bytes never
     * travel through PHP. Its absence is not a failure — a backend without
     * it serves through this application's own route, exactly as the local
     * disk does — but it is the difference between an album opened by
     * thirty parents costing the storage provider's bandwidth and costing
     * this site's.
     */
    case SignedUrl = 'signed_url';

    /**
     * Resumes an interrupted upload instead of restarting it. What makes a
     * multi-gigabyte archive survive a connection that drops halfway.
     */
    case ResumableUpload = 'resumable_upload';

    /**
     * Answers « how much room is left here » without walking the whole
     * content. S3 deliberately lacks it: a bucket's size is only knowable
     * by listing every object in it.
     */
    case Quota = 'quota';

    /**
     * Announces a checksum for a stored object that can be compared with
     * one computed while reading the source. Only worth declaring when the
     * announced value is directly comparable to an MD5 — see
     * {@see Backend\StorageBackendInterface::announcedChecksum()} for the
     * S3 multipart ETag trap.
     */
    case Checksum = 'checksum';

    /**
     * Copies an object to another key without the bytes passing through
     * this process. A 900 MB video changes prefix in one API call.
     */
    case ServerSideCopy = 'server_side_copy';

    /**
     * A French sentence naming what this capability lets a site do, for a
     * message that has to explain a refusal. Still not a label to put in a
     * comparison table — that one is built from consequences.
     */
    public function frenchDescription(): string
    {
        return match ($this) {
            self::RangeRead => 'lire un fichier par morceaux',
            self::SignedUrl => 'servir un fichier directement au visiteur',
            self::ResumableUpload => 'reprendre un envoi interrompu',
            self::Quota => 'indiquer la place restante',
            self::Checksum => 'annoncer une empreinte de fichier',
            self::ServerSideCopy => 'copier un fichier sans le faire transiter par le site',
        };
    }
}
