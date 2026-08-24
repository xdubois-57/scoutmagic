-- ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
-- Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
--
-- Camps module: the places a unit has camped, and every stay it has made
-- there. See ARCHITECTURE.md §8.66.
--
-- ANY edit to this file MUST bump "version" in module.json in the same
-- change: ModuleManager only re-applies a module schema when the declared
-- version is greater than the recorded one, so editing this alone is a
-- silent no-op on every already-enabled install.


-- camp_places: a camp site. Kept indefinitely and never deleted — the
-- whole value of the module is that a staff in 2035 can read what a staff
-- in 2026 thought of a field in Vielsalm. Archiving hides it instead.
--
-- Every column here is in CLEAR, deliberately. A place is not a natural
-- person: its name and address identify a plot of land, they are what the
-- search runs on, and encrypting them would make the module's main screen
-- impossible to build. The people attached to a place — its owner, its
-- caretaker — are camp_contacts, and every one of their fields is a BLOB.
CREATE TABLE IF NOT EXISTS camp_places (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,

    -- All nullable: many camp sites are a field on a farm with no usable
    -- address at all, only a point on a map. A module that demanded an
    -- address would be unusable for exactly the places it exists to
    -- remember.
    address VARCHAR(255) NULL,
    postal_code VARCHAR(20) NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(80) NULL,
    website_url VARCHAR(500) NULL,

    -- Archived places disappear from every normal screen and from search,
    -- and are reachable only from the Archives view. Never a deletion:
    -- deleting a place would take its stays' history with it.
    is_archived BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_camp_places_archived (is_archived, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- camp_camps: one stay at one place. Never deleted either — a stay that
-- did not happen is 'cancelled', which is itself worth recording (a place
-- that cancels on its guests is exactly what a future staff needs to
-- know).
--
-- There is deliberately NO scout_year_id. Real dates are the truth, and a
-- camp from 2014 predates every scout year row this installation has. The
-- scout year is resolved on demand from the end date, and only where it is
-- genuinely needed (§ IT-04's review notification).
CREATE TABLE IF NOT EXISTS camp_camps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    place_id INT UNSIGNED NOT NULL,

    stay_type ENUM('grand_camp', 'weekend', 'other') NOT NULL DEFAULT 'grand_camp',

    -- Either (start_date AND end_date) or year_only, never both, never
    -- neither — enforced in Service\CampService rather than by a CHECK
    -- constraint, which Core\Database\SchemaComparator does not diff.
    -- year_only exists because half of what a unit remembers about its
    -- own past is "on est allés là en 2012", and refusing that would mean
    -- refusing the memory.
    start_date DATE NULL,
    end_date DATE NULL,
    year_only SMALLINT UNSIGNED NULL,

    status ENUM('to_confirm', 'confirmed', 'cancelled') NOT NULL DEFAULT 'to_confirm',

    -- Cents, as INT. Never a float, never a decimal string: same rule as
    -- the rental module's own pricing.
    price_cents INT UNSIGNED NULL,
    participant_count SMALLINT UNSIGNED NULL,

    -- Who booked it. The member id when that person is still in the member
    -- history, and the encrypted name as the fallback for when they are
    -- not — which for a camp booked eight years ago is the normal case,
    -- not the exception.
    booked_by_member_id INT UNSIGNED NULL,
    booked_by_name BLOB NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_camp_camps_place (place_id, end_date),
    INDEX idx_camp_camps_dates (end_date, year_only),
    CONSTRAINT fk_camp_camps_place FOREIGN KEY (place_id) REFERENCES camp_places(id),
    CONSTRAINT fk_camp_camps_member FOREIGN KEY (booked_by_member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- camp_camp_sections: which sections went. Many-to-many, with no
-- free-text fallback on purpose — a camp whose section no longer exists
-- simply has none, which reads as "we no longer know" rather than as a
-- section name that no picker will ever match again.
CREATE TABLE IF NOT EXISTS camp_camp_sections (
    camp_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (camp_id, section_id),
    INDEX idx_camp_camp_sections_section (section_id),
    CONSTRAINT fk_camp_camp_sections_camp FOREIGN KEY (camp_id) REFERENCES camp_camps(id) ON DELETE CASCADE,
    CONSTRAINT fk_camp_camp_sections_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- camp_contacts: the people to call about ONE stay — attached to the
-- camp, not to the place, deliberately. It freezes the details used at
-- the time of that booking; there is no global address book here, and a
-- caretaker who has since left is not an error to correct but a fact
-- about a stay that already happened.
--
-- These are external third parties with no relationship to the unit: an
-- owner, a caretaker, a neighbour with the key. That is a category of
-- data subject the rest of this site does not have, and it is why every
-- personal field below is a BLOB.
CREATE TABLE IF NOT EXISTS camp_contacts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    camp_id INT UNSIGNED NOT NULL,

    name BLOB NULL,

    -- In CLEAR: a function, not a person. "Propriétaire", "Gestionnaire
    -- sur place". Encrypting a job title would protect nothing and would
    -- stop the contact list from being grouped by role.
    role_label VARCHAR(60) NULL,

    email BLOB NULL,
    -- The only searchable thing about a contact, and it exists for one
    -- purpose: finding every row that belongs to the same person when
    -- that person asks to be erased. Exact match only, by construction.
    email_blind_index CHAR(64) NULL,

    phone BLOB NULL,

    -- A second number, a "demander Jean-Marie", anything. Never used for
    -- matching or search — a free-text field is exactly where someone
    -- writes something that must not become a lookup key, and the form's
    -- help text says so.
    other_details BLOB NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_camp_contacts_camp (camp_id),
    INDEX idx_camp_contacts_blind (email_blind_index),
    CONSTRAINT fk_camp_contacts_camp FOREIGN KEY (camp_id) REFERENCES camp_camps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- camp_links: web pages worth keeping next to a stay — the site of the
-- place, a weather page, a map.
--
-- The Open Graph metadata comes from Modules\Gallery\Api\
-- LinkPreviewFetcher and from nowhere else: that service carries
-- Core\Security\SsrfUrlValidator, and it is the only place in this
-- codebase allowed to make an outbound request to an address a member
-- typed (SECURITY.md §17). It is an OPTIONAL dependency — without the
-- gallery module the link is stored and shown as a bare URL.
--
-- The preview image is a `files` row, not a URL: the fetcher returns
-- bytes, and re-fetching a remote image on every render would leak every
-- reader's IP to the linked site. image_file_id is scoped to this
-- module's own access-control domain (Service\CampFileOwnershipChecker).
CREATE TABLE IF NOT EXISTS camp_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    camp_id INT UNSIGNED NOT NULL,
    url VARCHAR(1000) NOT NULL,
    title VARCHAR(255) NULL,
    description VARCHAR(500) NULL,
    image_file_id INT UNSIGNED NULL,
    site_name VARCHAR(120) NULL,
    -- Null when no preview was ever obtained (gallery absent, the site
    -- unreachable, no Open Graph tags). Distinct from "fetched and found
    -- nothing", which is a row with a date and no title.
    fetched_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_camp_links_camp (camp_id),
    CONSTRAINT fk_camp_links_camp FOREIGN KEY (camp_id) REFERENCES camp_camps(id) ON DELETE CASCADE,
    CONSTRAINT fk_camp_links_image FOREIGN KEY (image_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- camp_documents: a flat list of files attached to a stay — a contract, a
-- quote, an invoice, a map of the field.
--
-- No classification taxonomy on purpose. "Contrat / devis / facture" is a
-- vocabulary somebody has to maintain and everybody fills in differently,
-- and a stay carries four documents, not four hundred: a title and an
-- order are enough to find one.
CREATE TABLE IF NOT EXISTS camp_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    camp_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,

    -- 'manual': a chief uploaded it, and deleting the document deletes
    -- the file. 'email': it is an inbound message's own attachment, and
    -- deleting the document removes only THIS row — the file stays
    -- attached to the message it came from, which still owns it.
    source ENUM('manual', 'email') NOT NULL DEFAULT 'manual',
    source_reference VARCHAR(190) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_camp_documents_camp (camp_id, sort_order),
    CONSTRAINT fk_camp_documents_camp FOREIGN KEY (camp_id) REFERENCES camp_camps(id) ON DELETE CASCADE,
    CONSTRAINT fk_camp_documents_file FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
