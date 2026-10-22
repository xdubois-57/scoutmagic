<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Pdf\LinkedText;
use Core\Security\HtmlSanitizer;
use Core\Service\DateInput;
use Core\View\EditableContentService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\ConditionsVersion;
use Modules\Rental\Document\DocumentKeywords;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\Landlord;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Document\StandardTemplates;
use Modules\Rental\Document\SupplementOffer;
use Modules\Rental\Document\WithdrawalScale;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Stay\Settlement;
use Modules\Rental\Stay\SettlementLine;
use Modules\Rental\Support;

/**
 * Contracts and invoices (§6.25, §6.27), and every other document attached
 * to a booking (§6.24).
 *
 * **Three levels, each frozen the moment the next is born.** The asset's
 * template lives in `editable_contents`; the booking takes its own copy at
 * the first generation; the PDF is a rendering of that copy at one instant.
 * Editing the asset's template afterwards touches no existing booking —
 * which is the entire reason the middle level exists. A template reworded
 * in March must not silently change what a renter agreed to in February.
 *
 * **A generated document is never overwritten.** Regenerating produces v2
 * beside v1, because v1 may already have been sent, printed and signed, and
 * a signature refers to a text rather than to a file name.
 *
 * **Substitution happens after sanitizing, and every value is escaped**
 * (`Document\DocumentKeywords`). dompdf renders HTML: a renter's name or
 * organisation containing markup would otherwise be interpreted. The values
 * come from a form an anonymous visitor filled in, so they are the least
 * trustworthy thing in the document.
 *
 * **Nothing here is downloadable by a renter — but one document.** The
 * `is_for_renter` flag means "attach it to an email"; an external renter has
 * no account and the tracking token is not a file credential (§6.24,
 * §6.26). The one exception is the contract signed by both parties (#708,
 * IT-16), served by `RentalSignedContractService` to that booking's own
 * tracking page and to nothing else.
 *
 * Files go through `Core\File\FileRepository` and are served only through
 * `FileAccessGuard`/`file_url()`, never from under `public/`.
 */
class RentalDocumentService
{
    /** Where a rental document lands under `storage/`. */
    public const STORAGE_SUBDIRECTORY = 'rental/documents';

    /**
     * The floor for the file row itself. Never `public`: a rental document
     * is internal by construction, and the ownership checker narrows it
     * further to the asset's own managers.
     */
    public const FILE_ROLE_MIN = 'identified';

    /** The owner_type this module registers with FileAccessGuard. */
    public const OWNER_TYPE = 'rental_document';

    public function __construct(
        private RentalDocumentRepository $documentRepository,
        private RentalBookingRepository $bookingRepository,
        private BookingAudit $bookingAudit,
        private EditableContentService $editableContentService,
        private FileRepository $fileRepository,
        private AttachedFileRemover $fileRemover,
        private DocumentPdfService $pdfService,
        private HtmlSanitizer $sanitizer,
        private SettingService $settingService,
        private JournalService $journal,
        private string $storagePath,
        /**
         * The archive of the conditions (issue #494), so a contract names
         * the version the renter accepted with the request (#708, IT-16).
         * Nullable where nothing is generated; without it the contract
         * says « — » rather than point at a text nobody accepted.
         */
        private ?RentalConditionsService $conditions = null,
        /**
         * Where the renter pays (issue #977): the account and its holder a
         * contract states, read from Finance through the payment service.
         * Nullable like Finance itself; without it the contract says the
         * landlord communicates the account in writing.
         */
        private ?RentalPaymentService $payments = null
    ) {
    }

    /**
     * The archived conditions the renter accepted with the request — the
     * very text, checked against the fingerprint stored with the booking.
     * Null when none was accepted, or when that version is not in the
     * archive: pointing at some other text would be worse than nothing.
     */
    public function acceptedConditions(RentalBooking $booking): ?ConditionsVersion
    {
        if ($this->conditions === null
            || $booking->conditionsVersion === null
            || $booking->conditionsHash === null
        ) {
            return null;
        }

        $version = $this->conditions->find($booking->assetId, $booking->conditionsVersion);

        return $version !== null && hash_equals($version->hash, $booking->conditionsHash) ? $version : null;
    }

    // ── Level 1: the asset's template (§6.25) ───────────────────────────

    public function template(RentalAsset $asset, DocumentType $type): string
    {
        return (string) ($this->editableContentService->get($type->templateKey($asset->id), '') ?? '');
    }

    /**
     * The template that will actually be used: the asset's own wording, or —
     * while nobody has written any — the standard Belgian body ScoutMagic
     * ships (`Document\StandardTemplates`).
     *
     * This is what makes the shipped bodies a real DEFAULT rather than a
     * button: a unit that never opens the template editor still generates a
     * complete contract, and the editor opens pre-filled with the text that
     * generation would use, never an empty surface asking a volunteer to
     * write a rental contract from nothing. Saving any edit takes over from
     * the standard; the template page offers a "réinitialiser" action to
     * come back to it.
     */
    public function templateOrStandard(RentalAsset $asset, DocumentType $type): string
    {
        $stored = $this->template($asset, $type);
        if (trim($stored) !== '') {
            return $stored;
        }

        return (string) (StandardTemplates::forType($type) ?? '');
    }

    /**
     * Saves the asset's template.
     *
     * The rich text is sanitized on the way in by `EditableContentService`
     * itself (SECURITY.md §7), so what is stored is already safe and
     * substitution later only has to worry about the values.
     *
     * **Unknown keywords are reported here** rather than being allowed to
     * survive into a signed contract as literal braces: this is the last
     * moment anybody can still fix them (§6.25).
     *
     * @return string[] Unknown keywords, for the editor to show. Empty means clean.
     */
    public function saveTemplate(
        RentalAsset $asset,
        DocumentType $type,
        string $bodyHtml,
        ?int $actorAccountId,
        ?int $actorMemberId = null
    ): array {
        $this->editableContentService->set(
            $type->templateKey($asset->id),
            $bodyHtml,
            'rich_text',
            $actorAccountId ?? 0
        );

        // Who, which asset, when — never the content. A contract template
        // is not personal data, but copying a whole document into the audit
        // journal on every keystroke-sized edit is not what a journal is
        // for (SECURITY.md §5).
        $this->journal->log(
            'rental',
            'rental_document_template_saved',
            'info',
            'Gabarit « ' . $type->label() . ' » modifié',
            ['asset_id' => $asset->id, 'document_type' => $type->value, 'member_id' => $actorMemberId]
        );

        return DocumentKeywords::unknownIn($this->template($asset, $type));
    }

