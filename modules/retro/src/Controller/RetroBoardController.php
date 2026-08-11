<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentException;
use Core\Cookie\CookieConsentService;
use Core\Cookie\CookieHelper;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\Comment;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\CommentService;
use Modules\Retro\Service\ModerationService;
use Modules\Retro\Service\RateLimitService;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\VoteService;
use Twig\Environment;

/**
 * The public, token-based participation surface — reached via /r/{token}
 * (or /s/{code} redirecting into it), never via a board's internal numeric
 * id. Serves both anonymous participants and chiefs from the SAME route:
 * a logged-in chef d'unité (Core\Member\UnitStaffSectionService section
 * membership, not merely Role::CHIEF — see isUnitChief()) opening the link
 * sees the extra moderation controls (hide/unhide), everyone else —
 * including a chief of just one section — sees the plain participant view.
 *
 * Deliberately never touches JournalService — see schema.sql's header
 * comment and Service\CommentService's class doc for why posting/voting/
 * hiding are excluded from the journal on principle.
 */
class RetroBoardController extends AbstractController
{
    private const VOTER_COOKIE = 'retro_voter';
    private const VOTER_COOKIE_DAYS = 365;

    public function __construct(
        Environment $twig,
        private BoardRepository $boardRepository,
        private CommentRepository $commentRepository,
        private CommentService $commentService,
        private VoteService $voteService,
        private BoardService $boardService,
        private RateLimitService $rateLimitService,
        private ?ModerationService $moderationService,
        private CookieConsentService $cookieConsentService,
        private SettingService $settingService,
        private ScoutYearService $scoutYearService
    ) {
        parent::__construct($twig);
    }

    /**
     * Chef d'unité specifically (Core\Member\UnitStaffSectionService::
     * DESK_CODE section membership) — narrower than Role::CHIEF, which any
     * section's chief already has. Gates masked-comment visibility/
     * moderation only; unrelated to early vote-count reveal (revealVotes),
     * which intentionally stays available to any chief.
     */
    private function isUnitChief(Role $viewerRole): bool
    {
        if (!$viewerRole->hasAccess(Role::CHIEF)) {
            return false;
        }
        $email = AuthSession::getEmail();
        if ($email === null) {
            return false;
        }

        return $this->boardService->isUnitChief($email, $this->scoutYearService->getCurrentYear()['id']);
    }

    /**
     * 'disabled'|'warning'|'enforced', resolved server-side — never trust a
     * client-supplied mode. Forced to 'disabled' whenever the moderation
     * service is absent/unavailable, regardless of what the setting says
     * (the module spec: "disabled (only option if AI is not enabled)").
     */
    private function resolveModerationMode(): string
    {
        if ($this->moderationService === null || !$this->moderationService->isAvailable()) {
            return 'disabled';
        }

        $mode = (string) ($this->settingService->get('retro_moderation_mode', 'retro') ?: 'disabled');
        return in_array($mode, ['disabled', 'warning', 'enforced'], true) ? $mode : 'disabled';
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return new Response('Not Found', 404);
        }

        $viewerRole = Role::fromString(AuthSession::getRole());
        $isChief = $viewerRole->hasAccess(Role::CHIEF);
        $isUnitChief = $this->isUnitChief($viewerRole);
        $canVote = $this->canVote($board, $viewerRole);

        $revealVotes = $isChief || $board->resultsRevealed();
        $comments = $this->commentService->listForDisplay($board->id, $board->resultsRevealed());

        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');

        // Resolving the voter identifier here (rather than only on the vote
        // AJAX endpoints) lets a fresh page load seed budget mode's "points
        // remaining" display without waiting for a first vote action —
        // cookie mode also issues the anonymous voter cookie a visit early,
        // same best-effort/no-consent-no-block behavior as the vote actions.
        $voterIdentifier = $canVote ? $this->resolveVoterIdentifier($request, $board) : null;
        $remainingBudget = $board->voteMode === 'budget' && $voterIdentifier !== null
            ? $this->voteService->remainingBudget($board, $voterIdentifier)
            : null;
        $votedCommentIds = $voterIdentifier !== null
            ? $this->voteService->votedCommentIds($board->id, array_map(fn(Comment $c) => $c->id, $comments), $voterIdentifier)
            : [];
        $votedSet = array_flip($votedCommentIds);

