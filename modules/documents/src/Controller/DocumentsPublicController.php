<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Documents\File\DirectLinkGrants;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;
use Twig\Environment;

/**
 * « Notre unité › Documents », and the address that is shared.
 *
 * The page is `role_min: public` and filters its CONTENT by the reader:
 * the role floor of the route is the lowest reader, not the audience of
 * every document on it.
 */
class DocumentsPublicController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private DocumentService $documentService,
        private DirectLinkGrants $directLinkGrants
    ) {
    }

    /**
     * GET /documents
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $listing = $this->documentService->listFor(Role::fromString(AuthSession::getRole()));

        return $this->render('@documents/index.html.twig', [
            'documents' => $listing['documents'],
            'hidden_count' => $listing['hidden'],
            'reader_is_authenticated' => AuthSession::isAuthenticated(),
        ]);
    }

    /**
     * GET /documents/{slug} — the address that survives a new version.
     *
     * A redirect, never the bytes: the file is served by /files/{id},
     * through FileAccessGuard, like every other file (SECURITY.md §6).
     * So this answers the same thing whoever asks, and the guard behind it
     * decides who gets the document.
     *
     * 302 rather than 301: the target changes with every new version, and
     * a browser or a proxy that remembered a permanent redirect would
     * keep sending people to the version this address exists to replace.
     *
     * @param array<string, string> $params
     */
    public function open(Request $request, array $params): Response
    {
        $document = $this->documentService->findBySlug((string) ($params['slug'] ?? ''));
        if ($document === null) {
            return $this->notFound();
        }

        // An unlisted document's file answers only a session that came
        // through this address (File\DocumentFileOwnershipChecker): its
        // id alone, being sequential, would be found by counting.
        if ($document->visibility === DocumentVisibility::DIRECT_LINK) {
            $this->directLinkGrants->grant($document->id);
        }

        $response = $this->redirect('/files/' . $document->fileId)
            ->setHeader('Cache-Control', 'no-store');

        // Kept out of search engines server-side, not merely left out of
        // the list: an address that is indexed is offered to every search,
        // for ever (the rule ArticleService::enforceSeoRules() applies to an
        // article, for the same two visibilities and more).
        if (!$document->visibility->isIndexable()) {
            $response->setHeader('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
