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
    created_by_member_id INT UNSIGNED NOT NULL,
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
-- below) and, now, an optional single link preview (see
-- discussion_group_post_links below). Replies and reactions still arrive
-- later and will hang off this one with ON DELETE CASCADE exactly like
-- both of those already do — but a bare CASCADE is no longer the whole
-- cleanup story: both media and a link's cached image live outside this
-- table's own CASCADE reach (gallery_media, and core's own files table
-- respectively), so Service\PostMediaService and Service\PostLinkService
-- each explicitly delete their own external row/stored object BEFORE a
-- post is deleted (deleting a post is a real DELETE — this module does
-- not soft-delete).
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
