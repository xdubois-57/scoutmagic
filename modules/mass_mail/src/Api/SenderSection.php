<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Api;

/**
 * Where an imported email hangs (decision D6): `$fromSenderAddress` is true
 * when the sender's address is the section's own, false when the email
 * fell back to the Staff d'U.
 */
final class SenderSection
{
    public function __construct(
        public readonly int $sectionId,
        public readonly bool $fromSenderAddress
    ) {
    }
}
