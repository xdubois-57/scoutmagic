-- inbound_mail module
--
-- A read-only gateway onto one or more remote mailboxes (§7.1). Nothing in
-- this schema describes a folder, a flag or a draft: this is not a mail
-- client, and it never writes to the remote box (§7.5).

-- inbound_mailboxes: one configured remote mailbox.
--
-- The password is the only secret, is stored encrypted via
-- EncryptionService (BLOB), and is read exactly once — when a client is
-- about to connect. Nothing renders it, not even partially (§7.4).
CREATE TABLE IF NOT EXISTS inbound_mailboxes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- What the superadmin called it, and the only thing a non-superadmin
    -- ever learns about a box besides whether it is working.
    name VARCHAR(150) NOT NULL,
    -- 'imap' | 'fake'. The abstraction exists so a native connector could
    -- be added later (§7.2); no such connector is implemented, and Gmail
    -- deliberately connects over IMAP with an app password.
    provider_type VARCHAR(20) NOT NULL DEFAULT 'imap',
    host VARCHAR(255) NOT NULL DEFAULT '',
    port SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    -- 'ssl' | 'tls' | 'starttls'. Never a "no encryption" option and never
    -- a "skip certificate check" one: an invalid certificate is a failed
    -- connection, not a warning to click through (§7.5).
    encryption VARCHAR(20) NOT NULL DEFAULT 'ssl',
    -- The account name. Not a secret in the sense the password is, but it
    -- is an email address, so it is encrypted like every other one.
    username_encrypted BLOB NOT NULL,
    password_encrypted BLOB NOT NULL,
    -- Newline-separated folder paths, in the server's own naming. Empty
    -- means INBOX.
    folders TEXT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- 'shared' | 'dedicated'. **The first question the configuration
    -- screen asks, and the one that determines every other answer.**
    --
    -- A dedicated box is an address created for one purpose, whose entire
    -- content concerns one module: that module reads and files all of it,
    -- and no other module looks at it. A shared box is the unit's public
    -- address, where a parent's question sits next to a supplier's invoice
    -- and a medical certificate — there, each module is told what it may
    -- analyse and what its users may read, one answer per module.
    --
    -- Stored rather than derived, because the two produce identical
    -- per-module rows and only the operator knows which of the two they
    -- meant. Reading « dédiée » back off the rows would make the screen
    -- flip its own answer the first time somebody narrowed one scope.
    purpose VARCHAR(20) NOT NULL DEFAULT 'shared',
    -- The consumer a dedicated box belongs to. NULL on a shared box, and
    -- meaningless there.
    dedicated_to VARCHAR(50) NULL,
    -- 'never' | 'ok' | 'error'. 'never' is a real state, distinct from
    -- 'ok': a box that has not once connected must not look healthy.
    sync_state VARCHAR(20) NOT NULL DEFAULT 'never',
    last_synced_at DATETIME NULL,
    -- A sentence written for a superadmin by Service\MailboxErrorFormatter,
    -- never a library's own exception text — that routinely carries the
    -- account name and the server's verbatim rejection of a credential.
    last_error VARCHAR(500) NULL,
    last_error_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_mailbox_consumers: what each module may do with each box.
