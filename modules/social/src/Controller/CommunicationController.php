<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\File\UploadException;
use Core\File\EncryptedFileStorageService;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Api\PhotoPickerInterface;
use Modules\Social\Api\DirectShare;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardException;
use Modules\Social\Card\CardService;
use Modules\Social\Card\ReceivedCard;
use Modules\Social\File\PostedCardOwnershipChecker;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\Publication;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\DestinationStates;
use Modules\Social\Service\GroupPublishingService;
use Modules\Social\Service\PublicPageService;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\PublishRequest;
use Modules\Social\Service\ShareHistory;
use Modules\Social\Service\ShareSource;
use Modules\Social\Service\ShareSourceResolver;
use Twig\Environment;

/**
 * « Médias sociaux » (espace chefs): the ONE composer everything is
 * published through, and « Ce qui est parti », the history of everything
 * that left, destination by destination, with its retry.
 *
 * **One composer, two ways in** (docs/chantiers/CHANTIER-medias-sociaux.md,
 * IT-01). From nothing: an image of its own — a gallery photo or an upload
 * — a title written on it, a text. Or from « Partager » on an album or an
 * article, which opens the same page prefilled, with the image and the
 * title coming from that source and locked, and no address field anywhere:
 * the link is the source's, never typed.
 *
 * **A real page, in the mockup's order**: the image as it will be
 * published, the two buttons that change it (gallery or upload, and only
 * when the image is this communication's own), the title written on the
 * image, the text, then the destinations. The image is always required —
 * the card says so, and PublishingService refuses without one.
 *
 * **There is no « Enregistrer ».** Everything is saved by « Publier ». A
 * round trip to the gallery or an upload keeps the draft by itself, with
 * no button and no mention of it, and nothing is published or frozen
 * before « Publier » (IT-01). Publishing then returns to the history,
 * which is where what you just did is visible.
 *
 * **The TEXT is frozen once it left.** As soon as one destination has been
 * tried, the text no longer changes: a retry, and a destination published
 * later, receive the caption the first one did ({@see self::frozenCaption()}).
 * A communication with an image of its OWN freezes with it — `isFrozen()`
 * closes the gallery and upload buttons, and its image and title are its own
 * columns.
 *
 * **A source-backed one freezes nothing else, and that is visible to the
 * public.** Its title and image are the source's, read again at every use
 * ({@see ShareSourceResolver}), so an album renamed or its cover swapped
 * between two destinations sends the new title and image to the later one
 * while the first keeps what it got. The composer says so rather than
 * promising otherwise, and issue #706 closes it in IT-02, where the browser
 * sends the image at « Publier » and the image received is what is frozen.
 *
 * A communication is its author's, or an administrator's
 * (ShareSourceResolver::editableCommunication()); anyone else gets a 404.
 * The history is every chief's to read; a retry from it asks the source's
 * own rule again, through the resolver.
 */
final class CommunicationController extends AbstractController
{
    public const HISTORY_PATH = '/medias-sociaux';

    public const HISTORY_SIZE = 30;
    public const TITLE_MAX_LENGTH = 120;

    /** Where the cards the browser posts are kept, under the site's storage. */
    private const CARD_DIRECTORY = 'social/published-cards';

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param array<int, int> $linkedMemberIds the members this session is linked to
     */
    public function __construct(
        Environment $twig,
        private readonly CommunicationRepository $communications,
        private readonly ShareSourceResolver $sources,
        private readonly DestinationStates $states,
        private readonly PublicationRepository $publications,
        private readonly ConnectionRepository $connections,
        private readonly CardService $cards,
        private readonly UploadHandler $uploads,
        /**
         * Where the card the browser posted is kept, byte for byte
         * (issue #706, IT-02).
         *
         * Not through {@see UploadHandler}, deliberately: it strips EXIF
         * by decoding and re-encoding the image, which would re-compress
         * a card that is already a clean canvas export with no metadata
         * at all — and « what you saw is what left » cannot survive a
         * second pass through an encoder. {@see ReceivedCard} does the
         * checking that `UploadHandler` would have done, and more
         * strictly.
         */
        private readonly EncryptedFileStorageService $cardFiles,
        private readonly UserAccountRepository $accounts,
        /**
         * Where the link a chief copies or hands to the phone comes from
         * (issue #706, IT-05) — and the only thing that creates that
         * page, which is why the route answering it is a POST.
         */
        private readonly PublicPageService $publicPages,
        /**
         * What this SOURCE has already been through (issue #706, IT-05):
         * the block at the head of the composer, and one sentence per
         * destination for the confirmation.
         */
        private readonly ShareHistory $shareHistory,
        private readonly ?PhotoPickerInterface $photos = null,
        private readonly array $linkedMemberIds = []
    ) {
        parent::__construct($twig);
    }

    // ————— « Ce qui est parti » —————

    /** @param array<string, string> $params */
    public function history(Request $request, array $params): Response
    {
        $groups = $this->publications->recentSources(self::HISTORY_SIZE);
        $authorIds = [];
        foreach ($groups as $group) {
            foreach ($group['publications'] as $publication) {
                if ($publication->userAccountId !== null) {
                    $authorIds[] = $publication->userAccountId;
                }
            }
        }
        $names = $this->accounts->findNamesByIds($authorIds);
        $staleBefore = (new \DateTimeImmutable())->modify('-' . PublishingService::STALE_MINUTES . ' minutes');
        $platforms = $this->platformsShown();

        $entries = [];
        foreach ($groups as $group) {
            $entries[] = $this->historyEntry($group, $platforms, $names, $staleBefore);
        }

        return $this->render('@social/communications/history.html.twig', ['entries' => $entries]);
    }