    // ── Level 2: the booking's own copy (§6.25) ─────────────────────────

    /**
     * The booking's own copy of a template, taking it from the asset the
     * first time it is asked for.
     *
     * Copy-on-first-read rather than copy-at-confirmation: a manager who
     * opens the contract editor before generating anything should see the
     * asset's wording, and from that moment the booking owns it.
     */
    public function bookingText(RentalBooking $booking, RentalAsset $asset, DocumentType $type): string
    {
        $existing = $this->documentRepository->findText($booking->id, $type);
        if ($existing !== null) {
            return $existing;
        }

        // Through the standard fallback: an asset whose managers never
        // touched the template editor still hands each booking a complete
        // contract to start from, rather than an empty page and a
        // generation error.
        return $this->templateOrStandard($asset, $type);
    }

    /**
     * @return string[] Unknown keywords, for the editor to show.
     * @throws RentalException
     */
    public function saveBookingText(
        RentalBooking $booking,
        DocumentType $type,
        string $bodyHtml,
        ?int $actorMemberId = null
    ): array {
        if (!$type->hasEditableText()) {
            throw new RentalException("Ce type de document ne se rédige pas : il s'envoie.");
        }

        if ($this->textIsLocked($booking, $type)) {
            throw new RentalException(self::lockedRefusal($type));
        }

        // Sanitized here rather than relying on the caller, because this
        // path does not go through EditableContentService and would
        // otherwise be the one place raw HTML reached a PDF.
        //
        // Then repaired, in that order and no other (§22.6): the sanitizer
        // decides which markup exists at all, and the repair pass removes
        // what a rich-text surface left INSIDE a keyword —
        // `{{ pri<b>x</b>_total }}` is still readable to a human and no
        // longer a keyword to anything that substitutes.
        $clean = DocumentKeywords::repairSplitKeywords($this->sanitizer->sanitize($bodyHtml));

        // The check above is for the reader; THIS is the lock. Between
        // `textIsLocked()` and here, a second manager pressing « Envoyer »
        // can write `sent_at`, and both gestures then succeed — the tenant
        // holding a PDF whose source has since moved on, with nothing on
        // screen saying so (#405). `saveText()` carries the condition in
        // its own UPDATE, so the write either happens before the send or
        // does not happen at all. It says which.
        if (!$this->documentRepository->saveText($booking->id, $type, $clean)) {
            throw new RentalException(self::lockedRefusal($type));
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $type->label(),
            'Texte du document modifié',
            $actorMemberId
        );

        return DocumentKeywords::unknownIn($clean);
    }

    /**
     * Whether this booking's text for $type may still be edited (§22.6).
     *
     * **Sending is the action that locks.** Until a document of this type
     * has gone out, its text is a draft and a manager may rework it freely;
     * once the renter holds a copy, silently changing what that copy was
     * made from is exactly the confusion versioning exists to prevent. The
     * confirmation on « Envoyer » says so before it happens, because a
     * button that quietly makes a page read-only is a button nobody expects.
     *
     * Regenerating still never overwrites: v2 appears beside v1. What the
     * lock stops is the SOURCE moving under a version that has already been
     * read and possibly signed.
     */
    public function textIsLocked(RentalBooking $booking, DocumentType $type): bool
    {
        return $type->isGenerated()
            && $this->documentRepository->hasSentDocumentOfType($booking->id, $type);
    }

    /**
     * The one sentence a locked text is refused with, wherever the refusal
     * happens — written once because the editor shows it and the save
     * refuses with it, and two wordings for one rule read as two rules.
     */
    public static function lockedRefusal(DocumentType $type): string
    {
        $opening = 'Ce document a été envoyé au locataire : son texte n\'est plus modifiable. ';

        // Written out per type rather than assembled around
        // `$type->label()`, because French will not be assembled: « Facture »
        // is feminine, so the generic sentence produced « Le facture qu'il a
        // reçu doit rester celui qu'il a reçu » — on the editor's warning
        // banner and in the flash that refuses the save, both of which an
        // invoice reaches exactly as a contract does.
        return $opening . match ($type) {
            DocumentType::INVOICE => 'La facture qu\'il a reçue doit rester celle qu\'il a reçue.',
            DocumentType::CONTRACT => 'Le contrat qu\'il a reçu doit rester celui qu\'il a reçu.',
            DocumentType::SUPPLEMENTARY_INVOICE
                => 'La facture complémentaire qu\'il a reçue doit rester celle qu\'il a reçue.',
            // Unreachable while `textIsLocked()` guards on `isGenerated()`,
            // and a neutral sentence rather than a throw: a document type
            // added to that list must not be able to blank this page.
            default => 'Ce qui est parti doit rester ce qui est parti.',
        };
    }

    // ── Level 3: the PDF (§6.25) ────────────────────────────────────────

