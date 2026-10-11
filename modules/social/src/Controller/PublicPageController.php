<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\File\StoredFileReader;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\Gallery\Api\AlbumShareSourceInterface;
use Modules\News\Api\ArticleShareSourceInterface;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\Publication;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\PublicPageService;
use Modules\Social\Service\ShareSource;
use Twig\Environment;

/**
 * The public page of a communication, and its image (issue #706, IT-04):
 *
 * - `GET /partage/{token}` — what Facebook, WhatsApp and Messenger read to
 *   build the preview of a link somebody posted;
 * - `GET /partage/{token}/carte.jpg` — the card that preview shows.
 *
 * **It shows what was published and nothing more**: the card the browser
 * drew, the title and the text the first destination received. Not the
 * album the communication came from, not its other photos, not an
 * article's body. The album stays `identified`, and sharing one of its
 * photos in a card is not a decision to publish the album
 * (docs/chantiers/CHANTIER-medias-sociaux.md, « La page publique d'une
 * communication »).
 *
 * **This is why the image is not `/files/{id}`.** The card is stored as an
 * ordinary `files` row that {@see \Modules\Social\File\PostedCardOwnershipChecker}
 * refuses to every reader of that route, because a card can be a gallery
 * photo barely blurred and sequential ids can be guessed. Here the
 * authorisation is the token: 32 random bytes that were handed to one
 * destination with one communication, and that open one card. SECURITY.md
 * § 6 keeps that exception with its scope.
 *
 * **It is not the hour-long card route either.** {@see CardController}
 * serves Instagram a composition for sixty minutes, `no-store`, and lets
 * it expire — Meta fetches it once, while publishing. Facebook caches a
 * preview and comes back to re-read the page days later, so these two
 * addresses must still answer. Same module, opposite lifetimes.
 *
 * Every refusal is the same bare 404, whatever the reason: an answer that
 * differed would tell a guesser which tokens exist.
 */
final class PublicPageController extends AbstractController
{
    /**
     * How long a card may be cached. It is immutable by construction —
     * the token names one communication and the card of a frozen
     * communication never changes — so the longest value anything
     * respects is the right one.
     */
    private const IMAGE_MAX_AGE = 31536000;

    public function __construct(
        Environment $twig,
        private readonly PublicPageService $pages,
        private readonly PublicationRepository $publications,
        /**
         * The card's bytes. The same reader
         * {@see \Modules\Social\Service\ShareSourceResolver::frozenCard()}
         * uses, so a card whose file has gone answers null here too,
         * rather than erroring.
         */
        private readonly StoredFileReader $files,
        /**
         * Asked one content-free question each — « is the source still
         * there, and where is its page » — to decide the « Voir sur le
         * site » button. Null when the module is off (ARCHITECTURE.md
         * § 7.5), and then the button is simply absent: the page still
         * shows what was published, which is the same thing it does for
         * a source that was deleted.
         */
        private readonly ?AlbumShareSourceInterface $albums = null,
        private readonly ?ArticleShareSourceInterface $articles = null,
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $communication = $this->pages->findByToken((string) ($params['token'] ?? ''));
        if ($communication === null) {
            // The site's own 404 page, not a plain-text body: a person
            // who mistyped the address, or followed one whose
            // communication is gone, lands on a readable page rather
            // than an unstyled sentence. Identical for every reason —
            // malformed, unknown, never minted — so a guesser still
            // learns nothing from it.
            return $this->notFound()->setHeader('Cache-Control', 'no-store');
        }

        $published = $this->published($communication);

        return $this->render('@social/communications/public.html.twig', [
            'title' => $published['title'],
            'body' => $published['caption'],
            'og_url' => $this->pages->urlFor($communication),
            // No image rather than an address that 404s: a card is
            // stored for every share the browser drew one for, and a
            // share from before the browser drew any has nothing to
            // show. An `og:image` pointing at a 404 is worse than none,
            // since Facebook caches the failure.
            //
            // The ROW is what is checked, not the file behind it: proving
            // the bytes are still readable would mean decrypting the card
            // on every page view, for a crawler that may not ask for the
            // image at all. A file lost under its row is a storage
            // failure, and then the image answers 404 and the preview
            // simply has none.
            'og_image_url' => $communication->cardFileId === null
                ? null
                : $this->pages->imageUrlFor($communication),
            'source_path' => $this->sourcePath($communication),
            // What the visitor will find behind that button, which only
            // an album needs said: the view branches on the kind rather
            // than carrying the sentence, so the rule lives next to the
            // markup that shows it.
            'source_kind' => $communication->sourceKind,
        ]);
    }

