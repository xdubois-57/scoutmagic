<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class ParsedAddress
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $street,
        public readonly ?string $number,
        public readonly ?string $box,
        public readonly ?string $complement,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $country
    ) {
    }
}
