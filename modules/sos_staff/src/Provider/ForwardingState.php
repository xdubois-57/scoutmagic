<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