        $byColumn = ['good' => [], 'improve' => [], 'suggestion' => []];
        foreach ($comments as $comment) {
            $byColumn[$comment->columnKey][] = $this->serializeComment($comment, $isUnitChief, $revealVotes, isset($votedSet[$comment->id]));
        }

        return $this->render('@retro/board.html.twig', [
            'board' => $board,
            'token' => $board->token,
            'is_unit_chief' => $isUnitChief,
            'can_vote' => $canVote,
            'vote_requires_auth' => $board->antiDuplicateMode === 'auth',
            'columns' => $byColumn,
            'ai_available' => $this->moderationService !== null && $this->moderationService->isAvailable(),
            'polling_interval_seconds' => (int) ($this->settingService->get('retro_polling_interval_seconds', 'retro') ?: 8),
            'csrf_token' => CsrfGuard::generateToken(),
            'public_url' => $baseUrl . $this->boardService->publicUrl($board),
            'remaining_budget' => $remainingBudget,
        ]);
    }

    /**
     * GET /r/{token}/qr — PNG QR code for the board's own public URL, for
     * projecting/printing (module spec: "Works both in person ... and
     * remotely").
     *
     * @param array<string, string> $params
     */
    public function qr(Request $request, array $params): Response
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return new Response('Not Found', 404);
        }

        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');
        $url = $baseUrl . $this->boardService->publicUrl($board);

        $result = (new \Endroid\QrCode\Builder\Builder(
            writer: new \Endroid\QrCode\Writer\PngWriter(),
            data: $url,
            size: 300,
            margin: 10
        ))->build();

        return (new Response($result->getString()))->setHeader('Content-Type', 'image/png');
    }

    /**
     * GET /r/{token}/poll (AJAX, JSON) — near-real-time updates. Returns
     * only what a poller needs to redraw comments/votes/status; hidden
     * comments' bodies are redacted for non-chiefs at the source (never
     * sent to the browser at all, not merely hidden client-side).
     *
     * @param array<string, string> $params
     */
    public function poll(Request $request, array $params): Response
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return $this->json(['error' => 'Introuvable.'], 404);
        }

        $viewerRole = Role::fromString(AuthSession::getRole());
        $isChief = $viewerRole->hasAccess(Role::CHIEF);
        $isUnitChief = $this->isUnitChief($viewerRole);
        $revealVotes = $isChief || $board->resultsRevealed();
        $comments = $this->commentService->listForDisplay($board->id, $board->resultsRevealed());

        $canVote = $this->canVote($board, $viewerRole);
        $voterIdentifier = $canVote ? $this->resolveVoterIdentifier($request, $board) : null;
        $votedSet = $voterIdentifier !== null
            ? array_flip($this->voteService->votedCommentIds($board->id, array_map(fn(Comment $c) => $c->id, $comments), $voterIdentifier))
            : [];

        return $this->json([
            'status' => $board->status,
            'comments' => array_map(
                fn(Comment $c) => $this->serializeComment($c, $isUnitChief, $revealVotes, isset($votedSet[$c->id])),
                $comments
            ),
        ]);
    }

    /**
     * POST /r/{token}/comments (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function addComment(Request $request, array $params): Response
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return $this->json(['success' => false, 'error' => 'Introuvable.'], 404);
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $rateLimitHash = $this->rateLimitService->identifierHash($this->readVoterCookie($request), session_id());
        $moderationMode = $this->resolveModerationMode();
        $acceptedWarning = (bool) ($data['accepted_warning'] ?? false);

        try {
            $comment = $this->commentService->postComment(
                $board,
                (string) ($data['column'] ?? ''),
                (string) ($data['body'] ?? ''),
                $rateLimitHash,
                $moderationMode,
                $acceptedWarning
            );
        } catch (RetroException $e) {
            return $this->json([
                'success' => false, 'error' => $e->getMessage(), 'type' => $e->type,
                'suggestion' => $e->suggestion, 'moderation_mode' => $moderationMode,
            ], 422);
        }

        return $this->json(['success' => true, 'comment' => $this->serializeComment($comment, false, true, false)]);
    }

    /**
     * POST /r/{token}/shorten (AJAX, JSON) — AI rewrite preview only, never
     * saves anything; the caller must resubmit via addComment() for the
     * result to be published.
     *
     * @param array<string, string> $params
     */
    public function shorten(Request $request, array $params): Response
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return $this->json(['success' => false, 'error' => 'Introuvable.'], 404);
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if ($this->moderationService === null) {
            return $this->json(['success' => false, 'error' => 'Service IA non disponible.'], 422);
        }

        try {
            $shortened = $this->moderationService->shorten((string) ($data['body'] ?? ''), $board->maxCommentLength);
        } catch (RetroException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'body' => $shortened]);
    }

    /**
     * @param array<string, string> $params
     */
    public function toggleLike(Request $request, array $params): Response
    {
        [$board, $comment, $error] = $this->resolveBoardAndComment($params);
        if ($error !== null) {
            return $error;
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $voterIdentifier = $this->resolveVoterIdentifier($request, $board);
        if ($voterIdentifier === null) {
            return $this->json(['success' => false, 'error' => 'Connexion requise pour voter.'], 403);
        }

        try {
            $this->rateLimitService->checkAndRecord($this->rateLimitService->identifierHash($this->readVoterCookie($request), session_id()), 'vote');
            $liked = $this->voteService->toggleLike($board, $comment, $voterIdentifier);
        } catch (RetroException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $isChief = Role::fromString(AuthSession::getRole())->hasAccess(Role::CHIEF);
        $revealVotes = $isChief || $board->resultsRevealed();
        $votes = $revealVotes ? ($this->commentRepository->findById($comment->id)?->votes ?? 0) : null;

        return $this->json(['success' => true, 'liked' => $liked, 'votes' => $votes]);
    }

    /**
     * @param array<string, string> $params
     */
    public function addPoint(Request $request, array $params): Response
    {
        return $this->handleBudgetVote($request, $params, add: true);
    }

    /**
     * @param array<string, string> $params
     */
    public function removePoint(Request $request, array $params): Response
    {
        return $this->handleBudgetVote($request, $params, add: false);
    }

    /**
     * @param array<string, string> $params
     */
    public function hideComment(Request $request, array $params): Response
    {
        return $this->setHidden($request, $params, true);
    }

    /**
     * @param array<string, string> $params
     */
    public function unhideComment(Request $request, array $params): Response
    {
        return $this->setHidden($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function handleBudgetVote(Request $request, array $params, bool $add): Response
    {
        [$board, $comment, $error] = $this->resolveBoardAndComment($params);
        if ($error !== null) {
            return $error;
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $voterIdentifier = $this->resolveVoterIdentifier($request, $board);
        if ($voterIdentifier === null) {
            return $this->json(['success' => false, 'error' => 'Connexion requise pour voter.'], 403);
        }

        try {
            if ($add) {
                $this->rateLimitService->checkAndRecord($this->rateLimitService->identifierHash($this->readVoterCookie($request), session_id()), 'vote');
                $remaining = $this->voteService->addPoint($board, $comment, $voterIdentifier);
            } else {
                $remaining = $this->voteService->removePoint($board, $comment, $voterIdentifier);
            }
        } catch (RetroException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $isChief = Role::fromString(AuthSession::getRole())->hasAccess(Role::CHIEF);
        $revealVotes = $isChief || $board->resultsRevealed();
        $votes = $revealVotes ? ($this->commentRepository->findById($comment->id)?->votes ?? 0) : null;

        return $this->json(['success' => true, 'remaining' => $remaining, 'votes' => $votes]);
    }

    /**
     * @param array<string, string> $params
     */
    private function setHidden(Request $request, array $params, bool $hidden): Response
    {
        if (!$this->isUnitChief(Role::fromString(AuthSession::getRole()))) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        [, $comment, $error] = $this->resolveBoardAndComment($params);
        if ($error !== null) {
            return $error;
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $hidden ? $this->commentService->hide($comment->id) : $this->commentService->unhide($comment->id);

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: ?Board, 1: ?Comment, 2: ?Response}
     */
    private function resolveBoardAndComment(array $params): array
    {
        $board = $this->boardRepository->findByToken((string) ($params['token'] ?? ''));
        if ($board === null) {
            return [null, null, $this->json(['success' => false, 'error' => 'Introuvable.'], 404)];
        }

        $comment = $this->commentRepository->findById((int) ($params['comment_id'] ?? 0));
        if ($comment === null || $comment->boardId !== $board->id) {
            return [null, null, $this->json(['success' => false, 'error' => 'Commentaire introuvable.'], 404)];
        }

        return [$board, $comment, null];
    }

    private function canVote(Board $board, Role $viewerRole): bool
    {
        return $board->antiDuplicateMode !== 'auth' || $viewerRole->hasAccess(Role::IDENTIFIED);
    }

    /**
     * Cookie mode: the anonymous functional cookie (or the PHP session id
     * as a same-visit-only fallback when consent is refused — module spec:
     * "Best-effort — if consent is refused, voting still works, just
     * without duplicate protection"). Auth mode: the account id, prefixed
     * distinctly so the two modes can never collide even if a board's mode
     * changes after votes were cast under the other one. Null only when
     * auth mode requires a login the visitor doesn't have.
     */
    private function resolveVoterIdentifier(Request $request, Board $board): ?string
    {
        if ($board->antiDuplicateMode === 'auth') {
            $userId = AuthSession::getUserAccountId();
            return $userId !== null ? 'account:' . $userId : null;
        }

        return 'cookie:' . $this->getOrSetVoterCookie($request);
    }

    private function readVoterCookie(Request $request): ?string
    {
        $value = $request->getCookie(self::VOTER_COOKIE);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function getOrSetVoterCookie(Request $request): string
    {
        $existing = $this->readVoterCookie($request);
        if ($existing !== null) {
            return $existing;
        }

        $value = bin2hex(random_bytes(16));
        try {
            CookieHelper::set(
                self::VOTER_COOKIE,
                $value,
                time() + self::VOTER_COOKIE_DAYS * 86400,
                'functional',
                $this->cookieConsentService
            );
        } catch (CookieConsentException) {
            // Best-effort — falls back to this request's session id via
            // RateLimitService/resolveVoterIdentifier() callers using
            // session_id() directly when no cookie ever gets persisted.
            return session_id() ?: $value;
        }

        return $value;
    }

    /**
     * @return array{id: int, column: string, body: ?string, votes: ?int, hidden: bool, youVoted: bool, created_at?: string}
     */
    private function serializeComment(Comment $comment, bool $isUnitChief, bool $revealVotes, bool $youVoted): array
    {
        if ($comment->hidden && !$isUnitChief) {
            return ['id' => $comment->id, 'column' => $comment->columnKey, 'body' => null, 'votes' => null, 'hidden' => true, 'youVoted' => $youVoted];
        }

        return [
            'id' => $comment->id,
            'column' => $comment->columnKey,
            'body' => $comment->body,
            'votes' => $revealVotes ? $comment->votes : null,
            'hidden' => $comment->hidden,
            'youVoted' => $youVoted,
            'created_at' => $comment->createdAt,
        ];
    }
}
