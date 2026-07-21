<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\PdfRasterizer;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Twig\Environment;

class FileController extends AbstractController
{
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private FileAccessGuard $fileAccessGuard,
        private string $storagePath,
        private EncryptedFileStorageService $encryptedFileStorageService
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * GET /files/{id} — serve a file through the access guard.
     *
     * @param array<string, string> $params
     */
    public function serve(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            return (new Response('Not Found', 404));
        }

        $file = $this->fileAccessGuard->check($id);

        if ($file === null) {
            $this->journalService?->log(
                'core', 'file_access_denied', 'security', 'Accès à un fichier refusé',
                ['file_id' => $id, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
                AuthSession::getUserAccountId()
            );
            return (new Response('Forbidden', 403));
        }

        if ($file->encrypted) {
            try {
                $content = $this->encryptedFileStorageService->retrieve($file->id);
            } catch (\RuntimeException) {
                return (new Response('Not Found', 404));
            }
        } else {
            $filePath = $this->storagePath . '/' . $file->relativePath;

            if (!file_exists($filePath)) {
                return (new Response('Not Found', 404));
            }

            $content = file_get_contents($filePath);
            if ($content === false) {
                return (new Response('Internal Server Error', 500));
            }
        }

        $isImage = str_starts_with($file->mimeType, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment; filename="' . addslashes($file->originalName) . '"';

        $cacheControl = $file->roleMin === 'public'
            ? 'public, max-age=86400'
            : 'private, no-cache';

        return (new Response($content))
            ->setHeader('Content-Type', $file->mimeType)
            ->setHeader('Content-Disposition', $disposition)
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('Content-Length', (string) strlen($content));
    }

    /**
     * GET /files/{id}/thumbnail — a JPEG rendering of a PDF's first page,
     * so a grid of receipts can show a real preview instead of a generic
     * icon (module spec follow-up). Only PDFs need this: images already
     * work fine as their own thumbnail via serve(). Never cached
     * server-side (Task\ExtractReceiptDataHandler doesn't persist a
     * rasterized copy either) — cheap enough on demand, and the response
     * itself is cacheable long-term since a given file id's content never
     * changes (replace() always creates a new attachment/file id).
     *
     * @param array<string, string> $params
     */
    public function thumbnail(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            return (new Response('Not Found', 404));
        }

        $file = $this->fileAccessGuard->check($id);
        if ($file === null) {
            return (new Response('Forbidden', 403));
        }

        if ($file->mimeType !== 'application/pdf') {
            return (new Response('Unsupported Media Type', 415));
        }

        if ($file->encrypted) {
            try {
                $content = $this->encryptedFileStorageService->retrieve($file->id);
            } catch (\RuntimeException) {
                return (new Response('Not Found', 404));
            }
        } else {
            $filePath = $this->storagePath . '/' . $file->relativePath;
            $content = file_exists($filePath) ? file_get_contents($filePath) : false;
            if ($content === false) {
                return (new Response('Not Found', 404));
            }
        }

        $thumbnail = (new PdfRasterizer())->firstPageToJpeg($content);
        if ($thumbnail === null) {
            return (new Response('Not Found', 404));
        }

        $cacheControl = $file->roleMin === 'public' ? 'public, max-age=604800' : 'private, max-age=604800';

        return (new Response($thumbnail))
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('Content-Length', (string) strlen($thumbnail));
    }
}
