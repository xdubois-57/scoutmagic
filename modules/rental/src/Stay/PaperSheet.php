<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * An inventory « Signé sur papier » (#989, lot F, decision Q3): the photo
 * of the printed checklist both parties signed by hand, and the account
 * of the session that validated it.
 *
 * `$fileId` is the photo as the controller stored it — normalised by
 * `Core\Image\PhotoNormalizer`, owned by the booking — and not yet a
 * document: the validation files it as one (`DocumentType::PHOTO`, not
 * for the renter, since it is printed in the PDF they are sent) inside
 * its own transaction.
 *
 * `$accountId` is the session's, read from `AuthSession`, never from the
 * form. No electronic signature names it here, so it is what the
 * validation row, the booking's history and the journal record as the
 * account that validated.
 */
final readonly class PaperSheet
{
    public function __construct(
        public int $fileId,
        public int $accountId
    ) {
    }
}
