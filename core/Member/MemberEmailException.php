<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * A user-facing (French) error from Core\Member\MemberEmailService — same
 * "typed domain exception, caught by the controller, message shown as-is"
 * convention as Modules\MassMail\Service\MassMailException.
 *
 * Marked {@see \Core\Exception\UserFacingException}: every message is a
 * French sentence written for the visitor.
 */
class MemberEmailException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
