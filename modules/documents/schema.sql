-- ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
-- Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.

-- documents: the unit's shared documents — the rules, the parents'
-- charter, the kit list, the accident procedure, the budget.
--
-- A fifth mechanism of attached documents, and deliberately so
-- (ARCHITECTURE.md, module « documents »): section_documents and
-- camp_documents belong to a section or a stay, rental_documents and
-- member_documents to a booking or a member. These belong to the UNIT,
-- face the PUBLIC, and carry an address that must survive a new version.
--
-- `slug` is that address, /documents/{slug}: derived from the title at
-- creation and FROZEN afterwards, like text_pages.slug — the address is
-- printed and mailed the day the document is published, and correcting a
-- typo in a title must not break a link somebody already sent. A
-- document created as `direct_link` gets a random segment appended, so
-- the address cannot be guessed from the title.
--
-- `visibility` is NOT a role, and that is why it is not called role_min.
-- 'public', 'identified', 'chief' and 'admin' are rungs of the role ladder
-- (Core\Security\Role); 'direct_link' is not — it means "unlisted", and
-- grants anyone holding the URL, exactly as news_articles.visibility says.
-- The file's own files.role_min is derived from it
-- (Modules\Documents\Service\DocumentVisibility::fileRoleMin()): the
-- ladder values copy across, 'direct_link' becomes 'public'. The module
-- never serves bytes itself — /documents/{slug} redirects to /files/{id},
-- where FileAccessGuard enforces that role_min (SECURITY.md §6).
--
-- `file_id` is the CURRENT version's file.
CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    -- Whether the slug carries the random segment, i.e. whether the
    -- document was CREATED unlisted. The slug is frozen: a listed document
    -- later switched to « Lien direct » keeps its title-derived, guessable
    -- address, and the current visibility alone cannot tell the two apart.
    slug_is_random TINYINT(1) NOT NULL DEFAULT 0,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    visibility ENUM('public', 'identified', 'chief', 'admin', 'direct_link') NOT NULL DEFAULT 'public',
    file_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,

    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE INDEX idx_documents_slug (slug),
    INDEX idx_documents_sort (sort_order),
    CONSTRAINT fk_documents_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_documents_created_by FOREIGN KEY (created_by) REFERENCES user_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_documents_updated_by FOREIGN KEY (updated_by) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
