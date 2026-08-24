-- leadership module ("Encadrement")
--
-- One table, and it holds no member data at all.
--
-- Everything this module SHOWS — the contact lists, the staffing situation,
-- the 20th birthdays, the candidates, the stewards' countdowns — is computed
-- from core tables at display time, on a few hundred rows, with no cache and
-- nothing to recompute after an import. That is the whole design: a module
-- that stores no derived state cannot drift out of step with Desk, and there
-- is no invalidation bug available to it.
--
-- What IS stored is a vocabulary decision a human made: which normalised step
-- (none / t1 / t2 / t3 / brevet) a raw Desk `member_years.formation_level`
-- string means. Desk exports the federation's own wording, which differs
-- between exports and changes between years, and no heuristic will ever
-- recognise all of it — so a chief d'unité maps the leftovers by hand from
-- the Formations page, and that decision is what survives here.
--
-- Deliberately NO scout_year_id, against the default rule in AGENTS.md
-- § Database. The rule exists for member-related data, and a row here is not
-- about a member: it says what a WORD means. "Animateur breveté" meant a
-- brevet last year and will mean one next year; scoping the mapping per year
-- would make a chief re-map the same vocabulary every September and would let
-- two years disagree about the same string, which is not a feature anybody
-- asked for.
CREATE TABLE IF NOT EXISTS leadership_formation_levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- The Desk string verbatim, as an admin saw it on the Formations page.
    -- Display only: never matched against, because casing and accents drift.
    raw_value VARCHAR(100) NOT NULL,
    -- Case- and accent-folded form of raw_value (Modules\Leadership\Service\
    -- TextMatcher::fold), and the real key. UNIQUE so "Animateur Breveté" and
    -- "animateur brevete" are one decision instead of two rows able to
    -- contradict each other.
    raw_value_key VARCHAR(100) NOT NULL,
    -- One of: none, t1, t2, t3, brevet. Never 'unknown' — unknown is what the
    -- site says when nobody has decided, not a decision somebody can record
    -- (Modules\Leadership\FormationStep::assignable()).
    step VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    UNIQUE INDEX idx_lfl_key (raw_value_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
