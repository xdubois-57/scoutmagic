<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Exception\UserFacingException;

/**
 * Any validation/business-rule failure raised by the sos_staff module's
 * services — message is always human-readable (French) and safe to
 * display directly to an admin — which is exactly the claim
 * {@see UserFacingException} makes, now stated in the type system. The one
 * site that used to append a provider's own words
 * (Service\RedirectService::apply()) writes its own sentence and keeps the
 * detail for the journal and the alert mail.
 */
class SosException extends \RuntimeException implements UserFacingException
{
}
