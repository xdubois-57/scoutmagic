-- Modules\UsageStats — how much the site is actually used
-- (ARCHITECTURE.md §8.93).
--
-- ONE table, and the shape of it is the whole design decision: a counter
-- per (month, route PATTERN, audience). Not a row per visit, not a row per
-- visitor, not a row per URL.
--
-- **The pattern, never the URL.** `/members/{id}`, never `/members/42`.
-- That is what makes the table aggregate naturally — a unit of 260 members
-- produces one row for the member page, not 260 — and it is also what
-- guarantees no identifier is ever kept: there is no column an id could be
-- written into. The site this replaces had a `STATS_PAGES` table keyed
-- `(PAGE, EMAIL, MONTH)`, from which one could read that a given parent
-- had opened their child's page fourteen times. Nothing here can answer
-- that question, by construction, and that is the point.
--
-- No `scout_year_id`: a page view belongs to a calendar month, not to a
-- school year, and the twelve-month curve every screen draws is read off
-- `month` directly.

CREATE TABLE usage_page_views (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- 'YYYY-MM'. A string rather than a DATE for the same reason
    -- support_monthly_aggregates uses one: the value IS the month, not a
    -- day inside it, and it sorts and compares correctly as text.
    month CHAR(7) NOT NULL,
    -- The DECLARED route path — '/calendar', '/members/{id}'. 160 chars
    -- is far beyond anything this application declares, and keeps the
    -- unique key below under the 767-byte InnoDB limit on every row
    -- format.
    route_pattern VARCHAR(160) NOT NULL,
    -- Which module declared that route, or 'core'. Free dimension: the
    -- route table already knows, so the same counter aggregates by module
    -- without a second table. Denormalised deliberately — a module that
    -- takes a route over keeps one row rather than splitting the history
    -- in two (the upsert below rewrites this column).
    module_id VARCHAR(64) NOT NULL,
    -- 'anonymous' | 'identified' | 'staff' — Modules\UsageStats\Audience.
    -- The coarsest possible answer to « qui consulte », and the only one
    -- that never needs to know who.
    audience VARCHAR(12) NOT NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    -- One row per (month, page, public), incremented in place: a few dozen
    -- rows a month for a unit, not one per visit. The uniqueness is
    -- enforced here rather than by a read-then-write in PHP, which two
    -- concurrent requests could interleave past.
    UNIQUE KEY uniq_usage_page_view (month, route_pattern, audience),
    INDEX idx_usage_page_views_month (month),
    INDEX idx_usage_page_views_module (month, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
