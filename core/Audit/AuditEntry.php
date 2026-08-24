<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

/**
 * One line of an entity's history, already decrypted (Repository is the
 * only layer that sees ciphertext) and ready to render.
 *
 * The three values are strings the RECORDING module already formatted for
 * a reader ("2 450 €", "Confirmé"): this component never formats,
 * interprets, parses or compares them. That is what lets one table serve
 * prices, dates, statuses and free text without knowing any of them.
 */
class AuditEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $fieldKey,
        public readonly ?string $fromValue,
        public readonly ?string $toValue,
        public readonly ?string $summary,
        public readonly AuditSource $source,
        public readonly ?string $sourceReference,
        public readonly ?int $actorUserAccountId,
        public readonly ?string $actorName,
        public readonly string $createdAt
    ) {
    }

    /**
     * No actor means nobody did this — the timeline renders it as an
     * automatic entry. A deleted account also lands here (the FK is ON
     * DELETE SET NULL), which is the honest reading: the site can no
     * longer say who it was.
     */
    public function isAutomatic(): bool
    {
        return $this->actorUserAccountId === null;
    }
}
