<?php

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