    /**
     * Renders a new version of a generated document.
     *
     * **Never overwrites.** The version comes from `MAX(version) + 1`, so a
     * deleted v2 leaves v3 as the next number rather than handing out "2"
     * twice — two different PDFs called v2 is exactly the confusion
     * versioning exists to prevent.
     *
     * The values used are frozen alongside the file, so "why does v1 say
     * 467,50 € when the booking says 400,00 €?" still has an answer six
     * months later.
     *
     * An invoice also records what it bills (#980, chantier decision 2.8): the
     * booking's latest validated settlement, `$validatedSettlement`, and its
     * kept amount — or, before any, the agreed total it prints. A
     * supplementary invoice later bills the difference from that figure.
     * The invoice itself prints what it always printed.
     *
     * @throws RentalException
     */
    public function generate(
        RentalBooking $booking,
        RentalAsset $asset,
        DocumentType $type,
        PaymentSettings $paymentSettings,
        ?string $communication = null,
        ?int $actorMemberId = null,
        ?Settlement $validatedSettlement = null
    ): RentalDocument {
        if (!$type->isGenerated()) {
            throw new RentalException("Ce type de document ne se génère pas.");
        }
        // Worked out from two settlements, never from a template alone:
        // its own path checks there is a difference to bill.
        if ($type === DocumentType::SUPPLEMENTARY_INVOICE) {
            throw new RentalException(
                'Une facture complémentaire se génère depuis la page « Facture », '
                    . "une fois un décompte validé après l'envoi de la facture."
            );
        }
        if ($validatedSettlement !== null
            && (!$validatedSettlement->isValidated || $validatedSettlement->bookingId !== $booking->id)
        ) {
            throw new RentalException("Ce décompte n'est pas un décompte validé de cette réservation.");
        }

        if ($type === DocumentType::CONTRACT) {
            $this->assertContractCanBeGenerated($booking, $asset);
        }

        $body = trim($this->bookingText($booking, $asset, $type));
        if ($body === '') {
            throw new RentalException(
                'Le gabarit « ' . $type->label() . ' » de ce bien est vide : rédigez-le avant de générer.'
            );
        }

        $values = $this->valuesFor($booking, $asset, $paymentSettings, $communication);

        // Sanitize, repair, substitute — in that order and no other
        // (§6.25, §22.6). Sanitizing last would run the sanitizer over a
        // document that already carries renter-supplied values; repairing
        // after substitution would have no keyword left to rescue.
        //
        // Repaired here as well as at save time, because a text stored
        // before the repair pass existed is still out there.
        $conditionsUrl = $this->acceptedConditionsUrl($booking, $asset);
        $rendered = DocumentKeywords::substitute(
            DocumentKeywords::repairSplitKeywords($this->sanitizer->sanitize($body)),
            $values,
            // A real link, not an address printed as text (issue #977).
            $conditionsUrl !== null ? ['conditions_acceptees' => $conditionsUrl] : []
        );

        // The booking takes its own copy at the FIRST generation (§6.25),
        // which is also what gives the version counter a row to live on.
        // From here the asset's template can be reworded without touching
        // this booking.
        if ($this->documentRepository->findText($booking->id, $type) === null) {
            $this->documentRepository->saveText($booking->id, $type, $this->sanitizer->sanitize($body));
        }

        $version = $this->documentRepository->claimNextVersion($booking->id, $type);
        $fileName = RentalDocument::fileNameFor($type, $booking->reference, $version);

        $header = [
            'Bien : ' . $asset->name,
            'Séjour : du '
                . self::frenchDate($booking->arrivalDate)
                . ' au '
                . self::frenchDate($booking->departureDate),
        ];
        // In the frame rather than only as a keyword: a unit whose own
        // template predates the keyword still sends a contract that names
        // the conditions its renter accepted — never today's (#708, IT-16).
        if ($type === DocumentType::CONTRACT && $this->acceptedConditions($booking) !== null) {
            $header[] = new LinkedText(
                'Conditions de location acceptées : ' . $values['conditions_acceptees'],
                $conditionsUrl
            );
        }

        $pdf = $this->pdfService->generate(
            $type->label() . ' — ' . $booking->reference,
            $rendered,
            // A contract is headed by the party letting the asset — the
            // unit or the ASBL that owns it — never by the site's own name,
            // which on many installations is the product's (issue #977).
            $type === DocumentType::CONTRACT
                ? $this->landlordFor($asset)->name
                : (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
            $header,
            $type === DocumentType::INVOICE ? $this->vatNote($asset) : null
        );

        $fileId = $this->storePdf($pdf, $fileName, $booking->id);

        $documentId = $this->documentRepository->create(
            $booking->id,
            $fileId,
            $type,
            $version,
            // Generated documents are meant for the renter by definition —
            // and "for the renter" means "gets emailed", never "gets a
            // download link" (§6.24).
            true,
            (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            $actorMemberId,
            RentalDocument::SOURCE_MANUAL,
            null,
            null,
            $type === DocumentType::INVOICE ? $validatedSettlement?->id : null,
            $type === DocumentType::INVOICE
                ? ($validatedSettlement?->keptCents() ?? $booking->effectiveTotalCents())
                : null
        );

        // What this contract says about its booking, so the day the booking
        // stops saying it the contract is known to be void (#708, IT-20).
        if ($type === DocumentType::CONTRACT) {
            $this->documentRepository->setFingerprint(
                $documentId,
                \Modules\Rental\Document\ContractFingerprint::of($values, $booking->effectivePrice())
            );
        }

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $type->label() . ' v' . $version,
            'Document généré',
            $actorMemberId
        );

        $this->journal->log(
            'rental',
            'rental_document_generated',
            'info',
            $type->label() . ' v' . $version . ' généré pour ' . $booking->reference,
            ['booking_id' => $booking->id, 'document_id' => $documentId, 'version' => $version]
        );

        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            throw new RentalException("Le document n'a pas pu être enregistré.");
        }

        return $document;
    }

    // ── The supplementary invoice (#980, chantier decision 2.8) ─────────────────

    /**
     * What a supplementary invoice would bill now, or null when there is
     * nothing to bill.
     *
     * Something to bill means: an invoice went out, the booking's latest
     * validated settlement is not what the last billing document sent was
     * based on, no supplement for that settlement exists yet (sent or
     * not — « Générer » twice makes one), and its kept amount differs from
     * what that document billed. The baseline is the last one sent, the
     * invoice or a supplement before this one, so two supplements never
     * bill the same euro; every supplement still names the invoice.
     *
     * @param Settlement[] $settlements the booking's, as `RentalStayService::settlementsFor()` gives them
     */
    public function supplementOffer(RentalBooking $booking, array $settlements): ?SupplementOffer
    {
        $byId = [];
        $latest = null;
        foreach ($settlements as $settlement) {
            if ($settlement->bookingId !== $booking->id) {
                continue;
            }
            $byId[$settlement->id] = $settlement;
            if ($settlement->isValidated && ($latest === null || $settlement->version > $latest->version)) {
                $latest = $settlement;
            }
        }
        // A version closing the booking with an unrecovered balance (#980)
        // writes the debt off; it bills nothing, in either direction.
        if ($latest === null || $latest->isUnrecovered()) {
            return null;
        }

        // Newest first: the first sent invoice met is the one the renter
        // holds, the first sent billing document what was last billed.
        $original = null;
        $lastBilled = null;
        foreach ($this->documentRepository->findForBooking($booking->id) as $document) {
            if (!$document->hasBeenSent() || $document->isSuperseded()) {
                continue;
            }
            if ($document->type === DocumentType::INVOICE) {
                $original ??= $document;
                $lastBilled ??= $document;
            } elseif ($document->type === DocumentType::SUPPLEMENTARY_INVOICE) {
                $lastBilled ??= $document;
            }
        }
        if ($original === null || $lastBilled === null || $lastBilled->settlementId === $latest->id
            || $this->documentRepository->hasSupplementForSettlement($booking->id, $latest->id)
        ) {
            return null;
        }

        $billed = $this->billedCents($lastBilled);
        if ($billed === null) {
            return null;
        }

        $offer = new SupplementOffer(
            $original,
            $latest,
            $billed,
            $lastBilled->settlementId !== null ? ($byId[$lastBilled->settlementId] ?? null) : null
        );

        return $offer->differenceCents() === 0 ? null : $offer;
    }

