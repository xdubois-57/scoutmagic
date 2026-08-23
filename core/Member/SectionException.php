<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * A refused section edit, stated in French for the chef who attempted it
 * (« Couleur invalide — format hexadécimal attendu (ex : #378ADD). »).
 *
 * Marked {@see \Core\Exception\UserFacingException}. It replaces a plain
 * \InvalidArgumentException at the one place this was thrown: that class
 * is also what PHP itself and every library throws, so a display site
 * catching it could not tell "the colour you typed is not hex" from
 * whatever an unrelated dependency happened to say. Same "typed domain
 * exception, caught by the controller, message shown as-is" convention as
 * Core\Member\MemberEmailException.
 */
class SectionException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
