<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

/**
 * One document attached to a booking (§6.24).
 *
 * `$isForRenter` is an **email flag, not an access right**: an external
 * renter downloads nothing from this site, so this says "attach it to an
 * email", never "let them fetch it".
 */
final class RentalDocument
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly int $fileId,
        public readonly DocumentType $type,
        public readonly int $version,
        public readonly bool $isForRenter,
        public readonly ?string $originalName,
        public readonly ?int $sizeBytes,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly ?int $createdByMemberId,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }

    /** `contrat-LOC-2027-0042-v2.pdf`. */
    public static function fileNameFor(DocumentType $type, string $reference, int $version): string
    {
        return $type->fileStem() . '-' . $reference . '-v' . $version . '.pdf';
    }

    public function label(): string
    {
        return $this->type->isGenerated()
            ? $this->type->label() . ' v' . $this->version
            : $this->type->label();
    }

    public function hasBeenSent(): bool
    {
        return $this->sentAt !== null;
    }
}
