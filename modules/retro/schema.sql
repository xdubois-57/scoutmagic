-- retro module: anonymous retrospective boards.
--
-- Anonymity is the entire point of this feature (see the module's README-
-- level spec) — retro_comments and retro_votes deliberately carry NO
-- column of any kind (member_id, user_account_id, session id, ip_address,
-- cookie value) that could link an entry back to a person. retro_votes'
-- voter_hash is a one-way HMAC (Core\Security\EncryptionService's blind
-- index, same technique as the rest of the codebase's blind-indexed
-- lookups) of (voter identifier, board_id, comment_id) — it can answer
-- "has this person already voted on this comment?" and nothing else.

CREATE TABLE retro_boards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    board_date DATE NOT NULL,
    -- No FK to calendar_events: the calendar module is an optional
    -- dependency (ARCHITECTURE.md §7.5) and may be disabled after a board
    -- already links to one of its events — the reference simply becomes
    -- unresolvable rather than the column becoming orphaned/invalid.
    calendar_event_id INT UNSIGNED NULL,
    -- Opaque token for the /r/{token} fallback URL (always present,
    -- regenerable). short_code additionally registers the same target with
    -- Core\Url\ShortUrlService when available — see Service\BoardService.
    -- The token is a long-lived bearer credential the chief re-displays
    -- (share link, QR), so it is encrypted at rest with a blind index for
    -- lookup (same pattern as user_accounts.email), never plaintext.
    token_encrypted BLOB NOT NULL,
    token_blind_index CHAR(64) NOT NULL,
    short_code VARCHAR(20) NULL,
    status ENUM('open', 'closed', 'archived') NOT NULL DEFAULT 'open',
    listed BOOLEAN NOT NULL DEFAULT TRUE,
    vote_mode ENUM('unlimited', 'budget') NOT NULL DEFAULT 'unlimited',
    vote_budget INT UNSIGNED NOT NULL DEFAULT 5,
    votes_visible BOOLEAN NOT NULL DEFAULT TRUE,
    anti_duplicate_mode ENUM('cookie', 'auth') NOT NULL DEFAULT 'cookie',
    max_comment_length INT UNSIGNED NOT NULL DEFAULT 140,
    auto_close_delay ENUM('none', '2h', '24h', '3d', '7d') NOT NULL DEFAULT '7d',
    -- Precomputed at creation/whenever auto_close_delay changes — the
    -- run_at of the corresponding scheduled_actions row (Task\
    -- AutoCloseHandler), kept here too so the board list/edit page can
    -- display "closes in X" without re-deriving it from the scheduler.
    auto_close_at DATETIME NULL,
    -- Cached once at close time (Service\SummaryService) — never
    -- regenerated automatically, so it survives even if llm_connector is
    -- later disabled.
    ai_summary TEXT NULL,
    -- The chief who created the board — board metadata, not a participant
    -- action, so this is explicitly allowed (unlike retro_comments/
    -- retro_votes below).
    created_by INT UNSIGNED NULL,
    -- When true (default) and close_notify_email is set, Service\
    -- BoardService::close() / Task\AutoCloseHandler emails the visible
    -- comments + AI summary to that address on closure. close_notify_email
    -- defaults to the creating chief's own address at creation time (form-
    -- layer default, not re-resolved later) but is editable per board and
    -- stays NULL for boards created before this feature existed — a NULL
    -- address never sends regardless of close_notify_enabled.
    close_notify_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    close_notify_email VARCHAR(255) NULL,
    -- Minimum audience allowed to see this board's link in the calendar
    -- module's event GUI and personal ICS feed (Api\
    -- RetroEventLinkLookupInterface::findLinkedBoardLink()) — 'unit_chief'
    -- is Service\BoardService::isUnitChief() (Staff d'U section membership),
    -- not a Core\Security\Role case, so it can't be a plain role_min string.
    -- Meaningless unless calendar_event_id is set.
    link_visibility ENUM('identified', 'chief', 'unit_chief', 'superadmin') NOT NULL DEFAULT 'chief',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    UNIQUE INDEX idx_retro_boards_token (token_blind_index),
    UNIQUE INDEX idx_retro_boards_short_code (short_code),
    INDEX idx_retro_boards_status (status),
    CONSTRAINT fk_retro_boards_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retro_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id INT UNSIGNED NOT NULL,
    column_key ENUM('good', 'improve', 'suggestion') NOT NULL,
    -- Ceiling above the settable 120-200 range — retro_boards.
    -- max_comment_length is the actual enforced limit at write time, this
    -- is just a hard storage cap that never changes.
    body VARCHAR(200) NOT NULL,
    -- Denormalized SUM(retro_votes.weight) for this comment, kept in sync
    -- transactionally by Service\VoteService — avoids a join/aggregate on
    -- every board render and poll.
    votes INT UNSIGNED NOT NULL DEFAULT 0,
    hidden BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_retro_comments_board (board_id, column_key),
    CONSTRAINT fk_retro_comments_board FOREIGN KEY (board_id) REFERENCES retro_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE retro_votes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id INT UNSIGNED NOT NULL,
    -- Denormalized: lets Service\VoteService sum a voter's total spend
    -- across an entire board (budget mode) without joining through
    -- retro_comments.
    board_id INT UNSIGNED NOT NULL,
    -- One-way HMAC of (voter identifier, board_id, comment_id) — the
    -- dedup key: "has this voter already voted on this exact comment?".
    voter_hash VARCHAR(64) NOT NULL,
    -- A SECOND one-way HMAC of (voter identifier, board_id) only — never
    -- comment-scoped. Budget mode needs to sum how many points a voter has
    -- allocated across an ENTIRE board, which voter_hash alone cannot
    -- answer (it differs per comment by design, so it carries no common
    -- value to SUM() across a voter's rows). This column intentionally
    -- links a voter's own rows together WITHIN one board — the minimum
    -- correlation a shared-budget feature requires — but never reveals who
    -- they are, and a different board yields an unrelated hash.
    voter_board_hash VARCHAR(64) NOT NULL,
    -- 1 for an unlimited-mode like; 1-N points currently allocated in
    -- budget mode (a row is deleted once its weight reaches 0, not kept at
    -- weight=0 — see Service\VoteService::removePoint()).
    weight INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_retro_votes_dedup (comment_id, voter_hash),
    INDEX idx_retro_votes_board_voter (board_id, voter_board_hash),
    CONSTRAINT fk_retro_votes_comment FOREIGN KEY (comment_id) REFERENCES retro_comments(id) ON DELETE CASCADE,
    CONSTRAINT fk_retro_votes_board FOREIGN KEY (board_id) REFERENCES retro_boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived rows only — Task\PurgeRateLimitHandler deletes anything past
-- the rate-limit window on a schedule. identifier_hash is the same kind of
-- one-way HMAC as retro_votes.voter_hash (never the raw cookie/session
-- value), since even a short-lived link between a raw identifier and a
-- timestamped action stream is exactly the correlation risk the module's
-- anonymity guarantee exists to avoid.
CREATE TABLE retro_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash VARCHAR(64) NOT NULL,
    action_type ENUM('comment', 'vote') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_retro_rate_limits_lookup (identifier_hash, action_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
