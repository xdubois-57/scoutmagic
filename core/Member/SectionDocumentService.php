<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\SchedulerService;

/**
 * Section documents (camp booklets, activity sheets, material lists) —
 * staff-managed from the Staffs page, shown read-only on the member page
 * for every section/year a member was active in (Core\Member\
 * SectionMembershipRepository). Files are encrypted at rest
 * (Core\File\EncryptedFileStorageService) and access-gated by the
 * generic owner_type/owner_id registry (Core\File\FileAccessGuard +
 * Core\Member\SectionDocumentOwnershipChecker) — never by role_min alone,
 * since a document's real audience (staff + past/present members of that
 * exact section) can't be expressed as a single flat role.
 */
class SectionDocumentService
{
    /**
     * Real MIME type whitelist (module addendum: "strict whitelist,
     * validated on the real MIME type, never the extension") — PDF,
     * Word, Excel, PowerPoint, OpenDocument, plain text, CSV. No images
     * or videos: those belong to the Galerie module.
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'text/plain',
        'text/csv',
    ];

    private const STORAGE_SUBDIRECTORY = 'section_documents';

    public function __construct(
        private SectionDocumentRepository $repository,
        private SectionMembershipRepository $membershipRepository,
        private EncryptedFileStorageService $fileStorage,
        private FileRepository $fileRepository,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService,
        private JournalService $journalService,
        private SchedulerService $schedulerService,
        private SettingService $settingService,
        private PdfCompressor $pdfCompressor
    ) {
    }

    /**
     * Re-detects the available PDF compression backend and caches it in
     * the read-only 'section_document_compression_backend' setting shown
     * (greyed) on the Paramètres page — called from StaffsController::
     * index() rather than on every request, since it's a real subprocess
     * spawn (a quick --version-style check) and is only actually
     * consulted from the Staffs page's own explanatory panel.
     */
    public function refreshDetectedBackend(): string
    {
        $backend = $this->pdfCompressor->detectBackend();
        $this->settingService->setInternal('section_document_compression_backend', $backend);

        return $backend;
    }

    /**
     * @return SectionDocument[]
     */
    public function listForSectionAndYear(int $sectionId, int $scoutYearId): array
    {
        return $this->repository->findBySectionAndYear($sectionId, $scoutYearId);
    }

    /**
     * Staffs page accordion data: every scout year that has at least one
     * document for this section, plus the current scout year even if it
     * has none yet (so there's always somewhere to add the first one),
     * most recent first.
     *
     * @return array<int, array{scout_year: array{id: int, label: string, start_date: string, end_date: string}, documents: SectionDocument[], is_current: bool}>
     */
    public function listYearsForStaffsPage(int $sectionId, int $currentScoutYearId): array
    {
        $yearIds = $this->repository->findScoutYearIdsWithDocuments($sectionId);
        if (!in_array($currentScoutYearId, $yearIds, true)) {
            $yearIds[] = $currentScoutYearId;
        }
        rsort($yearIds);

        $result = [];
        foreach ($yearIds as $yearId) {
            $scoutYear = $this->scoutYearService->findById($yearId);
            if ($scoutYear === null) {
                continue;
            }
            $result[] = [
                'scout_year' => $scoutYear,
                'documents' => $this->repository->findBySectionAndYear($sectionId, $yearId),
                'is_current' => $yearId === $currentScoutYearId,
            ];
        }

        return $result;
    }

    /**
     * @throws SectionDocumentException on an unsupported MIME type
     */
    public function upload(
        int $sectionId,
        int $scoutYearId,
        string $content,
        string $mimeType,
        string $originalFilename,
        string $title,
        ?string $description,
        ?int $uploadedBy
    ): SectionDocument {
        $this->assertMimeTypeAllowed($mimeType);
        $title = trim($title) !== '' ? trim($title) : $originalFilename;

        // owner_id can't be known until the section_documents row exists
        // (chicken-and-egg with file_id) — stored ownerless, then
        // FileRepository::updateOwner() right after.
        $fileId = $this->fileStorage->store(
            $content, $mimeType, $originalFilename, self::STORAGE_SUBDIRECTORY, 'identified', 'core', $uploadedBy
        );

        $documentId = $this->repository->create($sectionId, $scoutYearId, $fileId, $title, $description, strlen($content), $uploadedBy);
        $this->fileRepository->updateOwner($fileId, 'section_document', $documentId);

        $this->journalService->log(
            'core', 'section_document_added', 'info', 'Document de section ajouté',
            ['section_id' => $sectionId, 'scout_year_id' => $scoutYearId, 'section_document_id' => $documentId], $uploadedBy
        );

        // Background compression (Core\Member\Task\CompressSectionDocumentHandler)
        // — only worth scheduling for a PDF with the setting on; the
        // handler itself degrades to 'skipped' for every other reason
        // (no backend, no size win), this is just avoiding scheduling a
        // task that would immediately no-op for a non-PDF.
        if ($mimeType === 'application/pdf' && $this->settingService->get('section_document_compression_enabled') !== '0') {
            $this->schedulerService->scheduleAfter('core', 'compress_section_document', 0, ['section_document_id' => $documentId]);
        }

        $document = $this->repository->findById($documentId);
        \assert($document !== null);
        return $document;
    }

