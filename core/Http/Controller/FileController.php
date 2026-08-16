<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\EncryptedFileStorageService;
use Core\File\FileAccessGuard;
use Core\File\PdfRasterizer;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Photo\ImageVariantService;
use Core\Security\AuthSession;
use Twig\Environment;

class FileController extends AbstractController
{
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private FileAccessGuard $fileAccessGuard,
        private string $storagePath,
        private EncryptedFileStorageService $encryptedFileStorageService,
        private ImageVariantService $imageVariantService
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

        // Owner-scoped files (member page private documents, §8.3) are the
        // only ones journaled on successful access — every other /files/{id}
        // hit (public assets, ordinary role-gated content) would be pure
        // noise here. member_id reference only, never personal data.
        if ($file->ownerMemberId !== null) {
            $this->journalService?->log(
                'core', 'owner_scoped_file_accessed', 'info', 'Document privé d\'un membre consulté',
                ['file_id' => $id, 'owner_member_id' => $file->ownerMemberId],
                AuthSession::getUserAccountId()
            );
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

    /**
     * GET /files/{id}/{variant} — a pre-generated derivative (Core\Photo\
     * ImageVariantService) of one of the core photo contexts, resolved
     * through the exact same guard/owner-scoping/journal path as serve()
     * — this is a rendition of the same file, not a second access path
     * (SECURITY.md §6). {variant} is validated against the fixed
     * vocabulary before it is ever used to build a filesystem path —
     * anything else is 404, and the filesystem is never touched for it.
     * No on-demand generation and no fallback to the original: a missing
     * derivative is 404, exactly like a missing PDF thumbnail above.
     *
     * @param array<string, string> $params
     */
    public function variant(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $variant = (string) ($params['variant'] ?? '');

        if ($id <= 0 || !ImageVariantService::isValidVariant($variant)) {
            return new Response('Not Found', 404);
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

        if ($file->ownerMemberId !== null) {
            $this->journalService?->log(
                'core', 'owner_scoped_file_accessed', 'info', 'Document privé d\'un membre consulté',
                ['file_id' => $id, 'owner_member_id' => $file->ownerMemberId],
                AuthSession::getUserAccountId()
            );
        }

        $path = $this->imageVariantService->resolvePath($file->relativePath, $variant);
        if ($path === null) {
            return new Response('Not Found', 404);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new Response('Not Found', 404);
        }

        // Immutable: a given file id's derivative never changes in place —
        // a re-upload always creates a new file id (see the module's own
        // "no on-demand regeneration" rule), so the URL itself only ever
        // serves one set of bytes.
        $cacheControl = $file->roleMin === 'public'
            ? 'public, max-age=31536000, immutable'
            : 'private, max-age=31536000, immutable';

        return (new Response($content))
            ->setHeader('Content-Type', 'image/webp')
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('Content-Length', (string) strlen($content));
    }
}
