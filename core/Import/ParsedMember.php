<?php

declare(strict_types=1);

namespace Core\Import;

class ParsedMember
{
    /**
     * @param ParsedAddress[] $addresses
     * @param ParsedFunction[] $functions
     */
    public function __construct(
        public readonly string $deskId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $gender,
        public readonly ?string $birthDate,
        public readonly ?string $phone,
        public readonly ?string $mobile,
        public readonly ?string $email,
        public readonly ?string $totem,
        public readonly ?string $quali,
        public readonly ?string $patrol,
        public readonly ?string $formationLevel,
        public readonly bool $federationMailConsent,
        public readonly bool $unitMailConsent,
        public readonly ?string $feeCode,
        public readonly ?string $unitCode,
        public readonly ?string $handicap,
        public readonly ?string $supplementaryInsurance,
        public readonly array $addresses,
        public readonly array $functions
    ) {
    }
}
