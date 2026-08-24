<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/** A reading that contradicts a stay's stored value (schema.sql: camp_field_proposals). */
class FieldProposal
{
    public function __construct(
        public readonly int $id,
        public readonly int $campId,
        public readonly string $fieldKey,
        public readonly ?string $currentValue,
        public readonly string $proposedValue,
        public readonly string $proposedMachineValue,
        public readonly ?string $sourceReference,
        public readonly string $createdAt
    ) {
    }
}
