-- ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
-- Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
--
-- Carpool module: who has free seats towards an outing, and who is asking
-- for one. Three objects (ARCHITECTURE.md §8.120): a carpool, the offers of
-- seats in it — one per car — and the requests on an offer, for named
-- people. Every row here is deleted a set number of days after the last
-- date of its carpool (Task\PurgeCarpoolsHandler): this module must never
-- become a register of the families' journeys.


-- carpools: one per outing, created by a chief. It carries THE PLACE —
-- the destination of every outbound trip and the departure of every return
-- trip. An offer never repeats it: without that, every driver retypes the
-- destination and spells it differently.
--
-- The address is an outing's venue, never a person's: kept in clear, like
-- a camp place. The four point columns follow Core\Geo's convention and
-- are written only through Core\Geo\GeoPointStore (the manual lock).
CREATE TABLE IF NOT EXISTS carpools (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    address VARCHAR(255) NOT NULL,
    latitude DECIMAL(9, 6) NULL,
    longitude DECIMAL(9, 6) NULL,
    coordinates_are_manual BOOLEAN NOT NULL DEFAULT FALSE,
    geocoded_at DATETIME NULL,
    outbound_date DATE NOT NULL,
    -- Null for an outing with a single trip.
    return_date DATE NULL,
    -- The section whose staff sees the passengers when the carpool is
    -- linked to no event. With linked events, the staff is derived from
    -- them instead and this stays null.
    section_id INT UNSIGNED NULL,
    created_by_user_account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_carpools_dates (outbound_date, return_date),
    INDEX idx_carpools_geocoding (coordinates_are_manual, geocoded_at),
    CONSTRAINT fk_carpools_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
    CONSTRAINT fk_carpools_created_by FOREIGN KEY (created_by_user_account_id) REFERENCES user_accounts(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- carpool_events: the calendar events a carpool serves, zero, one or
-- several — a unit party replicated on four section calendars is ONE
-- carpool. The title and section are copied at link time: the staff who
-- sees the passengers is derived from them, and that must not change
-- because an event was renamed or the calendar module switched off. No
-- foreign key into calendar_events: a module never points into another
-- module's table (AGENTS.md § Database).
--
-- An event belongs to one carpool at most — the unique key is what stops
-- two chiefs of two sections from each creating one for the same party and
-- the families splitting over two lists.
CREATE TABLE IF NOT EXISTS carpool_events (
    carpool_id INT UNSIGNED NOT NULL,
    calendar_event_id INT UNSIGNED NOT NULL,
    event_title VARCHAR(255) NOT NULL,
    section_id INT UNSIGNED NULL,
    section_name VARCHAR(150) NULL,
    PRIMARY KEY (carpool_id, calendar_event_id),
    UNIQUE KEY uq_carpool_events_event (calendar_event_id),
    CONSTRAINT fk_carpool_events_carpool FOREIGN KEY (carpool_id) REFERENCES carpools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- carpool_offers: one car, one direction. The meeting point (`endpoint`)
-- is the only end of the trip a driver chooses — a meeting point, not a
-- home address, and the form says so (D7): it is shown to every member,
-- so it stays in clear. The driver's name, phone and note are personal
-- data and BLOBs, encrypted and decrypted in Repository\OfferRepository
-- only.
--
-- The phone is COPIED here from what the driver saw and confirmed (D6),
-- never read live from member_years: a later Desk import must not change
-- in silence what somebody agreed to show.
CREATE TABLE IF NOT EXISTS carpool_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    carpool_id INT UNSIGNED NOT NULL,
    direction ENUM('outbound', 'return') NOT NULL,
    departure_time TIME NOT NULL,
    endpoint VARCHAR(150) NOT NULL,
    seats TINYINT UNSIGNED NOT NULL,
    driver_user_account_id INT UNSIGNED NOT NULL,
    driver_name_encrypted BLOB NOT NULL,
    phone_encrypted BLOB NOT NULL,
    note_encrypted BLOB NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_carpool_offers_carpool (carpool_id, direction),
    INDEX idx_carpool_offers_driver (driver_user_account_id),
    CONSTRAINT fk_carpool_offers_carpool FOREIGN KEY (carpool_id) REFERENCES carpools(id) ON DELETE CASCADE,
    -- An account that is deleted takes its cars with it: nothing here is
    -- worth keeping without the person who offered it.
    CONSTRAINT fk_carpool_offers_driver FOREIGN KEY (driver_user_account_id) REFERENCES user_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- carpool_requests: seats asked on one offer, for named people. A request
-- is accepted WHOLE (D4); seats are counted in people, never in requests,
-- which is why passenger_count sits in clear beside the encrypted names.
-- The requester's phone is revealed to the driver only once accepted.
--
-- status: pending → accepted | refused; accepted → revoked (the driver
-- took a granted seat back — not the same thing as never having had one).
-- A request withdrawn by its author is deleted outright.
CREATE TABLE IF NOT EXISTS carpool_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offer_id INT UNSIGNED NOT NULL,
    requester_user_account_id INT UNSIGNED NOT NULL,
    requester_name_encrypted BLOB NOT NULL,
    passenger_names_encrypted BLOB NOT NULL,
    passenger_count TINYINT UNSIGNED NOT NULL,
    phone_encrypted BLOB NOT NULL,
    status ENUM('pending', 'accepted', 'refused', 'revoked') NOT NULL DEFAULT 'pending',
    decided_at DATETIME NULL,
    -- When the driver was last reminded of this request while it waited
    -- (Task\RemindPendingRequestsHandler): a reminder every few days, never
    -- one a day — a driver who has not answered yet does not need a daily
    -- nudge, which is also why that notification never goes by e-mail.
    reminded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_carpool_requests_offer (offer_id, status),
    INDEX idx_carpool_requests_requester (requester_user_account_id),
    CONSTRAINT fk_carpool_requests_offer FOREIGN KEY (offer_id) REFERENCES carpool_offers(id) ON DELETE CASCADE,
    CONSTRAINT fk_carpool_requests_requester FOREIGN KEY (requester_user_account_id) REFERENCES user_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
