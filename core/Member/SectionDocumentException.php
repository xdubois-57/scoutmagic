<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * User-facing (French) failure for the section documents feature — same
 * convention as Core\Member\MemberEmailException.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message is a
 * French sentence written for the visitor.
 */
class SectionDocumentException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
