<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\AppClock;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Support\Timestamps;

/**
 * Creating, editing, deleting and pinning posts, and the rules that
 * govern each. Authorisation for "may this session post here at all" is
 * GroupAccessService's; this service owns the per-post rules on top.
 */
class PostService
{
    /**
     * Plain text, and bounded server-side: the client's maxlength is a
     * convenience, never the limit.
     */
    public const MAX_BODY_LENGTH = 5000;

    /**
     * How long an author may still edit their own post. Measured from the
     * stored created_at, in UTC, on the server — never from a timestamp
     * the client sends, and never from the browser's own clock.
     */
    public const EDIT_WINDOW_MINUTES = 15;

    public function __construct(
        private PostRepository $postRepository,
        private GroupActivityService $activityService,
        private RateLimitService $rateLimitService,
        private ?ModerationService $moderationService = null
    ) {
    }

    /**
     * @return int the new post id
     * @throws GroupsException TYPE_RATE_LIMITED when the member has posted
     *         too fast, TYPE_OFFENSIVE when the AI flagged the text — the
     *         latter carrying the suggested rewording, and nothing being
     *         written in either case
     */
    public function create(DiscussionGroup $group, int $userAccountId, int $authorMemberId, string $body): int
    {
        // Before the moderation call, never after: an abusive burst is
        // stopped by the cheap check and never reaches the LLM (same
        // ordering as Modules\Retro\Service\CommentService).
        $this->rateLimitService->checkAndRecord($authorMemberId, RateLimitService::ACTION_POST);

        $this->assertAcceptable($body);

        $now = Timestamps::now();
        $postId = $this->postRepository->create($group->id, $userAccountId, $authorMemberId, $this->normalize($body),
            $now);
        $this->activityService->bump($group->id, $postId, $now);

        return $postId;
    }

    /**
     * The author — the account that actually typed the post, not the
     * member it was signed as — and only inside the window.
     */
    public function canEdit(Post $post, GroupSessionContext $context, ?\DateTimeImmutable $now = null): bool
    {
        if ($context->userAccountId === null || $post->authorUserAccountId !== $context->userAccountId) {
            return false;
        }

        return $this->isWithinEditWindow($post, $now);
    }

    /**
     * Computed from the stored created_at every single time, both sides in
     * the application clock (Support\Timestamps). Nothing the client sends takes part in
     * this: a forged "created 10 seconds ago" field changes nothing,
     * because no such field is ever read.
     */
    public function isWithinEditWindow(Post $post, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= AppClock::now();
        $deadline = Timestamps::parse($post->createdAt)->modify('+' . self::EDIT_WINDOW_MINUTES . ' minutes');

        return $now <= $deadline;
    }

    /**
     * The author, or any moderator of the group. Deletion is real — this
     * module does not soft-delete — and replies/reactions will hang off
     * the post with ON DELETE CASCADE, so nothing is left orphaned.
     */
    public function canDelete(Post $post, GroupSessionContext $context, bool $canModerate): bool
    {
        return $canModerate
            || ($context->userAccountId !== null && $post->authorUserAccountId === $context->userAccountId);
    }

    /**
     * The a-priori AI check, applied to the normalised text that would
     * actually be stored. Silent no-op when the llm_connector module is
     * absent, when the admin turned moderation off, or when the call
     * failed for any technical reason — fail open, always
     * (Service\ModerationService).
     *
     * @throws GroupsException TYPE_OFFENSIVE
     */
    private function assertAcceptable(string $body): void
    {
        if ($this->moderationService === null) {
            return;
        }

        $result = $this->moderationService->moderate($this->normalize($body));
        if ($result === null || !$result['flagged']) {
            return;
        }

        // The rejected text is not stored, journaled or logged anywhere;
        // the suggestion travels back to its own author in this exception
        // and no further (module spec).
        throw new GroupsException(
            $result['reason'] ?? 'Ce message peut être perçu comme une attaque personnelle. Merci de le reformuler.',
            GroupsException::TYPE_OFFENSIVE,
            $result['suggestion']
        );
    }

    /**
     * Edits go through the same check as a creation, and deliberately so:
     * without it, posting an innocuous message and immediately editing it
     * would be a one-click way around moderation entirely.
     *
     * @throws GroupsException TYPE_OFFENSIVE
     */
    public function edit(Post $post, string $body): void
    {
        $this->assertAcceptable($body);

        // Marks the post "modifié"; last_activity_at stays untouched, so
        // an edit never reorders the feed (PostRepository::updateBody()).
        $this->postRepository->updateBody($post->id, $this->normalize($body), Timestamps::now());
    }

    public function delete(Post $post): void
    {
        $this->postRepository->delete($post->id);
    }

