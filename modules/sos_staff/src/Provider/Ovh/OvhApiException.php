<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider\Ovh;

/**
 * Low-level OVH REST API failure (network error or an HTTP 4xx/5xx from
 * OVH) — caught and re-thrown as Provider\ProviderException by
 * OvhTelephonyProvider, which is the only class other module code should
 * depend on.
 */
class OvhApiException extends \RuntimeException
{
}
