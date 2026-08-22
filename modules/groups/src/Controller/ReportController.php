<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupFeedService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Service\GroupSessionContextFactory;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\ReportService;
use Modules\Groups\Support\GroupLabel;
use Modules\Groups\Support\ReportedAuthor;
use Twig\Environment;

/**
 * Reporting a post or a reply, and a moderator's answer to it: restore.
 * (Deleting stays in PostController / ReplyController, which already own
 * the media-cleanup ordering a deletion needs.)
 *
 * The 404-not-403 rule of Controller\ReactionController applies unchanged
 * and for the same oracle reason: POSTing a report at an arbitrary post id
 * must not answer differently for "exists in a group you cannot see" than
 * for "does not exist". So the group is resolved and read-checked first,
 * and the item is then required to belong to THAT group.
 *
 * The reporter is told nothing about the outcome. The same neutral
 * confirmation is shown whether this was a first report, a repeat, the one
 * that crossed the threshold, or one on an item a moderator has already
 * restored — nothing here leaks how many others reported, or what
 * happened next.
 */
class ReportController extends AbstractController
{
    /**
     * Deliberately identical for every path through report(): see the
     * class docblock.
     */
    private const CONFIRMATION = 'Merci, votre signalement a bien été transmis aux modérateurs du groupe.';

    /**
     * Returned by a restore closure instead of true/false when the
     * moderator authored the item themselves — a third answer, because it
     * is neither "found and done" nor "not found".
     */
    private const SELF_RESTORE = 'self';

    public function __construct(
        protected Environment $twig,
        private GroupRepository $groupRepository,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private GroupAccessService $accessService,
        private ReportService $reportService,
        private GroupSessionContextFactory $contextFactory,
        private ?GroupNotificationService $notificationService = null,
        private ?GroupRecipientResolver $recipientResolver = null,
        // Optional and trailing like the two above: without it the two
        // report/restore actions work exactly as before, only the
        // moderator's list has nothing to render.
        private ?GroupFeedService $feedService = null,
        // Same posture again, for one line of the page: which message a
        // pin would replace (Service\PostService::pinnedLabel()).
        private ?PostService $postService = null
    ) {
    }

