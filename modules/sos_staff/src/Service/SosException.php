<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

/**
 * Any validation/business-rule failure raised by the sos_staff module's
 * services — message is always human-readable (French) and safe to
 * display directly to an admin.
 */
class SosException extends \RuntimeException
{
}
