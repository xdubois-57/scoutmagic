<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * Writes an email sent from a former site into this module's history,
 * WITHOUT any side effect — the import interface of the chantier
 * « Migration de données » (docs/chantiers/CHANTIER-migration-donnees.md,
 * lot C), consumed by the data_migration module and by nothing else.
 *
 * What it does not do is the point of it, because the ordinary path does
 * all of it: no e-mail, no message 'pending' for Task\SendBatchHandler to
 * send, no batch task scheduled, no « Nouvel email » notification, no
 * recipient recomputed from today's lists. What it does do is what any
 * email of this module gets: the body through Core\Security\HtmlSanitizer,
 * the addresses and the original sender encrypted at rest, the attachments
 * through Core\File\UploadHandler (real type, size, disk budget), and a
 * journal line without a subject or an address.
 *
 * An email written here is imported for ever (decision D11): the module's
 * own `imported_at` column makes it read-only — no edit, no deletion, no
 * « Renvoyer », no attachment added or removed — whether or not the
 * data_migration module is still enabled.
 */
interface EmailHistoryImportInterface
{
    /**
     * The section an email from this address hangs on (decision D6): the
     * section whose own address it is, or else the Staff d'U. Null when the
     * site has neither — no Staff d'U section yet — and the email cannot be
     * placed anywhere.
     */
    public function senderSection(string $senderAddress): ?SenderSection;

    /**
     * The email already imported under this provenance, or null — the
     * module's own check against a duplicate, independent of the
     * data_migration correspondence table.
     */
    public function findImported(string $importSource): ?int;

    /**
     * The MIME types an attachment may have, by its real content — the
     * same as for an attachment a chief adds by hand.
     *
     * @return list<string>
     */
    public function acceptedAttachmentTypes(): array;

    /** The largest attachment accepted, in bytes — the same as by hand. */
    public function maxAttachmentBytes(): int;

    /**
     * Writes the email, its known messages, the members it concerned and
     * its valid attachments, on the caller's connection and inside the
     * caller's transaction if one is open. An attachment the file storage
     * refuses (type, size, disk space) is left out and counted; the email
     * is written all the same.
     *
     * @throws MassMailException when the email cannot be placed in a
     *         section, or its content is empty once sanitized
     */
    public function import(ImportedEmail $email): ImportedEmailResult;
}
