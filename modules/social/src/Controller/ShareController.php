<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Modules\Social\Card\CardException;
use Modules\Social\Card\CardService;
use Modules\Social\Service\DestinationStates;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\ShareSource;
use Modules\Social\Service\ShareSourceResolver;
use Twig\Environment;

/**
 * « Partager » an album or an article: the confirmation page, the image it
 * shows, and the publication.
 *
 * **The page shows the card itself** — blur, title and address included —
 * composed the way the publication will compose it: approving one image
 * and publishing another is the one serious mistake possible here. It says
 * in so many words that the publication is public, outside the site, and
 * that nobody can take it back from ScoutMagic.
 *
 * **Every destination shows its state.** Already published: ticked,
 * greyed, with its date. Failed: the reason, and a retry that asks to be
 * confirmed. The server does not trust the page on any of it: the « once
 * per destination » rule is {@see PublishingService}'s.
 *
 * Who may share is the gallery's and the news module's rule, answered
 * through their `Api` ({@see ShareSourceResolver}): a chief who does not
 * manage the album, or may not edit the article, gets a 404 like anyone
 * asking for something that is not there.
 */
final class ShareController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private readonly ShareSourceResolver $sources,
        private readonly PublishingService $publishing,
        private readonly DestinationStates $states,
        private readonly CardService $cards
    ) {
        parent::__construct($twig);
    }

    /** @param array<string, string> $params */
    public function showAlbum(Request $request, array $params): Response
    {
        return $this->page($this->album($params));
    }

    /** @param array<string, string> $params */
    public function showArticle(Request $request, array $params): Response
    {
        return $this->page($this->article($params));
    }

    /** @param array<string, string> $params */
    public function previewAlbum(Request $request, array $params): Response
    {
        return $this->preview($this->album($params));
    }

    /** @param array<string, string> $params */
    public function previewArticle(Request $request, array $params): Response
    {
        return $this->preview($this->article($params));
    }

    /** @param array<string, string> $params */
    public function publishAlbum(Request $request, array $params): Response
    {
        return $this->publish($request, $this->album($params), '/partage/album/' . (int) ($params['id'] ?? 0));
    }

    /** @param array<string, string> $params */
    public function publishArticle(Request $request, array $params): Response
    {
        return $this->publish($request, $this->article($params), '/partage/actualite/' . (int) ($params['id'] ?? 0));
    }

    private function page(?ShareSource $source): Response
    {
        if ($source === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@social/share/index.html.twig', [
            'source' => $source,
            'self_path' => $this->selfPath($source),
            'destinations' => $this->states->forSource($source),
            'facebook_link' => $source->link !== null,
            'max_caption' => PublishingService::CAPTION_MAX_LENGTH,
        ]);
    }

    private function preview(?ShareSource $source): Response
    {
        if ($source === null || $source->image === null || $source->image === '') {
            return new Response('Not Found', 404);
        }

        try {
            $jpeg = $this->cards->preview($source->image, $source->title, $source->address, $source->imageFromGallery);
        } catch (CardException) {
            return new Response('Not Found', 404);
        }

        return (new Response($jpeg))
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Cache-Control', 'private, no-store');
    }

    private function publish(Request $request, ?ShareSource $source, string $selfPath): Response
    {
        if (($guard = $this->guardCsrf($request, $selfPath)) !== null) {
            return $guard;
        }
        if ($source === null) {
            return new Response('Not Found', 404);
        }

        [$destinations, $retries] = DestinationStates::requested(
            $request->getBody('destinations', []),
            $request->getBody('retry', [])
        );
        if ($destinations === []) {
            FlashMessage::set('error', 'Cochez au moins une destination.');

            return $this->redirect($selfPath);
        }

        $outcomes = $this->publishing->publish(
            $source,
            $destinations,
            (string) $request->getBody('caption', ''),
            $retries,
            AuthSession::getUserAccountId(),
            new \DateTimeImmutable()
        );

        [$type, $message] = DestinationStates::summary($outcomes);
        FlashMessage::set($type, $message);

        return $this->redirect($selfPath);
    }


    /**
     * @param array<string, string> $params
     */
    private function album(array $params): ?ShareSource
    {
        return $this->sources->album(
            (int) ($params['id'] ?? 0),
            AuthSession::getRole(),
            AuthSession::getEmail() ?? ''
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function article(array $params): ?ShareSource
    {
        return $this->sources->article(
            (int) ($params['id'] ?? 0),
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId()
        );
    }

    private function selfPath(ShareSource $source): string
    {
        return ($source->kind === ShareSource::KIND_ALBUM ? '/partage/album/' : '/partage/actualite/') . $source->id;
    }

}
