<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider;

/**
 * Any failure talking to a telephony provider — message is always
 * human-readable (French) and safe to display directly to an admin.
 */
class ProviderException extends \RuntimeException
{
}
