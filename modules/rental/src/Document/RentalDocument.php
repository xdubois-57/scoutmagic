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
        public readonly string $source = self::SOURCE_MANUAL,
        /**
         * A renter's signed copy the unit refused (#708, IT-16): it stays
         * on file, and no longer counts as received.
         */
        public readonly ?\DateTimeImmutable $refusedAt = null,
        public readonly ?string $refusalReason = null,
        /**
         * When the booking changed under this contract — or under the copy
         * signed from it — and made it void (#708, IT-20). Kept on file,
         * never sent to the renter again, never downloadable.
         */
        public readonly ?\DateTimeImmutable $supersededAt = null,
        /** `Document\ContractFingerprint` of what a contract says, at generation. */
        public readonly ?string $fingerprint = null,
        /**
         * The contract a signed copy, or a contract signed by both
         * parties, answers (#927, chantier §2.3).
         */
        public readonly ?int $contractDocumentId = null,
        /**
         * The modification project this document belongs to — its
         * replacement contract and what was signed from it.
         */
        public readonly ?int $modificationId = null,
        /**
         * Whether that project is still in progress: then the document is
         * the project's, and the booking's contract in force is still the
         * one before (chantier §2.3).
         */
        public readonly bool $inActiveModification = false,
        /**
         * An invoice or a supplementary invoice (#980, chantier decision 2.8): the
         * validated settlement it bills — null for an invoice generated
         * before any — and the amount it brings the billing to, that
         * settlement's kept amount or else the agreed total.
         */
        public readonly ?int $settlementId = null,
        public readonly ?int $billedKeptCents = null,
        /** The invoice a supplementary invoice completes. */
        public readonly ?int $supplementsDocumentId = null
    ) {
    }

    /**
     * Whether this document says anything about the booking as it stands:
     * not outgrown (`isSuperseded()`), and not the paperwork of a
     * modification still waiting for its countersignature. What the
     * journey, the reminders and the renter's page read.
     */
    public function countsForTheBooking(): bool
    {
        return !$this->isSuperseded() && !$this->inActiveModification;
    }

    public function isSuperseded(): bool
    {
        return $this->supersededAt !== null;
    }

    public function isRefused(): bool
    {
        return $this->refusedAt !== null;
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

    /** `contrat-LOC-K7Q2M4-v2.pdf`. */
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