    /**
     * What a billing document billed: the figure recorded at its
     * generation, or — for an invoice generated before #980 recorded one —
     * the agreed total frozen in its snapshot, the figure it printed.
     */
    public function billedCents(RentalDocument $document): ?int
    {
        if ($document->billedKeptCents !== null) {
            return $document->billedKeptCents;
        }
        if ($document->type !== DocumentType::INVOICE) {
            return null;
        }

        $printed = $this->documentRepository->findSnapshot($document->id)['prix_total'] ?? null;
        if (!is_string($printed)) {
            return null;
        }

        try {
            return RentalPricingService::parseAmountToCents(str_replace('€', '', $printed));
        } catch (RentalException) {
            return null;
        }
    }

    /**
     * Generates the supplementary invoice of the latest validated
     * settlement (#980, chantier decision 2.8): only the difference from what was
     * last billed, the invoice it completes named by number and date, and
     * how to settle it — the balance due with its deadline (validation +
     * 30 days, Q13), account, communication and payment QR, or the
     * overpayment the landlord gives back, with no QR.
     *
     * Decided and written under the booking's lock, so two managers make
     * one. Neither the invoice nor any earlier supplement is touched.
     *
     * @param Settlement[] $settlements the booking's
     * @throws RentalException when there is nothing to bill
     */
    public function generateSupplementaryInvoice(
        RentalBooking $booking,
        RentalAsset $asset,
        PaymentSettings $paymentSettings,
        array $settlements,
        ?int $actorMemberId = null
    ): RentalDocument {
        $type = DocumentType::SUPPLEMENTARY_INVOICE;
        // Read from Finance before the booking is locked: nothing of it
        // depends on what the lock decides, and the lock stays short.
        $instructions = $this->payments?->paymentInstructions($booking, $paymentSettings);

        $documentId = $this->documentRepository->withBookingLocked(
            $booking->id,
            function () use (
                $booking,
                $asset,
                $paymentSettings,
                $settlements,
                $actorMemberId,
                $type,
                $instructions
            ): int {
                $offer = $this->supplementOffer($booking, $settlements);
                if ($offer === null) {
                    throw new RentalException(
                        'Aucune facture complémentaire à générer : la facture envoyée correspond au dernier '
                        . 'décompte validé, ou sa facture complémentaire existe déjà.'
                    );
                }

                $body = trim($this->bookingText($booking, $asset, $type));
                if ($this->documentRepository->findText($booking->id, $type) === null) {
                    $this->documentRepository->saveText($booking->id, $type, $this->sanitizer->sanitize($body));
                }
                $version = $this->documentRepository->claimNextVersion($booking->id, $type);

                $values = array_merge(
                    $this->valuesFor($booking, $asset, $paymentSettings, $instructions['communication'] ?? null),
                    $this->supplementValues($booking, $offer, $version, $instructions)
                );
                $rendered = DocumentKeywords::substitute(
                    DocumentKeywords::repairSplitKeywords($this->sanitizer->sanitize($body)),
                    $values
                );

                $original = $offer->originalInvoice;
                $pdf = $this->pdfService->generate(
                    $type->label() . ' — ' . $booking->reference,
                    $rendered,
                    (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
                    [
                        'Bien : ' . $asset->name,
                        'Séjour : du ' . self::frenchDate($booking->arrivalDate)
                            . ' au ' . self::frenchDate($booking->departureDate),
                        // In the frame as well as in the text: whatever the
                        // body says, the reader sees which invoice this completes.
                        'Complète la facture ' . self::invoiceNumber($booking, $original)
                            . ' du ' . $original->createdAt->format('d/m/Y'),
                    ],
                    $this->vatNote($asset),
                    // Only what the renter still owes is scanned: a
                    // supplement in their favour says what comes back, and
                    // a QR there would invite them to pay it (Q15).
                    $offer->differenceCents() > 0 && ($instructions['amount_cents'] ?? 0) > 0
                        ? ($instructions['qr_data_uri'] ?? null)
                        : null
                );

                $fileName = RentalDocument::fileNameFor($type, $booking->reference, $version);

                return $this->documentRepository->create(
                    $booking->id,
                    $this->storePdf($pdf, $fileName, $booking->id),
                    $type,
                    $version,
                    true,
                    (string) json_encode($values, JSON_UNESCAPED_UNICODE),
                    $actorMemberId,
                    RentalDocument::SOURCE_MANUAL,
                    null,
                    null,
                    $offer->settlement->id,
                    $offer->settlement->keptCents(),
                    $original->id
                );
            }
        );

        $document = $this->documentRepository->findById($documentId)
            ?? throw new RentalException("Le document n'a pas pu être enregistré.");

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $document->label(),
            'Document généré',
            $actorMemberId
        );
        // Ids and the version, never an amount or anything about the renter.
        $this->journal->log(
            'rental',
            'rental_document_generated',
            'info',
            $document->label() . ' généré pour ' . $booking->reference,
            [
                'booking_id' => $booking->id,
                'document_id' => $documentId,
                'version' => $document->version,
                'settlement_id' => $document->settlementId,
                'supplements_document_id' => $document->supplementsDocumentId,
            ]
        );

        return $document;
    }

    /**
     * An invoice's number (Q3): the booking's reference and the version,
     * « LOC-K7Q2M4-v1 » — the name it has always been listed and filed
     * under, with no sequence of its own.
     */
    public static function invoiceNumber(RentalBooking $booking, RentalDocument $invoice): string
    {
        return $booking->reference . '-v' . $invoice->version;
    }

    /**
     * The supplement's own keywords.
     *
     * @param array<string, mixed>|null $instructions RentalPaymentService::paymentInstructions()
     * @return array<string, string|null>
     */
    private function supplementValues(
        RentalBooking $booking,
        SupplementOffer $offer,
        int $version,
        ?array $instructions
    ): array {
        $settlement = $offer->settlement;
        $difference = $offer->differenceCents();
        $validatedAt = $settlement->validatedAt ?? $settlement->createdAt;

        return [
            // « C » for complément: never « -v1 », which is the invoice's.
            'numero_facture_complementaire' => $booking->reference . '-C' . $version,
            'facture_origine' => self::invoiceNumber($booking, $offer->originalInvoice)
                . ' du ' . $offer->originalInvoice->createdAt->format('d/m/Y'),
            'date_decompte' => $validatedAt->format('d/m/Y'),
            'detail_complement' => self::lineChanges(
                $offer->billedSettlement->lines ?? self::agreedLines($booking),
                $settlement->lines
            ),
            'montant_deja_facture' => Support::euros($offer->alreadyBilledCents),
            'montant_decompte' => Support::euros($settlement->keptCents()),
            'montant_complement' => $difference < 0
                ? Support::euros(-$difference) . ' en faveur du locataire'
                : Support::euros($difference),
            'reglement_complement' => self::settlementSentence(
                $settlement,
                $instructions,
                // Q13: thirty days from the validation, never the
                // balance date of a stay that is over — the same rule as
                // RentalPaymentService::statusFor().
                Settlement::balanceDueAfter($validatedAt)
            ),
        ];
    }