    // ————— A free communication —————

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        return $this->editor(null);
    }

    /**
     * The same composer, opened by « Partager » on an album or an article
     * and prefilled from it.
     *
     * **No row is created here.** A click on « Partager » is not a
     * decision to publish anything, and creating one per click would leave
     * a trail of empty communications behind every look at the page. The
     * source travels in the form and is checked again, against the owning
     * module's own rule, when « Publier » creates the row.
     *
     * @param array<string, string> $params
     */
    public function createFromSource(Request $request, array $params): Response
    {
        $kind = (string) ($params['kind'] ?? '');
        $source = $this->sources->describeSource(
            $kind,
            (int) ($params['id'] ?? 0),
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId(),
            AuthSession::getEmail()
        );
        if ($source === null) {
            return new Response('Not Found', 404);
        }

        return $this->editor(null, $source);
    }

    /** @param array<string, string> $params */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::HISTORY_PATH . '/nouvelle')) !== null) {
            return $guard;
        }

        // The source the form carries is never taken on trust: it is
        // described again, through the owning module's own Api, so a
        // forged album id answers 404 exactly as asking for that album
        // directly would.
        $kind = $this->nullableSourceKind($request);
        $sourceId = $kind === null ? null : (int) $request->getBody('source_id', 0);
        if ($kind !== null && $this->describedSource($kind, (int) $sourceId) === null) {
            return new Response('Not Found', 404);
        }

        // **A replayed POST must not publish the album twice**, and since
        // IT-05 the database is what says so: `create()` either inserts
        // or hits `uq_social_communications_twin` and hands back the row
        // that is already there (issue #793). Creating the row and
        // publishing it happen in one request, and the publication is
        // keyed on the new row's own id — so a second identical POST
        // making a second row would sail past the unique constraint that
        // stops a second publication. Measured before any of this: two
        // rows, two publications, two Facebook posts.
        //
        // The guard stopped the duplicate ROW and nothing else, and that
        // has not changed: the request is carried out against whichever
        // row comes back, exactly as a second « Publier » on a saved
        // communication is. A destination already sent is refused by
        // `social_publications`' own key, with the sentence that says so,
        // and a destination this click asks for and that one did not —
        // the chief who shared to Facebook and then, straight away, to a
        // discussion group — goes out. Answering the row never means
        // « drop this »: deciding that from the text alone would have
        // thrown the second share away in silence, since the caption the
        // composer prefills is the same both times (raised in review on
        // the pull request for IT-01).
        //
        // What IT-05 changed is the window. It was a SELECT before the
        // INSERT over 120 seconds, which closed the sequential replay and
        // left two simultaneous POSTs both passing; the key closes both,
        // and makes the refusal permanent — the chantier's own rule,
        // since the same source with a DIFFERENT message is a new
        // communication, allowed with a warning.
        $id = $this->communications->create(
            // A source-backed communication has no title of its own: the
            // card's title IS the source's, read at every use, so writing
            // a copy here would be a second answer able to disagree with
            // it (Repository\Communication).
            $kind === null ? self::title($request) : '',
            self::body($request),
            AuthSession::getUserAccountId(),
            new \DateTimeImmutable(),
            $kind,
            $sourceId
        );
        $communication = $this->communications->find($id);
        \assert($communication !== null);

        return $this->act($request, $communication);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params): Response
    {
        $communication = $this->mine($params);

        return $communication === null ? new Response('Not Found', 404) : $this->editor($communication);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $communication = $this->mine($params);
        if (($guard = $this->guardCsrf($request, self::path($communication))) !== null) {
            return $guard;
        }
        if ($communication === null) {
            return new Response('Not Found', 404);
        }

        if (!$this->isFrozen($communication)) {
            $kept = $this->communications->updateText(
                $communication->id,
                $communication->hasSource() ? $communication->title : self::title($request),
                self::body($request),
                new \DateTimeImmutable()
            );
            // **Refused: this text is already another communication of
            // the same source** (the twin key, issue #793). Going on with
            // the old text would publish something the chief did not
            // write, so the draft is left as it is and the page says why.
            if (!$kept) {
                FlashMessage::set('error', self::twinRefusal($communication));

                return $this->redirect(self::path($communication));
            }
            $communication = $this->communications->find($communication->id) ?? $communication;
        }

        return $this->act($request, $communication);
    }

    /** @param array<string, string> $params */
    public function preview(Request $request, array $params): Response
    {
        return $this->cardOf($this->source($params), $this->frozenShare($params));
    }

    /**
     * The card of an album or an article, for a composer that has no row
     * yet — keyed on the SOURCE rather than on a communication.
     *
     * It exists because « Partager » writes nothing on opening: without
     * it the prefilled composer had no image to show, and since the
     * gallery and upload buttons are absent there, « Publier » was the
     * only button on the page — so the first POST both created the row and
     * published it, and a chief committed to an irreversible public post
     * without ever seeing the card. The page that this composer replaced
     * showed that image, and SECURITY.md promises it.
     *
     * The source's own rule is asked again here, as everywhere else: an
     * album this caller may not share has no card to look at either.
     *
     * @param array<string, string> $params
     */
    public function previewSource(Request $request, array $params): Response
    {
        // Never frozen: there is no communication here to have published
        // anything, and so no kept card to prefer over a fresh one.
        return $this->cardOf(
            $this->describedSource(
                (string) ($params['kind'] ?? ''),
                (int) ($params['id'] ?? 0)
            ),
            false
        );
    }

    /**
     * The BACKGROUND of the card, as the source's own bytes — what the
     * browser draws the card from (issue #706, IT-02).
     *
     * **Why a second pair of image routes.** `preview()` serves the card
     * already composed, which was all the page needed while the server
     * composed it. From IT-02 the browser composes it, so it needs the
     * photo itself: the title moves as the chief types and the blur moves
     * with a slider, and a round trip per frame is exactly the waiting
     * the chantier rules out.
     *
     * **It is the same access, not a new one.** The source's own rule is
     * asked again here, through the owning module's `Api`, exactly as
     * every other read does — an album this caller may not share has no
     * background to fetch either. A chief who may see that album can
     * already open the photo in the gallery; what is new is that the
     * social module serves it too, and it serves it UNBLURRED, because
     * the blur is now the browser's to apply and the chief's to choose.
     * That is written down in SECURITY.md rather than left to be noticed.
     *
     * @param array<string, string> $params
     */
    public function background(Request $request, array $params): Response
    {
        return $this->backgroundOf($this->source($params), $this->frozenShare($params));
    }

    /**
     * The same, for a composer « Partager » has not saved yet — keyed on
     * the source, like `previewSource()` and for the same reason.
     *
     * @param array<string, string> $params
     */
    public function backgroundSource(Request $request, array $params): Response
    {
        // Never frozen, as in `previewSource()` above.
        return $this->backgroundOf(
            $this->describedSource(
                (string) ($params['kind'] ?? ''),
                (int) ($params['id'] ?? 0)
            ),
            false
        );
    }

    /**
     * The source's image bytes, or 404 — for something with no image,
     * something this caller may not see, and bytes that are not one of
     * the three formats the card accepts alike. The type is sniffed from
     * the bytes rather than taken from anything the request said: the only
     * thing that decides what is served is what the owning module handed
     * over.
     */
    private function backgroundOf(?ShareSource $source, bool $frozen): Response
    {
        if ($source === null || $source->image === null || $source->image === '') {
            return new Response('Not Found', 404);
        }

        // A FROZEN share has nothing left to draw: the kept card IS the
        // image, and handing the canvas the album's current cover would
        // let it draw over the frozen one. Refused rather than served, so
        // the composer keeps the <img> `cardOf()` serves — which is that
        // kept card. Found in the review of #850, with `cardOf()` above.
        //
        // **On the freeze, not on « a card is stored ».** Keying this on
        // `$source->card` was the next defect in the same review, and it
        // closed the composer of a share that is still the chief's to
        // edit: `refreshPostedCard()` stores the card BEFORE publishing, so
        // an attempt refused without claiming a row — an expired Meta
        // token, a caption past Instagram's limit — leaves a card stored
        // with `isFrozen()` still false. This route then answered 404,
        // the canvas had no photo, `canDraw()` stayed false forever, and
        // the next « Publier » posted no card at all. That last step no
        // longer publishes the refused card — `dropStoredCard()` forgets
        // it, the third and last fix of this one rule in the same review
        // — but the composer was closed all the same, and a chief who
        // cannot see the card they are about to publish is the thing
        // this route exists to prevent.
        if ($frozen) {
            return new Response('Not Found', 404);
        }

        $type = self::imageType($source->image);
        if ($type === null) {
            return new Response('Not Found', 404);
        }

        return (new Response($source->image))
            ->setHeader('Content-Type', $type)
            // Never a shared cache, and never a disk: the same address
            // answers a different album to a different chief, and an
            // unblurred gallery photo is not something to leave behind
            // in a proxy (as `cardOf()` already decided for the card).
            ->setHeader('Cache-Control', 'private, no-store')
            // The bytes are an image and nothing else, whatever they
            // happen to contain: a module that handed over an HTML file
            // must not get it rendered as one.
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The media type of these bytes, among the three the card accepts, or
     * null.
     *
     * Read from the magic bytes, not from a file name or a declared type:
     * what reaches here came from another module's `Api`, and the card is
     * drawn by `<canvas>`, which decodes by content too.
     */
    private static function imageType(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    /**
     * The composed card as a JPEG, or 404 — for something with no image,
     * something this caller may not see, and a composition that failed
     * alike: a chief who may not look at it learns nothing from which.
     */
    private function cardOf(?ShareSource $source, bool $frozen): Response
    {
        if ($source === null) {
            return new Response('Not Found', 404);
        }

        // **The kept card wins, as it does at publication.** Composing
        // again from the live source would show the album's CURRENT
        // title and cover, so a share frozen before the album was
        // renamed displayed a card that is not the one a retry
        // re-sends — the page saying « l'image et le texte ne changent
        // plus » directly above it. `PublishingService::cardBytes()`
        // already reads this first; the preview did not. Found in the
        // review of #850.
        //
        // **The kept card is served only where the source's own image
        // is, and that condition is the whole access rule.** This route
        // serves the card BEFORE the image-null check that used to
        // answer 404, and `editableCommunication()` asks the gallery
        // nothing — the row is this chief's whatever became of the
        // album. So without this, a chief who lost access went on
        // fetching the card: a gallery photo under a veil, and at
        // « Net » no veil at all.
        //
        // **Both producers of a `ShareSource`, not one.** The review of
        // #850 found this twice: first on a source-backed share, where
        // `describeSource()` answers null for an album deleted,
        // delegated, migrating or out of reach; then on a communication
        // with its OWN gallery photo, where `photoContents()` answers
        // null once `mayView()` fails. I fixed the first producer and
        // did not look at the second. Keying on `image` covers both, and
        // every future one, because « the caller cannot see the source's
        // image » is exactly when the card must not be served.
        //
        // A frozen share whose photo is merely GONE loses its preview
        // too, and that is the right side to err on: the two causes are
        // indistinguishable here, SECURITY.md § 6 promises one 404 for
        // all of them, and the publication still goes out on the kept
        // card ({@see ShareSource::hasImageToPublish()}) — what this
        // refuses is handing the bytes to an HTTP caller, not sending
        // them where the chief already asked.
        //
        // **Only once it is frozen**, for the reason `backgroundOf()`
        // spells out: before that the share is still the chief's to
        // edit, and a card stored by a refused attempt is not what a
        // later attempt will send. `PublishingService::cardBytes()` does
        // read the stored card whether or not the share is frozen, and
        // must: the FIRST publication is the one that posts a card and
        // has no publication row yet.
        if ($frozen && $source->card !== null && $source->card !== ''
            && $source->image !== null && $source->image !== '') {
            return (new Response($source->card))
                ->setHeader('Content-Type', ReceivedCard::MIME)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($source->image === null || $source->image === '') {
            return new Response('Not Found', 404);
        }

        try {
            $jpeg = $this->cards->compose(
                $source->image,
                $source->title,
                $source->address,
                $source->imageFromGallery,
                // So the fallback preview and the card the browser draws
                // agree about the slider's position (issue #706, IT-02).
                $source->blurRatio
            );
        } catch (CardException) {
            return new Response('Not Found', 404);
        }

        return (new Response($jpeg))
            ->setHeader('Content-Type', 'image/jpeg')
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /** @param array<string, string> $params */
    public function picker(Request $request, array $params): Response
    {
        $communication = $this->mine($params);
        if ($communication === null || $this->photos === null || !$this->mayChangeImage($communication)) {
            return new Response('Not Found', 404);
        }

        return $this->render('@social/communications/picker.html.twig', [
            'communication' => $communication,
            'photos' => $this->photos->pickablePhotos(AuthSession::getRole(), $this->linkedMemberIds),
        ]);
    }

    /** @param array<string, string> $params */
    public function pick(Request $request, array $params): Response
    {
        $communication = $this->mine($params);
        if (($guard = $this->guardCsrf($request, self::path($communication))) !== null) {
            return $guard;
        }
        if ($communication === null || $this->photos === null || !$this->mayChangeImage($communication)) {
            return new Response('Not Found', 404);
        }

        // Only a photo the picker offered this very caller: the gallery's
        // rule, not an id the form could name.
        $mediaId = (int) $request->getBody('media_id', 0);
        $offered = array_map(
            static fn ($photo): int => $photo->mediaId,
            $this->photos->pickablePhotos(AuthSession::getRole(), $this->linkedMemberIds)
        );
        if (!in_array($mediaId, $offered, true)) {
            FlashMessage::set('error', 'Cette photo ne fait pas partie de celles proposées.');

            return $this->redirect(self::path($communication) . '/photo');
        }

        $this->communications->useGalleryPhoto($communication->id, $mediaId, new \DateTimeImmutable());

        return $this->redirect(self::path($communication));
    }


    // ————— Retry from the history —————

    /** @param array<string, string> $params */
    public function confirmRetry(Request $request, array $params): Response
    {
        $retry = $this->retryContext($params);
        if ($retry === null) {
            return new Response('Not Found', 404);
        }

        $view = $retry + ['self_path' => self::retryPath($retry['source'], $retry['key'])];

        return $this->render('@social/communications/retry.html.twig', $view);
    }

    /** @param array<string, string> $params */
    public function retry(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::HISTORY_PATH)) !== null) {
            return $guard;
        }
        $retry = $this->retryContext($params);
        if ($retry === null) {
            return new Response('Not Found', 404);
        }

        $groupId = GroupPublishingService::groupIdOf($retry['key']);
        $outcomes = $this->states->publish(
            $retry['source'],
            $groupId === null
                ? new PublishRequest([$retry['platform']], [$retry['platform']])
                : new PublishRequest([], [], [$groupId], [$groupId]),
            $retry['failed']->caption,
            AuthSession::getEmail(),
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            new \DateTimeImmutable()
        );
        [$type, $message] = DestinationStates::summary($outcomes);
        FlashMessage::set($type, $message);

        return $this->redirect(self::HISTORY_PATH);
    }

    // ————— Sharing by hand, from the history —————

    /**
     * **The address of this communication's public page, creating it if
     * this is the first share that needs one** (issue #706, IT-05).
     *
     * Answered to the share icon of the history, which needs the link
     * before it can hand it to the phone's share sheet or to the
     * clipboard. A POST and not a GET, because answering it CREATES the
     * page.
     *
     * **It records nothing and freezes nothing**, and that is why it is a
     * second route rather than one: a share sheet the chief dismisses is
     * not a share, and the chantier says so in as many words. The history
     * row and the freeze come from {@see share()}, called once the
     * gesture has gone through.
     *
     * The page it mints for a sheet nobody completed stays online,
     * unreachable by anyone who was never handed the token — the cost of
     * asking for a link, and the reason nothing but an explicit gesture
     * reaches this.
     *
     * @param array<string, string> $params
     */
    public function link(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }
        $communication = $this->mine($params);
        if ($communication === null) {
            return $this->json(['success' => false, 'error' => 'Communication introuvable.'], 404);
        }

        $url = $this->publicPages->ensureUrlFor($communication->id, new \DateTimeImmutable());
        if ($url === null) {
            return $this->json([
                'success' => false,
                'error' => 'Renseignez d\'abord l\'adresse du site dans Configuration > Réglages.',
            ], 409);
        }

        return $this->json(['success' => true, 'url' => $url]);
    }

    /**
     * **This share happened** — the sheet was carried through, or the
     * link reached the clipboard (issue #706, IT-05).
     *
     * Recorded as a publication like any other, against its own
     * destination key, which is what makes the history show it AND what
     * freezes the communication: `isFrozen()` is « this communication has
     * at least one publication ». The chantier asks for every share to
     * freeze, the link copy included — the applications re-read the
     * public page at every display, so a card changed afterwards would
     * not be the one the recipients were sent.
     *
     * Called only after the browser's own promise resolved, so a
     * dismissed sheet reaches nothing here. The site never claims the
     * message was SENT: it knows the link left, and no answer comes back.
     *
     * @param array<string, string> $params
     */
    public function share(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrfJson($request)) !== null) {
            return $guard;
        }
        $communication = $this->mine($params);
        // **Read from the body stream, not from `getBody()`.** The one
        // caller is `ScoutMagicApi.postJson()`, which sends
        // `Content-Type: application/json`, and PHP fills `$_POST` for a
        // form body only — so `getBody('kind')` answered '' on every real
        // request and this route refused every share ever made from a
        // browser. The CSRF token came through the `X-CSRF-Token` header
        // and hid nothing of it; the controller test passed because it
        // built the `Request` with a parsed body, which is a shape no
        // transport produces. `Claude review` found it on PR #868. The
        // rest of the site decodes the raw body for the same reason
        // (`Core\Http\Controller\PushSubscriptionController`).
        $payload = json_decode($request->getRawBody(), true);
        $asked = is_array($payload) ? ($payload['kind'] ?? null) : null;
        $kind = is_string($asked) ? DirectShare::tryFrom($asked) : null;
        if ($communication === null || $kind === null) {
            return $this->json(['success' => false, 'error' => 'Partage inconnu.'], 404);
        }

        $now = new \DateTimeImmutable();
        // The caption and title are frozen with the rest, so the row
        // carries what the public page shows rather than the draft's
        // current text.
        // **`retry: true`, so a row left `pending` repairs itself.**
        // `claim()` and `markPublished()` are two statements: a request
        // that dies between them leaves a `pending` row which freezes the
        // communication, shows no date and offers no « Réessayer » —
        // there is nothing to retry for a gesture. Without this flag
        // every later gesture answered `recorded: false` and the row
        // stayed broken for good. With it, clicking share again past
        // `STALE_MINUTES` takes the row over and finishes it; the gesture
        // IS the confirmation a retry needs, there being no form to tick.
        // A `published` row is still never re-claimed, so the same share
        // twice is recorded once. CodeRabbit on PR #868.
        $claimed = $this->publications->claim(
            ShareSource::KIND_COMMUNICATION,
            $communication->id,
            $kind->value,
            true,
            AuthSession::getUserAccountId(),
            $now,
            $now->modify('-' . PublishingService::STALE_MINUTES . ' minutes'),
            $this->frozenTitle($communication) ?? $communication->title,
            $this->frozenCaption($communication) ?? $communication->body
        );

        // Already there: the same communication shared the same way
        // twice. The history said it once, and saying it again would be
        // the same message at the same place — the one thing this module
        // always refuses.
        if (!$claimed) {
            return $this->json(['success' => true, 'recorded' => false]);
        }

        $this->publications->markPublished(
            ShareSource::KIND_COMMUNICATION,
            $communication->id,
            $kind->value,
            '',
            $now,
            $now
        );

        return $this->json(['success' => true, 'recorded' => true]);
    }

    // ————— Internals —————

    /**
     * What to say when the twin key refuses an edit: the chief typed, in
     * this draft, the text of a communication this album or article
     * already has.
     */
    private static function twinRefusal(Communication $communication): string
    {
        // **The source is never null here.** A communication written from
        // nothing has a NULL `body_hash` (`CommunicationRepository::
        // create()`), so the twin key cannot refuse ITS edit — writing a
        // branch for that case would read as a state that can happen.
        // The `??` is for an unknown source KIND, which `sourceLabel()`
        // answers null for, and that is a different question.
        return 'Ce texte est déjà celui d\'une autre communication de '
            . (ShareSourceResolver::sourceLabel((string) $communication->sourceKind) ?? 'la même source')
            . '. Modifiez-le, ou repartez de celle qui existe.';
    }

    /**
     * What a form's button asked: go and choose a gallery photo, take the
     * uploaded file, or publish.
     *
     * There is no « Enregistrer » any more (IT-01), so the fall-through is
     * not a save that announces itself: the row has already been written
     * by the time this runs, and the visitor is simply put back on their
     * draft with nothing to read. Saying « Communication enregistrée »
     * here would tell them something happened that they did not ask for.
     */
    private function act(Request $request, Communication $communication): Response
    {
        $action = (string) $request->getBody('action', '');
        $self = self::path($communication);

        // **The slider's position is part of the draft**, so it survives a
        // trip to the gallery or an upload exactly as the text does, and
        // it is saved by « Publier » like everything else — there is no
        // « Enregistrer » on this page (issue #706, IT-02).
        //
        // Only when the form actually carried the field: the retry page
        // posts to this controller too, and it has no slider, so reading
        // an absent field as zero would silently turn a blurred share
        // sharp on its second attempt. And never once something has left,
        // where the card is frozen with the rest.
        if (!$this->isFrozen($communication) && $request->getBody('blur_ratio') !== null) {
            $this->communications->updateBlurRatio(
                $communication->id,
                $this->blurRatio($request),
                new \DateTimeImmutable()
            );
            $communication = $this->communications->find($communication->id) ?? $communication;
        }

        if ($action === 'gallery' && $this->photos !== null && $this->mayChangeImage($communication)) {
            return $this->redirect($self . '/photo');
        }
        if ($action === 'upload' && $this->mayChangeImage($communication)) {
            $this->upload($request, $communication);

            return $this->redirect($self);
        }
        if ($action === 'publish') {
            return $this->publishNow($request, $communication);
        }

        return $this->redirect($self);
    }

    /**
     * Whether the image is this communication's to change: not once it has
     * left, and never when it comes from a source — an album's cover is
     * the album's, and « Partager » hides both buttons for that reason.
     *
     * **The gallery picker's own two routes ask this too**, not just the
     * buttons that lead to them. Hiding a button hides nothing from a
     * typed address, and a pick accepted for a source-backed
     * communication would write `gallery_media_id` where nothing ever
     * reads it: {@see ShareSourceResolver::communication()} branches on
     * `hasSource()` first and never looks at that column. The visitor
     * would choose a photo, see no change, and get no error.
     */
    private function mayChangeImage(Communication $communication): bool
    {
        return !$this->isFrozen($communication) && !$communication->hasSource();
    }

    private function upload(Request $request, Communication $communication): void
    {
        $file = $request->getFile('image');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            FlashMessage::set('error', 'Choisissez d\'abord le fichier de l\'image à téléverser.');

            return;
        }

        try {
            $fileId = $this->uploads->handle(
                $file,
                'social/communications',
                self::IMAGE_MIMES,
                self::IMAGE_MAX_BYTES,
                'chief',
                'social',
                AuthSession::getUserAccountId()
            );
        } catch (UploadException $e) {
            FlashMessage::set('error', $e->getMessage());

            return;
        }

        $this->communications->useUploadedFile($communication->id, $fileId, new \DateTimeImmutable());
    }

    private function publishNow(Request $request, Communication $communication): Response
    {
        $self = self::path($communication);
        $asked = PublishRequest::fromForm(
            $request->getBody('destinations', []),
            $request->getBody('groups', []),
            $request->getBody('retry', [])
        );
        if ($asked->isEmpty()) {
            FlashMessage::set('error', 'Cochez au moins une destination.');

            return $this->redirect($self);
        }

        // **The card the browser drew arrives with « Publier »** (issue
        // #706, IT-02), and the stored one is brought into line with it
        // here — AFTER the refusal above, and nowhere earlier. What goes
        // to Instagram, to a group, to a retry and — from IT-04 — to the
        // public page is this one file, so « what you saw is what left »
        // holds across destinations published minutes apart; storing it
        // on a POST that publishes nothing would keep the card of an
        // attempt that never happened, and the chief, still free to
        // change the photo or the blur because nothing is frozen yet,
        // would have the first attempt's card published instead of the
        // one they are looking at. Found in the review of #850.
        //
        // A refusal stops the publication rather than falling back to
        // composing one: the chief looked at a card and pressed
        // « Publier », and quietly publishing a different image would be
        // worse than asking them to try again.
        try {
            $communication = $this->refreshPostedCard($request, $communication);
        } catch (CardException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($self);
        }

        // Read after the card is kept, so the source carries it.
        $source = $this->communicationSource($communication->id);
        if ($source === null) {
            return new Response('Not Found', 404);
        }

        $outcomes = $this->states->publish(
            $source,
            $asked,
            $this->frozenCaption($communication) ?? $communication->body,
            AuthSession::getEmail(),
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            new \DateTimeImmutable()
        );
        [$type, $message] = DestinationStates::summary($outcomes);
        FlashMessage::set($type, $message);

        // To the history, not back to the draft (IT-01): what just left is
        // what you want to see, and the composer has nothing more to say.
        return $this->redirect(self::HISTORY_PATH);
    }

    /**
     * The composer, for a saved communication or for a new one.
     *
     * `$prefill` is the album or article « Partager » came from, on a page
     * that has no row yet: it is what the card shows and what the hidden
     * source fields carry into the POST. For a saved communication the
     * source comes from the row instead, through the resolver, which is
     * also where a source-backed one gets its image and title.
     */
    private function editor(?Communication $communication, ?ShareSource $prefill = null): Response
    {
        $source = $communication === null
            ? $prefill
            : $this->communicationSource($communication->id);
        // What is being shared, for the one line above the card — from the
        // row once there is one, from the prefill before that.
        $sourceKind = $communication->sourceKind ?? $prefill?->kind;
        // The album's or the article's own id — never the
        // communication's. On a prefill it is what « Partager » named; on
        // a saved row it is the column that remembered it.
        $sourceId = $communication->sourceId ?? $prefill?->id;
        $fromSource = $sourceKind !== null && $sourceKind !== ShareSource::KIND_COMMUNICATION;

        return $this->render('@social/communications/edit.html.twig', [
            'communication' => $communication,
            'source' => $source,
            'frozen' => $communication !== null && $this->isFrozen($communication),
            'form_action' => $communication === null ? self::HISTORY_PATH : self::path($communication),
            'has_image' => $source !== null && $source->image !== null && $source->image !== '',
            // From the SOURCE, not from this row's own columns: a
            // source-backed communication never sets `gallery_media_id`
            // — and can never set it, since the gallery button is absent
            // — yet an album's cover IS blurred at publication
            // (ShareSourceResolver::album() answers imageFromGallery).
            // Read from the row, the sentence promising the blur
            // disappeared for exactly the shares that get blurred.
            'from_gallery' => $source->imageFromGallery ?? false,
            // Neither button is offered when the image is the source's:
            // an album's cover is the album's to change, not this page's.
            'gallery_available' => $this->photos !== null && !$fromSource,
            'uploadable' => !$fromSource,
            'from_source' => $fromSource,
            'image_is_settled' => self::imageIsSettled($source, $fromSource),
            // **What Facebook will be pointed at, when it is pointed at
            // a page rather than handed a photo** (issue #706, IT-03,
            // then IT-04): null for a photo post, otherwise which page
            // builds the preview — because the two tell the chief
            // opposite things about the card they are looking at.
            //
            // An ARTICLE goes as a link to its own page, whose cover,
            // title and summary Facebook renders instead of the card.
            // An ALBUM goes as a link to the communication's public
            // page, which shows this very card — so the card does reach
            // Facebook, inside the preview.
            //
            // IT-03 keyed this on `link` alone and said IT-04 would
            // carry it for free. **It does not**, and the reason is
            // worth keeping: the public page is minted at the first
            // share that needs it, so while the composer is open an
            // album's `link` is still null. The decision had to become a
            // predicate of its own, {@see
            // ShareSource::facebookReceivesLink()}, which the publisher
            // obeys too — one rule, read before and applied after.
            'facebook_link_target' => match (true) {
                !$source?->facebookReceivesLink() => null,
                ($source->link ?? null) !== null => 'article',
                default => 'page',
            },
            // **What this source has already been through** (issue #706,
            // IT-05): every share of every communication sharing it, not
            // just this row's — the same album with a different message
            // is a new communication since IT-05, so a chief opening the
            // composer for the third time has two earlier ones behind
            // them. Reading only the row in hand would show an empty
            // history to the one person who needs it most.
            'previous_shares' => $this->shareHistory->lines($sourceKind, $sourceId),
            // One sentence per destination, for the confirmation the
            // browser builds from the boxes actually ticked. « avec le
            // même message » is the case that almost always means
            // somebody forgot they had already shared it.
            'shared_by_destination' => $this->shareHistory->byDestination(
                $sourceKind,
                $sourceId,
                (string) ($communication->body ?? $prefill->defaultCaption ?? '')
            ),
            'source_kind' => $fromSource ? $sourceKind : null,
            'source_id' => $fromSource ? ($communication->sourceId ?? $prefill?->id) : null,
            'source_label' => $fromSource ? ShareSourceResolver::sourceLabel((string) $sourceKind) : null,
            // Before the row exists the card is served by its source, so
            // that « Publier » is never the first time the image is seen.
            'preview_path' => match (true) {
                $communication !== null => self::path($communication) . '/apercu',
                $prefill !== null => self::HISTORY_PATH . '/nouvelle/' . $prefill->kind . '/'
                    . $prefill->id . '/apercu',
                default => null,
            },
            // `$communication !== null` is « this row exists, so its own
            // publications are its own ». Before it exists the prefill
            // carries the ALBUM's key, under which the retired
            // /partage/album/{id} route recorded its shares — see
            // DestinationStates::forSource().
            'destinations' => $source === null
                ? $this->unsavedDestinations()
                : $this->states->forSource($source, $communication !== null),
            'offers_groups' => $this->states->offersGroups() && $source !== null,
            'groups' => $source === null ? [] : $this->states->groupsFor(
                $source,
                AuthSession::getEmail(),
                AuthSession::getRole(),
                AuthSession::getUserAccountId(),
                $communication !== null
            ),
            // What the BROWSER needs to draw the card itself (issue #706,
            // IT-02): the source's own photo, beside the composed card
            // above. Both are kept — the <img> is what a page with no
            // JavaScript, or a browser with no 2D context, still shows,
            // and the canvas replaces it only once it has drawn.
            'background_path' => match (true) {
                $communication !== null => self::path($communication) . '/image',
                $prefill !== null => self::HISTORY_PATH . '/nouvelle/' . $prefill->kind . '/'
                    . $prefill->id . '/image',
                default => null,
            },
            // The address written at the foot of the card, and whether
            // this photo is blurred — the two things the drawing needs
            // that are not on the form. Taken from the SOURCE, like the
            // card the server composes from the same pair.
            'card_address' => $source->address ?? '',
            // The strength the card is drawn with: the one this share
            // kept, or this site's starting position when the slider was
            // never moved. Zero for an image that is not the gallery's —
            // an upload leaves as it is, so there is nothing to blur and
            // no slider offered for it.
            'blur_ratio' => ($source->imageFromGallery ?? false)
                ? ($source->blurRatio ?? $this->cards->blurRatio())
                : 0.0,
            // The slider exists only where a blur does.
            'blur_adjustable' => ($source->imageFromGallery ?? false)
                && !($communication !== null && $this->isFrozen($communication)),
            'blur_max' => CardService::MAX_BLUR_RATIO,
            'title_max' => self::TITLE_MAX_LENGTH,
            'body_max' => PublishingService::CAPTION_MAX_LENGTH,
        ]);
    }

    /**
     * Before the first save there is nothing to publish yet: the
     * destinations are shown, unticked, so the page reads the same.
     *
     * @return list<array{value: string, label: string, account: string, state: string, date: null, reason: ?string}>
     */
    private function unsavedDestinations(): array
    {
        $rows = [];
        foreach (SocialPlatform::cases() as $platform) {
            $connection = $this->connections->find($platform);
            if ($connection !== null && $connection->isConnected()) {
                $rows[] = [
                    'value' => $platform->value,
                    'label' => $platform->label(),
                    'account' => ($platform === SocialPlatform::Instagram ? '@' : '')
                        . (string) $connection->accountName,
                    'state' => 'blocked',
                    'date' => null,
                    'reason' => 'Choisissez d\'abord une image.',
                ];
            }
        }

        return $rows;
    }

    /**
     * Whether the communication these route parameters name has already
     * published something.
     *
     * Read from the row rather than from the `ShareSource`, which carries
     * a stored card for an unfrozen share too — see `cardOf()`. A
     * communication that is not this caller's answers false, and the
     * source it is asked beside is null anyway, so both routes refuse.
     *
     * @param array<string, string> $params
     */
    private function frozenShare(array $params): bool
    {
        $communication = $this->communications->find((int) ($params['id'] ?? 0));

        return $communication !== null && $this->isFrozen($communication);
    }

    /**
     * Whether the image a later destination or a retry receives is
     * settled — which is NOT the same as the share being frozen.
     *
     * Freezing settles the text always, and the image only where there
     * is something to settle it with:
     *
     * - **A kept card** settles it, whatever the source does afterwards.
     * - **A communication with an image of its OWN** settles it too,
     *   even with no card: the photo and the title are this row's own
     *   columns, so composing again gives the same card.
     * - **A source-backed share with NO kept card** does not. The title
     *   and the cover are read from the album or the article at every
     *   publication, so renaming it between a Facebook post and an
     *   Instagram retry sends two different cards. Reachable for a share
     *   made before IT-02, for a browser with no `DataTransfer`
     *   constructor, and — since `dropStoredCard()` — for any attempt
     *   published from a canvas that never drew.
     *
     * Both the composer's frozen notice and the retry page promised « the
     * same image » unconditionally, which is false in that third case;
     * the retry page even had `from_source` passed to it and no longer
     * read it. Found in the review of #850, in the round after the one
     * that made the third case easier to reach.
     */
    private static function imageIsSettled(?ShareSource $source, bool $fromSource): bool
    {
        return !$fromSource || ($source?->card !== null && $source->card !== '');
    }

    /**
     * Once one destination has been tried, the communication is what was
     * sent, and stays so.
     */
    private function isFrozen(Communication $communication): bool
    {
        return $this->publications->forSource(ShareSource::KIND_COMMUNICATION, $communication->id) !== [];
    }

    /**
     * The title the first destination received, which every later one
     * receives too — the album's name as it stood then, for a
     * source-backed share whose own column stays empty by design.
     */
    private function frozenTitle(Communication $communication): ?string
    {
        foreach ($this->publications->forSource(ShareSource::KIND_COMMUNICATION, $communication->id) as $publication) {
            return $publication->sourceTitle;
        }

        return null;
    }

    /**
     * The text the first destination received, which every later one
     * receives too.
     */
    private function frozenCaption(Communication $communication): ?string
    {
        foreach ($this->publications->forSource(ShareSource::KIND_COMMUNICATION, $communication->id) as $publication) {
            return $publication->caption;
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function mine(array $params): ?Communication
    {
        return $this->sources->editableCommunication(
            (int) ($params['id'] ?? 0),
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId()
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function source(array $params): ?ShareSource
    {
        return $this->communicationSource((int) ($params['id'] ?? 0));
    }

    /**
     * A communication as something publishable — asked of its own rule,
     * and of its source's when it has one, at every single use.
     */
    private function communicationSource(int $communicationId): ?ShareSource
    {
        return $this->sources->communication(
            $communicationId,
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId(),
            AuthSession::getEmail()
        );
    }

    /**
     * The album or article kind a composer's form carried, or null for a
     * communication written from nothing. An unknown kind is null too:
     * store() then records no source rather than one nothing can describe.
     */
    private function nullableSourceKind(Request $request): ?string
    {
        $kind = trim((string) $request->getBody('source_kind', ''));

        return ShareSourceResolver::sourceLabel($kind) === null ? null : $kind;
    }

    private function describedSource(string $kind, int $id): ?ShareSource
    {
        return $this->sources->describeSource(
            $kind,
            $id,
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId(),
            AuthSession::getEmail()
        );
    }

    /**
     * The source of a history line, asked of its own rule again, and its
     * failed destination.
     *
     * @param array<string, string> $params
     * @return array{
     *     source: ShareSource, key: string, platform: ?SocialPlatform, label: string, failed: Publication,
     *     from_source: bool, image_is_settled: bool, source_label: ?string, published: list<Publication>
     * }|null
     */
    private function retryContext(array $params): ?array
    {
        $key = (string) ($params['platform'] ?? '');
        $platform = SocialPlatform::tryFrom($key);
        $groupId = GroupPublishingService::groupIdOf($key);
        $id = (int) ($params['id'] ?? 0);
        $role = AuthSession::getRole();
        $accountId = (int) AuthSession::getUserAccountId();
        $source = (string) ($params['kind'] ?? '') === ShareSource::KIND_COMMUNICATION
            ? $this->communicationSource($id)
            : $this->sources->describeSource(
                (string) ($params['kind'] ?? ''),
                $id,
                $role,
                $accountId,
                AuthSession::getEmail()
            );
        if (($platform === null && ($groupId === null || !$this->states->offersGroups())) || $source === null) {
            return null;
        }

        $publications = $this->publications->forSource($source->kind, $source->id);
        $failed = $publications[$key] ?? null;
        $staleBefore = (new \DateTimeImmutable())->modify('-' . PublishingService::STALE_MINUTES . ' minutes');
        if ($failed === null || !$failed->isRetryable($staleBefore)) {
            return null;
        }

        // Whether the title and image come from an album or an article.
        // Read from the KIND, not from `$id`: on this route `$id` is a
        // communication key only when the kind says so, which is why
        // `$source` above branches the same way. A legacy
        // `…/reessayer/album/{albumId}/…` carries an album key, so
        // looking a communication up by it read an unrelated or missing
        // row. Any kind but `communication` IS the source.
        $fromSource = (string) ($params['kind'] ?? '') !== ShareSource::KIND_COMMUNICATION
            || ($this->communications->find($id)?->hasSource() ?? false);

        return [
            'source' => $source,
            'key' => $key,
            'platform' => $platform,
            'label' => $platform?->label() ?? ($failed->destinationLabel ?? 'le groupe'),
            'failed' => $failed,
            'from_source' => $fromSource,
            // Which, with the kept card, decides the sentence this page
            // leads with. {@see self::imageIsSettled()}
            'image_is_settled' => self::imageIsSettled($source, $fromSource),
            // « l'album » or « l'actualité », to name what the image
            // would be read from again. `originKind` on a
            // communication-backed share, the kind itself on a legacy
            // `…/reessayer/album/{id}/…` link.
            'source_label' => ShareSourceResolver::sourceLabel($source->originKind ?? $source->kind),
            'published' => array_values(array_filter(
                $publications,
                static fn (Publication $p): bool => $p->isPublished()
            )),
        ];
    }

    /**
     * @param array{kind: string, id: int, publications: array<string, Publication>} $group
     * @param list<SocialPlatform> $platforms
     * @param array<int, array{first_name: ?string, last_name: ?string}> $names
     * @return array<string, mixed>
     */
    private function historyEntry(array $group, array $platforms, array $names, \DateTimeImmutable $staleBefore): array
    {
        $publications = $group['publications'];
        $first = null;
        foreach ($publications as $publication) {
            if ($first === null || $publication->attemptedAt < $first->attemptedAt) {
                $first = $publication;
            }
        }

        $destinations = [];
        foreach ($platforms as $platform) {
            $destinations[$platform->value] = [$platform->label(), $platform->value === 'facebook'
                ? 'bi-facebook' : 'bi-instagram'];
        }
        // One line per discussion group, named as it was when the post
        // left — never « non demandé »: a group is not a standing account.
        foreach ($publications as $key => $publication) {
            if (GroupPublishingService::groupIdOf($key) !== null) {
                $destinations[$key] = [$publication->destinationLabel ?? 'Groupe de discussion', 'bi-people'];
            }
        }
        // And one per share the chief made by hand (issue #706, IT-05),
        // on the same footing and for the same reason: a share sheet or a
        // copied link is something that HAPPENED, not a standing
        // destination, so it has no « Jamais partagé ici » state to show
        // and appears only once it exists.
        foreach ($publications as $key => $publication) {
            $direct = DirectShare::tryFrom($key);
            if ($direct !== null) {
                $destinations[$key] = [$direct->label(), $direct->icon()];
            }
        }

        $rows = [];
        foreach ($destinations as $key => [$label, $icon]) {
            $publication = $publications[$key] ?? null;
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'state' => match (true) {
                    $publication === null => 'not_requested',
                    $publication->isPublished() => 'published',
                    $publication->isRetryable($staleBefore) => 'failed',
                    default => 'pending',
                },
                'publication' => $publication,
                // Said plainly so the view does not have to read the key:
                // a hand-made share is dated, never « Publié ».
                'direct' => DirectShare::tryFrom($key) !== null,
                // **No retry for a share made by hand** (issue #706,
                // IT-05): nothing failed, and nothing could be resent —
                // the site handed a link to the phone or to the
                // clipboard and never learnt what followed. The view
                // needs the null to leave the button out rather than
                // offer an address that would answer 404.
                'retry_path' => DirectShare::tryFrom($key) !== null
                    ? null
                    // Unencoded: the router matches the raw path, and a
                    // destination key (`group:3`) is valid in one as it
                    // is.
                    : self::HISTORY_PATH . '/reessayer/'
                        . $group['kind'] . '/' . $group['id'] . '/' . $key,
            ];
        }

        return [
            'kind' => $group['kind'],
            // The id the share icon posts to, and null for an entry with
            // no communication behind it — a share recorded against an
            // album's own key, from before the composer existed, has no
            // public page to offer.
            'share_id' => $group['kind'] === ShareSource::KIND_COMMUNICATION ? $group['id'] : null,
            'title' => $first->sourceTitle ?? '',
            'caption' => mb_strimwidth($first->caption ?? '', 0, 80, '…'),
            'date' => $first?->attemptedAt,
            'author' => $first?->userAccountId !== null ? ($names[$first->userAccountId]['first_name'] ?? null) : null,
            'rows' => $rows,
        ];
    }

    /**
     * The destinations a history line shows: every connected one, and
     * every one something was ever sent to.
     *
     * @return list<SocialPlatform>
     */
    private function platformsShown(): array
    {
        return array_values(array_filter(
            SocialPlatform::cases(),
            fn (SocialPlatform $platform): bool => $this->connections->find($platform) !== null
        ));
    }

    private static function retryPath(ShareSource $source, string $key): string
    {
        return self::HISTORY_PATH . '/reessayer/' . $source->kind . '/' . $source->id . '/' . $key;
    }

    private static function path(?Communication $communication): string
    {
        return $communication === null ? self::HISTORY_PATH : ShareSourceResolver::path($communication->id);
    }

    /**
     * Brings the stored card into line with this publishing POST (issue
     * #706, IT-02): the one it carries, or none at all.
     *
     * **Once the communication is FROZEN** nothing here applies — the
     * kept card is what every later destination and every retry
     * receives, which is the same guard the text has. While nothing has
     * been published the chief may still change the photo, the title and
     * the slider, so the stored card is only ever a record of what their
     * browser last drew, and this runs on every « Publier » to keep it
     * one.
     *
     * **A card arrives**: it replaces the stored one, because it was
     * drawn from what the chief is looking at. Guarding that on
     * `cardFileId !== null` instead was a defect found in the review of
     * #850, and a subtle one: several refusals inside
     * `PublishingService::publish()` — a blocked source, a caption past
     * Instagram's limit, a Meta token that has expired — record a failed
     * outcome and return WITHOUT claiming a publication row, so
     * `isFrozen()` stays false. The card of that refused attempt was
     * stored all the same, and the next attempt could not replace it.
     * Moving this call after the « no destination » refusal fixed one
     * path of three; the rule fixes all of them.
     *
     * **No card arrives** — JavaScript off, a photo still loading, a
     * canvas that paints nothing — and the stored one is dropped rather
     * than reused (`dropStoredCard()`). Leaving the row as it was is
     * what the same review found next: the chief raised the blur after a
     * refused attempt, published from a canvas that had not drawn, and
     * the sharp card of that first attempt left while the site recorded
     * the new blur beside it. The publication then composes a current
     * card, so nobody is refused for a browser that could not draw.
     *
     * @throws CardException when a card arrived and is not usable
     */
    private function refreshPostedCard(Request $request, Communication $communication): Communication
    {
        // Frozen: the kept card is what every later destination receives,
        // and this POST has no say in it either way.
        if ($this->isFrozen($communication)) {
            return $communication;
        }

        $file = $request->getFile('card');
        $tmp = is_array($file) ? ($file['tmp_name'] ?? null) : null;
        if (!is_string($tmp) || $tmp === '' || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->dropStoredCard($communication);
        }

        $bytes = (string) @file_get_contents($tmp);
        ReceivedCard::assertUsable($bytes);

        $fileId = $this->cardFiles->store(
            $bytes,
            ReceivedCard::MIME,
            'carte.jpg',
            self::CARD_DIRECTORY,
            'chief',
            'social',
            AuthSession::getUserAccountId(),
            null,
            // **The owner type is what keeps this row off `/files/{id}`.**
            // Without it the row fell through to the `chief` floor alone,
            // and any chief could read any card — a gallery photo at
            // « Net » among them — by asking for a sequential id. The
            // only reader is ShareSourceResolver::frozenCard(), which
            // reads the bytes directly.
            // {@see \Modules\Social\File\PostedCardOwnershipChecker}
            PostedCardOwnershipChecker::OWNER_TYPE,
            $communication->id
        );
        $this->communications->updateCardFile($communication->id, $fileId, new \DateTimeImmutable());
        // The card this one replaces, dropped only once the row points at
        // the new one: the other order would leave a row naming a file
        // that no longer exists if the update failed in between.
        if ($communication->cardFileId !== null) {
            $this->cardFiles->delete($communication->cardFileId);
        }

        return $this->communications->find($communication->id) ?? $communication;
    }

    /**
     * Forgets a stored card that no longer shows what this share would
     * publish.
     *
     * **A stored card is only ever a record of what the browser last
     * drew.** It becomes the thing published only once something has
     * left, and until then anything the chief changes — the slider, the
     * photo, the title — leaves it stale. `cardBytes()` and
     * `GroupPublishingService::card()` both prefer it over composing,
     * and they must: the FIRST publication is the one that posts a card
     * and has no publication row yet. So the row has to stop naming it
     * the moment it stops being true.
     *
     * Dropped here, at the one moment that matters, rather than at each
     * of the four places that can make it stale (the slider, an upload,
     * a gallery photo, a retyped title): a publishing POST that carries
     * no fresh card is exactly the case where the stored one would be
     * used, so this is where the rule holds for all four at once. Fixing
     * the slider alone would have been the third partial fix of the same
     * rule in this pull request.
     *
     * The publication then composes a current card, which is the
     * documented fallback — the chief is never refused for a browser
     * that could not draw. Found in the review of #850.
     */
    private function dropStoredCard(Communication $communication): Communication
    {
        if ($communication->cardFileId === null) {
            return $communication;
        }

        $this->communications->updateCardFile($communication->id, null, new \DateTimeImmutable());
        // The row first, the file second, as above.
        $this->cardFiles->delete($communication->cardFileId);

        return $this->communications->find($communication->id) ?? $communication;
    }

    /**
     * The blur the composer's slider was left on, brought into the range
     * the slider can express.
     *
     * Anything unreadable answers the site's own starting position rather
     * than zero: a posted value nobody could parse is not a chief asking
     * for « Net ».
     */
    private function blurRatio(Request $request): float
    {
        $posted = $request->getBody('blur_ratio');

        return is_numeric($posted)
            ? CardService::clampBlurRatio((float) $posted)
            : $this->cards->blurRatio();
    }

    private static function title(Request $request): string
    {
        return mb_substr(trim((string) $request->getBody('title', '')), 0, self::TITLE_MAX_LENGTH);
    }

    private static function body(Request $request): string
    {
        return mb_substr(trim((string) $request->getBody('body', '')), 0, PublishingService::CAPTION_MAX_LENGTH);
    }
}
