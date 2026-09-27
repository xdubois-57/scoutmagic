<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\File\Held\HeldDocumentService;
use Core\File\Held\OpenedDocument;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * The two routes of a document the installed application put aside
 * (Core\File\Held\HeldDocumentService, issue #502).
 *
 *  - GET /document/{token} — « Ouvrir dans le navigateur ». The phone's
 *    browser has no session of the app, so this one needs none: the key
 *    is the whole authorisation, and it works once, for five minutes.
 *  - GET /document/telecharger/{token} — « Télécharger » and the image
 *    preview, inside the app, for the session that caused the hold only.
 *
 * Every refusal is the same bare 404, whatever the reason: an answer that
 * differed would tell a guesser which keys once existed.
 */
final class HeldDocumentController extends AbstractController
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /** @var \Closure(): string */
    private \Closure $sessionId;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param (\Closure(): string)|null $sessionId
     */
    public function __construct(
        Environment $twig,
        private readonly HeldDocumentService $documents,
        ?\Closure $clock = null,
        ?\Closure $sessionId = null
    ) {
        parent::__construct($twig);
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
        $this->sessionId = $sessionId ?? static fn(): string => (string) session_id();
    }

    /**
     * @param array<string, string> $params
     */
    public function browser(Request $request, array $params): Response
    {
        $document = $this->documents->openInBrowser((string) ($params['token'] ?? ''), ($this->clock)());

        // Inline: the browser shows a PDF or an image itself, and offers
        // to save anything it cannot show.
        return $document === null ? self::bareNotFound() : self::serve($document, 'inline');
    }

    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $document = $this->documents->openInApp(
            (string) ($params['token'] ?? ''),
            ($this->sessionId)(),
            ($this->clock)()
        );

        return $document === null ? self::bareNotFound() : self::serve($document, 'attachment');
    }

    private static function serve(OpenedDocument $document, string $disposition): Response
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $document->name);

        return (new Response($document->content))
            ->setHeader('Content-Type', $document->mimeType)
            ->setHeader('Content-Length', (string) strlen($document->content))
            ->setHeader(
                'Content-Disposition',
                $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($document->name)
            )
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Robots-Tag', 'noindex, noimageindex');
    }

    private static function bareNotFound(): Response
    {
        return (new Response('Not Found', 404))
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store');
    }
}
