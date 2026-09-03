<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * One object a consumer offers as a target for « Rattacher à… »
 * (`ReferenceDirectory::searchReferences()`).
 *
 * A label a person recognises — « Ferme du Bois-Joli — 12–22 juillet
 * 2027 », « LOC-2027-0042 — Pierre Lambert » — and, optionally, one line
 * of detail that tells two similar labels apart: a status, a place, a
 * renter. Never anything the screen has to interpret.
 */
final class ReferenceSuggestion
{
    public function __construct(
        public readonly string $businessReference,
        public readonly string $label,
        public readonly ?string $detail = null
    ) {
    }

    /**
     * @return array{reference: string, label: string, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->businessReference,
            'label' => $this->label,
            'detail' => $this->detail,
        ];
    }
}
