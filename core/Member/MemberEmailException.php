<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * A user-facing (French) error from Core\Member\MemberEmailService — same
 * "typed domain exception, caught by the controller, message shown as-is"
 * convention as Modules\MassMail\Service\MassMailException.
 */
class MemberEmailException extends \RuntimeException
{
}
