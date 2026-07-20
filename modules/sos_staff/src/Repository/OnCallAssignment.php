<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

/**
 * One sos_oncall_assignments row: a member's state for a single day.
 * Sparse storage — a day with no row is "available" (module spec §2.6),
 * so this class only ever represents an explicitly marked day.
 */
final class OnCallAssignment
{
    public const STATE_ONCALL = 'oncall';
    public const STATE_UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly int $memberId,
        public readonly string $date,
        public readonly string $state
    ) {
    }
}