--
-- **Two separate questions, deliberately not one.** « Analyser » is whether
-- the module is asked to recognise this box's mail at all; « qui peut
-- lire » is whether its users get a list of that mail and how much of it.
-- The first screen conflated them into one checkbox plus three radios that
-- overlapped, and an operator could not tell which combination meant what.
--
-- read_mode is 'none' | 'relevant' | 'all':
--
--   none      the module files mail automatically but opens no list. The
--             messages stay visible from the record they are attached to,
--             which is where somebody already has the right to be.
--   relevant  the module's users see the messages it attached to something
--             they manage, AND the ones it merely proposed. A proposition
--             is shown because the whole point is that somebody confirms
--             or dismisses it.
--   all       the module's users read EVERY message of the box. On a
--             dedicated box that is the normal answer. On a shared one it
--             is a heavy choice, and the screen says so in as many words:
--             it hands a module's audience the parents' questions, the
--             medical documents and the applications too.
--
-- A missing row means « ne rien faire » — no analysis, no reading. A module
-- installed later is therefore inert on every existing box until somebody
-- says otherwise, which is the right default for a screen whose whole
-- subject is who sees what.
CREATE TABLE IF NOT EXISTS inbound_mailbox_consumers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT UNSIGNED NOT NULL,
    -- Api\MessageConsumerInterface::consumerId(). Not a foreign key: a
    -- module can be deactivated and reactivated, and losing its
    -- configuration in between would be a surprise.
    consumer_id VARCHAR(50) NOT NULL,
    -- Named analyze_enabled rather than `analyze`, which is a keyword in
    -- more than one engine this schema has to survive.
    analyze_enabled TINYINT(1) NOT NULL DEFAULT 0,
    read_mode VARCHAR(20) NOT NULL DEFAULT 'none',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_mailbox_consumer (mailbox_id, consumer_id),
    INDEX idx_consumer_scope (consumer_id, read_mode),
    CONSTRAINT fk_scope_mailbox FOREIGN KEY (mailbox_id) REFERENCES inbound_mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_mailbox_cursors: how far each watched folder has been read.
--
-- An IMAP UID means nothing without its folder's UIDVALIDITY: a server that
-- renumbers a folder (restore, migration) announces it by changing that
-- value, and a cursor remembering only "last UID 4212" would then skip
-- every message below the new 4212 (§7.5). Storing the pair is what makes a
-- renumbering a re-read rather than a silent loss.
CREATE TABLE IF NOT EXISTS inbound_mailbox_cursors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT UNSIGNED NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_cursor_mailbox_folder (mailbox_id, folder),
    CONSTRAINT fk_cursor_mailbox FOREIGN KEY (mailbox_id) REFERENCES inbound_mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_messages: a message, and nothing about who it belongs to.
