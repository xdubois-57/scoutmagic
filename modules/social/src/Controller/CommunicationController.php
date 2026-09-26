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
 * « Communications » (espace chefs): a free communication — an image, a
 * title written on it, a text — published like an album or an article, and
 * « Ce qui est parti », the history of everything that left, destination by
 * destination, with its retry.
 *
 * **A real page, in the mockup's order**: the image as it will be
 * published, the two buttons that change it (gallery or upload), the title
 * written on the image, the text, then the destinations. The image is
 * always required — the card says so, and PublishingService refuses
 * without one.
 *
 * **What was sent is frozen once it left.** As soon as one destination has
 * been tried, the image, title and text no longer change: a retry, and a
 * destination published later, receive exactly what the first one did.
 *
 * A communication is its author's, or an administrator's
 * (ShareSourceResolver::editableCommunication()); anyone else gets a 404.
 * The history is every chief's to read; a retry from it asks the source's
 * own rule again, through the resolver.
 */
final class CommunicationController extends AbstractController
{
    public const HISTORY_PATH = '/communications';
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

    /** @param array<string, string> $params */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/communications/nouvelle')) !== null) {
            return $guard;
        }

        $id = $this->communications->create(
            self::title($request),
            self::body($request),
            AuthSession::getUserAccountId(),
            new \DateTimeImmutable()
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
                self::title($request),
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
        $source = $this->source($params);
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
        if ($communication === null || $this->photos === null || $this->isFrozen($communication)) {
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
        if ($communication === null || $this->photos === null || $this->isFrozen($communication)) {
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

        return $this->render('@social/communications/retry.html.twig', $retry + [
            'self_path' => self::retryPath($retry['source'], $retry['key']),
        ]);
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
     * uploaded file, publish, or only save.
     */
    private function act(Request $request, Communication $communication): Response
    {
        $action = (string) $request->getBody('action', 'save');
        $self = self::path($communication);

        if ($action === 'gallery' && $this->photos !== null && !$this->isFrozen($communication)) {
            return $this->redirect($self . '/photo');
        }
        if ($action === 'upload' && !$this->isFrozen($communication)) {
            $this->upload($request, $communication);

            return $this->redirect($self);
        }
        if ($action === 'publish') {
            return $this->publishNow($request, $communication);
        }

        FlashMessage::set('success', 'Communication enregistrée.');

        return $this->redirect($self);
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

        $source = $this->sources->communication(
            $communication->id,
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId()
        );
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

        return $this->redirect($self);
    }

    private function editor(?Communication $communication): Response
    {
        $source = $communication === null ? null : $this->sources->communication(
            $communication->id,
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId()
        );

        return $this->render('@social/communications/edit.html.twig', [
            'communication' => $communication,
            'source' => $source,
            'frozen' => $communication !== null && $this->isFrozen($communication),
            'form_action' => $communication === null ? '/communications' : self::path($communication),
            'has_image' => $source !== null && $source->image !== null && $source->image !== '',
            'from_gallery' => $communication?->galleryMediaId !== null,
            'gallery_available' => $this->photos !== null,
            'destinations' => $source === null ? $this->unsavedDestinations() : $this->states->forSource($source),
            'offers_groups' => $this->states->offersGroups() && $source !== null,
            'groups' => $source === null ? [] : $this->states->groupsFor(
                $source,
                AuthSession::getEmail(),
                AuthSession::getRole(),
                AuthSession::getUserAccountId()
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
        return $this->sources->communication(
            (int) ($params['id'] ?? 0),
            AuthSession::getRole(),
            (int) AuthSession::getUserAccountId()
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
        $source = match ((string) ($params['kind'] ?? '')) {
            ShareSource::KIND_ALBUM => $this->sources->album($id, $role, AuthSession::getEmail() ?? ''),
            ShareSource::KIND_ARTICLE => $this->sources->article($id, $role, $accountId),
            ShareSource::KIND_COMMUNICATION => $this->sources->communication($id, $role, $accountId),
            default => null,
        };
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
                'retry_path' => '/communications/reessayer/' . $group['kind'] . '/' . $group['id'] . '/' . $key,
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
        return '/communications/reessayer/' . $source->kind . '/' . $source->id . '/' . $key;
    }

    private static function path(?Communication $communication): string
    {
        return $communication === null ? self::HISTORY_PATH : '/communications/' . $communication->id;
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
