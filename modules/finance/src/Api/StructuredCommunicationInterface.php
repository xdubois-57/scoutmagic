<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5).
 * Generates Belgian structured communications for payment reconciliation.
 */
interface StructuredCommunicationInterface
{
    /**
     * Generates a unique, valid Belgian structured communication in the
     * "+++NNN/NNNN/NNNNN+++" format (mod-97 check digits).
     */
    public function generate(): string;
}
