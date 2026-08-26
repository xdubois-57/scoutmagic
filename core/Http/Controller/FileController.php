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
use Core\Import\DeskImportFileOwnershipChecker;
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

        // A kept Desk CSV is the second, and only other, successful access
        // this journals — a deliberate extension of the owner-scoped rule
        // above (SECURITY.md §13). The reason is the file itself: names,
        // dates of birth, addresses, telephone numbers, e-mail addresses,
        // formation level and handicap for the whole unit, in clear, in
        // one document. Nothing else on this disk is that concentrated, so
        // who took a copy, and when, is worth knowing. The entry carries
        // the file id and the import id — never a line of its content.
        if ($file->ownerType === DeskImportFileOwnershipChecker::OWNER_TYPE) {
            $this->journalService?->log(
                'core',
                'desk_import_file_downloaded',
                'security',
                'Fichier CSV d\'un import Desk téléchargé',
                ['file_id' => $id, 'import_journal_id' => $file->ownerId],
                AuthSession::getUserAccountId()
            );
        }

        $isImage = str_starts_with($file->mimeType, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment; filename="' . addslashes($file->originalName) . '"';

        $cacheControl = $file->roleMin === 'public'
            ? 'public, max-age=86400'
            : 'private, no-cache';

        // A file id's content is immutable (a re-upload always mints a new
        // id — see variant() below), so the id and size are a complete
        // validator: a matching If-None-Match saves re-reading, decrypting
        // and re-sending the whole file. Without this, the 'private,
        // no-cache' answer above forced a FULL re-download of every
        // non-public document on every revisit — no-cache means
        // revalidate, and there was nothing to revalidate against.
        // Deliberately AFTER the guard and the access journals: a
        // revalidation confirms the client holds the content, so it is an
        // access like any other.
        $etag = '"f' . $file->id . '-' . $file->sizeBytes . '"';
        $ifNoneMatch = $request->getServer('HTTP_IF_NONE_MATCH');
        // mod_deflate (DeflateAlterETag AddSuffix, the default) rewrites
        // the ETag it compressed to `…-gzip"`, and the browser replays
        // exactly that — a strict comparison would then never match for
        // any content type the .htaccess deflates (SVG among them).
        if (is_string($ifNoneMatch)) {
            $ifNoneMatch = str_replace('-gzip"', '"', $ifNoneMatch);
        }
        if (is_string($ifNoneMatch) && $ifNoneMatch === $etag) {
            return (new Response('', 304))
                ->setHeader('ETag', $etag)
                ->setHeader('Cache-Control', $cacheControl);
        }

        if ($file->encrypted) {
            // An AES-256-GCM blob authenticates the whole file against a single
            // tag, so it cannot be streamed — the plaintext must exist whole in
            // memory at least once. Cap the ciphertext size up front so one
            // request can't be made to buffer an unbounded amount (audit M10).
            // Upload limits keep real encrypted files well under this.
            $cipherPath = $this->storagePath . '/' . $file->relativePath;
            $cipherSize = @filesize($cipherPath);
            if ($cipherSize !== false && $cipherSize > self::MAX_ENCRYPTED_SERVE_BYTES) {
                return (new Response('Payload Too Large', 413));
            }

            try {
                $content = $this->encryptedFileStorageService->retrieve($file->id);
            } catch (\RuntimeException) {
                return (new Response('Not Found', 404));
            }

            return (new Response($content))
                ->setHeader('Content-Type', $file->mimeType)
                ->setHeader('Content-Disposition', $disposition)
                ->setHeader('Cache-Control', $cacheControl)
                ->setHeader('ETag', $etag)
                ->setHeader('Content-Length', (string) strlen($content));
        }

        // Non-encrypted: stream straight off disk (readfile at send() time)
        // rather than reading the whole file into a PHP string (audit M10).
        $filePath = $this->storagePath . '/' . $file->relativePath;
        if (!file_exists($filePath)) {
            return (new Response('Not Found', 404));
        }

        return (new Response())
            ->setBodyFile($filePath)
            ->setHeader('Content-Type', $file->mimeType)
            ->setHeader('Content-Disposition', $disposition)
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('ETag', $etag)
            ->setHeader('Content-Length', (string) filesize($filePath));
    }

    /**
     * Ceiling on a single encrypted-file serve (audit M10). Encrypted files
     * are receipts/documents, capped far lower at upload (finance 15 MB,
     * section documents 20 MB); this is a safety bound on the memory one
     * request can force, since a GCM blob cannot be streamed.
     */
    private const MAX_ENCRYPTED_SERVE_BYTES = 128 * 1024 * 1024;

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

        // Rendering a thumbnail runs Imagick/Ghostscript, and this route is
        // role_min public, so an uncached endpoint is a repeatable CPU/RSS
        // sink (audit M8). Cache the JPEG on disk keyed by the (immutable —
        // replace() mints a new id) file id, so repeated hits serve a static
        // file. Only for NON-encrypted files: caching an encrypted file's
        // thumbnail as plaintext would defeat encryption-at-rest, and an
        // encrypted file is never anonymously reachable anyway (FileAccessGuard
        // gates it to intendant+), so its render cost is bounded by authorised
        // users rather than the public.
        $cachePath = $this->storagePath . '/' . \Core\File\PdfThumbnailCache::SUBDIRECTORY . '/' . $file->id . '.jpg';
        if (!$file->encrypted && is_file($cachePath)) {
            $cached = file_get_contents($cachePath);
            if ($cached !== false && $cached !== '') {
                return $this->jpegThumbnailResponse($cached, $file->roleMin);
            }
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

        if (!$file->encrypted) {
            $this->writeThumbnailCache($cachePath, $thumbnail);
        }

        return $this->jpegThumbnailResponse($thumbnail, $file->roleMin);
    }

    private function jpegThumbnailResponse(string $bytes, string $roleMin): Response
    {
        $cacheControl = $roleMin === 'public' ? 'public, max-age=604800' : 'private, max-age=604800';

        return (new Response($bytes))
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Cache-Control', $cacheControl)
            ->setHeader('Content-Length', (string) strlen($bytes));
    }

    /**
     * Best-effort: an unwritable cache directory (a hosting quirk) must never
     * fail the request — the thumbnail was already rendered, so a cache-write
     * failure just means the next hit re-renders. Written via a temp file +
     * rename so a concurrent reader never sees a half-written JPEG.
     */
    private function writeThumbnailCache(string $cachePath, string $bytes): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $cachePath)) {
            @unlink($tmp);
        }
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
