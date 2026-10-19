<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

use Modules\Rental\Document\RentalDocument;

/**
 * A booking's modification in progress, with its paperwork (#927, chantier
 * §2.4): the project, the stage it is at, its replacement contract, and the
 * copy waiting for an answer or the last one refused — what the dashboard,
 * the Modifications page, Documents and the renter's page each read, from
 * one derivation.
 */
final class ModificationProgress
{
    public function __construct(
        public readonly BookingModification $modification,
        public readonly ModificationStage $stage,
        public readonly ?RentalDocument $contract = null,
        public readonly ?RentalDocument $pendingCopy = null,
        public readonly ?RentalDocument $refusedCopy = null
    ) {
    }

    /**
     * @param RentalDocument[] $documents the project's own documents
     *   (`RentalDocumentRepository::findForModification()`)
     */
    public static function of(BookingModification $modification, array $documents): ?self
    {
        $contract = null;
        $pending = null;
        $refused = null;
        foreach ($documents as $document) {
            if ($document->isSuperseded()) {
                continue;
            }
            if ($document->type === \Modules\Rental\Document\DocumentType::CONTRACT
                && ($contract === null || $document->version > $contract->version)
            ) {
                $contract = $document;
            }
            if ($document->type === \Modules\Rental\Document\DocumentType::SIGNED_COPY) {
                if (!$document->isRefused() && ($pending === null || $document->id > $pending->id)) {
                    $pending = $document;
                }
                if ($document->isRefused() && ($refused === null || $document->id > $refused->id)) {
                    $refused = $document;
                }
            }
        }

        // A refusal older than the copy now waiting says nothing any more.
        if ($pending !== null && $refused !== null && $refused->id < $pending->id) {
            $refused = null;
        }

        $stage = ModificationStage::of($modification, $pending !== null, $refused !== null);

        return $stage === null ? null : new self($modification, $stage, $contract, $pending, $refused);
    }

    /** Whether the renter is expected to send a copy of its contract now. */
    public function awaitsACopy(): bool
    {
        return $this->stage === ModificationStage::AWAITING_COPY || $this->stage === ModificationStage::COPY_REFUSED;
    }
}
