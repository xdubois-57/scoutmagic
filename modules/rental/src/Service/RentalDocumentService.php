<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Config\SettingService;
use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Core\Pdf\DocumentPdfService;
use Core\Security\HtmlSanitizer;
use Core\View\EditableContentService;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentKeywords;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Document\StandardTemplates;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalBookingEventRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;

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
 * **Nothing here is downloadable by a renter.** The `is_for_renter` flag
 * means "attach it to an email"; an external renter has no account and the
 * tracking token is not a file credential (§6.24, §6.26). The only recourse
 * for a lost email is a manager resending it.
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
        private RentalBookingEventRepository $eventRepository,
        private EditableContentService $editableContentService,
        private FileRepository $fileRepository,
        private AttachedFileRemover $fileRemover,
        private DocumentPdfService $pdfService,
        private HtmlSanitizer $sanitizer,
        private SettingService $settingService,
        private JournalService $journal,
        private string $storagePath
    ) {
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
        if (!$type->isGenerated()) {
            throw new RentalException("Ce type de document ne se rédige pas : il s'envoie.");
        }

        // Sanitized here rather than relying on the caller, because this
        // path does not go through EditableContentService and would
        // otherwise be the one place raw HTML reached a PDF.
        $clean = $this->sanitizer->sanitize($bodyHtml);
        $this->documentRepository->saveText($booking->id, $type, $clean);

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
            null,
            $type->label(),
            'Texte du document modifié',
            $actorMemberId
        );

        return DocumentKeywords::unknownIn($clean);
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
     * @throws RentalException
     */
    public function generate(
        RentalBooking $booking,
        RentalAsset $asset,
        DocumentType $type,
        PaymentSettings $paymentSettings,
        ?string $communication = null,
        ?int $actorMemberId = null
    ): RentalDocument {
        if (!$type->isGenerated()) {
            throw new RentalException("Ce type de document ne se génère pas.");
        }

        $body = trim($this->bookingText($booking, $asset, $type));
        if ($body === '') {
            throw new RentalException(
                'Le gabarit « ' . $type->label() . ' » de ce bien est vide : rédigez-le avant de générer.'
            );
        }

        $values = $this->valuesFor($booking, $asset, $paymentSettings, $communication);

        // Sanitize first, substitute second, and escape every value. The
        // other order would run the sanitizer over renter-supplied content
        // and let it decide whether that content was markup (§6.25).
        $rendered = DocumentKeywords::substitute($this->sanitizer->sanitize($body), $values);

        // The booking takes its own copy at the FIRST generation (§6.25),
        // which is also what gives the version counter a row to live on.
        // From here the asset's template can be reworded without touching
        // this booking.
        if ($this->documentRepository->findText($booking->id, $type) === null) {
            $this->documentRepository->saveText($booking->id, $type, $this->sanitizer->sanitize($body));
        }

        $version = $this->documentRepository->claimNextVersion($booking->id, $type);
        $fileName = RentalDocument::fileNameFor($type, $booking->reference, $version);

        $pdf = $this->pdfService->generate(
            $type->label() . ' — ' . $booking->reference,
            $rendered,
            (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
            [
                'Bien : ' . $asset->name,
                'Séjour : du ' . self::frenchDate($booking->arrivalDate) . ' au ' . self::frenchDate($booking->departureDate),
            ],
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
            $actorMemberId
        );

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
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

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
            null,
            $type->label(),
            'Document ajouté',
            $actorMemberId
        );

        return $documentId;
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

        $this->eventRepository->record(
            $booking->id,
            RentalBookingEventRepository::STATUS_CHANGED,
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
        $this->fileRemover->remove(
            $this->documentRepository, $document->id, $document->fileId, $document->ownsItsFile()
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
        $deposit = $total !== null ? $paymentSettings->depositFor($total) : null;
        $securityDeposit = $paymentSettings->securityDepositAmount();

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
            'prix_total' => $total !== null ? self::euros($total) : null,
            'acompte' => $deposit !== null ? self::euros($deposit) : null,
            'caution' => $securityDeposit !== null ? self::euros($securityDeposit) : null,
            'communication' => $communication,
            'locataire_nom' => $booking->renterName,
            'locataire_organisation' => $booking->renterOrganisation,
            'locataire_email' => $booking->renterEmail,
            'locataire_telephone' => $booking->renterPhone,
            'locataire_adresse' => $billing['address'],
            'locataire_tva' => $billing['vat_number'] ?? $billing['enterprise_number'],
            'adresse_bailleur' => (string) ($this->settingService->get('unit_address') ?: ''),
            'unite' => (string) ($this->settingService->get('site_name') ?: 'Unité scoute'),
            'date_du_jour' => (new \DateTimeImmutable())->format('d/m/Y'),
            'mention_tva' => $this->vatNote($asset),
        ];
    }

    /**
     * The configurable exemption sentence (§6.27).
     *
     * **No VAT is ever computed** — prices are what the renter pays. A unit
     * letting a hall is not a VAT-registered business in the general case,
     * and a module that computed VAT would be quietly wrong for almost
     * every installation. What a Belgian invoice with no VAT on it does
     * need is a sentence saying why, so that sentence is configurable per
     * asset with a sane default.
     */
    public function vatNote(RentalAsset $asset): string
    {
        $configured = $asset->vatExemptionNote;
        if ($configured !== null && trim($configured) !== '') {
            return trim($configured);
        }

        return (string) ($this->settingService->get('rental_vat_exemption_note', 'rental')
            ?: 'Opération exonérée de TVA — association sans but lucratif.');
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
        if (file_put_contents($this->storagePath . '/' . $relativePath, $pdf) === false) {
            throw new RentalException("Le document n'a pas pu être écrit sur le disque.");
        }

        return $this->fileRepository->create(
            $relativePath,
            $displayName,
            'application/pdf',
            strlen($pdf),
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
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $arrival);
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', $departure);

        if ($from === false || $to === false) {
            return 0;
        }

        return max(0, (int) $from->diff($to)->days);
    }

    private static function frenchDate(string $isoDate): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);

        return $parsed !== false ? $parsed->format('d/m/Y') : $isoDate;
    }

    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}