    /**
     * How the supplement is settled, as one sentence a template can hold.
     *
     * @param array<string, mixed>|null $instructions
     */
    private static function settlementSentence(
        Settlement $settlement,
        ?array $instructions,
        \DateTimeImmutable $dueDate
    ): string {
        if ($instructions !== null) {
            $dueCents = (int) ($instructions['amount_cents'] ?? 0);
            $overpaidCents = (int) ($instructions['overpaid_cents'] ?? 0);
        } else {
            // Without Finance the payments are recorded by hand, and the
            // settlement's own balance is what is left.
            $dueCents = max(0, $settlement->balanceCents);
            $overpaidCents = max(0, -$settlement->balanceCents);
        }

        if ($dueCents > 0) {
            $account = '';
            if (is_string($instructions['iban'] ?? null)) {
                $holder = $instructions['beneficiary'] ?? null;
                $account = ' sur le compte ' . $instructions['iban'] . (is_string($holder) ? ' (' . $holder . ')' : '');
            }
            $communication = is_string($instructions['communication'] ?? null)
                ? ', avec la communication ' . $instructions['communication']
                : '';

            return 'Restant dû : ' . Support::euros($dueCents) . ', à verser au plus tard le '
                . $dueDate->format('d/m/Y') . $account . $communication . '.';
        }
        if ($overpaidCents > 0) {
            return 'Montant à rembourser au locataire (trop-perçu) : ' . Support::euros($overpaidCents)
                . '. Le bailleur le rembourse ; rien n\'est à payer.';
        }

        return 'Rien ne reste à payer : les paiements reçus couvrent le décompte final.';
    }

    /**
     * The agreed quote's lines, as an invoice billed before any settlement
     * billed them — what the settlement's lines are compared with then.
     * Null when the booking has no quote to compare with.
     *
     * @return SettlementLine[]|null
     */
    private static function agreedLines(RentalBooking $booking): ?array
    {
        $quote = $booking->effectivePrice();
        if ($quote === null) {
            return null;
        }

        $lines = [];
        foreach ($quote->lines as $line) {
            if (!$line->isInformational) {
                $lines[] = new SettlementLine($line->label, $line->amountCents, SettlementLine::ORIGIN_AGREED);
            }
        }

        return $lines;
    }

    /**
     * The settlement lines added and taken away since what was billed — a
     * charged damage, a waived one, a consumption — matched on what they
     * say rather than how they were produced, so an agreed line carried
     * unchanged is not « added ». Without lines to compare with, says the
     * settlement replaces what was billed.
     *
     * @param SettlementLine[]|null $billed
     * @param SettlementLine[] $current
     */
    private static function lineChanges(?array $billed, array $current): string
    {
        if ($billed === null) {
            return 'Le décompte final validé remplace le montant déjà facturé.';
        }

        $key = static fn(SettlementLine $line): string => $line->label . "\x1F" . $line->amountCents;
        $remaining = [];
        foreach ($billed as $line) {
            $remaining[$key($line)][] = $line;
        }

        $added = [];
        foreach ($current as $line) {
            if (($remaining[$key($line)] ?? []) !== []) {
                array_pop($remaining[$key($line)]);
                continue;
            }
            $added[] = $line->label . ' (' . Support::euros($line->amountCents) . ')';
        }
        $removed = [];
        foreach ($remaining as $lines) {
            foreach ($lines as $line) {
                $removed[] = $line->label . ' (' . Support::euros($line->amountCents) . ')';
            }
        }

        $parts = [];
        if ($added !== []) {
            $parts[] = 'Ajouté au décompte : ' . implode(' ; ', $added) . '.';
        }
        if ($removed !== []) {
            $parts[] = 'Retiré du décompte : ' . implode(' ; ', $removed) . '.';
        }

        return $parts !== [] ? implode(' ', $parts) : 'Les lignes du décompte sont celles déjà facturées.';
    }

    /**
     * Refuses a contract that would leave out what annex 8.1 of the
     * Walloon tourism code requires it to state and nothing else can fill
     * in afterwards (issue #977): the renter's name and address (point 2)
     * and the address of the asset let (point 4).
     *
     * Called by `generate()` itself, so no caller can get round it, and by
     * the callers before they raise the receivable, so a refused contract
     * leaves nothing behind. The refusal says what to complete and where:
     * a manager reading only « données manquantes » cannot act on it.
     *
     * @throws RentalException
     */
    public function assertContractCanBeGenerated(RentalBooking $booking, RentalAsset $asset): void
    {
        $billing = $this->bookingRepository->findBillingIdentity($booking->id);
        $missingName = self::renterName($booking, $billing) === null;
        $missingAddress = trim((string) ($billing['address'] ?? '')) === '';
        $missingAsset = trim((string) ($asset->address ?? '')) === '';

        $reasons = [];
        if ($missingName || $missingAddress) {
            $what = match (true) {
                $missingName && $missingAddress => ['le nom et l\'adresse', 'les'],
                $missingName => ['le nom', 'le'],
                default => ['l\'adresse', 'la'],
            };
            $reasons[] = 'il manque ' . $what[0] . ' du locataire. Le locataire peut ' . $what[1]
                . ' compléter dans « Vos coordonnées de facturation » sur sa page de suivi, ou vous dans '
                . '« Coordonnées de facturation » sur la page « Facture » de la réservation.';
        }
        if ($missingAsset) {
            $reasons[] = ($reasons === [] ? 'l\'adresse du bien manque.' : 'L\'adresse du bien manque aussi.')
                . ' Complétez « Adresse du bien » dans la section « Contrat » des « Réglages » du bien.';
        }

        if ($reasons !== []) {
            throw new RentalException('Le contrat ne peut pas être généré : ' . implode(' ', $reasons));
        }
    }

