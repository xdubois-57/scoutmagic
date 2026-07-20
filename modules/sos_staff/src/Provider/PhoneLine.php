<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider;

/**
 * A phone line available on the provider account, for the configuration
 * page's line picker (module spec §1.2 étape 3) — avoids any manual
 * entry of the billing account / service name / number.
 */
final class PhoneLine
{
    public function __construct(
        public readonly string $billingAccount,
        public readonly string $serviceName,
        public readonly string $number
    ) {
    }
}