--
-- **A message is an entity of its own**, associable with zero, one or
-- several business objects through `inbound_message_links`. It used to
-- carry the consumer and the business reference in its own columns, which
-- made "one message, two owners" unrepresentable: a renter's email that is
-- also an invoice had to be stored twice or belong to one module only.
--
-- Everything a sender wrote is encrypted at rest: subject, addresses and
-- both bodies. message_id_blind_index is the searchable form of the
-- Message-ID, used for deduplication and for threading a reply onto the
-- message it answers, since an encrypted column cannot be compared.
CREATE TABLE IF NOT EXISTS inbound_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT UNSIGNED NOT NULL,
    folder VARCHAR(255) NOT NULL,
    uid_validity BIGINT UNSIGNED NOT NULL DEFAULT 0,
    imap_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,

    -- LEGACY, and nullable on purpose. These three moved to
    -- inbound_message_links; nothing writes them any more, and the only
    -- reader left is the one-time backfill that turns each of them into a
    -- link (Repository\InboundMessageRepository::backfillLinks()).
    --
    -- They are still DECLARED rather than dropped because the backfill runs
    -- from the composition root, which is reached long after the schema
    -- migration on the very request that would have dropped them — a drop
    -- shipped here would delete the values before anything could read them.
    -- drops.sql says the same thing, and carries the statements that remove
    -- them once every installation has run the backfill at least once. The
    -- same expand-then-contract that rental_assets.calendar_id uses.
    consumer_id VARCHAR(50) NULL,
    business_reference VARCHAR(100) NULL,
    link_origin VARCHAR(20) NULL,

    message_id_blind_index VARCHAR(64) NOT NULL,
    in_reply_to_blind_index VARCHAR(64) NULL,
    from_email_blind_index VARCHAR(64) NOT NULL,

    subject_encrypted BLOB NOT NULL,
    from_email_encrypted BLOB NOT NULL,
    from_name_encrypted BLOB NULL,
    message_id_encrypted BLOB NOT NULL,
    in_reply_to_encrypted BLOB NULL,
    -- Every address the message named, newline-separated and encrypted like
    -- the rest. A consumer's timeline shows them so a manager can tell a
    -- private reply from one copied to the whole staff.
    to_emails_encrypted BLOB NULL,
    body_text_encrypted BLOB NOT NULL,
    -- Already sanitised through HtmlSanitizer and stripped of remote images
    -- before it was written (§7.9). The raw HTML is never stored: keeping it
    -- would mean every future reader has to remember to sanitise.
    body_html_encrypted BLOB NOT NULL,

    sent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Automatic mail: a newsletter, a bounce, an acknowledgement, spam.
    -- Computed ONCE from the headers on arrival and never recomputed, never
    -- editable by hand: it is a technical observation about `Precedence`,
    -- `Auto-Submitted`, `List-Unsubscribe`, `X-Spam-Flag` and the sender,
    -- not a judgement somebody made.
    --
    -- **It changes nothing about storage or analysis.** A flagged message is
    -- offered to the consumers exactly like any other, which is
    -- indispensable: plenty of booking platforms send with
    -- `Precedence: bulk`. All it does is hide the message from the general
    -- mailbox by default, behind a filter that shows it again — and a
    -- message wrongly flagged becomes visible again the moment it carries
    -- an association or a proposition.
    is_bulk TINYINT(1) NOT NULL DEFAULT 0,

    -- When this message last stopped being associated with anything.
    --
    -- The retention floor of A4: a message becomes purgeable again at the
    -- LATER of `sent_at + retention` and this plus 30 days. Without it,
    -- detaching a 2024 message by mistake makes it disappear on the next
    -- purge, with no window to notice and undo.
    last_unlinked_at DATETIME NULL,
    -- When the deferred, content-level pass last ran over this message
    -- (Task\AnalyzeStoredMessagesHandler). NULL means never.
    --
    -- Analysis happens ONCE, on arrival, plus this one pass afterwards.
    -- Nothing re-analyses a stored message on its own, ever: propositions
    -- appearing and disappearing as modules are updated, with nobody able
    -- to say why, is worse than none. A superadmin who enables a module on
    -- a box that has been collecting for months asks for it explicitly.
    stored_analysis_at DATETIME NULL,

    -- A message exists **once per mailbox**, whatever UID it now carries
    -- after a renumbering and however many objects it ends up associated
    -- with. Under a new name because SchemaComparator matches an index by
    -- name only: redefining the old idx_message_dedup in place would have
    -- been a silent no-op on every installed site (§10).
    UNIQUE INDEX idx_message_box_dedup (mailbox_id, message_id_blind_index),
    -- The general mailbox lists by sent_at descending and pages by cursor
    -- (A13) — never OFFSET, on a table that grows indefinitely.
    INDEX idx_message_sent (sent_at),
    INDEX idx_message_box_sent (mailbox_id, sent_at),
    INDEX idx_message_thread (mailbox_id, message_id_blind_index),
    CONSTRAINT fk_message_mailbox FOREIGN KEY (mailbox_id) REFERENCES inbound_mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_message_links: which business objects a message is associated
-- with. Zero, one, or several.
--
-- This is the table that makes a message transversal. `rental` linking a
-- message to a booking does not stop `finance` linking the same message to
-- an invoice: two rows, two consumers, one message, and each module reads
-- only its own.
CREATE TABLE IF NOT EXISTS inbound_message_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    -- Deliberately opaque on this side: `inbound_mail` has no idea what a
    -- booking reference looks like and must not acquire one.
    consumer_id VARCHAR(50) NOT NULL,
    business_reference VARCHAR(100) NOT NULL,
    -- **0 means the whole message**, and it is never NULL. MySQL considers
    -- two NULLs distinct inside a unique index, so a nullable column here
    -- would make idx_link_unique inoperative and let the same association
    -- be created twice.
    attachment_id INT UNSIGNED NOT NULL DEFAULT 0,
    -- 'reference' | 'thread' | 'sender' | 'ai' | 'manual'. Shown to the
    -- reader: an association made on a sender address is a weaker claim
    -- than one made on an explicit reference, and saying so is the honest
    -- interface.
    link_origin VARCHAR(20) NOT NULL,
    -- NULL means the association was made automatically. A human one names
    -- its author, because an interface that says "manual association"
    -- without being able to say by whom helps nobody settle a disputed
    -- filing.
    created_by_user_account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_link_unique (message_id, consumer_id, business_reference, attachment_id),
    INDEX idx_link_reference (consumer_id, business_reference),
    CONSTRAINT fk_link_message FOREIGN KEY (message_id) REFERENCES inbound_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_link_author FOREIGN KEY (created_by_user_account_id) REFERENCES user_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_message_candidates: what a consumer suspects but will not act on.
