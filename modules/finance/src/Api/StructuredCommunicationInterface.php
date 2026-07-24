<?php

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
