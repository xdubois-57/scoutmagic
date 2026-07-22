<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

final class EmailAttachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $emailId,
        public readonly int $fileId
    ) {
    }
}