    /**
     * POST /groups/{id}/posts/{postId}/report
     *
     * @param array<string, string> $params
     */
    public function reportPost(Request $request, array $params): Response
    {
        return $this->reportAction($request, $params, 'postId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context, int $memberId) {
            $post = $this->postRepository->findById($itemId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }
            // Already hidden from this member by moderation — reporting what
            // you cannot see adds nothing, and leaving it open lets the
            // endpoint confirm a hidden post still exists. Moderators, who
            // can see it, keep the ability.
            if ($post->isHidden() && !$this->accessService->canModerate($group, $context)) {
                return false;
            }

            if ($this->reportService->reportPost($group->id, $post->id, $memberId, $context->userAccountId)) {
                // Only on a NEW report: a repeat submission by the same
                // member must not buzz every moderator again, and it must
                // stay indistinguishable from a first one at the endpoint
                // (prompt 9) — which it does, since the caller sees the
                // same response either way.
                $author = new ReportedAuthor($post->authorUserAccountId, $post->authorMemberId);
                $this->notify($group, $post->id, 'post', $post->id, $context, $author);
            }

            return true;
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/report
     *
     * @param array<string, string> $params
     */
    public function reportReply(Request $request, array $params): Response
    {
        return $this->reportAction($request, $params, 'replyId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context, int $memberId) {
            $reply = $this->replyRepository->findById($itemId);
            if ($reply === null) {
                return false;
            }

            $post = $this->postRepository->findById($reply->postId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }
            if (($reply->isHidden() || $post->isHidden()) && !$this->accessService->canModerate($group, $context)) {
                return false;
            }

            if ($this->reportService->reportReply($group->id, $reply->id, $memberId, $context->userAccountId)) {
                // The deep link points at the reply's POST: a reply has no
                // page of its own, and the post is what a moderator has to
                // open to judge it in context.
                $author = new ReportedAuthor($reply->authorUserAccountId, $reply->authorMemberId);
                $this->notify($group, $post->id, 'reply', $reply->id, $context, $author);
            }

            return true;
        });
    }

    /**
     * POST /groups/{id}/posts/{postId}/restore — moderator only.
     *
     * @param array<string, string> $params
     */
    public function restorePost(Request $request, array $params): Response
    {
        return $this->restoreAction($request, $params, 'postId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context) {
            $post = $this->postRepository->findById($itemId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }

            if ($this->isOwnItem($post->authorUserAccountId, $post->authorMemberId, $context)) {
                return self::SELF_RESTORE;
            }

            $this->reportService->restorePost($group->id, $post->id, $context->userAccountId);

            return true;
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/restore — moderator only.
     *
     * @param array<string, string> $params
     */
    public function restoreReply(Request $request, array $params): Response
    {
        return $this->restoreAction($request, $params, 'replyId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context) {
            $reply = $this->replyRepository->findById($itemId);
            if ($reply === null) {
                return false;
            }

            $post = $this->postRepository->findById($reply->postId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }

            if ($this->isOwnItem($reply->authorUserAccountId, $reply->authorMemberId, $context)) {
                return self::SELF_RESTORE;
            }

            $this->reportService->restoreReply($group->id, $reply->id, $context->userAccountId);

            return true;
        });
    }

    /**
     * GET /groups/{id}/reports — a moderator's own list of what is
     * waiting for them.
     *
     * The gap this closes: reports notified the moderators and then
     * hid an item once enough of them arrived, and in between there was
     * nowhere to go and nothing to act on — a moderator who wanted to
     * judge a single report had to find the message by scrolling. This is
     * that place, and it is a list of CONVERSATIONS rather than of
     * fragments: a reported comment is judged inside the message it
     * answers, where the actions already live.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        // A member of the group may read it, so 403 rather than 404 —
        // exactly the reasoning restoreAction() applies (this class's own
        // docblock): they already know the group exists.
        if (!$this->accessService->canModerate($group, $context)) {
            return new Response('Seul un modérateur du groupe peut consulter les signalements.', 403);
        }

        $reported = $this->reportService->reportedInGroup($group->id);

        return $this->render('@groups/reports.html.twig', [
            'group' => $group,
            'group_label' => GroupLabel::withYear(
                $group,
                $context->effectiveScoutYearId,
                $context->effectiveScoutYearLabel
            ),
            'badges' => [
                'is_invitation' => $group->sectionId === null,
                'section_names' => [],
                'is_archived' => false,
                'is_moderator' => true,
            ],
            'rows' => $this->feedService?->rowsForPostIds($group, $context, $reported['post_ids']) ?? [],
            'reply_counts' => $reported['reply_counts'],
            'threshold' => $this->reportService->threshold(),
            // Only a moderator reaches this page at all, so the pin
            // control on these cards is offered — and needs the same
            // "what would it replace?" the group page passes.
            'pinned_post_label' => $this->postService?->pinnedLabel($group->id) ?? '',
            'breadcrumb_trail' => [
                ['label' => 'Groupes', 'url' => '/groups'],
                ['label' => $group->name, 'url' => '/groups/' . $group->id],
            ],
        ]);
    }

    /**
     * POST /groups/{id}/posts/{postId}/hide — moderator only.
     *
     * The judgement a count cannot make: one report is sometimes already
     * enough, and waiting for a threshold to agree is not moderation.
     * Nothing is deleted — restore() undoes exactly this.
     *
     * @param array<string, string> $params
     */
    public function hidePost(Request $request, array $params): Response
    {
        return $this->hideAction($request, $params, 'postId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context): bool {
            $post = $this->postRepository->findById($itemId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }

            $this->reportService->hidePost($group->id, $post->id, $context->userAccountId);

            return true;
        });
    }

    /**
     * POST /groups/{id}/replies/{replyId}/hide — moderator only.
     *
     * @param array<string, string> $params
     */
    public function hideReply(Request $request, array $params): Response
    {
        return $this->hideAction($request, $params, 'replyId', function (DiscussionGroup $group, int $itemId, GroupSessionContext $context): bool {
            $reply = $this->replyRepository->findById($itemId);
            if ($reply === null) {
                return false;
            }

            $post = $this->postRepository->findById($reply->postId);
            if ($post === null || $post->groupId !== $group->id) {
                return false;
            }

            $this->reportService->hideReply($group->id, $reply->id, $context->userAccountId);

            return true;
        });
    }

    /**
     * The same shape as restoreAction(), minus the self-restore refusal:
     * hiding your own content is not a conflict of interest, it is
     * agreeing with the report.
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, int, GroupSessionContext): bool $hide
     */
    private function hideAction(Request $request, array $params, string $idKey, callable $hide): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        if (!$this->accessService->canModerate($group, $context)) {
            return new Response('Seul un modérateur du groupe peut masquer un contenu.', 403);
        }

        if (!$hide($group, (int) ($params[$idKey] ?? 0), $context)) {
            return new Response('Not Found', 404);
        }

        FlashMessage::set('success', 'Le contenu a été masqué : seuls les modérateurs du groupe le voient encore.');

        return $this->redirect($request->getBody('return_to') === 'reports'
            ? '/groups/' . $group->id . '/reports'
            : '/groups/' . $group->id);
    }

    /**
     * Reporting needs read access and a member identity, nothing more — a
     * closed group or a past scout year still gets reported, because an
     * item that stays readable stays reportable. That is deliberately
     * weaker than canPost(), which governs writes to the conversation
     * itself.
     *
     * $locate returns false when the item does not belong to the
     * authorised group (→ 404).
     *
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, int, GroupSessionContext, int): bool $locate
     */
    private function reportAction(Request $request, array $params, string $idKey, callable $locate): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        // Which member the report is recorded under: the same resolution a
        // post or a reaction uses, so the UNIQUE (item, member) index
        // means one report per person and not one per account-member link.
        $allowed = $this->accessService->memberIdsAllowedToPostAs($group, $context);
        $memberId = $allowed[0] ?? 0;
        if ($memberId === 0) {
            return new Response('Aucun membre de ce groupe n\'est associé à votre compte.', 403);
        }

