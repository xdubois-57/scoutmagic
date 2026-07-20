<?php

declare(strict_types=1);

namespace Modules\SosStaff\Provider;

/**
 * The live state of unconditional call forwarding on the configured line,
 * as read directly from the provider (module spec §2.2) — never derived
 * from what the site thinks is configured.
 */
final class ForwardingState
{
    public function __construct(
        public readonly bool $active,
        public readonly ?string $number
    ) {
    }
}
