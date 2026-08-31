<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Security\CapabilityToken;
use Core\Security\Role;
use Core\Service\DateInput;
use Core\Service\IntegerInput;
use Core\Url\ShortUrlService;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Retro\Api\RetroEventLinkLookupInterface;
use Modules\Retro\Api\RetroLinkSummary;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;

/**
 * Board lifecycle (create/update/close/regenerate link). Unlike
 * Comment/Vote/RateLimit services, board-level actions here ARE logged via
 * JournalService — creating, closing, or auto-closing a board is unit
 * management activity by a named chief, not an anonymous participant
 * action, so it doesn't carry the deanonymisation risk the module spec
 * warns about elsewhere.
 *
 * Also implements Api\RetroEventLinkLookupInterface — the calendar
 * module's optional reverse dependency (mirror of this class's own
 * CalendarEventLookupInterface dependency above) for showing a linked
 * board's link in the event GUI/ICS export.
 */
class BoardService implements RetroEventLinkLookupInterface
{
    private const EVENT_WINDOW_DAYS_BEFORE = 10;
    private const EVENT_WINDOW_DAYS_AFTER = 15;
    private const MIN_MAX_COMMENT_LENGTH = 120;
    private const MAX_MAX_COMMENT_LENGTH = 200;

    public function __construct(
        private BoardRepository $boardRepository,
        private CommentRepository $commentRepository,
        private MemberService $memberService,
        private SectionService $sectionService,
        private SchedulerService $schedulerService,
        private JournalService $journalService,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private string $siteName,
        private string $baseUrl,
        private ?ShortUrlService $shortUrlService = null,
        private ?CalendarEventLookupInterface $calendarEventLookup = null,
        private ?SummaryService $summaryService = null
    ) {
    }

    /**
     * The section of the highest-role member linked to $email for
     * $scoutYearId — same resolution SectionPicker uses by default
     * (ARCHITECTURE.md §8.8). Null when the account has no linked member
     * or that member has no section-bearing function.
     */
    public function resolvePrincipalSectionId(string $email, int $scoutYearId): ?int
    {
        $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);
        if ($linkedMembers === []) {
            return null;
        }

