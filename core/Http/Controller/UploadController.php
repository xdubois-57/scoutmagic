<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Twig\Environment;

class UploadController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private UploadHandler $uploadHandler,
        private EditableContentService $editableContentService
    ) {
    }

    /**
     * GET /upload — render the upload page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = (string) $request->getQuery('context', '');
        $key = (string) $request->getQuery('key', '');
        $returnUrl = (string) $request->getQuery('return', '/');

        return $this->render('upload/index.html.twig', [
            'context' => $context,
            'key' => $key,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * POST /upload — handle the upload.
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (!CsrfGuard::validateToken($csrf)) {
            return (new Response('', 403))->setBody('Forbidden: invalid CSRF token.');
        }

        $context = (string) $request->getBody('context', '');
        $key = (string) $request->getBody('key', '');
        $returnUrl = (string) $request->getBody('return_url', '/');

        $uploadedFile = $request->getFile('file');
        if ($uploadedFile === null) {
            // Try camera input
            $uploadedFile = $request->getFile('file_camera');
        }

        if ($uploadedFile === null) {
            FlashMessage::set('error', 'Aucun fichier sélectionné.');
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }

        try {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5 MB

            $fileId = $this->uploadHandler->handle(
                $uploadedFile,
                'core/editable_contents',
                $allowedMimes,
                $maxSize,
                'public',
                null,
                AuthSession::getUserAccountId()
            );

            // For editable_image context, update the editable content record
            if ($context === 'editable_image' && $key !== '') {
                $userId = AuthSession::getUserAccountId();
                if ($userId !== null) {
                    $this->editableContentService->set($key, (string) $fileId, 'image', $userId);
                }
            }

            FlashMessage::set('success', 'Fichier téléchargé avec succès.');

            return $this->redirect($returnUrl);
        } catch (UploadException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/upload?context=' . urlencode($context) . '&key=' . urlencode($key) . '&return=' . urlencode($returnUrl));
        }
    }
}
