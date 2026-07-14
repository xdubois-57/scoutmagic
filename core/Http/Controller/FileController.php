<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\FileAccessGuard;
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
        private string $storagePath
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

        $filePath = $this->storagePath . '/' . $file->relativePath;

        if (!file_exists($filePath)) {
            return (new Response('Not Found', 404));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return (new Response('Internal Server Error', 500));
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
}