--
-- A **proposition** — « proposition » at the screen, never « candidat ».
-- The difference from a link is who decides: a link is a certainty the
-- consumer creates unattended, a proposition is a guess it hands to
-- somebody. That is why the label and the explanation are stored at all,
-- and why they are French sentences rather than internal codes.
--
-- Both may quote the message, so both are encrypted like everything else a
-- sender wrote.
--
-- **dismissed_at is final.** A proposition somebody set aside never comes
-- back, not on the next synchronisation and not on a manual re-analysis:
-- setting one aside is a human decision, and contradicting it with a
-- technical job is the surest way to make people stop using the screen.
CREATE TABLE IF NOT EXISTS inbound_message_candidates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    -- 0 = the whole message, never NULL. Same convention, same reason, as
    -- inbound_message_links.
    attachment_id INT UNSIGNED NOT NULL DEFAULT 0,
    consumer_id VARCHAR(50) NOT NULL,
    business_reference VARCHAR(100) NOT NULL,
    -- The kind of signal ('sender_window', 'amount_and_date', …), stable
    -- and machine-readable so a screen can group by it. Never shown raw.
    evidence_type VARCHAR(50) NOT NULL,
    evidence_label_encrypted BLOB NOT NULL,
    evidence_explanation_encrypted BLOB NOT NULL,
    dismissed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_candidate_unique (message_id, consumer_id, business_reference, attachment_id),
    INDEX idx_candidate_reference (consumer_id, business_reference),
    CONSTRAINT fk_candidate_message FOREIGN KEY (message_id) REFERENCES inbound_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- inbound_message_attachments: metadata only.
--
-- The bytes live in the core file store behind FileAccessGuard, under a
-- generated name, outside public/ (§7.9). content_hash is what makes the
-- same signature logo arriving on ten messages one stored file rather than
-- ten.
CREATE TABLE IF NOT EXISTS inbound_message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    -- NULL when the file was NOT kept — see omission_reason. The row still
    -- exists so a screen can say « le message est bien arrivé, ce fichier
    -- n'a pas été conservé, il reste dans la boîte d'origine » rather than
    -- silently showing one attachment fewer than the sender sent.
    file_id INT UNSIGNED NULL,
    -- What the sender called it — a label, never the stored name.
    filename_encrypted BLOB NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    -- The SHA-256 of the **original** bytes, never of the stored derivative.
    -- Hashing what was written would make deduplication depend on the GD
    -- version that resized it: the same photo arriving twice would hash
    -- differently after a library upgrade and be stored twice.
    content_hash CHAR(64) NOT NULL,
    -- 'too_large' | 'mime_rejected' | 'quota_exceeded' | 'storage_error'
    -- | 'reclassified'. NULL means the file was kept, and then file_id is
    -- set. The last value is the odd one — the file WAS kept and then a
    -- consumer took it over as a document of its own, so the message stops
    -- naming it and the retention purge cannot delete a booking's signed
    -- contract along with the email it arrived in.
    omission_reason VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attachment_message (message_id),
    INDEX idx_attachment_hash (content_hash),
    CONSTRAINT fk_attachment_message FOREIGN KEY (message_id) REFERENCES inbound_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
