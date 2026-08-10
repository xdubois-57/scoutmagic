<?php

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * A decrypted registration request — assembled only inside
 * RegistrationRequestRepository (SECURITY.md §5: encryption confined to
 * the Repository layer), never touched again by a Service/Controller for
 * its own decryption.
 */
final class RegistrationRequest
{
    public const STATUS_PENDING = 'pending';

    public function __construct(
        public readonly int $id,
        public readonly int $scoutYearId,
        public readonly string $parentName,
        public readonly string $childLastName,
        public readonly string $childFirstName,
        public readonly string $gender,
        public readonly string $birthDate,
        public readonly string $street,
        public readonly string $number,
        public readonly string $postalCode,
        public readonly string $city,
        public readonly string $email,
        public readonly string $phone1,
        public readonly ?string $phone2,
        public readonly ?string $remarks,
        public readonly ?int $desiredSectionId,
        public readonly string $status,
        public readonly \DateTimeImmutable $receivedAt
    ) {
    }

    public function birthYear(): ?int
    {
        if (preg_match('/(\d{4})-\d{2}-\d{2}/', $this->birthDate, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
