<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

/**
 * A merge of two member records that was refused before anything moved —
 * each message French, a whole sentence, naming nothing internal.
 *
 * Marked {@see \Core\Exception\UserFacingException}: the reasons a merge
 * is refused are all things the person clicking needs to read, not
 * technical detail to hide behind a fallback.
 */
class MergeException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
