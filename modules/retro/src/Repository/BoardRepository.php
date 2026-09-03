<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Repository;

use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;

/**
 * The board token is a long-lived bearer credential the chief re-displays
 * (share link, QR), so it is encrypted at rest with a blind index for
 * lookup — same pattern as user_accounts.email — never plaintext.
 */
class BoardRepository
{
    private const TOKEN_ENCRYPTION_CONTEXT = 'retro_boards.token';
    private const TOKEN_BLIND_INDEX_PURPOSE = 'retro_board_token';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(
        string $title,
        string $boardDate,
        ?int $calendarEventId,
        string $token,
        ?string $shortCode,
        bool $listed,
        string $voteMode,
        int $voteBudget,
        bool $votesVisible,
        string $antiDuplicateMode,
        int $maxCommentLength,
        string $autoCloseDelay,
        ?string $autoCloseAt,
        ?int $createdBy,
        bool $closeNotifyEnabled = true,
        ?string $closeNotifyEmail = null,
        string $linkVisibility = 'chief'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO retro_boards (
                title, board_date, calendar_event_id, token_encrypted, token_blind_index, short_code, listed, vote_mode, vote_budget,
                votes_visible, anti_duplicate_mode, max_comment_length, auto_close_delay, auto_close_at, created_by,
                close_notify_enabled, close_notify_email, link_visibility
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title, $boardDate, $calendarEventId,
            $this->encryption->encrypt($token, self::TOKEN_ENCRYPTION_CONTEXT),
            $this->encryption->blindIndex($token, self::TOKEN_BLIND_INDEX_PURPOSE),
            $shortCode, $listed ? 1 : 0, $voteMode, $voteBudget,
            $votesVisible ? 1 : 0, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $autoCloseAt, $createdBy,
            $closeNotifyEnabled ? 1 : 0, $closeNotifyEmail, $linkVisibility,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?Board
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_boards WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByToken(string $token): ?Board
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_boards WHERE token_blind_index = ?');
        $stmt->execute([$this->encryption->blindIndex($token, self::TOKEN_BLIND_INDEX_PURPOSE)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Verify exact match (blind index collisions are theoretically possible).
        $board = $this->hydrate($row);

        return CapabilityToken::equalsConstantTime($board->token, $token) ? $board : null;
    }

    /**
     * Api\RetroEventLinkLookupInterface's backing lookup — at most one
     * board per calendar event by convention (nothing in the schema
     * enforces it, but every creation path checks hasLinkedBoard() first).
     */
    public function findByCalendarEventId(int $calendarEventId): ?Board
    {
        $stmt = $this->pdo->prepare('SELECT * FROM retro_boards WHERE calendar_event_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$calendarEventId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Board[]
     */
    public function findAllOrderedByCreated(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM retro_boards ORDER BY created_at DESC, id DESC');

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every board that is NOT archived, newest first — the naturally
     * bounded half of the chief list (boards auto-close and get archived;
     * with auto-create per calendar event the ARCHIVED half grows
     * forever, which is why it is read separately, capped).
     *
     * @return Board[]
     */
    public function findUnarchivedOrderedByCreated(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM retro_boards WHERE status <> 'archived' ORDER BY created_at DESC, "
            . "id DESC");
        $stmt->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * The most recent archived boards — the chief list shows at most
     * $limit of a set that grows by one per auto-created retro forever.
     *
     * @return Board[]
     */
    public function findRecentArchived(int $limit): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM retro_boards WHERE status = 'archived' ORDER BY created_at DESC, "
            . "id DESC LIMIT ?");
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countArchived(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM retro_boards WHERE status = 'archived'");

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every currently-open board — used by Task\AutoCloseHandler's
     * reschedule bootstrap and generally useful for any "what's live right
     * now" listing.
     *
     * @return Board[]
     */
    public function findOpen(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM retro_boards WHERE status = 'open' ORDER BY created_at DESC");

        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function update(
        int $id,
        string $title,
        string $boardDate,
        ?int $calendarEventId,
        bool $listed,
        string $voteMode,
        int $voteBudget,
        bool $votesVisible,
        string $antiDuplicateMode,
        int $maxCommentLength,
        string $autoCloseDelay,
        ?string $autoCloseAt,
        bool $closeNotifyEnabled = true,
        ?string $closeNotifyEmail = null,
        string $linkVisibility = 'chief'
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE retro_boards SET
                title = ?, board_date = ?, calendar_event_id = ?, listed = ?, vote_mode = ?, vote_budget = ?,
                votes_visible = ?, anti_duplicate_mode = ?, max_comment_length = ?, auto_close_delay = ?, auto_close_at = ?,
                close_notify_enabled = ?, close_notify_email = ?, link_visibility = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $title, $boardDate, $calendarEventId, $listed ? 1 : 0, $voteMode, $voteBudget,
            $votesVisible ? 1 : 0, $antiDuplicateMode, $maxCommentLength, $autoCloseDelay, $autoCloseAt,
            $closeNotifyEnabled ? 1 : 0, $closeNotifyEmail, $linkVisibility, $id,
        ]);
    }

    public function close(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE retro_boards SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE "
            . "id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Reopens a closed board, clearing closed_at and setting a freshly
     * recomputed auto_close_at (the countdown restarts from now, same
     * principle as update()'s handling of an edited delay).
     */
    public function reopen(int $id, ?string $autoCloseAt): void
    {
        $stmt = $this->pdo->prepare("UPDATE retro_boards SET status = 'open', closed_at = NULL, auto_close_at = ? "
            . "WHERE id = ?");
        $stmt->execute([$autoCloseAt, $id]);
    }

    public function archive(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE retro_boards SET status = 'archived' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function unarchive(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE retro_boards SET status = 'closed' WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function regenerateLink(int $id, string $token, ?string $shortCode): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_boards SET token_encrypted = ?, token_blind_index = ?, short_code = '
            . '? WHERE id = ?');
        $stmt->execute([
            $this->encryption->encrypt($token, self::TOKEN_ENCRYPTION_CONTEXT),
            $this->encryption->blindIndex($token, self::TOKEN_BLIND_INDEX_PURPOSE),
            $shortCode,
            $id,
        ]);
    }

    public function setAiSummary(int $id, string $summary): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_boards SET ai_summary = ? WHERE id = ?');
        $stmt->execute([$summary, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Board
    {
        return new Board(
            id: (int) $row['id'],
            title: (string) $row['title'],
            boardDate: (string) $row['board_date'],
            calendarEventId: $row['calendar_event_id'] !== null ? (int) $row['calendar_event_id'] : null,
            token: $this->encryption->decrypt((string) $row['token_encrypted'], self::TOKEN_ENCRYPTION_CONTEXT),
            shortCode: $row['short_code'] !== null ? (string) $row['short_code'] : null,
            status: (string) $row['status'],
            listed: (bool) $row['listed'],
            voteMode: (string) $row['vote_mode'],
            voteBudget: (int) $row['vote_budget'],
            votesVisible: (bool) $row['votes_visible'],
            antiDuplicateMode: (string) $row['anti_duplicate_mode'],
            maxCommentLength: (int) $row['max_comment_length'],
            autoCloseDelay: (string) $row['auto_close_delay'],
            autoCloseAt: $row['auto_close_at'] !== null ? (string) $row['auto_close_at'] : null,
            aiSummary: $row['ai_summary'] !== null ? (string) $row['ai_summary'] : null,
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            closeNotifyEnabled: (bool) $row['close_notify_enabled'],
            closeNotifyEmail: $row['close_notify_email'] !== null ? (string) $row['close_notify_email'] : null,
            linkVisibility: (string) $row['link_visibility'],
            createdAt: (string) $row['created_at'],
            closedAt: $row['closed_at'] !== null ? (string) $row['closed_at'] : null
        );
    }
}
