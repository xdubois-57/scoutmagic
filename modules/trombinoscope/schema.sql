-- trombinoscope module
--
-- Records which function(s) mark a section's "responsable" (highlighted lead
-- card). Every active member with a chief/chief-d'unité role function is
-- shown on the wall regardless — this table only stores the lead flag.
CREATE TABLE IF NOT EXISTS trombinoscope_function_flags (
    function_id INT UNSIGNED NOT NULL PRIMARY KEY,
    is_lead BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_tff_function FOREIGN KEY (function_id) REFERENCES functions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
