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
    /** This module put the bytes there: an upload, or a generated PDF. */
    public const SOURCE_MANUAL = 'manual';
    /** An inbound message's own attachment, shared by `files` id (§8.59). */
    public const SOURCE_EMAIL = 'email';

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
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $source = self::SOURCE_MANUAL
    ) {
    }

    /**
     * Whether deleting this document may also delete its file.
     *
     * An email's attachment belongs to the message it arrived in, which
     * still owns and still serves it from the very same `files` row
     * (§8.59) — removing the bytes here would blank the message too.
     */
    public function ownsItsFile(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
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
