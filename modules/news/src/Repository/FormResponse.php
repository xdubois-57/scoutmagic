<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

final class FormResponse
{
    public function __construct(
        public readonly int $id,
        public readonly int $formId,
        public readonly ?int $userAccountId,
        public readonly ?int $memberYearId,
        public readonly string $contactEmail,
        public readonly ?string $structuredCommunication,
        public readonly ?int $receivableId,
        public readonly string $submittedAt,
        public readonly ?string $updatedAt
    ) {
    }
}
