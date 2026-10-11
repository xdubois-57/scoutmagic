<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

/**
 * A rename refused because Google Drive could not rename the location's
 * folder (#832): the one refusal the journal records as such.
 *
 * Its own class rather than a `$previous` to look for — other refusals
 * carry a cause too (a consumer that could not be asked, for one), and
 * none of them is a Drive folder that would not follow.
 */
final class StorageLocationRenameRefusedException extends StorageLocationException
{
}
