<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

/**
 * What a document attached to a booking is (§6.24).
 *
 * Two of them — the contract and the invoice — are **generated** from a
 * template and are versioned; the rest are uploaded by a manager. That
 * difference is the only one the code cares about, and `isGenerated()` is
 * where it lives rather than in a condition repeated at every call site.
 */
enum DocumentType: string
{
    case CONTRACT = 'contract';
    /**
     * The renter's signed copy of the contract, as it came back — a PDF, a
     * scan or a photo (#708, IT-16). Not yet the agreement: the unit has
     * still to countersign it.
     */
    case SIGNED_COPY = 'signed_copy';
    /** The contract signed by both parties: the agreement itself. */
    case SIGNED_CONTRACT = 'signed_contract';
    case INVOICE = 'invoice';
    case INVENTORY = 'inventory';
    case PHOTO = 'photo';
    case METER_READING = 'meter_reading';
    case CERTIFICATE = 'certificate';
    case EVIDENCE = 'evidence';
    case UNSORTED = 'unsorted';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CONTRACT => 'Contrat',
            self::SIGNED_COPY => 'Copie signée du locataire',
            self::SIGNED_CONTRACT => 'Contrat signé par les deux parties',
            self::INVOICE => 'Facture',
            self::INVENTORY => 'État des lieux',
            self::PHOTO => 'Photo',
            self::METER_READING => 'Relevé',
            self::CERTIFICATE => 'Attestation',
            self::EVIDENCE => 'Preuve',
            self::UNSORTED => 'Non classé',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Whether this type is produced by the module from a template, and
     * therefore versioned and never overwritten (§6.25).
     */
    public function isGenerated(): bool
    {
        return $this === self::CONTRACT || $this === self::INVOICE;
    }

    /**
     * The file-name stem a generated document uses:
     * `contrat-LOC-K7Q2M4-v1.pdf`.
     */
    public function fileStem(): string
    {
        return match ($this) {
            self::CONTRACT => 'contrat',
            self::INVOICE => 'facture',
            default => 'document',
        };
    }

    /**
     * The `editable_contents` key holding this asset's template.
     *
     * Per asset rather than per unit: a hall and a trailer are let on
     * genuinely different terms, and one shared template would be edited
     * into uselessness by the first manager who needed the other.
     */
    public function templateKey(int $assetId): string
    {
        return 'rental_asset_' . $assetId . '_' . $this->value . '_template';
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return self[] The ones a manager may upload by hand. */
    public static function uploadable(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $type) => !$type->isGenerated()));
    }
}
