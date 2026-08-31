<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * Why a message no longer offers an attachment it carried.
 *
 * **The message is stored either way**, and so is a row for the attachment
 * — without one, a screen shows fewer attachments than the sender sent and
 * nobody can tell whether ScoutMagic dropped one or the sender never
 * attached it. With one, the screen can say the honest thing: the message
 * arrived, this file was not kept, and it is still sitting in the original
 * mailbox where anybody who needs it can fetch it.
 */
enum AttachmentOmission: string
{
    /** Over `AttachmentPolicy::maxSizeBytes()`. */
    case TOO_LARGE = 'too_large';

    /** Not on the allowlist — an archive, an executable, an unknown type. */
    case MIME_REJECTED = 'mime_rejected';

    /**
     * The module's disk quota was already reached. The message is kept
     * whole; only the bytes are refused (D5).
     */
    case QUOTA_EXCEEDED = 'quota_exceeded';

    /** The write itself failed — a full disk, a permission, a bad temp file. */
    case STORAGE_ERROR = 'storage_error';

    /**
     * The odd one out, and deliberately here rather than in a list of its
     * own: the file **was** kept, and then a consumer re-classified it into
     * a document of its own — a signed contract filed under a booking, a
     * permit filed under a stay. It stops being the message's file at that
     * moment, so the retention purge cannot take it away with the message
     * ninety days later.
     *
     * It shares this enum because the screen asks one question — why does
     * this message no longer offer this file? — and a second list would
     * only mean a second place to forget.
     */
    case RECLASSIFIED = 'reclassified';

    /**
     * What a reader is told, in French, and why. Never a code, and never a
     * blank: a file missing without explanation reads as a bug.
     */
    public function label(): string
    {
        return match ($this) {
            self::TOO_LARGE => 'Fichier trop volumineux',
            self::MIME_REJECTED => 'Type de fichier non accepté',
            self::QUOTA_EXCEEDED => 'Espace de stockage atteint',
            self::STORAGE_ERROR => 'Échec de l\'enregistrement',
            self::RECLASSIFIED => 'Fichier classé dans un dossier',
        };
    }

    /**
     * The sentence shown next to the attachment's name. It ends the same
     * way every time on purpose — the reassurance is the point, and it is
     * true: this module never writes to the remote mailbox, so the file is
     * always still there.
     */
    public function explanation(): string
    {
        if ($this === self::RECLASSIFIED) {
            // Nothing was lost here, so the reassuring sentence below would
            // be a lie in the other direction: it would send somebody back
            // to the mailbox for a file ScoutMagic still holds.
            return $this->label()
                . ". Il a été rangé dans le dossier auquel il se rapporte et n'est plus "
                . 'rattaché au message.';
        }

        return $this->label()
            . ". Le message est bien arrivé ; le fichier n'a pas été conservé "
            . 'et reste disponible dans la boîte d\'origine.';
    }
}