        $best = MemberService::getHighestRoleMember($linkedMembers);
        $sectionCode = $best?->getMainFunction()?->sectionCode;
        if ($sectionCode === null) {
            return null;
        }

        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if ($section['desk_code'] === $sectionCode) {
                return (int) $section['id'];
            }
        }

        return null;
    }

    /**
     * Whether $email's highest-role linked member (for $scoutYearId) sits
     * in the "Staff d'U" section specifically — i.e. is a chef d'unité, not
     * merely a chief of one section. Distinct from Role::CHIEF (a site-wide
     * RBAC floor granted to every section's chiefs alike): this checks
     * actual section membership (Core\Member\UnitStaffSectionService::
     * DESK_CODE), the same signal syncMembership() uses to populate that
     * section. Used to gate who may reveal/toggle a masked retro comment —
     * a narrower audience than "any chief" on purpose (module spec update).
     */
    public function isUnitChief(string $email, int $scoutYearId): bool
    {
        return $this->memberService->isUnitChief($email, $scoutYearId);
    }

    /**
     * Events available for the picker: -10/+15 days around today, scoped
     * server-side by role/section (module spec — never just hidden
     * client-side). $sectionId is ignored (every section visible) when
     * $isAdmin is true. Empty when the calendar module is disabled.
     *
     * @return EventSummary[]
     */
    public function listEventsForPicker(?int $sectionId, bool $isAdmin, Role $viewerRole): array
    {
        if ($this->calendarEventLookup === null) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $windowStart = $today->modify('-' . self::EVENT_WINDOW_DAYS_BEFORE . ' days');
        $windowEnd = $today->modify('+' . self::EVENT_WINDOW_DAYS_AFTER . ' days');

        return $this->calendarEventLookup->findEventsInWindow($windowStart, $windowEnd, $isAdmin ? null : $sectionId, $viewerRole);
    }

    /**
     * @throws RetroException on invalid input
     */
    public function create(
        string $title,
        ?int $calendarEventId,
        ?string $manualDate,
        bool $listed,
        string $voteMode,
        int $voteBudget,
        bool $votesVisible,
        string $antiDuplicateMode,
        int $maxCommentLength,
        string $autoCloseDelay,
        Role $viewerRole,
        ?int $createdBy,
        bool $closeNotifyEnabled = true,
        ?string $closeNotifyEmail = null,
        string $linkVisibility = 'chief'
    ): Board {
        $title = $this->resolveTitle($calendarEventId, $title, $viewerRole);
        [$title, $voteBudget, $maxCommentLength] = $this->validateCommon(
            $title, $voteMode, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $voteBudget
        );
        $linkVisibility = $this->validateLinkVisibility($linkVisibility);

        // Server-side pairing enforcement (module spec): the client's date
        // is never trusted when an event is linked — it's always
        // re-resolved from the event itself.
        $boardDate = $this->resolveBoardDate($calendarEventId, $manualDate, $viewerRole);

        $token = $this->generateToken();
        $shortCode = $this->tryCreateShortLink($token, $createdBy);
        $autoCloseAt = $this->computeAutoCloseAt($autoCloseDelay);

        $id = $this->boardRepository->create(
            $title, $boardDate, $calendarEventId, $token, $shortCode, $listed, $voteMode, $voteBudget,
            $votesVisible, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $autoCloseAt, $createdBy,
            $closeNotifyEnabled, $this->normalizeEmail($closeNotifyEmail), $linkVisibility
        );

        if ($autoCloseAt !== null) {
            $this->scheduleAutoClose($id, $autoCloseAt);
        }

        $this->journalService->log('retro', 'board_created', 'info', 'Rétrospective créée', ['board_id' => $id], $createdBy);

        $board = $this->boardRepository->findById($id);
        \assert($board !== null);

        return $board;
    }

    /**
     * @throws RetroException on invalid input or an unknown board
     */
    public function update(
        int $id,
        string $title,
        ?int $calendarEventId,
        ?string $manualDate,
        bool $listed,
        string $voteMode,
        int $voteBudget,
        bool $votesVisible,
        string $antiDuplicateMode,
        int $maxCommentLength,
        string $autoCloseDelay,
        Role $viewerRole,
        ?int $updatedBy,
        bool $closeNotifyEnabled = true,
        ?string $closeNotifyEmail = null,
        string $linkVisibility = 'chief'
    ): Board {
        $existing = $this->boardRepository->findById($id);
        if ($existing === null) {
            throw new RetroException('Rétrospective introuvable.');
        }

        $title = $this->resolveTitle($calendarEventId, $title, $viewerRole);
        [$title, $voteBudget, $maxCommentLength] = $this->validateCommon(
            $title, $voteMode, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $voteBudget
        );
        $linkVisibility = $this->validateLinkVisibility($linkVisibility);
        $boardDate = $this->resolveBoardDate($calendarEventId, $manualDate, $viewerRole);

        // Changing the delay resets the countdown from now — editing while
        // open is explicitly allowed by the module spec, and re-basing
        // from the original creation time would make a freshly-lengthened
        // delay potentially already-elapsed.
        $autoCloseAt = $this->computeAutoCloseAt($autoCloseDelay);

        $this->boardRepository->update(
            $id, $title, $boardDate, $calendarEventId, $listed, $voteMode, $voteBudget,
            $votesVisible, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $autoCloseAt,
            $closeNotifyEnabled, $this->normalizeEmail($closeNotifyEmail), $linkVisibility
        );

        if ($existing->isOpen()) {
            $this->schedulerService->cancelPending('retro', 'auto_close_board', 'board_' . $id);
            if ($autoCloseAt !== null) {
                $this->scheduleAutoClose($id, $autoCloseAt);
            }
        }

        $board = $this->boardRepository->findById($id);
        \assert($board !== null);

        return $board;
    }

    /**
     * @throws RetroException when already closed
     */
    public function close(int $id, ?int $closedBy): Board
    {
        $board = $this->boardRepository->findById($id);
        if ($board === null) {
            throw new RetroException('Rétrospective introuvable.');
        }
        if (!$board->isOpen()) {
            return $board;
        }

        $this->boardRepository->close($id);
        $this->schedulerService->cancelPending('retro', 'auto_close_board', 'board_' . $id);
        $this->journalService->log('retro', 'board_closed', 'info', 'Rétrospective clôturée', ['board_id' => $id], $closedBy);

        $visible = array_values(array_filter(
            $this->commentRepository->findByBoardId($id),
            fn($c) => !$c->hidden
        ));

        if ($this->summaryService !== null && $this->summaryService->isAvailable()) {
            try {
                $summary = $this->summaryService->generate($visible);
                if ($summary !== '') {
                    $this->boardRepository->setAiSummary($id, $summary);
                }
            } catch (RetroException) {
                // Best-effort — the board stays closed and readable either way.
            }
        }

        $refreshed = $this->boardRepository->findById($id);
        \assert($refreshed !== null);

        if ($refreshed->closeNotifyEnabled && $refreshed->closeNotifyEmail !== null) {
            $this->sendCloseNotification($refreshed, $refreshed->closeNotifyEmail, $visible);
        }

        return $refreshed;
    }

    /**
     * @throws RetroException when the board isn't currently closed
     */
    public function reopen(int $id, ?int $reopenedBy): Board
    {
        $board = $this->boardRepository->findById($id);
        if ($board === null) {
            throw new RetroException('Rétrospective introuvable.');
        }
        if ($board->status !== 'closed') {
            throw new RetroException('Seule une rétrospective clôturée peut être réouverte.');
        }

        // Countdown restarts from now, same principle as update()'s handling
        // of an edited delay — re-basing from the original creation time
        // would make a board reopened after a long closure instantly re-close.
        $autoCloseAt = $this->computeAutoCloseAt($board->autoCloseDelay);
        $this->boardRepository->reopen($id, $autoCloseAt);
        if ($autoCloseAt !== null) {
            $this->scheduleAutoClose($id, $autoCloseAt);
        }

        $this->journalService->log('retro', 'board_reopened', 'info', 'Rétrospective réouverte', ['board_id' => $id], $reopenedBy);

        $refreshed = $this->boardRepository->findById($id);
        \assert($refreshed !== null);

        return $refreshed;
    }

    /**
     * @throws RetroException when the board isn't currently closed
     */
    public function archive(int $id, ?int $archivedBy): Board
    {
        $board = $this->boardRepository->findById($id);
        if ($board === null) {
            throw new RetroException('Rétrospective introuvable.');
        }
        if ($board->status !== 'closed') {
            throw new RetroException('Seule une rétrospective clôturée peut être archivée.');
        }

        $this->boardRepository->archive($id);
        $this->journalService->log('retro', 'board_archived', 'info', 'Rétrospective archivée', ['board_id' => $id], $archivedBy);

        $refreshed = $this->boardRepository->findById($id);
        \assert($refreshed !== null);

        return $refreshed;
    }

    /**
     * @throws RetroException when the board isn't currently archived
     */
    public function unarchive(int $id, ?int $unarchivedBy): Board
    {
        $board = $this->boardRepository->findById($id);
        if ($board === null) {
            throw new RetroException('Rétrospective introuvable.');
        }
        if ($board->status !== 'archived') {
            throw new RetroException('Cette rétrospective n\'est pas archivée.');
        }

        $this->boardRepository->unarchive($id);
        $this->journalService->log('retro', 'board_unarchived', 'info', 'Rétrospective désarchivée', ['board_id' => $id], $unarchivedBy);

        $refreshed = $this->boardRepository->findById($id);
        \assert($refreshed !== null);

        return $refreshed;
    }

    /**
     * Generates a new token (and short link, when available), immediately
     * and permanently revoking the previous one — a lookup by the old
     * token simply no longer matches any board. Scoped to this one board
     * only; comments/votes are untouched.
     */
    public function regenerateLink(int $id, ?int $regeneratedBy): Board
    {
        $board = $this->boardRepository->findById($id);
        if ($board === null) {
            throw new RetroException('Rétrospective introuvable.');
        }

        $token = $this->generateToken();
        $shortCode = $this->tryCreateShortLink($token, $regeneratedBy);
        $this->boardRepository->regenerateLink($id, $token, $shortCode);

        $this->journalService->log('retro', 'board_link_regenerated', 'info', 'Lien de rétrospective régénéré', ['board_id' => $id], $regeneratedBy);

        $refreshed = $this->boardRepository->findById($id);
        \assert($refreshed !== null);

        return $refreshed;
    }

    /**
     * The public-facing URL for a board — /s/{code} when a short link
     * could be registered, /r/{token} otherwise (module spec: "never a
     * broken link").
     */
    public function publicUrl(Board $board): string
    {
        return $board->shortCode !== null ? '/s/' . $board->shortCode : '/r/' . $board->token;
    }

    /**
     * Api\RetroEventLinkLookupInterface implementation — see its docblock.
     */
    public function findLinkedBoardLink(int $eventId, Role $viewerRole, ?string $viewerEmail, ?int $scoutYearId): ?RetroLinkSummary
    {
        $board = $this->boardRepository->findByCalendarEventId($eventId);
        if ($board === null || !$this->viewerMeetsLinkAudience($board, $viewerRole, $viewerEmail, $scoutYearId)) {
            return null;
        }

        // Absolute, unlike publicUrl()'s own relative-path convention
        // (correct for same-origin Twig links elsewhere in this module) —
        // this URL is consumed by ICS descriptions in external calendar
        // apps, which have no concept of "this site's origin" at all.
        return new RetroLinkSummary(rtrim($this->baseUrl, '/') . $this->publicUrl($board), $board->title);
    }

    /**
     * Api\RetroEventLinkLookupInterface implementation — see its docblock.
     */
    public function hasLinkedBoard(int $eventId): bool
    {
        return $this->boardRepository->findByCalendarEventId($eventId) !== null;
    }

    private function viewerMeetsLinkAudience(Board $board, Role $viewerRole, ?string $viewerEmail, ?int $scoutYearId): bool
    {
        return match ($board->linkVisibility) {
            'identified' => $viewerRole->hasAccess(Role::IDENTIFIED),
            'chief' => $viewerRole->hasAccess(Role::CHIEF),
            'unit_chief' => $viewerRole->hasAccess(Role::CHIEF)
                && $viewerEmail !== null && $scoutYearId !== null
                && $this->isUnitChief($viewerEmail, $scoutYearId),
            'superadmin' => $viewerRole->hasAccess(Role::SUPERADMIN),
            default => false,
        };
    }

    /**
     * @return array{0: string, 1: int, 2: int} validated title, voteBudget, maxCommentLength
     * @throws RetroException
     */
    private function validateCommon(
        string $title,
        string $voteMode,
        string $antiDuplicateMode,
        int $maxCommentLength,
        string $autoCloseDelay,
        int $voteBudget
    ): array {
        $title = trim($title);
        if ($title === '') {
            throw new RetroException('Le titre est obligatoire.');
        }
        if (!in_array($voteMode, Board::VOTE_MODES, true)) {
            throw new RetroException('Mode de vote invalide.');
        }
        if (!in_array($antiDuplicateMode, Board::ANTI_DUPLICATE_MODES, true)) {
            throw new RetroException('Mode anti-doublon invalide.');
        }
        if (!in_array($autoCloseDelay, Board::AUTO_CLOSE_DELAYS, true)) {
            throw new RetroException('Délai de clôture automatique invalide.');
        }
        if ($maxCommentLength < self::MIN_MAX_COMMENT_LENGTH || $maxCommentLength > self::MAX_MAX_COMMENT_LENGTH) {
            throw new RetroException('La longueur maximale doit être comprise entre ' . self::MIN_MAX_COMMENT_LENGTH . ' et ' . self::MAX_MAX_COMMENT_LENGTH . '.');
        }
        if ($voteBudget < 1) {
            $voteBudget = 1;
        }
        // The ceiling is the column's, not a rule about retrospectives:
        // `vote_budget` is INT UNSIGNED, and a larger number reached
        // MySQL as an uncaught PDOException and a 500 (SECURITY.md § 35).
        // A tighter, meaningful maximum would be a product decision and
        // is deliberately not invented here.
        if ($voteBudget > IntegerInput::UNSIGNED_INT_MAX) {
            throw new RetroException('Le budget de points est trop grand.');
        }

        return [$title, $voteBudget, $maxCommentLength];
    }

    /**
     * @throws RetroException
     */
    private function validateLinkVisibility(string $linkVisibility): string
    {
        if (!in_array($linkVisibility, Board::LINK_VISIBILITIES, true)) {
            throw new RetroException('Audience du lien invalide.');
        }

        return $linkVisibility;
    }

    /**
     * @throws RetroException
     */
    private function resolveBoardDate(?int $calendarEventId, ?string $manualDate, Role $viewerRole): string
    {
        return $this->resolveLinkedEvent($calendarEventId, $viewerRole)?->endDate
            ?? $this->resolveManualDate($manualDate);
    }

    private function resolveManualDate(?string $manualDate): string
    {
        return DateInput::isoStringOrNull($manualDate)
            ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /**
     * When an event is linked, the title is ALWAYS re-derived here from the
     * event itself, in the exact same format the create/edit form's own JS
     * shows the chief live (retro-config.js) — never the client-submitted
     * value. The title input is disabled the moment an event is picked
     * (module spec: "generated automatically ... and cannot be modified
     * here"), and a disabled input is omitted from form submission
     * entirely by the browser, so trusting the client here would silently
     * save an empty title on every single event-linked board — exactly the
     * "Le titre est obligatoire" bug this guards against, following the
     * same never-trust-the-client precedent resolveBoardDate() already
     * applies to the date.
     */
    private function resolveTitle(?int $calendarEventId, string $clientTitle, Role $viewerRole): string
    {
        $event = $this->resolveLinkedEvent($calendarEventId, $viewerRole);
        return $event !== null
            ? "Rétrospective {$event->title} - {$event->calendarName} - {$event->startDate}"
            : $clientTitle;
    }

    private function resolveLinkedEvent(?int $calendarEventId, Role $viewerRole): ?EventSummary
    {
        if ($calendarEventId === null) {
            return null;
        }
        if ($this->calendarEventLookup === null) {
            throw new RetroException('Le module calendrier n\'est pas disponible.');
        }

        $event = $this->calendarEventLookup->findEventById($calendarEventId, $viewerRole);
        if ($event === null) {
            throw new RetroException('Événement introuvable.');
        }

        return $event;
    }

    /**
     * When a board with this auto-close delay should close, or null when
     * the delay is not one this class offers.
     *
     * A match rather than the lookup table this used to keep, so that the
     * argument handed to the constructor is a LITERAL. The table was
     * private and read only here, and its every value was a relative
     * expression — which is precisely what DateInput::fromStorage()
     * refuses on purpose (SECURITY.md § 35), so no reader could be used
     * and the safety rested on an isset() three lines up. Written this
     * way there is nothing left to argue about: the delay is one of four
     * or the answer is null, and no variable ever reaches the
     * constructor.
     */
    private function computeAutoCloseAt(string $autoCloseDelay): ?string
    {
        $closesAt = match ($autoCloseDelay) {
            '2h' => new \DateTimeImmutable('+2 hours'),
            '24h' => new \DateTimeImmutable('+24 hours'),
            '3d' => new \DateTimeImmutable('+3 days'),
            '7d' => new \DateTimeImmutable('+7 days'),
            default => null,
        };

        return $closesAt?->format('Y-m-d H:i:s');
    }

    private function scheduleAutoClose(int $boardId, string $autoCloseAt): void
    {
        $this->schedulerService->schedule(
            'retro',
            'auto_close_board',
            DateInput::requireFromStorage($autoCloseAt, 'retro_boards.auto_close_at'),
            ['board_id' => $boardId],
            'board_' . $boardId
        );
    }

    private function generateToken(): string
    {
        return CapabilityToken::generate();
    }

    private function tryCreateShortLink(string $token, ?int $createdBy): ?string
    {
        if ($this->shortUrlService === null) {
            return null;
        }

        try {
            return $this->shortUrlService->createShortUrl('/r/' . $token, $createdBy);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Empty/whitespace-only form input is stored as NULL, never as an
     * empty string — close()'s "closeNotifyEmail !== null" check is the
     * single source of truth for "is a recipient actually configured".
     *
     * @throws RetroException when a non-empty address isn't a valid email —
     *     caught here (save time) rather than left to fail silently inside
     *     sendCloseNotification()'s best-effort try/catch at close time,
     *     which would otherwise leave the chief with no idea why nothing
     *     arrived.
     */
    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);
        if ($trimmed === '') {
            return null;
        }
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) === false) {
            throw new RetroException('L\'adresse e-mail de notification est invalide.');
        }

        return $trimmed;
    }

    /**
     * Best-effort — a mail failure never reverses or blocks the close that
     * already happened (module spec has no such requirement, and closing
     * must never depend on network/SMTP availability). Only ever includes
     * $visibleComments (module spec: "except hidden words") and the AI
     * summary already persisted onto $board by the caller, if any.
     *
     * A failure is still journal-logged (same 'board_summary_failed'
     * precedent right above in close()) — silently swallowing it left no
     * trace anywhere that the notification never went out.
     *
     * @param array<int, \Modules\Retro\Repository\Comment> $visibleComments
     */
    private function sendCloseNotification(Board $board, string $email, array $visibleComments): void
    {
        $byColumn = ['good' => [], 'improve' => [], 'suggestion' => []];
        foreach ($visibleComments as $comment) {
            $byColumn[$comment->columnKey][] = $comment->body;
        }

        $context = [
            'site_name' => $this->siteName,
            'board_title' => $board->title,
            'board_date' => $board->boardDate,
            'columns' => $byColumn,
            'ai_summary' => $board->aiSummary,
            'board_url' => rtrim($this->baseUrl, '/') . $this->publicUrl($board),
        ];

        try {
            // Through the register (ARCHITECTURE.md §8.7bis): the declared
            // subject is « Rétrospective clôturée : {{ board_title }} », so
            // with nothing customised this produces the same subject and
            // the same two bodies as the direct Twig renders it replaces.
            $email_ = $this->emailTemplateRenderer->render('retro.board_closed', $context);

            $this->mailService->send(
                to: $email,
                subject: $email_->subject,
                bodyHtml: $email_->bodyHtml,
                bodyText: $email_->bodyText
            );
        } catch (\Throwable $e) {
            $this->journalService->log(
                'retro',
                'board_close_notification_failed',
                'info',
                'Échec de l\'envoi de la notification de clôture',
                ['board_id' => $board->id, 'error' => $e->getMessage()]
            );
        }
    }
}
