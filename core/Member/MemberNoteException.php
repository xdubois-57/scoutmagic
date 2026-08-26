<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * A member note refused: empty, too long, or an id that does not name a
 * note of the member being looked at.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message this
 * class is ever constructed with is a full French sentence naming
 * nothing internal — no path, no SQL, no class name — and, in particular,
 * never the note's own text, which is the most sensitive free text on
 * the site (see MemberNoteService's docblock). Read every `throw new` of
 * it before adding a message, and never construct it from another
 * exception's `getMessage()`.
 */
class MemberNoteException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
