<?php

declare(strict_types=1);

namespace Core\Member;

class MemberAddress
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

    /**
     * Format as a single-line string.
     */
    public function format(): string
    {
        $parts = array_filter([
            trim(implode(' ', array_filter([$this->street, $this->number, $this->box]))),
            $this->complement,
            trim(implode(' ', array_filter([$this->postalCode, $this->city]))),
            $this->country
        ]);
        return implode(', ', $parts);
    }
}
