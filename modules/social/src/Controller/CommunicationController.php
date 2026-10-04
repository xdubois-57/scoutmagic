<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Controller;

use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\UserAccountRepository;
use Modules\Gallery\Api\PhotoPickerInterface;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardException;
use Modules\Social\Card\CardService;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\Publication;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\DestinationStates;
use Modules\Social\Service\GroupPublishingService;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\PublishRequest;
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

    /**
     * How long a second, byte-identical share of the same source counts
     * as the same POST arriving twice rather than a decision taken again.
     * Generous enough to cover a slow Meta round trip and the tap that
     * follows it; short enough that a share meant later is still a share.
     */
    private const REPLAY_WINDOW_SECONDS = 120;
    public const HISTORY_SIZE = 30;
    public const TITLE_MAX_LENGTH = 120;

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
        private readonly UserAccountRepository $accounts,
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

        // **A replayed POST must not publish the album twice.** Creating
        // the row and publishing it happen in one request, and the
        // publication is keyed on the new row's own id, so a second
        // identical POST would make a second row with a second key and
        // sail past the unique constraint that used to stop it when the
        // retired route published against the album's own id. Measured
        // before this guard: two rows, two publications, two Facebook
        // posts.
        //
        // **So the guard stops the duplicate ROW, and nothing else.** The
        // request is then carried out against the row that already
        // exists, exactly as a second « Publier » on a saved
        // communication is: a destination already sent is refused by the
        // unique key, with the sentence that says so, and a destination
        // this click asks for and that one did not — the chief who shared
        // to Facebook and then, straight away, to a discussion group —
        // goes out. A twin never means « drop this »: deciding that from
        // the text alone would have thrown the second share away in
        // silence, since the caption the composer prefills is the same
        // both times (raised in review on the pull request for IT-01).
        if ($kind !== null) {
            $twin = $this->communications->recentTwin(
                $kind,
                (int) $sourceId,
                self::body($request),
                AuthSession::getUserAccountId(),
                (new \DateTimeImmutable())->modify('-' . self::REPLAY_WINDOW_SECONDS . ' seconds')
            );
            $existing = $twin === null ? null : $this->communications->find($twin);
            if ($existing !== null) {
                return $this->act($request, $existing);
            }
        }

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
            $this->communications->updateText(
                $communication->id,
                $communication->hasSource() ? $communication->title : self::title($request),
                self::body($request),
                new \DateTimeImmutable()
            );
            $communication = $this->communications->find($communication->id) ?? $communication;
        }

        return $this->act($request, $communication);
    }

    /** @param array<string, string> $params */
    public function preview(Request $request, array $params): Response
    {
        return $this->cardOf($this->source($params));
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
        return $this->cardOf($this->describedSource(
            (string) ($params['kind'] ?? ''),
            (int) ($params['id'] ?? 0)
        ));
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
        return $this->backgroundOf($this->source($params));
    }

    /**
     * The same, for a composer « Partager » has not saved yet — keyed on
     * the source, like `previewSource()` and for the same reason.
     *
     * @param array<string, string> $params
     */
    public function backgroundSource(Request $request, array $params): Response
    {
        return $this->backgroundOf($this->describedSource(
            (string) ($params['kind'] ?? ''),
            (int) ($params['id'] ?? 0)
        ));
    }

    /**
     * The source's image bytes, or 404 — for something with no image,
     * something this caller may not see, and bytes that are not one of
     * the three formats the card accepts alike. The type is sniffed from
     * the bytes rather than taken from anything the request said: the only
     * thing that decides what is served is what the owning module handed
     * over.
     */
    private function backgroundOf(?ShareSource $source): Response
    {
        if ($source === null || $source->image === null || $source->image === '') {
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
    private function cardOf(?ShareSource $source): Response
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

    // ————— Internals —————

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
     * Once one destination has been tried, the communication is what was
     * sent, and stays so.
     */
    private function isFrozen(Communication $communication): bool
    {
        return $this->publications->forSource(ShareSource::KIND_COMMUNICATION, $communication->id) !== [];
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
     *     published: list<Publication>
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

        return [
            'source' => $source,
            'key' => $key,
            'platform' => $platform,
            'label' => $platform?->label() ?? ($failed->destinationLabel ?? 'le groupe'),
            'failed' => $failed,
            // Whether the title and image come from an album or an
            // article, and are therefore read again at this retry rather
            // than kept from the first publication. The page says so
            // instead of promising « the same image » — see the frozen
            // notice in edit.html.twig for the whole reason.
            //
            // Read from the KIND, not from `$id`: on this route `$id` is a
            // communication key only when the kind says so, which is why
            // `$source` above branches the same way. A legacy
            // `…/reessayer/album/{albumId}/…` carries an album key, so
            // looking a communication up by it read an unrelated or
            // missing row. Any kind but `communication` IS the source.
            'from_source' => (string) ($params['kind'] ?? '') !== ShareSource::KIND_COMMUNICATION
                || ($this->communications->find($id)?->hasSource() ?? false),
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
                // Unencoded: the router matches the raw path, and a
                // destination key (`group:3`) is valid in one as it is.
                'retry_path' => self::HISTORY_PATH . '/reessayer/'
                    . $group['kind'] . '/' . $group['id'] . '/' . $key,
            ];
        }

        return [
            'kind' => $group['kind'],
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

    private static function title(Request $request): string
    {
        return mb_substr(trim((string) $request->getBody('title', '')), 0, self::TITLE_MAX_LENGTH);
    }

    private static function body(Request $request): string
    {
        return mb_substr(trim((string) $request->getBody('body', '')), 0, PublishingService::CAPTION_MAX_LENGTH);
    }
}