    /**
     * Registers a file a manager uploaded — an inventory, a photo, a signed
     * contract come back by post.
     *
     * The upload itself is `UploadHandler`'s job (real MIME check,
     * regenerated file name, EXIF stripped, size cap, stored outside
     * `public/`); this only records what the file *is*.
     *
     * `$source` says who owns the bytes. `RentalDocument::SOURCE_EMAIL` is
     * for an inbound message's own attachment, registered by the very same
     * `files` id the message serves it from (§8.59) — the flag is what
     * stops `delete()` from blanking that message later.
     */
    public function attachUploaded(
        RentalBooking $booking,
        int $fileId,
        DocumentType $type,
        bool $isForRenter,
        ?int $actorMemberId = null,
        string $source = RentalDocument::SOURCE_MANUAL
    ): int {
        $documentId = $this->documentRepository->create(
            $booking->id,
            $fileId,
            $type,
            1,
            $isForRenter,
            null,
            $actorMemberId,
            $source
        );

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            null,
            $type->label(),
            'Document ajouté',
            $actorMemberId
        );

        return $documentId;
    }

    /**
     * Files a PDF this module produced elsewhere — the contract signed by
     * both parties (#708, IT-16) — under `storage/`, like a generated one.
     *
     * @throws RentalException
     */
    public function attachPdf(
        RentalBooking $booking,
        string $pdf,
        DocumentType $type,
        string $displayName,
        bool $isForRenter,
        ?int $actorMemberId = null,
        ?int $contractDocumentId = null,
        ?int $modificationId = null
    ): RentalDocument {
        $fileId = $this->storePdf($pdf, $displayName, $booking->id);
        try {
            $documentId = $this->documentRepository->create(
                $booking->id,
                $fileId,
                $type,
                1,
                $isForRenter,
                null,
                $actorMemberId,
                RentalDocument::SOURCE_MANUAL,
                $contractDocumentId,
                $modificationId
            );
        } catch (\Throwable $e) {
            // The bytes and the `files` row go with the document row that was
            // refused: under a caller's transaction the row rolls back anyway,
            // without one it would stay behind, and the file on disk would be
            // left for nobody to find either way (#989, lot E).
            $this->fileRemover->removeOrphan($fileId);
            throw $e;
        }

        return $this->documentRepository->findById($documentId)
            ?? throw new RentalException("Le document n'a pas pu être enregistré.");
    }

    /**
     * Reclassify a document a manager filed or an email brought in (§6.24,
     * §7.8).
     *
     * **Generated documents are never reclassified.** A contract or an
     * invoice is what this module produced from a template, and calling it
     * something else would break the versioning that exists so a signed v1
     * stays findable — and would let a manager quietly turn an invoice into
     * a "photo" to hide it.
     *
     * @throws RentalException when the document belongs to another booking,
     *   or when either the current or the new type is a generated one
     */
    public function reclassify(
        RentalBooking $booking,
        int $documentId,
        DocumentType $type,
        bool $isForRenter,
        ?int $actorMemberId = null
    ): void {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null || $document->bookingId !== $booking->id) {
            // The booking check is the real guard: a document id alone must
            // not let a manager of one asset touch another asset's file.
            throw new RentalException("Ce document n'existe pas.");
        }

        if ($document->type->isGenerated() || $type->isGenerated()) {
            throw new RentalException('Un contrat ou une facture ne se reclasse pas : régénérez-le plutôt.');
        }

        $this->documentRepository->updateType($documentId, $type, $isForRenter);

        $this->bookingAudit->record(
            $booking->id,
            BookingAudit::STATUS_CHANGED,
            $document->type->label(),
            $type->label(),
            'Document reclassé',
            $actorMemberId
        );
    }

    /**
     * @return RentalDocument[]
     */
    public function forBooking(int $bookingId): array
    {
        return $this->documentRepository->findForBooking($bookingId);
    }

    /**
     * The documents of one modification project — its replacement
     * contract and what was signed from it (#927, chantier §2.3).
     *
     * @return RentalDocument[]
     */
    public function forModification(int $modificationId): array
    {
        return $this->documentRepository->findForModification($modificationId);
    }

    public function find(int $documentId): ?RentalDocument
    {
        return $this->documentRepository->findById($documentId);
    }

    public function latest(int $bookingId, DocumentType $type): ?RentalDocument
    {
        return $this->documentRepository->findLatest($bookingId, $type);
    }

    public function markSent(int $documentId, \DateTimeImmutable $sentAt): void
    {
        $this->documentRepository->markSent($documentId, $sentAt);
    }

    /**
     * The absolute path of a document's bytes, or null when the row points
     * at a file that is no longer there.
     *
     * Null rather than an exception: a missing file is a real state on a
     * restored backup or after a botched migration, and the listing has to
     * keep rendering with the others.
     */
    public function absolutePath(RentalDocument $document): ?string
    {
        $file = $this->fileRepository->findById($document->fileId);
        if ($file === null) {
            return null;
        }

        $path = $this->storagePath . '/' . $file->relativePath;

        return is_file($path) ? $path : null;
    }

    /**
     * Removes a photo stored for a reading or an incident whose own row
     * was then refused (#708, IT-17): the bytes and the `files` row exist,
     * nothing references them and nothing ever will.
     */
    public function discardUnusedUpload(int $fileId): void
    {
        $this->fileRemover->removeOrphan($fileId);
    }

    /**
     * Removes a document, and its file ONLY when this module owns the
     * bytes and nothing else points at them.
     *
     * A document whose source is `email` shares its `files` row with the
     * inbound message the attachment arrived in (§8.59): the message still
     * owns it and still serves it, so deleting the file here would blank
     * the message's own attachment. Only the link between the booking and
     * the file goes. The invariant itself, and its reasons, live in
     * `Core\File\AttachedFileRemover`.
     *
     * @throws RentalException
     */
    public function delete(RentalDocument $document, ?int $actorMemberId = null): void
    {
        // **A document that has gone out cannot be deleted.** Not tidiness:
        // `textIsLocked()` asks whether a document of this type carries a
        // `sent_at`, so deleting the only sent contract unlocked its source
        // text again — while the renter still holds the PDF that was made
        // from the old one. The module already refuses to reuse a version
        // number for the same reason (`claimNextVersion()`: "v2 may already
        // have been emailed"), and this is that rule one step further on.
        if ($document->sentAt !== null) {
            throw new RentalException(
                'Ce document a été envoyé au locataire : il ne peut plus être supprimé. '
                . 'Ce qui est parti reste au dossier.'
            );
        }
        // **Nor a signed inventory, sent or not** (#989, lot E): its PDF is
        // what the renter and the manager signed, and the validation points
        // at it. An e-mail that failed is resent, never replaced. Nor the
        // photo of the sheet one was signed on, on paper (lot F), whatever
        // it has been reclassified as: it is the signatures.
        if ($this->documentRepository->isInventoryValidationDocument($document->id)) {
            throw new RentalException(
                $document->type === DocumentType::INVENTORY
                    ? "Cet état des lieux est signé : il ne peut pas être supprimé. S'il n'est pas parti, "
                        . 'renvoyez-le au locataire.'
                    : "Cette photo est la feuille signée d'un état des lieux : elle ne peut pas être supprimée."
            );
        }

        $this->fileRemover->remove(
            $this->documentRepository,
            $document->id,
            $document->fileId,
            $document->ownsItsFile()
        );

        $this->journal->log(
            'rental',
            'rental_document_deleted',
            'info',
            $document->type->label() . ' supprimé',
            ['booking_id' => $document->bookingId, 'document_id' => $document->id, 'member_id' => $actorMemberId]
        );
    }

    // ── Keyword values ──────────────────────────────────────────────────

    /**
     * Every keyword's value for this booking, raw and unescaped —
     * `DocumentKeywords::substitute()` escapes them.
     *
     * @return array<string, string|null>
     */
    public function valuesFor(
        RentalBooking $booking,
        RentalAsset $asset,
        PaymentSettings $paymentSettings,
        ?string $communication = null
    ): array {
        $total = $booking->effectiveTotalCents();
        $billing = $this->bookingRepository->findBillingIdentity($booking->id);
        $onSite = $this->bookingRepository->findOnSiteContact($booking->id);
        $deposit = $total !== null ? $paymentSettings->depositFor($total) : null;
        $securityDeposit = $paymentSettings->securityDepositAmount();
        $landlord = $this->landlordFor($asset);
        $nights = self::nightsBetween($booking->arrivalDate, $booking->departureDate);
        $terms = $this->payments?->contractTerms($booking, $paymentSettings)
            ?? ['iban' => null, 'holder' => null, 'deposit_due_date' => null];
        $balanceDue = $paymentSettings->balanceDueDate($booking->arrivalDate);
        // As of today, the day the contract is generated: a deadline
        // already gone is never offered (issue #977).
        $withdrawal = WithdrawalScale::of($booking->arrivalDate, $asset->freeWithdrawalDays, new \DateTimeImmutable());

        return [
            'reference' => $booking->reference,
            'bien' => $asset->name,
            'bien_type' => $asset->assetType,
            'date_arrivee' => self::frenchDate($booking->arrivalDate),
            'date_depart' => self::frenchDate($booking->departureDate),
            'heure_arrivee' => $asset->arrivalTime,
            'heure_depart' => $asset->departureTime,
            'nuits' => (string) self::nightsBetween($booking->arrivalDate, $booking->departureDate),
            'participants' => $booking->estimatedPersons !== null ? (string) $booking->estimatedPersons : null,
            'capacite' => $asset->capacity !== null ? (string) $asset->capacity : null,
            'quantite' => (string) max(1, $booking->units),
            'prix_total' => $total !== null ? Support::euros($total) : null,
            'acompte' => $deposit !== null ? Support::euros($deposit) : null,
            'caution' => $securityDeposit !== null ? Support::euros($securityDeposit) : null,
            'communication' => $communication,
            'locataire_nom' => self::renterName($booking, $billing),
            'locataire_organisation' => $booking->renterOrganisation,
            'locataire_email' => $booking->renterEmail,
            'locataire_telephone' => $booking->renterPhone,
            'locataire_adresse' => $billing['address'],
            'locataire_tva' => $billing['vat_number'] ?? $billing['enterprise_number'],
            'nom_bailleur' => $landlord->name,
            'adresse_bailleur' => $landlord->address !== null ? UnitAddresses::oneLine($landlord->address) : null,
            'bce_bailleur' => $landlord->enterpriseNumber,
            'unite' => (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
            'date_du_jour' => (new \DateTimeImmutable())->format('d/m/Y'),
            'mention_tva' => $this->vatNote($asset),
            'conditions_acceptees' => $this->acceptedConditionsLine($booking, $asset),
            // Issue #977 — annex 8.1 of the Walloon tourism code.
            'bien_adresse' => $asset->address !== null ? UnitAddresses::oneLine($asset->address) : null,
            'duree_nuits' => Support::plural($nights, 'nuit', 'nuits'),
            'nombre_participants' => $booking->estimatedPersons !== null
                ? Support::plural($booking->estimatedPersons, 'participant', 'participants')
                : null,
            'capacite_personnes' => $asset->capacity !== null
                ? Support::plural($asset->capacity, 'personne', 'personnes')
                : null,
            'responsable_nom' => $onSite['name'],
            'responsable_telephone' => $onSite['phone'],
            // Said rather than left blank: a contract stating no account
            // reads as one where the landlord forgot it.
            'iban' => $terms['iban'] ?? 'à communiquer par écrit par le bailleur',
            'titulaire_compte' => $terms['holder'] ?? 'le bailleur',
            'echeance_acompte' => $deposit !== null
                ? self::depositDeadline($terms['deposit_due_date'], $paymentSettings->depositDueDays)
                : null,
            'echeance_solde' => $balanceDue !== null
                ? 'au plus tard le ' . self::frenchDate($balanceDue)
                : 'avant le début du séjour',
            'date_limite_desistement' => $withdrawal?->freeUntil !== null
                ? self::frenchDate($withdrawal->freeUntil)
                : null,
            'desistement_sans_indemnite' => $withdrawal?->freeSentence(),
            'bareme_desistement' => $withdrawal?->scaleSentence(),
            'mention_bce_bailleur' => $landlord->enterpriseNumber !== null
                ? ", n° d'entreprise " . $landlord->enterpriseNumber
                : null,
            'contact_donnees' => self::text($this->settingService->get('contact_email'))
                ?? "l'adresse du bailleur indiquée en tête du présent contrat",
            'duree_conservation' => $this->retentionPhrase(),
            // The supplementary invoice's own (#980): « — » in any other
            // document whose author inserts one, never literal braces.
            'numero_facture_complementaire' => null,
            'facture_origine' => null,
            'date_decompte' => null,
            'detail_complement' => null,
            'montant_deja_facture' => null,
            'montant_decompte' => null,
            'montant_complement' => null,
            'reglement_complement' => null,
        ];
    }

    /**
     * The renter as the contract names them: the name given with the
     * request, else the name of the billing coordinates, else null — and a
     * contract is not generated then (`assertContractCanBeGenerated()`).
     *
     * @param array<string, ?string> $billing
     */
    private static function renterName(RentalBooking $booking, array $billing): ?string
    {
        return self::text($booking->renterName) ?? self::text($billing['name'] ?? null);
    }

    /**
     * « au plus tard le 15/03/2027 » once the first send has stamped the
     * deadline (issue #927), « dans les 15 jours qui suivent l'envoi du
     * présent contrat » before it — the contract being generated is the
     * one whose send will stamp it.
     */
    private static function depositDeadline(?string $stampedDate, ?int $days): string
    {
        if ($stampedDate !== null) {
            return 'au plus tard le ' . self::frenchDate($stampedDate);
        }
        if ($days === null) {
            return 'à la date convenue avec le bailleur';
        }

        return $days === 0
            ? "dès l'envoi du présent contrat"
            : 'dans les ' . Support::plural($days, 'jour', 'jours') . " qui suivent l'envoi du présent contrat";
    }

    /**
     * How long a booking's data are kept, as the retention purge applies
     * it (`RentalRetentionService`): from the close of the accounting year
     * the stay fell in, never from the stay itself.
     */
    private function retentionPhrase(): string
    {
        $years = (int) ($this->settingService->get(RentalRetentionService::SETTING_RETENTION_YEARS, 'rental')
            ?: RentalRetentionService::DEFAULT_RETENTION_YEARS);
        if ($years <= 0) {
            $years = RentalRetentionService::DEFAULT_RETENTION_YEARS;
        }

        return Support::plural($years, 'an', 'ans')
            . " à compter de la clôture de l'exercice comptable au cours duquel le séjour a eu lieu";
    }

    private static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /**
     * « version du 12/09/2027, https://…/locations/salle/conditions/a1b2c3d4e5f6 »
     * — the version the renter accepted and the permanent address of THAT
     * text, the same one every email to them ends with.
     *
     * Never empty: the standard contract cites it inside a sentence, which
     * a bare « — » would leave reading « lors de sa demande (—) ». When the
     * archive cannot name the text — it was overwritten before the archive
     * existed, or the request predates acceptance being recorded — the
     * line says when the conditions in question were in force.
     */
    private function acceptedConditionsLine(RentalBooking $booking, RentalAsset $asset): string
    {
        $version = $this->acceptedConditions($booking);
        if ($version === null) {
            return 'en vigueur le ' . ($booking->conditionsAcceptedAt ?? $booking->receivedAt)->format('d/m/Y');
        }

        $dated = $version->dateKnownAt($booking->conditionsAcceptedAt);
        $line = $dated !== null
            ? 'version du ' . $dated->format('d/m/Y')
            : 'version acceptée le ' . $booking->conditionsAcceptedAt?->format('d/m/Y');
        $url = $this->acceptedConditionsUrl($booking, $asset);

        return $url === null ? $line : $line . ', ' . $url;
    }

    /**
     * The permanent address of the conditions the renter accepted, or null
     * when the archive cannot name them or the site has no base address.
     * The contract prints it AND links it (issue #977).
     */
    private function acceptedConditionsUrl(RentalBooking $booking, RentalAsset $asset): ?string
    {
        $version = $this->acceptedConditions($booking);
        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');
        if ($version === null || $baseUrl === '') {
            return null;
        }

        return $baseUrl . '/locations/' . $asset->slug . '/conditions/' . $version->version;
    }

    /**
     * Who lets $asset, as its documents name it (issue #497).
     */
    public function landlordFor(RentalAsset $asset): Landlord
    {
        return Landlord::forAsset($this->settingService, $asset);
    }

    /**
     * The mention an invoice with no VAT on it needs, stating the legal
     * basis of the exemption.
     *
     * **No VAT is ever computed** — prices are what the renter pays. A unit
     * letting a hall is not a VAT-registered business in the general case,
     * and a module that computed VAT would be quietly wrong for almost
     * every installation. What a Belgian invoice with no VAT on it does
     * need is a sentence saying why, so that sentence is configurable per
     * asset with a default.
     *
     * **Two levels, not three** (issue #613). A unit-wide level used to be
     * read from `rental_vat_exemption_note`, a setting nothing declared, so
     * no unit could ever fill it in. The read is gone; the default is the
     * standard mention for a letting exempted by article 44 of the Belgian
     * VAT Code, the basis the asset's own field already suggests.
     */
    public const DEFAULT_VAT_NOTE = 'Exemption de TVA — article 44 du Code de la TVA.';

    public function vatNote(RentalAsset $asset): string
    {
        $configured = $asset->vatExemptionNote;
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        return self::DEFAULT_VAT_NOTE;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /**
     * Writes the PDF under `storage/` and registers it in `files`.
     *
     * Not through `UploadHandler`: that one validates and moves an *upload*,
     * and there is no upload here — the bytes were produced a line ago and
     * their type is not in question. What matters is the part that is the
     * same either way, and it is: the file lands outside `public/` under a
     * name nobody chose, and reaches a browser only through
     * `FileAccessGuard`.
     *
     * @throws RentalException
     */
    private function storePdf(string $pdf, string $displayName, int $bookingId): int
    {
        $directory = $this->storagePath . '/' . self::STORAGE_SUBDIRECTORY;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RentalException("Le dossier de stockage des documents n'a pas pu être créé.");
        }

        // Random on disk, readable in the interface: the stored name must
        // not be guessable, and the displayed one must say what it is.
        $relativePath = self::STORAGE_SUBDIRECTORY . '/' . bin2hex(random_bytes(16)) . '.pdf';
        $absolutePath = $this->storagePath . '/' . $relativePath;
        if (file_put_contents($absolutePath, $pdf) === false) {
            throw new RentalException("Le document n'a pas pu être écrit sur le disque.");
        }

        try {
            return $this->storedPdfRow($relativePath, $displayName, strlen($pdf), $bookingId);
        } catch (\Throwable $e) {
            // No row, so nothing would ever point at these bytes: a PDF
            // left on disk without its `files` row is an orphan nobody
            // finds again — and a signed inventory's carries signatures.
            @unlink($absolutePath);
            throw $e;
        }
    }

    private function storedPdfRow(string $relativePath, string $displayName, int $size, int $bookingId): int
    {
        return $this->fileRepository->create(
            $relativePath,
            $displayName,
            'application/pdf',
            $size,
            self::FILE_ROLE_MIN,
            'rental',
            null,
            false,
            null,
            // The generic owner_type registry, keyed by the BOOKING —
            // File\RentalDocumentOwnershipChecker narrows access to the
            // asset's own managers, well below what role_min alone allows.
            self::OWNER_TYPE,
            $bookingId
        );
    }

    private static function nightsBetween(string $arrival, string $departure): int
    {
        $from = DateInput::iso($arrival);
        $to = DateInput::iso($departure);

        if ($from === null || $to === null) {
            return 0;
        }

        return max(0, (int) $from->diff($to)->days);
    }

    private static function frenchDate(string $isoDate): string
    {
        $parsed = DateInput::iso($isoDate);

        return $parsed !== null ? $parsed->format('d/m/Y') : $isoDate;
    }
}