    /**
     * The card, from its durable address.
     *
     * @param array<string, string> $params
     */
    public function image(Request $request, array $params): Response
    {
        $communication = $this->pages->findByToken((string) ($params['token'] ?? ''));
        if ($communication === null || $communication->cardFileId === null) {
            return self::imageNotFound();
        }

        // **Only `card_file_id`, ever.** This route reads the one file the
        // chief composed and published, never the source's photo and never
        // another row: that is what keeps the rest of the album out of a
        // public address.
        $bytes = $this->files->read($communication->cardFileId);
        if ($bytes === null || $bytes === '') {
            return self::imageNotFound();
        }

        return (new Response($bytes))
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Content-Length', (string) strlen($bytes))
            // Cached hard, unlike the hour-long card route: Facebook
            // re-reads this address long after the post, and the bytes
            // behind a token never change.
            ->setHeader('Cache-Control', 'public, max-age=' . self::IMAGE_MAX_AGE . ', immutable')
            ->setHeader('X-Robots-Tag', 'noindex, noimageindex');
    }

    /**
     * What was actually published: the title and text **the first
     * destination that received it** was given.
     *
     * Not any row, and not the row's own columns. A chief may have typed
     * on in the composer after publishing, and this page is read by
     * someone who followed a link to what left — the row says what the
     * draft became, the publication says what left. For a source-backed
     * share the row's title is empty anyway, by design: nothing of the
     * album is copied into the communication.
     *
     * **Why the earliest publication and not simply the first row.**
     * Every destination of one « Publier » records the same title and
     * caption, but a destination retried days later re-reads the
     * source — so an album renamed in between leaves two rows that
     * disagree. The card the page shows was drawn once, before the first
     * destination, with the title of that moment; taking the earliest
     * `publishedAt` is what keeps the heading and the image telling the
     * same story. An attempt that never succeeded has none, and is only
     * a fallback for a page whose publications all failed.
     *
     * @return array{title: string, caption: string}
     */
    private function published(Communication $communication): array
    {
        $first = null;
        foreach ($this->publications->forSource(ShareSource::KIND_COMMUNICATION, $communication->id) as $publication) {
            if (self::precedes($publication, $first)) {
                $first = $publication;
            }
        }

        if ($first === null) {
            return ['title' => $communication->title, 'caption' => $communication->body];
        }

        return [
            'title' => $first->sourceTitle !== '' ? $first->sourceTitle : $communication->title,
            'caption' => $first->caption !== '' ? $first->caption : $communication->body,
        ];
    }

    /**
     * Whether this publication came before the best one so far — a
     * successful one before any other, and the earlier of two
     * successful ones.
     */
    private static function precedes(Publication $candidate, ?Publication $best): bool
    {
        if ($best === null) {
            return true;
        }
        if ($candidate->publishedAt === null) {
            return false;
        }

        return $best->publishedAt === null || $candidate->publishedAt < $best->publishedAt;
    }

    /**
     * Where « Voir sur le site » leads, or null — no source, a module
     * switched off, or a source deleted since all answer the same, and
     * the page then shows what was published with no button.
     */
    private function sourcePath(Communication $communication): ?string
    {
        if (!$communication->hasSource()) {
            return null;
        }

        return match ($communication->sourceKind) {
            ShareSource::KIND_ALBUM => $this->albums?->pagePath((int) $communication->sourceId),
            ShareSource::KIND_ARTICLE => $this->articles?->pagePath((int) $communication->sourceId),
            default => null,
        };
    }

    /**
     * An image route's refusal, plain and uncacheable — the site's 404
     * PAGE would be an HTML document answered to an `<img>`.
     */
    private static function imageNotFound(): Response
    {
        return (new Response('Not Found', 404))
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store');
    }
}
