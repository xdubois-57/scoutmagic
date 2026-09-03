-- finance module
--
-- A finance "exercice" (fiscal year) is simply a scout year — the core
-- `scout_years` table — not a module-specific entity an admin creates by
-- hand. There is no finance_fiscal_years table: finance_transactions
-- references scout_years(id) directly (see fk_ft_scout_year below).
-- Repository\FiscalYearRepository is a thin adapter over
-- Core\Config\ScoutYearService.

-- finance_accounts: a bank or cash account, optionally tied to a section
-- (e.g. one account per section, per the module spec's "un compte par
-- section est créé par défaut"). iban/holder_name are encrypted
-- (Core\Security\EncryptionService) like any other sensitive field —
-- iban_blind_index (HMAC) lets the import flow verify a bank statement's
-- IBAN against the target account without ever decrypting on a WHERE
-- clause. role_min_view/status are validated in Service\FinanceService,
-- not just the form: role_min_view can never go below 'intendant' (the
-- module's own access floor), and status can only become 'active' once
-- iban and holder_name are both set. status's 'archived' value is kept
-- as-is for schema stability, but is only ever reached/left via the
-- config page's reversible activate/deactivate toggle now — see
-- Repository\Account::STATUS_INACTIVE and Service\FinanceService::
-- setAccountActive(); there is no longer a one-way "archive" action.
-- is_default marks one of the one-per-section accounts
-- Service\FinanceService::ensureDefaultAccountsForSections() creates —
-- for the config page's "Par défaut" badge, and to lock that account's
-- name/type/section from being edited (Service\FinanceService::
-- updateAccount()); IBAN, holder, role_min_view, and active/inactive
-- stay editable regardless.
CREATE TABLE IF NOT EXISTS finance_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    account_type ENUM('bank', 'cash') NOT NULL,
    section_id INT UNSIGNED NULL,
    iban BLOB NULL,
    iban_blind_index VARCHAR(64) NULL,
    holder_name BLOB NULL,
    role_min_view ENUM('intendant', 'chief', 'admin') NOT NULL DEFAULT 'intendant',
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fa_iban_blind (iban_blind_index),
    CONSTRAINT fk_fa_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_categories: simple, flat category list for movement
-- classification. Deleting one is refused while any movement still
-- references it (Service\FinanceService::deleteCategory() — the config
-- page disables the button in that case and the service is the backstop);
-- deactivating it is the way to retire a category that has history. The
-- one category removed with its movements un-linked instead is the derived
-- "Virement <compte>" one, dropped by Service\
-- AccountTransferCategoryService::removeFor() when its account stops being
-- eligible — that one is app-managed, not an admin decision. account_id is set only
-- for the auto-generated "Virement <compte>" category
-- Service\AccountTransferCategoryService keeps in sync with each active
-- account (one per account, never for the default/custom categories an
-- admin manages by hand) — it's how that service finds "the" category for
-- a given account again later without matching on name, which can be
-- freely renamed. description is mandatory at the application level
-- (Service\FinanceService::createCategory()/updateCategory() reject a
-- blank one) — it's what Service\AiCategorizationService sends the model
-- so it can actually tell categories apart by more than a short name;
-- the column itself stays NOT NULL DEFAULT '' so the schema migration
-- never breaks on a pre-existing row — VARCHAR rather than TEXT
-- specifically so that default is portable (vanilla MySQL, unlike
-- MariaDB, rejects a DEFAULT on a TEXT/BLOB column: error 1101).
-- is_default marks one of
-- FinanceService::DEFAULT_CATEGORY_NAMES, for the config page's "Par
-- défaut" badge — set at creation time (ensureDefaultCategories()/
-- resetDefaultCategories()), backfilled once by name for a category
-- that already existed before this column did.
CREATE TABLE IF NOT EXISTS finance_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT NOT NULL DEFAULT 0,
    account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_fc_account (account_id),
    CONSTRAINT fk_fc_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_category_rules: auto-categorization rules evaluated in
-- ascending priority order by Service\CategoryRuleEngine during import
-- (module spec follow-up "itération 3") — the first matching rule wins.
-- A rule can combine up to three independent conditions at once — all of
-- the ones that are non-NULL must match for the rule as a whole to match
-- (AND, not OR); a rule with all three NULL never matches anything.
-- keyword_pattern is a regular expression matched against the movement's
-- label (case-insensitive, Unicode) — a plain word like "delhaize" is
-- itself already a valid regex matching that substring, so this doubles
-- as the simple "keyword" mode with no special syntax required.
-- counterparty_account_pattern is an IBAN, full or partial (spaces/case
-- ignored). amount_range is ">100" (strictly greater than) or "50-200"
-- (inclusive), evaluated against the absolute amount. is_system marks a
-- rule as maintained by the app itself rather than an admin — currently
-- only the one Service\AccountTransferCategoryService keeps in sync with
-- an active account's IBAN — the config UI hides its edit/delete controls
-- (it would just be recreated/corrected on the next account save anyway,
-- since it's derived data, not a standing admin decision). is_default
-- marks a rule as originating from Service\FinanceService::
-- DEFAULT_CATEGORY_RULE_PATTERNS (seeded once alongside the default
-- categories) — unlike is_system it's freely editable/deactivatable, but
-- lets the config page's "Réinitialiser les règles par défaut" button
-- find exactly the rules it's allowed to replace, leaving any other
-- admin-authored rule untouched.
CREATE TABLE IF NOT EXISTS finance_category_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 0,
    keyword_pattern VARCHAR(255) NULL,
    counterparty_account_pattern VARCHAR(255) NULL,
    amount_range VARCHAR(50) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fcr_priority (priority),
    CONSTRAINT fk_fcr_category FOREIGN KEY (category_id) REFERENCES finance_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_transactions: one row per bank/cash movement. label and
-- comment are both encrypted — a bank communication line can contain
-- personal or otherwise confidential information (e.g. a member's name
-- in a payment reference), and a chef's free-text comment just as
-- easily can. amount is signed (positive = credit, negative = debit),
-- unlike most of this codebase's convention of separate signed/unsigned
-- fields, because a bank statement's own sign convention is what a
-- reconciling admin expects to see reproduced as-is. The unique
-- (account_id, bank_reference) pair is the import deduplication key
-- (module spec follow-up "itération 3": re-importing an overlapping
-- statement range must silently skip already-known lines) — manually
-- entered movements (source = 'manual') have no bank_reference, so it is
-- nullable and only enforced unique when present (see
-- Repository\TransactionRepository's insert-or-skip). counterparty_name/
-- counterparty_account (the other party's name/IBAN, when a bank export
-- provides them — see Parser\StatementLine) and extra_details (whatever
-- other columns a bank export has that don't get their own dedicated
-- column, concatenated) are encrypted for the same reason label/comment
-- are: they routinely contain a person's name or account number.
-- category_source records how category_id got set — 'auto' for
-- Service\CategoryRuleEngine (at import) or Service\
-- BulkCategorizationService (rules or AI, backfilling uncategorized
-- movements), 'manual' for an admin picking one by hand on the movements
-- page — NULL alongside category_id while uncategorized. Not enforced
-- anywhere yet beyond the movements page's "Automatique" badge; kept for
-- future use (module spec follow-up).
CREATE TABLE IF NOT EXISTS finance_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    fiscal_year_id INT UNSIGNED NOT NULL,
    bank_reference VARCHAR(100) NULL,
    transaction_date DATE NOT NULL,
    label BLOB NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    category_id INT UNSIGNED NULL,
    category_source ENUM('manual', 'auto') NULL,
    comment BLOB NULL,
    counterparty_name BLOB NULL,
    counterparty_account BLOB NULL,
    extra_details BLOB NULL,
    source ENUM('import', 'manual') NOT NULL,
    imported_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_ft_account_reference (account_id, bank_reference),
    INDEX idx_ft_date (transaction_date),
    -- (account_id, fiscal_year_id, transaction_date): the module's
    -- hottest filter (TransactionRepository::findFiltered and the
    -- dashboard's history reads) plus its ORDER BY, which otherwise
    -- filesorts once the account prefix of idx_ft_account_reference
    -- stops narrowing.
    INDEX idx_ft_account_year_date (account_id, fiscal_year_id, transaction_date),
    CONSTRAINT fk_ft_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ft_scout_year FOREIGN KEY (fiscal_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_ft_category FOREIGN KEY (category_id) REFERENCES finance_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_balance_checkpoints: a known-good balance at a point in time,
-- so Service\BalanceService never has to sum every transaction since the
-- account's creation — it finds the closest checkpoint at or before the
-- requested date and adds/subtracts only the transactions in between.
-- The first import for an account must create one (module spec: "premier
-- import... solde obligatoire"); later imports create one only when a
-- balance was actually supplied.
CREATE TABLE IF NOT EXISTS finance_balance_checkpoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    checkpoint_date DATE NOT NULL,
    balance DECIMAL(12, 2) NOT NULL,
    source ENUM('import', 'manual') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fbc_account_date (account_id, checkpoint_date),
    CONSTRAINT fk_fbc_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_statement_imports: bookkeeping for every bank statement import
-- run (module spec follow-up "itération 3") — shown on the import
-- result page and kept as an audit trail; never contains bank data
-- itself, only counts and the original filename.
CREATE TABLE IF NOT EXISTS finance_statement_imports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    bank_code VARCHAR(20) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    lines_total INT UNSIGNED NOT NULL DEFAULT 0,
    lines_new INT UNSIGNED NOT NULL DEFAULT 0,
    lines_duplicate INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by INT UNSIGNED NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fsi_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_attachments: a receipt (or other supporting document). A
-- user-facing delete only ever archives it (status = 'archived'), so a
-- mistaken deletion never loses proof of an expense — the sole
-- exception is Task\PurgeOldMovementsHandler, which physically deletes
-- an attachment (row + encrypted file) once its fiscal year has been
-- purged past the retention period AND it has no remaining movement
-- association (active or archived). file_id references
-- the core files table (Core\File\FileAccessGuard); the file itself is
-- stored encrypted at rest via the generic Core\File\
-- EncryptedFileStorageService (files.encrypted = true for every
-- attachment's file row). parent_attachment_id chains a replacement to
-- the receipt it replaces, so the full version history of a given
-- expense's proof is always reconstructable. suggested_source
-- distinguishes a manually-typed suggestion (module spec: optional
-- amount/date fields on the upload form) from one written by
-- Task\ExtractReceiptDataHandler, so the UI can show "(IA)" only for the
-- latter — NULL when no suggestion has ever been set. account_id is
-- enforced NOT NULL at the application level (Service\ReceiptService::
-- upload()/replace() always require one) — kept nullable in the schema
-- itself only so any pre-existing row from before this requirement
-- never breaks a migration. A receipt's file inherits its account's
-- role_min_view as the underlying files.role_min at upload time (and is
-- re-synced whenever the account's role_min_view changes — see
-- Controller\ConfigAccountController), so downloading it via
-- Core\File\FileAccessGuard enforces the same floor as the account
-- itself. suggested_label is the AI-extracted merchant name (e.g.
-- "Delhaize"); suggested_description is a one-sentence AI-generated
-- summary of what the receipt is for (e.g. "Achat de fournitures de
-- bureau pour l'unité") — both only ever written by Task\
-- ExtractReceiptDataHandler, no manual-entry counterpart on the upload
-- form (unlike suggested_amount/suggested_date), so they are always NULL
-- for a suggested_source='manual' row. Both are encrypted (BLOB) — a
-- merchant name or purchase description can reveal personal information
-- just as easily as a bank transaction label can, so they get the same
-- treatment. matching_ai_attempted_at marks that Service\
-- ReceiptMatchingService has already spent its one allowed AI-assisted
-- matching attempt on this receipt (rule-based matching has no such
-- limit and is retried on every bank import) — NULL means the AI
-- fallback hasn't been tried yet.
CREATE TABLE IF NOT EXISTS finance_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NULL,
    file_id INT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    suggested_amount DECIMAL(12, 2) NULL,
    suggested_date DATE NULL,
    suggested_label BLOB NULL,
    suggested_description BLOB NULL,
    suggested_source ENUM('manual', 'ai') NULL,
    matching_ai_attempted_at DATETIME NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    parent_attachment_id INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- SHA-256 of the bytes as received, so the same invoice forwarded a
    -- second time — a new Message-ID, a second watched box — is one
    -- receipt and not two. NULL on receipts stored before this existed.
    content_hash CHAR(64) NULL,
    INDEX idx_fatt_content_hash (content_hash),
    -- (account_id, status, uploaded_at): the receipts list and the
    -- dashboard's pending counts filter on account + status and order by
    -- uploaded_at on every load — none of the three was indexed.
    INDEX idx_fatt_account_status_uploaded (account_id, status, uploaded_at),
    CONSTRAINT fk_fatt_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_fatt_file FOREIGN KEY (file_id) REFERENCES files(id),
    CONSTRAINT fk_fatt_parent FOREIGN KEY (parent_attachment_id) REFERENCES finance_attachments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_transaction_attachments: many-to-many join — a receipt can
-- support several movements (e.g. one invoice covering both a bank fee
-- and a purchase line) and a movement can have several receipts.
CREATE TABLE IF NOT EXISTS finance_transaction_attachments (
    transaction_id INT UNSIGNED NOT NULL,
    attachment_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (transaction_id, attachment_id),
    CONSTRAINT fk_fta_transaction FOREIGN KEY (transaction_id) REFERENCES finance_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fta_attachment FOREIGN KEY (attachment_id) REFERENCES finance_attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_ai_category_suggestions: a rolling log of "no existing category
-- fit" suggestions from Service\AiCategorizationService (the AI
-- categorization rule's CHEAP-tier prompt asks for one in the same
-- request whenever it can't pick an existing category) — Service\
-- FinanceService keeps only the 10 most recent (oldest pruned on
-- insert), surfaced as one-click suggestions in the "new category"
-- dialog. Not a queue and never referenced by id elsewhere — just a
-- short list of names, so no encryption or FK needed.
CREATE TABLE IF NOT EXISTS finance_ai_category_suggestions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    suggested_name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_expected_receivables: generic "money we expect to receive"
-- registry, keyed by (source_module, source_reference_id) so any module
-- can register an expectation without Finance knowing anything about it
-- (Modules\Finance\Api\ExpectedReceivableInterface — ARCHITECTURE.md
-- §7.5, a module offering an API to others). Introduced for the news
-- module's paid form fields (one receivable per form response), but not
-- specific to it — a future membership-fee or event-registration feature
-- reuses this unchanged. amount_due_cents/communication are not personal
-- data (a payment reference and an integer), so no encryption needed;
-- label is caller-supplied free text that may identify the payer (e.g.
-- "Jean Dupont — Camp d'été"), so — same convention as finance_
-- transactions.counterparty_name — it is encrypted (SECURITY.md §5),
-- transparently, inside Service\ExpectedReceivableService; callers of
-- the public interface only ever see/pass a plain string. Status (paid/
-- partial/unpaid) is never stored — always computed live from matched
-- finance_transactions sharing the same communication (Service\
-- ExpectedReceivableService::getReceivableStatus()), since a receivable
-- can be settled across several bank transactions over time.
--
-- waived_at/waived_by record an abandoned receivable — a dispense, a
-- goodwill gesture, an invoicing mistake. **Abandoning is not
-- collecting**: it settles the receivable without a cent entering the
-- account, so it is deliberately not expressed as an allocation of some
-- imaginary payment, which would inflate every incoming total the
-- treasurer reads.
--
-- member_id says WHO owes this, when the debtor is a member of the unit
-- — which a payment campaign always requires and `rental` never can:
-- that module invoices outside renters who are not members at all, so
-- the column is **optional** and making it mandatory would break the
-- letting. It points at `members.id`, the persistent identity, and not
-- at a member_year: a receivable outlives the scout year that saw it
-- born, exactly as `files.owner_member_id` does.
--
-- refund_requested_at/refund_requested_by record the one human decision
-- in the overpayment cycle: "this surplus is to be paid back". Whether
-- it HAS been paid back is never stored — it is read from the refund
-- allocations against the receivable (finance_receivable_allocations
-- with a negative amount), because the debit leaving the account is what
-- makes a refund real, not a checkbox somebody ticked.
CREATE TABLE IF NOT EXISTS finance_expected_receivables (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_module VARCHAR(50) NOT NULL,
    source_reference_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    amount_due_cents INT UNSIGNED NOT NULL,
    communication VARCHAR(24) NOT NULL,
    label_encrypted BLOB NULL,
    member_id INT UNSIGNED NULL,
    waived_at DATETIME NULL,
    waived_by INT UNSIGNED NULL,
    refund_requested_at DATETIME NULL,
    refund_requested_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fer_source (source_module, source_reference_id),
    INDEX idx_fer_communication (communication),
    INDEX idx_fer_member (member_id),
    CONSTRAINT fk_fer_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id),
    CONSTRAINT fk_fer_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_receivable_allocations: "this much of that bank movement pays
-- for this receivable". Before it existed nothing was written down at
-- all — a receivable's status was refabricated on every display by
-- summing the *whole* amount of every transaction whose text carried its
-- communication — so no human could correct anything, a single transfer
-- covering three siblings could not be split, and a credit nobody's
-- communication matched could not be attached by hand.
--
-- amount_cents is signed, and the sign is the row's meaning:
--
--   > 0  the movement settles the receivable. Never more than what is
--        still due (Service\ReceivableAllocationService), which is the
--        single rule behind "paid too much", "paid twice a month apart"
--        and "paid in instalments that overshoot" alike — the surplus
--        simply stays unallocated instead of pretending a receivable
--        absorbed more than it was ever worth.
--   = 0  a human decided this movement pays nothing towards this
--        receivable. A tombstone, not a no-op: removing an automatic
--        allocation has to leave a trace, or the next automatic run
--        would put it straight back.
--   < 0  a debit refunding an overpayment on this receivable. Same
--        mechanism in reverse, which is why the refund cycle closes on
--        the account statement rather than on somebody's word.
--
-- source is 'auto' for Service\ReceivableAllocationService's matching at
-- import time and 'manual' for anything a human did — the same
-- distinction, and the same purpose, as finance_transactions
-- .category_source: **the automatic pass never touches a row marked
-- manual**, so a correction survives the next import.
--
-- The unique (transaction_id, receivable_id) pair is what makes the
-- automatic pass idempotent: re-running it over a statement that was
-- re-imported writes nothing new. Re-importing the statement itself is
-- already a no-op one level down, on finance_transactions' own unique
-- (account_id, bank_reference).
--
-- There is deliberately no cross-account flag: an allocation joins a
-- movement and a receivable that live on the SAME account, and the
-- service refuses anything else. Money paid onto the wrong account is
-- reported on both sides and transferred for real — never imputed at a
-- distance, which would mark a receivable settled while the account
-- concerned received nothing.
CREATE TABLE IF NOT EXISTS finance_receivable_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    receivable_id INT UNSIGNED NOT NULL,
    amount_cents INT NOT NULL,
    source ENUM('auto', 'manual') NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE INDEX idx_fra_pair (transaction_id, receivable_id),
    INDEX idx_fra_receivable (receivable_id),
    CONSTRAINT fk_fra_transaction FOREIGN KEY (transaction_id) REFERENCES finance_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_fra_receivable FOREIGN KEY (receivable_id) REFERENCES finance_expected_receivables(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_campaigns: "invoice this amount to each member of this list,
-- and follow the payments". A generic mechanism, not a cotisation
-- feature — a camp balance and a weekend both use it unchanged.
--
-- **A campaign belongs to a scout year**, because without one neither
-- the retention nor the filing has a rule, and a cotisation campaign
-- with no season makes no sense to anybody reading it later.
--
-- **Two states, and no draft.** The receivables exist from the import
-- onwards, so there is nothing a draft could usefully hold back.
-- Closing stops the reminders and freezes the campaign, which stays
-- readable for ever.
--
-- source_file_id points at the uploaded spreadsheet, stored encrypted
-- through Core\File\EncryptedFileStorageService like any other
-- confidential file. It is kept because it is the only way to
-- investigate "where did this amount come from" months later — and
-- purged with the same window as a Desk import (two scout years,
-- `import_retention_scout_years`, Task\PurgeCampaignFilesHandler),
-- because a second retention period to maintain is a second retention
-- period to get wrong.
--
-- notified_at records the treasurer pressing "Notifier les familles".
-- Not the creation of the campaign: the reminder e-mail leaves by hand
-- from the mass-mail draft, so the site cannot know when the request
-- actually went out, and a notification sent at import time would
-- announce a message that has not been written yet.
CREATE TABLE IF NOT EXISTS finance_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(150) NOT NULL,
    scout_year_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    source_file_id INT UNSIGNED NULL,
    source_filename VARCHAR(255) NOT NULL DEFAULT '',
    merge_columns TEXT NULL,
    notified_at DATETIME NULL,
    notified_by INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fcamp_year (scout_year_id),
    INDEX idx_fcamp_account (account_id),
    CONSTRAINT fk_fcamp_year FOREIGN KEY (scout_year_id) REFERENCES scout_years(id),
    CONSTRAINT fk_fcamp_account FOREIGN KEY (account_id) REFERENCES finance_accounts(id),
    CONSTRAINT fk_fcamp_file FOREIGN KEY (source_file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- finance_campaign_rows: one row per member, one receivable per row.
--
-- **One receivable per member, never per household.** A family of three
-- gets three requests, three communications, three transfers. The
-- household decides the TARIFF; it never decides the receivable. This is
-- the same shape as the federation's own invoice, which is nominative
-- too, and it is what makes a payment identifiable when it arrives.
--
-- member_id is MANDATORY here, unlike on finance_expected_receivables:
-- a campaign line that resolves to nobody is refused at import, and the
-- whole import fails rather than landing partially. There is no
-- fall-back matching on a name — the identifier column produced by the
-- site's own member export is what ties an amount to a person, and a
-- file rebuilt by hand without it is rejected with that sentence.
--
-- merge_data holds the spreadsheet's other columns for this line,
-- encrypted: they become the merge variables of the reminder draft, and
-- they are personal data (a name, an address, a section) that has no
-- business sitting in the clear. Purged with the source file, for the
-- same reason — keeping a copy of a file we have promised to delete
-- would make the promise decorative.
--
-- note is the treasurer's own note about this receivable, encrypted and
-- **never visible to the family**. A single field with its author and
-- the date it last moved, not a thread of dated entries: the question it
-- answers is "where do we stand with this one", and a thread answers it
-- worse. Present in the export, never offered as a merge variable — a
-- note that could end up in a reminder is a note nobody will write
-- honestly.
CREATE TABLE IF NOT EXISTS finance_campaign_rows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    source_line INT UNSIGNED NOT NULL DEFAULT 0,
    merge_data BLOB NULL,
    note BLOB NULL,
    note_author_id INT UNSIGNED NULL,
    note_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fcrow_campaign (campaign_id),
    INDEX idx_fcrow_member (member_id),
    CONSTRAINT fk_fcrow_campaign FOREIGN KEY (campaign_id) REFERENCES finance_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_fcrow_member FOREIGN KEY (member_id) REFERENCES members(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