    /**
     * @throws SectionDocumentException when the title is blank
     */
    public function updateTitleAndDescription(int $documentId, string $title, ?string $description, ?int $actorId): void
    {
        $cleanTitle = trim($title);
        if ($cleanTitle === '') {
            throw new SectionDocumentException('Le titre est obligatoire.');
        }
        $document = $this->requireDocument($documentId);

        $this->repository->updateTitleAndDescription($documentId, $cleanTitle, $description !== null && trim($description) !== '' ? trim($description) : null);

        $this->journalService->log(
            'core', 'section_document_renamed', 'info', 'Document de section renommé',
            ['section_id' => $document->sectionId, 'scout_year_id' => $document->scoutYearId, 'section_document_id' => $documentId], $actorId
        );
    }

    /**
     * $orderedIds is trusted to all belong to the same (section, scout
     * year) — true for any real list_editor.html.twig instance, since
     * each one only ever renders one accordion year's documents. Scoping
     * is resolved from the first id rather than taken from the request,
     * to match list-editor.js's flat "ids only" reorder payload
     * (Core\Http\Controller\SectionDocumentController::reorder()).
     *
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        $first = $this->repository->findById($orderedIds[0]);
        if ($first === null) {
            return;
        }

        $this->repository->reorder($first->sectionId, $first->scoutYearId, $orderedIds);
    }

    public function delete(int $documentId, ?int $actorId): void
    {
        $document = $this->requireDocument($documentId);

        $this->fileStorage->delete($document->fileId);
        $this->repository->delete($documentId);

        $this->journalService->log(
            'core', 'section_document_deleted', 'info', 'Document de section supprimé',
            ['section_id' => $document->sectionId, 'scout_year_id' => $document->scoutYearId, 'section_document_id' => $documentId], $actorId
        );
    }

    /**
     * Member page read-only listing: every scout year the member was
     * active in a section that has at least one document, most recent
     * year first, years without documents omitted entirely. A section
     * that has since been hidden or deactivated still appears — past
     * membership is a historical fact (module addendum).
     *
     * @return array<int, array{section: array<string, mixed>, scout_year: array{id: int, label: string, start_date: string, end_date: string}, documents: SectionDocument[]}>
     */
    public function listForMemberPage(int $memberId): array
    {
        $periods = $this->membershipRepository->findAllForMember($memberId);
        if ($periods === []) {
            return [];
        }

        $pairs = [];
        $seen = [];
        foreach ($periods as $period) {
            $key = $period->sectionId . ':' . $period->scoutYearId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = ['section_id' => $period->sectionId, 'scout_year_id' => $period->scoutYearId];
        }

        $pairsWithDocuments = $this->repository->filterPairsWithDocuments($pairs);
        $withDocsKeys = array_map(fn(array $p) => $p['section_id'] . ':' . $p['scout_year_id'], $pairsWithDocuments);

        $result = [];
        foreach ($pairs as $pair) {
            $key = $pair['section_id'] . ':' . $pair['scout_year_id'];
            if (!in_array($key, $withDocsKeys, true)) {
                continue;
            }

            $section = $this->sectionService->getSection($pair['section_id']);
            $scoutYear = $this->scoutYearService->findById($pair['scout_year_id']);
            if ($section === null || $scoutYear === null) {
                continue;
            }

            $result[] = [
                'section' => $section,
                'scout_year' => $scoutYear,
                'documents' => $this->repository->findBySectionAndYear($pair['section_id'], $pair['scout_year_id']),
            ];
        }

        return $result;
    }

    private function requireDocument(int $documentId): SectionDocument
    {
        $document = $this->repository->findById($documentId);
        if ($document === null) {
            throw new SectionDocumentException('Document introuvable.');
        }
        return $document;
    }

    private function assertMimeTypeAllowed(string $mimeType): void
    {
        if (in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return;
        }

        if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/')) {
            throw new SectionDocumentException(
                'Les images et vidéos ne sont pas acceptées ici — utilisez le module Galerie pour les partager.'
            );
        }

        throw new SectionDocumentException(
            "Type de fichier non accepté. Formats acceptés : PDF, Word, Excel, PowerPoint, OpenDocument, texte, CSV."
        );
    }
}
