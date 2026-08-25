<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Campaign
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * @param string[] $mergeColumns headers of the spreadsheet's other columns
     */
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly int $scoutYearId,
        public readonly int $accountId,
        public readonly string $status,
        public readonly ?int $sourceFileId,
        public readonly string $sourceFilename,
        public readonly array $mergeColumns,
        public readonly ?string $notifiedAt,
        public readonly ?int $notifiedBy,
        public readonly ?string $closedAt,
        public readonly ?int $createdBy,
        public readonly string $createdAt
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Whether the treasurer has pressed "Notifier les familles" yet. The
     * screen says so until they have, because nothing else can: the
     * reminder leaves by hand from the mass-mail draft, so the site never
     * learns when the request actually went out.
     */
    public function isNotified(): bool
    {
        return $this->notifiedAt !== null;
    }
}