        if (!$locate($group, (int) ($params[$idKey] ?? 0), $context, $memberId)) {
            return new Response('Not Found', 404);
        }

        // groups.js shows the confirmation next to the message that was
        // reported instead of reloading the whole group for one line of
        // reassurance. The SAME sentence either way, for the same reason
        // it is a constant: the reporter must not be able to tell a first
        // report from a repeat, nor a report from the one that crossed
        // the threshold (this class's own docblock).
        if ($this->wantsJson($request)) {
            return $this->json(['reported' => true, 'message' => self::CONFIRMATION]);
        }

        FlashMessage::set('success', self::CONFIRMATION);

        return $this->redirect('/groups/' . $group->id);
    }

    /**
     * @param array<string, string> $params
     * @param callable(DiscussionGroup, int, GroupSessionContext): (bool|string) $restore
     */
    private function restoreAction(Request $request, array $params, string $idKey, callable $restore): Response
    {
        if (!CsrfGuard::validateRequest()) {
            return new Response('Jeton CSRF invalide.', 403);
        }

        $context = $this->context();
        $group = $this->readableGroup($params, $context);
        if ($group === null) {
            return new Response('Not Found', 404);
        }

        // A non-moderator member of the group may read the item, so 403 —
        // not 404 — is right here: it reveals nothing they did not
        // already know.
        if (!$this->accessService->canModerate($group, $context)) {
            return new Response('Seul un modérateur du groupe peut rétablir un contenu masqué.', 403);
        }

        $result = $restore($group, (int) ($params[$idKey] ?? 0), $context);

        // A moderator restoring their OWN reported content is the one
        // conflict of interest this module cannot leave open: in a space
        // where minors write, the person a report is about must not be
        // the person who decides the report was wrong. Deleting their own
        // item stays allowed — that is only deleting your own content.
        if ($result === self::SELF_RESTORE) {
            $message = 'Vous ne pouvez pas rétablir un contenu que vous avez écrit : un autre modérateur du groupe, '
                . 'ou un administrateur du site, doit s\'en charger.';

            if ($this->wantsJson($request)) {
                return $this->json(['error' => $message], 403);
            }

            FlashMessage::set('error', $message);

            return $this->redirect('/groups/' . $group->id);
        }

        if ($result !== true) {
            return new Response('Not Found', 404);
        }

        // Nothing but the hidden state changed, so groups.js takes the
        // banner and the dimming off the item where it stands rather than
        // reloading the group around it — there is no re-render to ask
        // for, because a restored item is the item as it already reads
        // underneath its own warning.
        if ($this->wantsJson($request)) {
            return $this->json(['restored' => true]);
        }

        FlashMessage::set('success', 'Le contenu a été rétabli et ne sera plus masqué automatiquement.');

        return $this->redirect('/groups/' . $group->id);
    }

    /**
     * groups.js sends this header on every dynamic submit — the same
     * signal Controller\PostController and Controller\ReactionController
     * answer to. A plain form POST (no JS, or JS that failed to load)
     * never sets it and keeps the flash-and-redirect behaviour unchanged.
     */
    private function wantsJson(Request $request): bool
    {
        return $request->getServer('HTTP_X_REQUESTED_WITH') === 'XMLHttpRequest';
    }

    /**
     * Notifies the moderators about one new report, escalating to site
     * admins when the reported item was written by one of the group's own
     * moderators — the conflict of interest this whole path exists for.
     *
     * The escalation is decided once, here, and used for both the
     * notification and the journal entry, so the two can never disagree
     * about whether a given report was escalated.
     */
    private function notify(
        DiscussionGroup $group,
        int $postId,
        string $kind,
        int $itemId,
        GroupSessionContext $context,
        ReportedAuthor $author
    ): void {
        if ($this->recipientResolver?->isExplicitModeratorAccount($group, $author->userAccountId) === true) {
            $this->reportService->journalEscalation($kind, $group->id, $itemId, $context->userAccountId);
        }

        $this->notificationService?->itemReported($group, $postId, $kind, $context->userAccountId, $author);
    }

    /**
     * Whether the item under review is the caller's own.
     *
     * Both identities count: the account that actually typed it, and the
     * member it was signed as. A parent posting as their child must not
     * be able to restore it by arguing the author was the child.
     */
    private function isOwnItem(int $authorAccountId, int $authorMemberId, GroupSessionContext $context): bool
    {
        if ($context->userAccountId !== null && $authorAccountId === $context->userAccountId) {
            return true;
        }

        return in_array($authorMemberId, $context->linkedMemberIds, true);
    }

    /**
     * @param array<string, string> $params
     */
    private function readableGroup(array $params, GroupSessionContext $context): ?DiscussionGroup
    {
        $group = $this->groupRepository->findById((int) ($params['id'] ?? 0));
        if ($group === null || !$this->accessService->canRead($group, $context)) {
            return null;
        }

        return $group;
    }

    private function context(): GroupSessionContext
    {
        return $this->contextFactory->build(
            AuthSession::getEmail(),
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            ScoutYearSession::getPreviewId()
        );
    }
}
