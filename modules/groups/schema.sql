-- groups module: private discussion groups for a scout unit.
--
-- Table naming: GROUPS is a reserved word in MySQL 8.0 (the window
-- function frame clause), so no table here is called `groups` — every one
-- is prefixed `discussion_group`. This is not cosmetic: `CREATE TABLE
-- groups` is a syntax error on 8.0 and would only surface at install time
-- on a real server, never in the SQLite-backed test suite.
--
-- Personal data: none. Every column below is an id, a flag, a timestamp,
-- or a group name chosen by a chief — never a member's name, email or
-- phone (SECURITY.md §5). Who a member is is resolved at render time from
-- the core member tables through Core\Member\MemberService.

CREATE TABLE discussion_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    -- Optional one-liner a moderator writes to say what the group is for
    -- ("Coordination du camp d'été 2026"). Matters most for an invitation
    -- group, whose name alone often does not explain why it exists — a
    -- section group's does. Plain text, never HTML: rendered escaped like
    -- every other member-supplied string in this module.
    description VARCHAR(300) NULL,
    -- Deliberately NULLABLE, unlike AGENTS.md's "every member-related
    -- table carries scout_year_id": a group created by invitation may be
    -- tied to a scout year or not, at the chief's choice (a unit-wide
    -- working group that outlives a single year is the motivating case).
    -- A section group always has one. When NULL, section-derived
    -- membership resolves against whatever the effective year currently
    -- is; when set, it resolves against that year forever, which is what
    -- keeps a past-year group readable by whoever was a member that year
    -- (Service\GroupAccessService).
    scout_year_id INT UNSIGNED NULL,
    -- Set for a section group (the section it belongs to), NULL for an
    -- invitation group. The section that "owns" the group is also listed
    -- in discussion_group_sections — this column only records which kind
    -- of group it is, membership always goes through that table.
    section_id INT UNSIGNED NULL,
    -- Non-NULL closes the group: no new post, no new reply (prompt 4+).
    -- Reading a closed group stays allowed for its members.
    closed_at DATETIME NULL,
    -- Bumped by the module's service layer whenever something happens in
    -- the group (a single writer, so the column can never drift). Drives
    -- the group list ordering, and later the purge.
    last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULLABLE since prompt 11: a section group created automatically by
    -- Task\EnsureSectionGroupsHandler has no creator, and naming an
    -- arbitrary member would be a lie that also made them a moderator
    -- (Service\GroupService::createSectionGroup() adds the creator as
    -- one). NULL therefore reads as "created by the site", and such a
    -- group starts with no explicit moderator — site admins moderate it
    -- until a chief grants the flag, which is exactly what
    -- Service\GroupAccessService::canModerate() already allows for.
    created_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- The group's delegated gallery album (Modules\Gallery\Api\
    -- DelegatedAlbumManager, owner_type 'discussion_group' — see
    -- File\GroupFileOwnershipChecker::OWNER_TYPE — owner_id = this
    -- group's id), created lazily on the group's first media post
    -- (Service\PostMediaService::ensureAlbumId()), never at group
    -- creation. NULL until then.
    --
    -- No FK: gallery_albums lives in a different module's schema.sql,
    -- applied independently and in no guaranteed order relative to this
    -- one (each module's schema is migrated on its own, whenever that
    -- module happens to load — there is no cross-module migration
    -- ordering guarantee to declare a FK against). Same reasoning as
    -- gallery_albums.cover_media_id's own comment one file over, applied
    -- across a module boundary instead of within one file. This column
    -- is not the source of truth for uniqueness either — gallery's own
    -- UNIQUE(owner_type, owner_id) index is (see that column's comment in
    -- modules/gallery/schema.sql) — it is only a cache of the id
    -- Service\PostMediaService already resolved, to avoid re-resolving it
    -- from gallery on every read.
    gallery_album_id INT UNSIGNED NULL,
    INDEX idx_dg_scout_year (scout_year_id),
    INDEX idx_dg_section (section_id),
    INDEX idx_dg_last_activity (last_activity_at),
    CONSTRAINT fk_dg_scout_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_dg_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_dg_created_by FOREIGN KEY (created_by_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sections whose members are derived members of the group. A section
-- group has exactly one row here at creation; "inviter une section" on an
-- invitation group adds another. Never materialised into
-- discussion_group_members — membership is resolved per request against
-- Core\Member\SectionMembershipRepository, so a Desk import that moves a
-- member between sections takes effect immediately.
CREATE TABLE discussion_group_sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgs_group_section (group_id, section_id),
    CONSTRAINT fk_dgs_group FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgs_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individually invited members, and the moderator flag. A row may exist
-- purely to carry is_moderator on a section group, for a member who is
-- already a derived member — the two sources are unioned, never
-- exclusive. member_id is the persistent Core member identity
-- (members.id), not member_years.id: group membership outlives a single
-- scout year, exactly like files.owner_member_id (ARCHITECTURE.md §8.3).
CREATE TABLE discussion_group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    is_moderator BOOLEAN NOT NULL DEFAULT FALSE,
    invited_by_member_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgm_group_member (group_id, member_id),
    INDEX idx_dgm_member (member_id),
    CONSTRAINT fk_dgm_group FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgm_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgm_invited_by FOREIGN KEY (invited_by_member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Text posts, up to four media each (see discussion_group_post_media
-- below), an optional single link preview (discussion_group_post_links),
-- replies (discussion_group_replies) and reactions
-- (discussion_group_post_reactions). Every one of those hangs off this
-- table with ON DELETE CASCADE, so deleting a post takes its own rows
-- with it — but a bare CASCADE is not the whole cleanup story, because
-- three kinds of thing live outside this table's CASCADE reach:
-- gallery_media (post media AND reply images, another module's table), and
-- core's own files table (a link preview's cached image). So
-- Service\PostMediaService, Service\PostLinkService and
-- Service\ReplyService each explicitly delete their own external
-- row/stored object BEFORE the post row goes — deleting a post is a real
-- DELETE, this module does not soft-delete, and a reply's reactions are
-- reached by the CASCADE chain post -> reply -> reply reaction.
CREATE TABLE discussion_group_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    -- Both author identities, always. author_user_account_id is the human
    -- who actually typed; author_member_id is the member whose membership
    -- opens the group (an account linked to several members of the same
    -- group picks which one it posts as). The UI shows both — "Akéla
    -- (Marie Dupont)" — so neither can be dropped: the totem alone hides
    -- which parent wrote, the account name alone hides which child's
    -- membership it came through.
    author_user_account_id INT UNSIGNED NOT NULL,
    author_member_id INT UNSIGNED NOT NULL,
    -- Plain text, stored raw and escaped by Twig at render time. Never
    -- HTML: no sanitizer runs over this, no rich-text editor produces it,
    -- and no Markdown is interpreted. Line breaks are preserved on
    -- display only.
    body TEXT NOT NULL,
    is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
    -- Set the first time the author edits, within the edit window; drives
    -- the "modifié" marker. Editing deliberately does NOT touch
    -- last_activity_at.
    edited_at DATETIME NULL,
    -- NOT NULL and set to the creation time at insert — never null, or
    -- the feed's ordering (and the keyset cursor built on it) silently
    -- breaks. Bumped later by replies and reactions.
    last_activity_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    -- Set when the post reaches groups_report_hide_threshold reports
    -- (Service\ReportService). Hiding is the MAXIMUM automatic
    -- consequence of reporting — nothing is ever auto-deleted; a
    -- moderator still has to decide. NULL means visible.
    --
    -- Enforced in the QUERY, never by hiding markup: Repository\
    -- PostRepository's own reads take an $includeHidden flag that only a
    -- moderator's request ever sets true, so a hidden post is absent from
    -- the feed, from reply lists, from counts and from the group gallery
    -- for everyone else rather than merely invisible in the HTML.
    hidden_at DATETIME NULL,
    -- Set once a moderator has looked at a hidden post and restored it.
    -- Kept as its own column rather than recomputed from the report
    -- count, because the count only ever grows: without this, one more
    -- report would immediately re-hide what a moderator just cleared,
    -- and the moderator's decision would silently mean nothing.
    moderation_cleared BOOLEAN NOT NULL DEFAULT FALSE,
    -- The feed's exact ordering, so the keyset scan is index-only:
    -- pinned posts are fetched separately, the stream reads
    -- (group_id, last_activity_at DESC, id DESC).
    INDEX idx_dgp_feed (group_id, last_activity_at, id),
    INDEX idx_dgp_pinned (group_id, is_pinned, last_activity_at),
    INDEX idx_dgp_author_member (author_member_id),
    CONSTRAINT fk_dgp_group FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgp_author_account FOREIGN KEY (author_user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgp_author_member FOREIGN KEY (author_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per media attached to a post, in display order. The media
-- itself is a Modules\Gallery\Repository\Media row inside the group's
-- own delegated album (discussion_groups.gallery_album_id) — this table
-- only records which post it belongs to and where.
--
-- No FK to gallery_media: it lives in a different module's schema.sql,
-- same cross-module reasoning as discussion_groups.gallery_album_id's
-- own comment. Service\PostMediaService deletes the gallery media itself
-- (row and stored object, through Api\DelegatedAlbumManager::
-- deleteMedia()) BEFORE the post row is deleted — the ON DELETE CASCADE
-- below only ever cleans up this join row afterward, never the media it
-- pointed at.
CREATE TABLE discussion_group_post_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    gallery_media_id INT UNSIGNED NOT NULL,
    -- 0 to 3 (module spec: 1 to 4 media per post) — the composer's own
    -- attachment order, preserved on display.
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgpm_post_media (post_id, gallery_media_id),
    INDEX idx_dgpm_post (post_id, sort_order),
    CONSTRAINT fk_dgpm_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One link preview per post (module spec: max one link per post — the
-- UNIQUE index on post_id enforces it at the DB level too, not just in
-- Service\PostLinkService). title/description/image_file_id are all
-- nullable independently of one another: a URL with no Open Graph tags at
-- all, or one Service\PostLinkService could not reach, still becomes a
-- post — just a plain link with nothing else resolved (module spec:
-- "degrade to a plain link on any failure"). The preview image is fetched
-- through Modules\Gallery\Api\LinkPreviewFetcher (SECURITY.md §17) but
-- stored as this module's OWN files row (owner_type
-- File\GroupFileOwnershipChecker::OWNER_TYPE, owner_id = the group's id) —
-- never inside the group's delegated gallery album (discussion_groups.
-- gallery_album_id): a link preview image is not a photo/video the group
-- posted, and gallery's own Api\LinkPreviewFetcher never stores or serves
-- it itself (see that interface's own docblock) precisely so each group
-- keeps its own separately access-controlled copy.
--
-- No FK to files: it lives in core's schema, loaded before every module's
-- own schema.sql, so unlike gallery_media_id above (a cross-MODULE
-- reference, no guaranteed load-order) a FK here is safe — same reasoning
-- as e.g. gallery_media.file_id's own FK in modules/gallery/schema.sql.
-- Service\PostLinkService deletes the files row (and its stored object)
-- itself before a post is deleted, exactly like Service\PostMediaService
-- does for gallery_media — the CASCADE below only ever cleans up this
-- table's own row afterward, never the file it pointed at.
CREATE TABLE discussion_group_post_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    url VARCHAR(2048) NOT NULL,
    title VARCHAR(255) NULL,
    description TEXT NULL,
    image_file_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgpl_post (post_id),
    CONSTRAINT fk_dgpl_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgpl_image_file FOREIGN KEY (image_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replies to a post — ONE level deep, always. There is deliberately no
-- parent_reply_id: threading is not a feature of this module, and adding
-- the column "just in case" is exactly what makes it accidentally arrive
-- later. A reply is addressed only by its post.
--
-- Displayed oldest first (the opposite of the post stream, which is newest
-- first): a conversation reads forward, so idx_dgr_post below is
-- (post_id, id) and the keyset cursor walks it ascending.
--
-- gallery_media_id is the reply's single optional image (module spec: at
-- most one), living in the SAME delegated gallery album as post media
-- (discussion_groups.gallery_album_id) and reached through the same
-- Modules\Gallery\Api\DelegatedAlbumManager — never a second storage path,
-- which is also what makes a reply image show up in "Galerie du groupe"
-- like any other group media, with no extra code. No FK to gallery_media
-- for the cross-module reason discussion_group_post_media documents;
-- Service\ReplyService deletes the media itself (row and stored object)
-- before the reply row goes, and Service\ReplyService::deleteAllForPost()
-- does the same for every reply of a post about to be deleted — the
-- CASCADE below only ever removes this table's own rows.
CREATE TABLE discussion_group_replies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    -- Both author identities, same reasoning as discussion_group_posts.
    author_user_account_id INT UNSIGNED NOT NULL,
    author_member_id INT UNSIGNED NOT NULL,
    -- Plain text, escaped by Twig at render time — never HTML, exactly
    -- like a post's own body. May be empty when the reply carries an
    -- image instead (module spec: text alone and image alone are both
    -- valid, neither alone is not).
    body TEXT NOT NULL,
    gallery_media_id INT UNSIGNED NULL,
    -- Set on the author's first edit inside the window; drives the
    -- "modifié" marker. Editing a reply does NOT bump the post's
    -- last_activity_at, for the same reason editing a post does not.
    edited_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    -- Same pair, same reasoning, as discussion_group_posts above.
    hidden_at DATETIME NULL,
    moderation_cleared BOOLEAN NOT NULL DEFAULT FALSE,
    INDEX idx_dgr_post (post_id, id),
    INDEX idx_dgr_author_member (author_member_id),
    CONSTRAINT fk_dgr_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgr_author_account FOREIGN KEY (author_user_account_id) REFERENCES user_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgr_author_member FOREIGN KEY (author_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reactions: TWO tables, one per reactable kind, deliberately NOT one
-- polymorphic (target_type, target_id) table. A polymorphic pair cannot
-- carry a real foreign key, so deleting a post or a reply would leave its
-- reactions behind unless application code remembered to sweep them —
-- exactly the kind of cleanup that gets forgotten on the one path nobody
-- tested. With a real FK per table the database does it, always.
--
-- reaction_key stores a KEY ('thumbs_up', 'heart', …), never the emoji
-- character itself. Support\Reactions is the single source of truth for
-- the fixed set of six and the key => emoji map used at render time, and
-- validates every incoming key against it (module spec: never trust the
-- client's value, never accept an arbitrary emoji). Storing the character
-- would also walk straight into the utf8mb4-versus-utf8 trap, where a
-- three-byte column silently truncates or rejects a four-byte emoji.
-- Not an ENUM either: that would duplicate the set in the schema and turn
-- a rendering change into a migration.
--
-- UNIQUE (item, member) is what enforces "one reaction per member per
-- item" — the DATABASE enforces it, not application logic, because a
-- double-tap on mobile genuinely fires twice and two concurrent inserts
-- would otherwise both succeed. Repository\ReactionRepository catches the
-- resulting SQLSTATE 23000 and updates instead.
CREATE TABLE discussion_group_post_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    reaction_key VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgpr_post_member (post_id, member_id),
    INDEX idx_dgpr_post (post_id),
    CONSTRAINT fk_dgpr_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgpr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE discussion_group_reply_reactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reply_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    reaction_key VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgrr_reply_member (reply_id, member_id),
    INDEX idx_dgrr_reply (reply_id),
    CONSTRAINT fk_dgrr_reply FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgrr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports: TWO tables, one per reportable kind, for exactly the reason
-- the reaction tables above are two — a real foreign key per table, so
-- deleting a post or a reply takes its reports with it without any
-- application code remembering to sweep them.
--
-- UNIQUE (item, reporter) is the "one report per member per item" rule,
-- enforced by the DATABASE rather than by a read-then-write: a double
-- submit (a double-tap, a refreshed POST) would otherwise count twice and
-- push an item over the threshold on one person's say-so.
--
-- No reason/comment column: a report is a bare signal, and storing free
-- text here would put a member's words about another member into a table
-- this module otherwise keeps entirely free of personal data
-- (schema.sql's own header). Who reported is never revealed to anyone —
-- the column exists only to enforce uniqueness and to let a member undo
-- their own report.
CREATE TABLE discussion_group_post_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    reporter_member_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgprep_post_reporter (post_id, reporter_member_id),
    INDEX idx_dgprep_post (post_id),
    CONSTRAINT fk_dgprep_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgprep_member FOREIGN KEY (reporter_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE discussion_group_reply_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reply_id INT UNSIGNED NOT NULL,
    reporter_member_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_dgrrep_reply_reporter (reply_id, reporter_member_id),
    INDEX idx_dgrrep_reply (reply_id),
    CONSTRAINT fk_dgrrep_reply FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgrrep_member FOREIGN KEY (reporter_member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member flood protection on posting and replying — the limit
-- deliberately deferred from prompt 4, following the retro_rate_limits
-- precedent (see that table's comment in modules/retro/schema.sql) rather
-- than inventing a second mechanism. Purged by
-- Task\PurgeRateLimitHandler, exactly like retro's.
--
-- Deliberately NOT merged with discussion_group_link_fetch_log below,
-- despite the similar shape: that one bounds OUTBOUND HTTP requests the
-- server makes on a member's behalf (an SSRF/DoS-against-a-third-party
-- concern, SECURITY.md §17), this one bounds how much CONTENT a member
-- creates. They protect different things, have unrelated windows and
-- ceilings, and folding them together would mean one limit silently
-- consuming the other's budget.
--
-- member_id in the clear, like discussion_group_link_fetch_log and unlike
-- retro_rate_limits' HMAC: retro hashes because its whole feature is
-- anonymity, whereas a group post already carries both author identities
-- in plain form (discussion_group_posts above). There is no anonymity
-- here for a hash to protect.
CREATE TABLE discussion_group_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    action_type ENUM('post', 'reply') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dgrl_lookup (member_id, action_type, created_at),
    CONSTRAINT fk_dgrl_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member throttle on link-preview fetch attempts (module spec,
-- following the retro_rate_limits precedent — see that table's own
-- comment in modules/retro/schema.sql) — without this, posting links
-- would let a member trigger unlimited outbound requests through the
-- server (an SSRF-probing/DoS-against-a-third-party vector even with
-- Service\OgScraperService's own hardening, SECURITY.md §17). Unlike
-- retro_rate_limits, member_id is stored directly rather than an HMAC: a
-- group post is already tied to both author identities in plain form
-- (discussion_group_posts.author_member_id above) — there is no
-- anonymity guarantee here to protect. Purged opportunistically by
-- Service\LinkFetchThrottleService on every check (same "no dedicated
-- scheduled task" choice as gallery's gallery_link_preview_cache, and for
-- the same reason: low write volume, a fixed short retention window).
CREATE TABLE discussion_group_link_fetch_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dglfl_member (member_id, created_at),
    CONSTRAINT fk_dglfl_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Debounce state for the "reaction to your message" notification, one row
-- per item that has already produced one. A popular post picks up a dozen
-- reactions in a minute, and a notification each would make the feature
-- unusable rather than useful; Service\ReactionNoticeThrottle checks these
-- rows against groups_reaction_notification_window_minutes before asking
-- core to dispatch anything.
--
-- This tracks TIMING, never notifications: the notifications themselves
-- live in core's `notifications` table and only there (ARCHITECTURE.md
-- §8.24). Nothing here is ever read to find out what core already sent —
-- a module must not read core's table, so it keeps its own small record of
-- when it last asked.
--
-- Two tables rather than one polymorphic one, for the same reason
-- discussion_group_post_reactions and discussion_group_reply_reactions are
-- two (see their comment above): a single (item_type, item_id) table can
-- carry no foreign key at all, so every deleted post would leave its
-- debounce row behind forever. With one table per item kind, the CASCADE
-- does the cleanup and there is no purge task to write.
CREATE TABLE discussion_group_post_reaction_notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id INT UNSIGNED NOT NULL,
    notified_at DATETIME NOT NULL,
    UNIQUE INDEX idx_dgprn_post (post_id),
    CONSTRAINT fk_dgprn_post FOREIGN KEY (post_id) REFERENCES discussion_group_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE discussion_group_reply_reaction_notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reply_id INT UNSIGNED NOT NULL,
    notified_at DATETIME NOT NULL,
    UNIQUE INDEX idx_dgrrn_reply (reply_id),
    CONSTRAINT fk_dgrrn_reply FOREIGN KEY (reply_id) REFERENCES discussion_group_replies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member "last time I opened this group", the one thing an unread
-- indicator needs. Written once per group page view
-- (Service\GroupReadStateService, called from Controller\GroupController::
-- show()), read in a single batched query for the whole group list and for
-- the home page's own activity card.
--
-- Keyed on member_id, not on the user account: a parent linked to two
-- children who are both in the same group has ONE reading position in that
-- conversation, not one per child — the same identity resolution every
-- other per-member row in this module uses (the member the account posts
-- as, Service\GroupAccessService::memberIdsAllowedToPostAs()).
--
-- Deliberately a timestamp rather than a last-seen post id: a post can be
-- deleted, and an id-based cursor pointing at a deleted row has no
-- meaningful "everything after this" answer, while a timestamp always
-- does. It is compared against discussion_groups.last_activity_at (the
-- group's own single-writer activity clock) and against
-- discussion_group_posts.created_at for the per-post "seen by" answer.
--
-- No personal data: two ids and a timestamp (SECURITY.md §5).
CREATE TABLE discussion_group_reads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    last_read_at DATETIME NOT NULL,
    UNIQUE INDEX idx_dgr_group_member (group_id, member_id),
    INDEX idx_dgr_member (member_id),
    CONSTRAINT fk_dgr_group FOREIGN KEY (group_id) REFERENCES discussion_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_dgr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