    /**
     * How long a pin lasts, keyed by what the moderator picks. The value
     * is what `DateTimeImmutable::modify()` is given; `null` is "until a
     * moderator takes it down" and stores no deadline at all.
     *
     * A week is the default because that is the shape of the question a
     * pin usually answers ("rendez-vous samedi"), and because a pin
     * nobody ever takes down is how a group ends up with a banner three
     * months out of date at the top of every visit.
     *
     * @var array<string, string|null>
     */
    public const PIN_DURATIONS = [
        'day' => '+1 day',
        'week' => '+7 days',
        'month' => '+1 month',
        'forever' => null,
    ];

    public const PIN_DURATION_DEFAULT = 'week';

    /**
     * Pins one post, and unpins whatever else this group had pinned —
     * exactly one pinned post at a time (schema.sql). The other post is
     * dropped BEFORE this one is set, so a reader refreshing between the
     * two statements sees no pin rather than two.
     *
     * @param string $duration a key of PIN_DURATIONS; anything else is
     *        the default rather than an error, since it can only come
     *        from a hand-made request (the form offers four values).
     */
    public function pin(Post $post, string $duration = self::PIN_DURATION_DEFAULT): void
    {
        $this->postRepository->unpinAllExcept($post->groupId, $post->id);
        $this->postRepository->setPinned($post->id, true, $this->pinDeadline($duration));
    }

    public function unpin(Post $post): void
    {
        $this->postRepository->setPinned($post->id, false);
    }

    /**
     * Drops the pin of every post of this group whose deadline has
     * passed. Called from the group's own page load: a pin has to lapse
     * whether or not the site's scheduler ever runs, and this module
     * already self-heals from page loads elsewhere
     * (Service\SectionGroupSyncService, Service\ModeratorBindingService).
     *
     * @return int how many posts stopped being pinned
     */
    public function expireStalePins(int $groupId): int
    {
        return $this->postRepository->clearExpiredPins($groupId, Timestamps::now());
    }

    /**
     * A short, plain-text handle on whatever this group currently has
     * pinned — what the confirmation names as the post about to lose its
     * pin, so "épingler" never silently replaces something.
     *
     * Empty when nothing is pinned, and empty for anyone who is not a
     * moderator: the callers only ask when they have already established
     * that (a member is never offered the pin control at all). A post
     * with no text of its own — a photo, a poll — has no excerpt to
     * quote and comes back as the neutral wording instead.
     */
    public function pinnedLabel(int $groupId): string
    {
        $pinned = $this->postRepository->findPinned($groupId, true);
        if ($pinned === []) {
            return '';
        }

        $body = trim(preg_replace('/\s+/u', ' ', $pinned[0]->body) ?? '');
        if ($body === '') {
            return 'le message épinglé';
        }

        return mb_strlen($body) > 60 ? mb_substr($body, 0, 60) . '…' : $body;
    }

    /**
     * The stored deadline for a duration key, in UTC like every other
     * timestamp this module writes (Support\Timestamps).
     */
    private function pinDeadline(string $duration): ?string
    {
        // array_key_exists, never `?? default`: "forever" IS null here,
        // and a null-coalesce would quietly turn the one choice that
        // means "no deadline" into the default week.
        $key = array_key_exists($duration, self::PIN_DURATIONS) ? $duration : self::PIN_DURATION_DEFAULT;
        $modifier = self::PIN_DURATIONS[$key];
        if ($modifier === null) {
            return null;
        }

        return Timestamps::at($modifier);
    }

    /**
     * Plain text in, plain text out: line endings normalised so the "keep
     * line breaks" rendering is consistent, control characters other than
     * newline and tab dropped, and the length bounded. No markup is
     * produced, interpreted or stripped — Twig escapes the result.
     */
    public function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/[^\P{C}\n\t]/u', '', $body);

        return mb_substr(trim($body), 0, self::MAX_BODY_LENGTH);
    }

    /**
     * A media-only, link-only or poll-only post is valid (module spec); a
     * post with none of text, media, a link or a poll is not. $mediaCount
     * is trusted as-is — the caller (Controller\PostController::create())
     * is the one that already capped it against Service\PostMediaService::
     * MAX_MEDIA_PER_POST, and already validated $hasLink's URL via
     * Service\PostLinkService::isValidUrl() before reaching here.
     *
     * $hasPoll is likewise already normalised by Service\PollService: it
     * is true only for a poll that has a question AND at least two
     * options, so a half-filled poll cannot keep an otherwise empty post
     * alive.
     */
    public function isPostable(string $body, int $mediaCount = 0, bool $hasLink = false, bool $hasPoll = false): bool
    {
        return $this->normalize($body) !== '' || $mediaCount > 0 || $hasLink || $hasPoll;
    }
}
